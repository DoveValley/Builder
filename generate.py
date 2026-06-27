#!/usr/bin/env python3
"""
generate.py — AI block content generator for the homepage-builder CMS.

Scans ai_block type blocks across site.json (homepage + core pages) and
data/pages/*.json (landing pages), calls Claude to fill them with
city-specific content, and writes results back.

Usage:
  python3 generate.py --site granitepmacademy --all
  python3 generate.py --site granitepmacademy --page homepage
  python3 generate.py --site granitepmacademy --page landing
  python3 generate.py --site granitepmacademy --file san-antonio-tx
  python3 generate.py --site granitepmacademy --all --refresh
  python3 generate.py --site granitepmacademy --all --dry-run

Modes:
  --page homepage   Homepage content_blocks only
  --page core       Core pages (site.json pages{} array)
  --page landing    Landing page files in data/pages/ (default)
  --all             All three scopes

Flags:
  --file <substr>   Limit landing pages to files whose name contains <substr>
  --refresh         Regenerate even blocks marked _ai_locked
  --dry-run         Preview without calling API or writing files
"""

import argparse
import glob
import json
import os
import re
import sys
import time
import uuid
from datetime import datetime, timezone

MODEL_DEFAULT = 'claude-haiku-4-5-20251001'
INDENT = 2

# Pricing: (input $/M tokens, output $/M tokens)
MODEL_PRICING = {
    'claude-haiku-4-5-20251001': (0.80,  4.00),
    'claude-sonnet-4-6':         (3.00, 15.00),
}

# Module-level usage accumulator keyed by model — avoids threading through every call signature
_usage     = {'by_model': {}, 'api_calls': 0}
_run_start = 0.0

def _tally_usage(model: str, input_tokens: int, output_tokens: int):
    bucket = _usage['by_model'].setdefault(model, {'input_tokens': 0, 'output_tokens': 0})
    bucket['input_tokens']  += input_tokens
    bucket['output_tokens'] += output_tokens
    _usage['api_calls'] += 1

def _total_tokens() -> tuple[int, int]:
    total_in  = sum(v['input_tokens']  for v in _usage['by_model'].values())
    total_out = sum(v['output_tokens'] for v in _usage['by_model'].values())
    return total_in, total_out

def _estimated_cost_usd() -> float:
    total = 0.0
    for model, counts in _usage['by_model'].items():
        in_rate, out_rate = MODEL_PRICING.get(model, (0.80, 4.00))
        total += (counts['input_tokens']  / 1_000_000) * in_rate
        total += (counts['output_tokens'] / 1_000_000) * out_rate
    return round(total, 6)

# ANSI codes suppressed on non-TTY
_TTY = sys.stdout.isatty()
def _c(code, text): return f'\033[{code}m{text}\033[0m' if _TTY else text
def _ok(msg):   print(_c('32', '✓') + ' ' + msg)
def _warn(msg): print(_c('33', '!') + ' ' + msg)
def _err(msg):  print(_c('31', '✗') + ' ' + msg)
def _log(msg):  print(msg)


# ── File I/O ──────────────────────────────────────────────────────────────────

def load_json(path):
    if not os.path.exists(path):
        return None
    with open(path, encoding='utf-8') as f:
        raw = f.read()
    return json.loads(raw) if raw.strip() else None

def save_json(path, data):
    os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
    content = json.dumps(data, indent=INDENT, ensure_ascii=False)
    tmp = path + '.tmp.' + str(os.getpid())
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(content)
    os.replace(tmp, path)


# ── Site layout ───────────────────────────────────────────────────────────────

def site_paths(base_dir, site_id):
    site_dir = os.path.join(base_dir, 'sites', site_id)
    return {
        'site_dir':       site_dir,
        'site_json':      os.path.join(site_dir, 'data', 'site.json'),
        'registry':       os.path.join(site_dir, 'data', 'ai_block_types.json'),
        'cities':         os.path.join(site_dir, 'data', 'cities.json'),
        'pages_dir':      os.path.join(site_dir, 'data', 'pages'),
        'generation_log': os.path.join(site_dir, 'data', 'generation_log.json'),
        'templates':      os.path.join(site_dir, 'data', 'templates.json'),
    }


# ── Registry & city data ──────────────────────────────────────────────────────

def load_registry(paths):
    reg = load_json(paths['registry'])
    if reg is None:
        _warn('No ai_block_types.json found — all ai_blocks will be skipped')
        return {}
    _log(f'Registry: {len(reg)} block types ({", ".join(reg.keys())})')
    return reg

def cities_index(paths):
    """Return dict keyed by id, city_slug, and lowercase city name for fast lookup."""
    raw = load_json(paths['cities'])
    if not raw:
        return {}
    idx = {}
    for c in raw:
        for key in [c.get('id'), c.get('city_slug'), (c.get('city') or '').lower()]:
            if key:
                idx[key] = c
    return idx

def resolve_city(idx, city_vars):
    """Return the cities.json entry matching city_vars, or {}."""
    for key in [city_vars.get('id'), city_vars.get('city_slug'), (city_vars.get('city') or '').lower()]:
        if key and key in idx:
            return idx[key]
    return {}


# ── Context assembly ──────────────────────────────────────────────────────────

def build_context(site_vars, city_data, page_data=None):
    """Merge all data sources into a flat substitution context dict."""
    industries = city_data.get('industries', [])
    employers  = city_data.get('top_employers', [])

    ctx = {
        'business':      site_vars.get('business', ''),
        'phone':         site_vars.get('phone', ''),
        'website':       site_vars.get('website', ''),
        'city':          city_data.get('city',     site_vars.get('city',     '')),
        'SS':            city_data.get('SS',        site_vars.get('SS',       '')),
        'state':         city_data.get('state',     site_vars.get('state',    '')),
        'city_slug':     city_data.get('city_slug', site_vars.get('city_slug', '')),
        'industries':    ', '.join(industries) if isinstance(industries, list) else str(industries),
        'top_employers': ', '.join(employers)  if isinstance(employers, list)  else str(employers),
        'salary_note':   city_data.get('salary_note', ''),
        'market_blurb':  city_data.get('market_blurb', ''),
        'service':       '',
        'keyword':       '',
    }

    if page_data:
        seo     = page_data.get('seo', {})
        title   = page_data.get('title', '')
        service = seo.get('service_name', '') or _strip_city(title, ctx['city'])
        keyword = seo.get('seo_title', '') or f"{service} {ctx['city']}".strip()
        ctx['service'] = service
        ctx['keyword'] = keyword

    return ctx

def _strip_city(title, city):
    """Remove city reference from page title to derive service name."""
    s = title.replace(city, '').replace('{city}', '').replace('{SS}', '')
    return re.sub(r'\s+', ' ', s).strip(' -,|')

def substitute_vars(text, ctx):
    """Replace {var} placeholders with context values; leave unknown ones intact."""
    return re.sub(r'\{([a-z_]+)\}', lambda m: str(ctx.get(m.group(1), m.group(0))), text)


# ── Claude API ────────────────────────────────────────────────────────────────

def call_claude(prompt, model, api_key, dry_run=False):
    """Call the Claude API; return parsed JSON dict or None on failure."""
    if dry_run:
        _log(f'      [dry-run] {model} · {len(prompt)} char prompt')
        return {'heading_text': '[DRY RUN HEADING]', 'text': '<p>[Dry-run placeholder — no API call made.]</p>'}

    try:
        import anthropic
    except ImportError:
        _err('anthropic package not installed. Run: pip3 install anthropic')
        sys.exit(1)

    try:
        client  = anthropic.Anthropic(api_key=api_key)
        message = client.messages.create(
            model=model,
            max_tokens=1024,
            messages=[{'role': 'user', 'content': prompt}],
        )
        raw = message.content[0].text.strip()
        _tally_usage(model, message.usage.input_tokens, message.usage.output_tokens)

        # Strip markdown code fences if the model wraps its output
        if raw.startswith('```'):
            parts = raw.split('```')
            raw = parts[1] if len(parts) > 1 else raw
            if raw.startswith('json'):
                raw = raw[4:]
            raw = raw.strip()

        return json.loads(raw)

    except json.JSONDecodeError as exc:
        _err(f'JSON parse failed: {exc}')
        return None
    except Exception as exc:
        _err(f'API error: {exc}')
        return None


# ── Field helpers ─────────────────────────────────────────────────────────────

def extract_ai_value(ai_output, target_field):
    """
    Pull the relevant value from AI output for injection.
    The AI output key may differ from the PHP block field name.
    """
    if target_field in ai_output:
        return ai_output[target_field]
    # Field-name aliases (AI key → PHP block field)
    aliases = {
        'fq_items':   ['items', 'faqs', 'questions'],
        'columns':    ['columns', 'items'],
        'hs_subtext': ['text', 'subtext', 'content'],
    }
    for alias in aliases.get(target_field, ['text', 'items', 'content']):
        if alias in ai_output:
            return ai_output[alias]
    # Fallback: first non-null value
    vals = [v for v in ai_output.values() if v is not None]
    return vals[0] if vals else ''

def apply_inject(target_block, field, mode, ai_output):
    """Write AI output into target_block[field] using replace/append/prepend mode."""
    ai_value = extract_ai_value(ai_output, field)
    existing = target_block.get(field)

    if mode == 'replace' or existing is None:
        target_block[field] = ai_value
    elif mode == 'append':
        if isinstance(existing, list) and isinstance(ai_value, list):
            target_block[field] = existing + ai_value
        elif isinstance(existing, str) and isinstance(ai_value, str):
            target_block[field] = (existing.rstrip() + '\n' + ai_value).strip()
        else:
            target_block[field] = ai_value
    elif mode == 'prepend':
        if isinstance(existing, list) and isinstance(ai_value, list):
            target_block[field] = ai_value + existing
        elif isinstance(existing, str) and isinstance(ai_value, str):
            target_block[field] = (ai_value.rstrip() + '\n' + existing.lstrip()).strip()
        else:
            target_block[field] = ai_value


# ── Block processor ───────────────────────────────────────────────────────────

def _needs_processing(block):
    """True if process_blocks should handle this block."""
    return block.get('type') == 'ai_block' or (
        bool(block.get('ai_type_id')) and block.get('type') != 'ai_block'
    )

def process_blocks(blocks, registry, ctx, api_key, refresh=False, dry_run=False):
    """
    Process blocks that need AI generation:

    ai_block (standalone): filled with AI output, type changed to real block, kept.
    Any block (enrich):    block has ai_type_id set; AI fills a specific field in-place,
                           block type and all other fields unchanged, block kept.

    Locked blocks (_ai_locked=True): skipped unless --refresh.

    Returns (new_blocks_list, stats_dict).
    """
    result = [dict(b) for b in blocks]
    stats  = {'processed': 0, 'skipped': 0, 'errors': 0}

    for idx, block in enumerate(result):
        if not _needs_processing(block):
            continue

        if block.get('_ai_locked') and not refresh:
            _log(f'    [{idx}] {block.get("ai_type_id","?")} — locked, skipping')
            stats['skipped'] += 1
            continue

        type_id   = block.get('ai_type_id', '')
        reg_entry = registry.get(type_id) if type_id else None
        if not reg_entry:
            _warn(f'    [{idx}] Unknown ai_type_id "{type_id}" — skipping')
            stats['errors'] += 1
            continue

        model    = block.get('ai_model')           or reg_entry.get('ai_model',  MODEL_DEFAULT)
        prompt_t = block.get('ai_prompt_override') or reg_entry.get('ai_prompt', '')

        if not prompt_t:
            _warn(f'    [{idx}] {type_id} — empty prompt, skipping')
            stats['errors'] += 1
            continue

        # Determine mode: enrich for non-ai_block; otherwise from block/registry
        if block.get('type') != 'ai_block':
            mode = 'enrich'
        else:
            mode = block.get('ai_mode') or reg_entry.get('ai_mode', 'standalone')

        prompt = substitute_vars(prompt_t, ctx)
        _log(f'    [{idx}] {type_id} · {mode} · {model.split("-")[1]}...')

        ai_out = call_claude(prompt, model, api_key, dry_run)
        if ai_out is None:
            stats['errors'] += 1
            continue

        now = datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z')

        if mode == 'standalone':
            result[idx].update(reg_entry.get('default_fields', {}))
            result[idx].update(ai_out)
            result[idx]['_ai_generated']    = True
            result[idx]['_ai_generated_at'] = now
            result[idx]['_ai_model']        = model
            result[idx]['_ai_type']         = type_id
            result[idx]['_ai_locked']       = True
            _ok(f'    [{idx}] {type_id} — generated')
            stats['processed'] += 1

        elif mode == 'enrich':
            field    = block.get('ai_inject_field') or reg_entry.get('ai_inject_field', '')
            inj_mode = block.get('ai_inject_mode')  or reg_entry.get('ai_inject_mode', 'replace')

            if not field:
                _warn(f'    [{idx}] {type_id} — no ai_inject_field defined, skipping')
                stats['errors'] += 1
                continue

            apply_inject(result[idx], field, inj_mode, ai_out)
            result[idx]['_ai_generated']    = True
            result[idx]['_ai_generated_at'] = now
            result[idx]['_ai_model']        = model
            result[idx]['_ai_type']         = type_id
            result[idx]['_ai_locked']       = True
            _ok(f'    [{idx}] {type_id} — enriched .{field} ({inj_mode})')
            stats['processed'] += 1

    return result, stats


# ── Page-level processors ─────────────────────────────────────────────────────

def _merge_stats(total, s):
    for k in total:
        total[k] += s.get(k, 0)

def process_homepage(paths, site_data, registry, c_idx, api_key, refresh, dry_run):
    _log('\n── Homepage ─────────────────────────────────────────')
    site_vars = site_data.get('site_vars', {})
    city_data = resolve_city(c_idx, site_vars)
    ctx       = build_context(site_vars, city_data, site_data)
    blocks = site_data.get('content_blocks', [])
    n_ai   = sum(1 for b in blocks if _needs_processing(b))

    if n_ai == 0:
        _log('  No processable blocks on homepage')
        return {'processed': 0, 'skipped': 0, 'errors': 0}

    _log(f'  {n_ai} block(s) to process | city: {ctx["city"]}, {ctx["SS"]}')
    new_blocks, stats = process_blocks(blocks, registry, ctx, api_key, refresh, dry_run)

    if not dry_run:
        site_data['content_blocks'] = new_blocks
        save_json(paths['site_json'], site_data)
        _ok('  Saved site.json')

    return stats

def process_core_pages(paths, site_data, registry, c_idx, api_key, refresh, dry_run):
    _log('\n── Core Pages ───────────────────────────────────────')
    site_vars = site_data.get('site_vars', {})
    pages     = site_data.get('pages', {})
    total     = {'processed': 0, 'skipped': 0, 'errors': 0}
    changed   = False

    for pid, page in pages.items():
        blocks = page.get('content_blocks', [])
        n_ai   = sum(1 for b in blocks if _needs_processing(b))
        if n_ai == 0:
            continue
        _log(f'  Page "{page.get("title", pid)}" — {n_ai} block(s) to process')
        city_data = resolve_city(c_idx, site_vars)
        ctx       = build_context(site_vars, city_data, page)
        new_blocks, stats = process_blocks(blocks, registry, ctx, api_key, refresh, dry_run)
        _merge_stats(total, stats)
        if not dry_run:
            site_data['pages'][pid]['content_blocks'] = new_blocks
            changed = True

    if changed and not dry_run:
        save_json(paths['site_json'], site_data)
        _ok('  Saved site.json (core pages)')

    if total['processed'] == 0 and not total['errors']:
        _log('  No ai_blocks in core pages')

    return total

def process_landing_pages(paths, site_data, registry, c_idx, api_key, refresh, dry_run, file_filter=None):
    _log('\n── Landing Pages ────────────────────────────────────')
    site_vars = site_data.get('site_vars', {})
    pages_dir = paths['pages_dir']

    if not os.path.isdir(pages_dir):
        _warn(f'  No pages directory: {pages_dir}')
        return {'processed': 0, 'skipped': 0, 'errors': 0}

    json_files = sorted(glob.glob(os.path.join(pages_dir, '*.json')))
    if file_filter:
        json_files = [f for f in json_files if file_filter in os.path.basename(f)]
    total = {'processed': 0, 'skipped': 0, 'errors': 0}

    for fpath in json_files:
        page_data = load_json(fpath)
        if not page_data:
            continue

        blocks = page_data.get('content_blocks', [])
        n_ai   = sum(1 for b in blocks if _needs_processing(b))
        if n_ai == 0:
            continue

        fname = os.path.basename(fpath)
        _log(f'\n  {fname}')
        _log(f'  {n_ai} block(s) to process')

        city_vars = page_data.get('city_vars', {})
        city_data = resolve_city(c_idx, city_vars)
        merged_city = {**city_vars, **city_data}

        if not city_data.get('industries') and not city_data.get('salary_note'):
            _warn(f'  No research data for {city_vars.get("city","?")} — run research step or enrich cities.json')

        ctx = build_context(site_vars, merged_city, page_data)
        _log(f'  City: {ctx["city"]}, {ctx["SS"]}')

        new_blocks, stats = process_blocks(blocks, registry, ctx, api_key, refresh, dry_run)
        _merge_stats(total, stats)

        if not dry_run:
            page_data['content_blocks'] = new_blocks
            save_json(fpath, page_data)
            _ok(f'  Saved {fname}')

    if total['processed'] == 0 and not total['errors'] and not file_filter:
        _log('  No processable blocks in landing pages')

    return total


# ── Research pre-processor ────────────────────────────────────────────────────

RESEARCH_MODEL = 'claude-sonnet-4-6'

RESEARCH_PROMPT = """Research the project management job market in {city}, {state} ({SS}).

You are populating a database for a PMP certification training company. Provide accurate, specific information about {city}'s economy and PM job market.

Return a JSON object with exactly four string/array fields:

1. "industries" — array of 4-6 dominant industry sectors in {city} that employ significant numbers of project managers. Use concise sector names such as healthcare, defense/military, technology, finance, energy, manufacturing, government.

2. "top_employers" — array of 10-12 major employers in {city} that actively hire project managers. Include large corporations, healthcare systems, government/defense contractors, and prominent local employers. Use exact company/organization names.

3. "salary_note" — one sentence describing the typical annual salary range for PMP-certified project managers in {city}, {SS}. Include a specific dollar range (e.g. $110,000-$130,000 annually) and note any premium sectors if applicable.

4. "market_blurb" — 2-3 sentences describing the PM job market in {city}. Reference the city by name, mention the dominant industries, and explain concretely why PMP certification is valuable for professionals there.

Rules:
- Only include verifiable facts — real employers with a significant physical presence in {city}
- Do not invent statistics or companies
- Salary range should reflect current market rates for certified PMs

Return JSON only, no markdown fences, no explanation."""

def _research_city(city_name, state_name, SS, api_key, dry_run=False):
    """Call Claude to produce research fields for one city. Returns dict or None."""
    ctx    = {'city': city_name, 'state': state_name, 'SS': SS}
    prompt = substitute_vars(RESEARCH_PROMPT, ctx)

    if dry_run:
        _log(f'    [dry-run] Would call {RESEARCH_MODEL} ({len(prompt)} chars)')
        return {
            'industries':    ['technology', 'healthcare', 'finance', 'energy'],
            'top_employers': ['Employer A', 'Employer B', 'Employer C', 'Employer D'],
            'salary_note':   f'PMP-certified project managers in {city_name} average $110,000–$130,000 annually. [dry-run]',
            'market_blurb':  f'{city_name} has a strong PM market. [dry-run placeholder]',
        }

    result = call_claude(prompt, RESEARCH_MODEL, api_key, dry_run=False)
    if not result:
        return None

    # Validate the four required fields
    errs = []
    if not isinstance(result.get('industries'), list) or len(result['industries']) < 2:
        errs.append('industries missing or too short')
    if not isinstance(result.get('top_employers'), list) or len(result['top_employers']) < 3:
        errs.append('top_employers missing or too short')
    if not str(result.get('salary_note', '')).strip():
        errs.append('salary_note empty')
    if not str(result.get('market_blurb', '')).strip():
        errs.append('market_blurb empty')
    if errs:
        _warn(f'    Research response invalid: {"; ".join(errs)}')
        return None

    return result

def _needs_research(city_data):
    return not city_data.get('industries') and not city_data.get('top_employers')

def run_research_step(paths, api_key, dry_run=False, city_filter=None):
    """
    For every city in cities.json that lacks research fields, call Claude to fill them.
    Writes results back to cities.json.  Returns number of cities researched.
    """
    _log('\n── Research Step ────────────────────────────────────')

    raw = load_json(paths['cities'])
    if not raw:
        _warn('  No cities.json found')
        return 0

    cities    = list(raw)
    researched = 0

    for i, city in enumerate(cities):
        city_name = city.get('city', '')
        city_id   = city.get('id', '')

        # City filter: match on id, city name, or city_slug
        if city_filter:
            haystack = f'{city_id} {city_name} {city.get("city_slug","")}'.lower()
            if city_filter.lower() not in haystack:
                continue

        if not _needs_research(city):
            _log(f'  {city_name} — research data present, skipping')
            continue

        _log(f'  {city_name}, {city.get("SS","?")} — researching...')
        result = _research_city(
            city_name  = city_name,
            state_name = city.get('state', ''),
            SS         = city.get('SS', ''),
            api_key    = api_key,
            dry_run    = dry_run,
        )

        if result:
            cities[i] = {**city, **result}
            _ok(f'  {city_name} — research complete')
            _log(f'    industries    : {", ".join(result["industries"][:4])}')
            _log(f'    top_employers : {", ".join(result["top_employers"][:4])}...')
            _log(f'    salary_note   : {result["salary_note"][:80]}')
            researched += 1
        else:
            _warn(f'  {city_name} — research failed, skipping')

    if researched > 0 and not dry_run:
        save_json(paths['cities'], cities)
        _ok(f'  Saved cities.json ({researched} {"city" if researched == 1 else "cities"} enriched)')
    elif researched == 0:
        _log('  All cities already have research data (or none matched filter)')

    return researched


# ── Template sync ─────────────────────────────────────────────────────────────

def _ai_block_ids(blocks: list) -> set:
    """Return the set of ai_type_ids already present in a block list."""
    return {b.get('ai_type_id') for b in blocks if b.get('type') == 'ai_block' and b.get('ai_type_id')}

def _template_ai_blocks(tpl: dict) -> list:
    """Return the ai_block entries from a template's content_blocks, in order."""
    return [b for b in tpl.get('content_blocks', []) if b.get('type') == 'ai_block']

def _insert_ai_block_at_natural_position(blocks: list, ai_block: dict) -> list:
    """Insert a standalone ai_block after feature_columns if present, else after hero_split."""
    result = list(blocks)
    fc_idx = next((i for i, b in enumerate(result) if b.get('type') == 'feature_columns'), None)
    hs_idx = next((i for i, b in enumerate(result) if b.get('type') == 'hero_split'),      None)
    insert_after = fc_idx if fc_idx is not None else hs_idx
    if insert_after is not None:
        result.insert(insert_after + 1, ai_block)
    else:
        result.append(ai_block)
    return result


def sync_templates(paths, dry_run=False) -> dict:
    """
    For each page file in pages/, compare ai_blocks against the source template.
    Insert any missing ai_blocks at their natural positions.
    Returns stats dict with pages_updated, blocks_added.
    """
    templates_data = load_json(paths['templates'])
    if not templates_data:
        _warn('No templates.json found — nothing to sync')
        return {'pages_updated': 0, 'blocks_added': 0}

    # Index templates by id
    tpl_by_id = {t['id']: t for t in templates_data if t.get('id')}

    pages_dir = paths['pages_dir']
    if not os.path.isdir(pages_dir):
        _warn(f'pages/ directory not found: {pages_dir}')
        return {'pages_updated': 0, 'blocks_added': 0}

    pages_updated = 0
    blocks_added  = 0

    _log(f'\n── Template Sync ────────────────────────────────────')

    for page_file in sorted(glob.glob(os.path.join(pages_dir, '*.json'))):
        page = load_json(page_file)
        if not isinstance(page, dict):
            continue

        tpl_id = page.get('template_id', '')
        tpl    = tpl_by_id.get(tpl_id)
        if not tpl:
            continue

        tpl_ai_blocks  = _template_ai_blocks(tpl)
        page_ai_ids    = _ai_block_ids(page.get('content_blocks', []))
        missing_blocks = [b for b in tpl_ai_blocks if b.get('ai_type_id') not in page_ai_ids]

        if not missing_blocks:
            continue

        fname = os.path.basename(page_file)
        _log(f'  {fname}')
        for ab in missing_blocks:
            tid = ab.get('ai_type_id', '?')
            _log(f'    + {tid}')
            if not dry_run:
                page['content_blocks'] = _insert_ai_block_at_natural_position(
                    page['content_blocks'], dict(ab)
                )
            blocks_added += 1

        if not dry_run:
            save_json(page_file, page)
        pages_updated += 1

    if pages_updated == 0:
        _ok('All page files already have up-to-date ai_blocks')
    else:
        _ok(f'Synced {pages_updated} page file(s), inserted {blocks_added} ai_block(s)')

    return {'pages_updated': pages_updated, 'blocks_added': blocks_added}


# ── Generation log ────────────────────────────────────────────────────────────

def write_generation_log(paths, args, total, researched, started_at_ts):
    log_path = paths.get('generation_log')
    if not log_path:
        return
    in_tok, out_tok = _total_tokens()
    entry = {
        'run_id':            str(uuid.uuid4()),
        'started_at':        datetime.fromtimestamp(started_at_ts, tz=timezone.utc).isoformat().replace('+00:00', 'Z'),
        'finished_at':       datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z'),
        'duration_ms':       int((time.monotonic() - _run_start) * 1000),
        'dry_run':           args.dry_run,
        'options': {
            'site':     args.site,
            'page':     args.page,
            'all':      args.all,
            'file':     args.file,
            'refresh':  args.refresh,
            'research': getattr(args, 'research', False),
        },
        'pages_written':      total.get('processed', 0),
        'pages_skipped':      total.get('skipped',   0),
        'pages_backed_up':    0,
        'errors':             total.get('errors',    0),
        'blocks_generated':   total.get('processed', 0),
        'cities_researched':  researched,
        'input_tokens':       in_tok,
        'output_tokens':      out_tok,
        'api_calls':          _usage['api_calls'],
        'estimated_cost_usd': _estimated_cost_usd(),
    }
    existing = load_json(log_path)
    if not isinstance(existing, list):
        existing = []
    existing.append(entry)
    save_json(log_path, existing)


# ── Summary ───────────────────────────────────────────────────────────────────

def print_summary(total, researched=0):
    in_tok, out_tok = _total_tokens()
    cost = _estimated_cost_usd()
    _log(f'\n{"═"*54}')
    if researched:
        _ok(f'Researched: {researched} city/cities enriched')
    _ok(f'Generated : {total["processed"]} block(s)')
    if total['skipped']:
        _log(f'  Skipped  : {total["skipped"]} (locked — use --refresh to override)')
    if total['errors']:
        _warn(f'  Errors   : {total["errors"]}')
    if _usage['api_calls']:
        _log(f'  Tokens   : {in_tok:,} in / {out_tok:,} out  ({_usage["api_calls"]} calls)')
        _log(f'  Est. cost: ${cost:.4f}')
    _log(f'{"═"*54}')


# ── CLI ───────────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(description='AI block content generator')
    ap.add_argument('--site',            required=True,       help='Site ID, e.g. granitepmacademy')
    ap.add_argument('--page',            default='landing',   help='homepage | core | landing (default: landing)')
    ap.add_argument('--all',             action='store_true', help='Process homepage + core + landing pages')
    ap.add_argument('--file',            default=None,        help='Limit to page files whose name contains this string')
    ap.add_argument('--refresh',         action='store_true', help='Regenerate even _ai_locked blocks')
    ap.add_argument('--research',        action='store_true', help='Research missing city data before generating content')
    ap.add_argument('--research-only',   action='store_true', dest='research_only',
                    help='Only run the research step — do not generate content blocks')
    ap.add_argument('--sync-templates',  action='store_true', dest='sync_templates',
                    help='Insert missing ai_blocks from templates.json into existing page files, then exit')
    ap.add_argument('--dry-run',         action='store_true', dest='dry_run',
                    help='Preview without calling API or writing files')
    args = ap.parse_args()

    # research-only implies --research
    if args.research_only:
        args.research = True

    global _run_start
    _run_start = time.monotonic()
    _started_at_ts = time.time()

    base_dir = os.path.dirname(os.path.abspath(__file__))
    paths    = site_paths(base_dir, args.site)

    if not os.path.isdir(paths['site_dir']):
        _err(f'Site directory not found: {paths["site_dir"]}')
        sys.exit(1)

    _log(f'\n{"═"*54}')
    _log(f'generate.py · site={args.site}')
    scope = '--all' if args.all else ('--research-only' if args.research_only else args.page)
    _log(f'Scope    : {scope}' + (f' --file {args.file}' if args.file else ''))
    _log(f'Research : {args.research}  |  Refresh : {args.refresh}  |  Dry run : {args.dry_run}')
    _log(f'{"═"*54}')

    # ── Template sync (no API key required) ───────────────────────────────────
    if args.sync_templates:
        sync_templates(paths, dry_run=args.dry_run)
        return

    api_key = os.environ.get('ANTHROPIC_API_KEY')
    if not api_key and not args.dry_run:
        _err('ANTHROPIC_API_KEY environment variable not set')
        sys.exit(1)

    registry  = load_registry(paths)
    site_data = load_json(paths['site_json'])

    if not site_data:
        _err(f'Cannot read {paths["site_json"]}')
        sys.exit(1)

    city_count = len({c.get('id') for c in (load_json(paths['cities']) or []) if c.get('id')})
    _log(f'Cities   : {city_count} in cities.json')

    total      = {'processed': 0, 'skipped': 0, 'errors': 0}
    researched = 0

    # ── Step 1: Research (fills cities.json research fields) ──────────────────
    if args.research:
        researched = run_research_step(paths, api_key, dry_run=args.dry_run, city_filter=args.file)
        if args.research_only:
            _log(f'\n{"═"*54}')
            _ok(f'Research complete: {researched} city/cities enriched')
            _log(f'{"═"*54}')
            return

    # Reload city index after research may have written new data
    c_idx = cities_index(paths)

    # ── Step 2: Content generation ────────────────────────────────────────────
    if args.all or args.page == 'homepage':
        _merge_stats(total, process_homepage(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run))

    if args.all or args.page == 'core':
        _merge_stats(total, process_core_pages(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run))

    if args.all or args.page == 'landing':
        _merge_stats(total, process_landing_pages(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run, file_filter=args.file))

    print_summary(total, researched)
    if not args.dry_run:
        write_generation_log(paths, args, total, researched, _started_at_ts)


if __name__ == '__main__':
    main()
