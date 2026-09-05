#!/usr/bin/env python3
"""
run_batch.py — CLI entrypoint for the variant engine's two-pass content/build gate.

Usage:
  python3 run_batch.py --master water-site --batch b1 --pass content [--only=domain] [--force]
  python3 run_batch.py --master water-site --batch b1 --pass build   [--only=domain]

Reads:
  sites/{master}/batches/{batch}/params.csv          — identity per row (existing file/format)
  sites/{master}/batches/{batch}/variant_plan.json   — per-row variant assignment (PHP writes this)
  sites/{master}/multisite/niche_brief.json          — niche facts/guardrails/tone (existing file)
  sites/{master}/multisite/variants/{voices,research_prompts}/*.json — per-niche dimension data
  sites/{master}/multisite/variants/{services,faqs,guide_topics}.json — niche content catalog
  variants/{types,colors,titles}/*.json              — shared, niche-agnostic dimension data

Writes, per row, under sites/{master}/batches/{batch}/variant_sites/{slug}/:
  config.json, content.json, content_cache.json, images/, seo_report.json
Writes rendered HTML under the EXISTING sites/{master}/batches/{batch}/output/{slug}/ path,
so phase 5's "Upload sites" button needs zero changes.

Emits one JSON object per line to stdout — the admin panel shells this out and polls stdout
the same way it already polls run_campaign.php's jsonlines output for the existing generator.
"""

import argparse
import csv
import json
import os
import shutil
import sys
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import config_schema
import generate_content
import images
import legal_meaning_lock
import render
import seo_validate

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def emit(event, **kw):
    print(json.dumps({'event': event, **kw}), flush=True)


def master_dir(master_id):
    return os.path.join(REPO_ROOT, 'sites', master_id, 'multisite')


def batch_dir(master_id, batch_id):
    return os.path.join(REPO_ROOT, 'sites', master_id, 'batches', batch_id)


def load_params_rows(master_id, batch_id):
    path = os.path.join(batch_dir(master_id, batch_id), 'params.csv')
    with open(path, encoding='utf-8-sig', newline='') as fh:
        return list(csv.DictReader(fh))


def load_json(path, default=None):
    if os.path.isfile(path):
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    return {} if default is None else default


def save_json(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, indent=2)
    os.replace(tmp, path)


def load_niche_catalog(mdir):
    """The niche-level content catalog shared by every business generated from this master:
    which services exist, which FAQ questions get asked, which guide topics get written per
    city. Per-row files (params.csv) only ever carry per-business IDENTITY, never this."""
    services = load_json(os.path.join(mdir, 'variants', 'services.json'), {'items': []})
    faqs = load_json(os.path.join(mdir, 'variants', 'faqs.json'), {'questions': []})
    guides = load_json(os.path.join(mdir, 'variants', 'guide_topics.json'), {'topics': []})
    return services, faqs, guides


def parse_cities(landing_cities_value):
    """'Lufkin, TX; Conroe, TX' -> city dicts. Semicolon separates cities, comma separates
    name/state within one city — reuses the EXISTING landing_cities column already in
    MS_KNOWN_COLS, no new CSV schema needed."""
    out = []
    for chunk in (landing_cities_value or '').split(';'):
        chunk = chunk.strip()
        if not chunk:
            continue
        parts = [p.strip() for p in chunk.split(',')]
        if len(parts) >= 2:
            name, ss = parts[0], parts[1]
            slug = f"{name.lower().replace(' ', '-')}-{ss.lower()}"
            out.append({'id': slug, 'name': name, 'SS': ss, 'slug': slug, 'is_primary': len(out) == 0,
                        'stats': [], 'local_facts': []})
    return out


def build_fresh_config(row, master_id, batch_id, plan_row, niche_brief, services_catalog, faq_catalog, guide_catalog):
    variant = plan_row['variant']
    identity = {
        'business': row.get('business', ''), 'phone': row.get('phone', ''),
        'tel': row.get('tel') or row.get('phone', ''), 'email': row.get('email', ''),
        'address': row.get('address', ''), 'zip': row.get('zip', ''),
        'lat': float(row['lat']) if row.get('lat') else None,
        'lng': float(row['lng']) if row.get('lng') else None,
        'analytics_id': row.get('analytics_id', ''),
    }
    niche = {k: niche_brief.get(k, '') for k in
             ('business_descriptor', 'service_noun', 'customer_noun', 'local_angle', 'tone', 'guardrails')}
    config = config_schema.new_config(row['domain'], master_id, batch_id, variant, identity, niche)
    config['cities'] = parse_cities(row.get('landing_cities'))
    config['services'] = {'mode': 'flat', 'items': [dict(s) for s in services_catalog.get('items', [])],
                           'matrix': {'dimensions': [], 'rows': []}}
    config['faqs'] = [{'id': f'faq{i+1}', 'question_hint': q} for i, q in enumerate(faq_catalog.get('questions', []))]
    guides = []
    for city in config['cities']:
        for i, topic in enumerate(guide_catalog.get('topics', [])):
            slug = f"{topic.replace(' ', '-')}-{city['slug']}"
            guides.append({'id': f"g_{city['id']}_{i}", 'city_id': city['id'], 'slug': slug, 'topic': topic})
    config['pages']['guides'] = guides
    config['meta']['current_year'] = datetime.now(timezone.utc).year
    return config


def load_variant_dim(mdir, dimension, variant_id):
    return load_json(os.path.join(mdir, 'variants', dimension, f"{variant_id}.json"), {})


def run_content(master_id, batch_id, only_domain, force, dry_run, api_key):
    mdir = master_dir(master_id)
    bdir = batch_dir(master_id, batch_id)
    niche_brief = load_json(os.path.join(mdir, 'niche_brief.json'))
    services_catalog, faq_catalog, guide_catalog = load_niche_catalog(mdir)

    guardrails_path = os.path.join(mdir, 'variants', 'guardrail_clauses.json')
    guardrail_clauses = load_json(guardrails_path).get('clauses')
    if not guardrail_clauses:
        emit('deriving_guardrail_clauses')
        guardrail_clauses = legal_meaning_lock.derive_guardrail_clauses(
            niche_brief.get('guardrails', ''), generate_content.cc.WRITE_MODEL, api_key, dry_run=dry_run)
        save_json(guardrails_path, {'clauses': guardrail_clauses, 'source': niche_brief.get('guardrails', '')})

    plan_path = os.path.join(bdir, 'variant_plan.json')
    plan = load_json(plan_path)
    if not plan.get('approved'):
        emit('fatal', reason='variant_plan.json is not approved — run Propose + Approve in the panel first')
        return 1

    rows = load_params_rows(master_id, batch_id)
    ok = failed = 0
    for row in rows:
        domain = row.get('domain')
        if not domain or (only_domain and domain != only_domain):
            continue
        plan_row = plan.get('rows', {}).get(domain)
        if not plan_row or not plan_row.get('approved'):
            continue

        emit('row_start', domain=domain, step='content')
        site_dir = os.path.join(bdir, 'variant_sites', render.slugify(domain))
        config_path = os.path.join(site_dir, 'config.json')
        cache_path = os.path.join(site_dir, 'content_cache.json')

        config = load_json(config_path) if (os.path.isfile(config_path) and not force) else None
        if not config:
            config = build_fresh_config(row, master_id, batch_id, plan_row, niche_brief,
                                         services_catalog, faq_catalog, guide_catalog)

        config_errors = config_schema.validate_config(config)
        if config_errors:
            emit('row_error', domain=domain, errors=config_errors)
            failed += 1
            continue

        voice = load_variant_dim(mdir, 'voices', config['variant']['voice'])
        research_prompt = load_variant_dim(mdir, 'research_prompts', config['variant']['research_prompt'])
        niche_extra = {
            'voice_directive': voice.get('directive', ''),
            'research_prompt_template': research_prompt.get('template', ''),
        }

        cache = load_json(cache_path, {'fields': {}})
        content, cache = generate_content.run_content_pass(config, cache, niche_extra, guardrail_clauses, api_key, dry_run=dry_run)
        content_errors = config_schema.validate_content(content)

        save_json(config_path, config)   # cities[] now carries research_meta etc.
        save_json(cache_path, cache)
        save_json(os.path.join(site_dir, 'content.json'), content)

        needs_review = not config_schema.meaning_lock_passed(content)
        plan_row['content_generated'] = True
        plan_row['content_needs_review'] = needs_review
        if content_errors:
            emit('row_error', domain=domain, errors=content_errors)
            failed += 1
        else:
            emit('row_done', domain=domain, step='content', needs_review=needs_review)
            ok += 1

    save_json(plan_path, plan)
    emit('batch_done', pass_name='content', ok=ok, failed=failed)
    return 0 if failed == 0 else 1


def run_build(master_id, batch_id, only_domain, dry_run):
    mdir = master_dir(master_id)
    bdir = batch_dir(master_id, batch_id)
    plan_path = os.path.join(bdir, 'variant_plan.json')
    plan = load_json(plan_path)
    rows = load_params_rows(master_id, batch_id)
    all_domains = [r['domain'] for r in rows if r.get('domain')]
    output_dir = os.path.join(bdir, 'output')

    ok = failed = 0
    for row in rows:
        domain = row.get('domain')
        if not domain or (only_domain and domain != only_domain):
            continue
        plan_row = plan.get('rows', {}).get(domain, {})
        if not plan_row.get('content_approved'):
            continue

        emit('row_start', domain=domain, step='build')
        site_dir = os.path.join(bdir, 'variant_sites', render.slugify(domain))
        config = load_json(os.path.join(site_dir, 'config.json'))
        content = load_json(os.path.join(site_dir, 'content.json'))
        if not config or not content:
            emit('row_error', domain=domain, errors=['config.json/content.json missing — run the content pass first'])
            failed += 1
            continue

        type_json = load_json(os.path.join(REPO_ROOT, 'variants', 'types', f"{config['variant']['type']}.json"))
        color_json = load_json(os.path.join(REPO_ROOT, 'variants', 'colors', f"{config['variant']['color']}.json"))
        titles_json = load_json(os.path.join(REPO_ROOT, 'variants', 'titles', f"{config['variant']['titles']}.json"))

        # City-data-graphics — drawn directly from real per-city research stats, in this
        # site's own palette. Alt text is built from the same data dict used to draw them.
        img_out_dir = os.path.join(site_dir, 'images')
        os.makedirs(img_out_dir, exist_ok=True)
        for city in config['cities']:
            if city.get('stats'):
                chart_file = f"stats-{city['slug']}.webp"
                images.draw_city_stat_chart(city['stats'], color_json, os.path.join(img_out_dir, chart_file))
                for stat in city['stats']:
                    stat['chart_image'] = f"/images/{chart_file}"
                    stat['chart_alt'] = images.chart_alt_text(city['stats'], city['name'])

        pages = render.render_site(config, content, type_json, color_json, titles_json, output_dir)

        out_site_dir = os.path.join(output_dir, render.slugify(domain))
        if os.path.isdir(img_out_dir):
            out_images_dir = os.path.join(out_site_dir, 'images')
            os.makedirs(out_images_dir, exist_ok=True)
            for fname in os.listdir(img_out_dir):
                shutil.copyfile(os.path.join(img_out_dir, fname), os.path.join(out_images_dir, fname))

        other_domains = [d for d in all_domains if d != domain]
        report = seo_validate.validate_site(out_site_dir, domain, f"https://{domain}", pages, batch_domains=other_domains)
        save_json(os.path.join(site_dir, 'seo_report.json'), report)

        plan_row['built'] = True
        if report['errors']:
            emit('row_error', domain=domain, step='build', errors=report['errors'])
            failed += 1
        else:
            emit('row_done', domain=domain, step='build', warnings=len(report['warnings']))
            ok += 1

        save_json(os.path.join(site_dir, 'config.json'), config)  # persist chart_image/chart_alt

    save_json(plan_path, plan)
    emit('batch_done', pass_name='build', ok=ok, failed=failed)
    return 0 if failed == 0 else 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--master', required=True)
    ap.add_argument('--batch', required=True)
    ap.add_argument('--pass', dest='pass_name', required=True, choices=['content', 'build'])
    ap.add_argument('--only')
    ap.add_argument('--force', action='store_true')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    api_key = os.environ.get('ANTHROPIC_API_KEY', '')
    if not api_key and not args.dry_run:
        emit('fatal', reason='ANTHROPIC_API_KEY not set')
        return 1

    if args.pass_name == 'content':
        return run_content(args.master, args.batch, args.only, args.force, args.dry_run, api_key)
    return run_build(args.master, args.batch, args.only, args.dry_run)


if __name__ == '__main__':
    sys.exit(main())
