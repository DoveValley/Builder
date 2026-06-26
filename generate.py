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
from datetime import datetime, timezone

MODEL_DEFAULT = 'claude-haiku-4-5-20251001'
INDENT = 2

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
        'site_dir':  site_dir,
        'site_json': os.path.join(site_dir, 'data', 'site.json'),
        'registry':  os.path.join(site_dir, 'data', 'ai_block_types.json'),
        'cities':    os.path.join(site_dir, 'data', 'cities.json'),
        'pages_dir': os.path.join(site_dir, 'data', 'pages'),
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


# ── Inject helpers ────────────────────────────────────────────────────────────

def find_inject_target(blocks, ai_idx, direction):
    """Return index of nearest non-ai_block in the given direction, or None."""
    if direction == 'previous':
        for j in range(ai_idx - 1, -1, -1):
            if blocks[j].get('type') != 'ai_block':
                return j
    elif direction == 'next':
        for j in range(ai_idx + 1, len(blocks)):
            if blocks[j].get('type') != 'ai_block':
                return j
    return None

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
    """Merge AI output into target_block[field] using the specified inject mode."""
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

def process_blocks(blocks, registry, ctx, api_key, refresh=False, dry_run=False):
    """
    Process all ai_block instances in a content_blocks list.

    Standalone blocks: filled with AI output, kept in the list.
    Inject blocks: AI output merged into adjacent block, then removed.
    Locked blocks (_ai_locked=True): skipped unless --refresh.

    Returns (new_blocks_list, stats_dict).
    """
    result       = [dict(b) for b in blocks]
    inject_remove = set()
    stats        = {'processed': 0, 'skipped': 0, 'errors': 0}

    for idx, block in enumerate(result):
        if block.get('type') != 'ai_block':
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

        mode      = block.get('ai_mode')      or reg_entry.get('ai_mode', 'standalone')
        model     = block.get('ai_model')     or reg_entry.get('ai_model', MODEL_DEFAULT)
        prompt_t  = block.get('ai_prompt_override') or reg_entry.get('ai_prompt', '')

        if not prompt_t:
            _warn(f'    [{idx}] {type_id} — empty prompt, skipping')
            stats['errors'] += 1
            continue

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

        elif mode == 'inject':
            direction = block.get('ai_inject_target') or reg_entry.get('ai_inject_target', 'previous')
            field     = block.get('ai_inject_field')  or reg_entry.get('ai_inject_field', '')
            inj_mode  = block.get('ai_inject_mode')   or reg_entry.get('ai_inject_mode', 'replace')

            target_idx = find_inject_target(result, idx, direction)
            if target_idx is None:
                _warn(f'    [{idx}] {type_id} — no {direction} target block found')
                stats['errors'] += 1
                continue

            apply_inject(result[target_idx], field, inj_mode, ai_out)
            inject_remove.add(idx)
            _ok(f'    [{idx}] {type_id} — injected into [{target_idx}].{field} ({inj_mode})')
            stats['processed'] += 1

    # Remove inject blocks highest-index first so earlier indices stay valid
    for idx in sorted(inject_remove, reverse=True):
        result.pop(idx)

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
    blocks    = site_data.get('content_blocks', [])
    n_ai      = sum(1 for b in blocks if b.get('type') == 'ai_block')

    if n_ai == 0:
        _log('  No ai_blocks on homepage')
        return {'processed': 0, 'skipped': 0, 'errors': 0}

    _log(f'  {n_ai} ai_block(s) | city: {ctx["city"]}, {ctx["SS"]}')
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
        n_ai   = sum(1 for b in blocks if b.get('type') == 'ai_block')
        if n_ai == 0:
            continue
        _log(f'  Page "{page.get("title", pid)}" — {n_ai} ai_block(s)')
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
        n_ai   = sum(1 for b in blocks if b.get('type') == 'ai_block')
        if n_ai == 0:
            continue

        fname = os.path.basename(fpath)
        _log(f'\n  {fname}')
        _log(f'  {n_ai} ai_block(s)')

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
        _log('  No ai_blocks in landing pages')

    return total


# ── Summary ───────────────────────────────────────────────────────────────────

def print_summary(total):
    _log(f'\n{"═"*54}')
    _ok(f'Generated : {total["processed"]} block(s)')
    if total['skipped']:
        _log(f'  Skipped  : {total["skipped"]} (locked — use --refresh to override)')
    if total['errors']:
        _warn(f'  Errors   : {total["errors"]}')
    _log(f'{"═"*54}')


# ── CLI ───────────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(description='AI block content generator')
    ap.add_argument('--site',    required=True,       help='Site ID, e.g. granitepmacademy')
    ap.add_argument('--page',    default='landing',   help='homepage | core | landing (default: landing)')
    ap.add_argument('--all',     action='store_true', help='Process homepage + core + landing pages')
    ap.add_argument('--file',    default=None,        help='Limit to page files whose name contains this string')
    ap.add_argument('--refresh', action='store_true', help='Regenerate even _ai_locked blocks')
    ap.add_argument('--dry-run', action='store_true', dest='dry_run',
                    help='Show what would be done without calling API or writing files')
    args = ap.parse_args()

    api_key = os.environ.get('ANTHROPIC_API_KEY')
    if not api_key and not args.dry_run:
        _err('ANTHROPIC_API_KEY environment variable not set')
        sys.exit(1)

    base_dir = os.path.dirname(os.path.abspath(__file__))
    paths    = site_paths(base_dir, args.site)

    if not os.path.isdir(paths['site_dir']):
        _err(f'Site directory not found: {paths["site_dir"]}')
        sys.exit(1)

    _log(f'\n{"═"*54}')
    _log(f'generate.py · site={args.site}')
    _log(f'Scope   : {"--all" if args.all else args.page}' + (f' --file {args.file}' if args.file else ''))
    _log(f'Refresh : {args.refresh}  |  Dry run : {args.dry_run}')
    _log(f'{"═"*54}')

    registry  = load_registry(paths)
    c_idx     = cities_index(paths)
    site_data = load_json(paths['site_json'])

    if not site_data:
        _err(f'Cannot read {paths["site_json"]}')
        sys.exit(1)

    city_count = len({c.get('id') for c in (load_json(paths['cities']) or []) if c.get('id')})
    _log(f'Cities  : {city_count} in cities.json')

    total = {'processed': 0, 'skipped': 0, 'errors': 0}

    if args.all or args.page == 'homepage':
        _merge_stats(total, process_homepage(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run))

    if args.all or args.page == 'core':
        _merge_stats(total, process_core_pages(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run))

    if args.all or args.page == 'landing':
        _merge_stats(total, process_landing_pages(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run, file_filter=args.file))

    print_summary(total)


if __name__ == '__main__':
    main()
