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
import hashlib
import json
import os
import random
import re
import sys
import threading
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

INDENT = 2

# Model catalog — the single source of truth shared with PHP (includes/models.json).
# MODEL_DEFAULT and MODEL_PRICING are derived from it so pricing never drifts / goes stale.
def _load_model_catalog():
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'includes', 'models.json')
    try:
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    except Exception:
        return {}

_MODEL_CATALOG = _load_model_catalog()
_MODELS        = _MODEL_CATALOG.get('models', {}) if isinstance(_MODEL_CATALOG, dict) else {}
MODEL_DEFAULT  = _MODEL_CATALOG.get('default') or 'claude-haiku-4-5-20251001'
# Pricing: {model: (input $/M tokens, output $/M tokens)} — from the catalog.
MODEL_PRICING  = {mid: (float(m.get('input', 0)), float(m.get('output', 0)))
                  for mid, m in _MODELS.items() if isinstance(m, dict)}
# Cost fallback for a model missing from the catalog: the most expensive tier (never under-report).
_COST_FALLBACK = max(MODEL_PRICING.values(), default=(5.00, 25.00))

# Module-level usage accumulator keyed by model — avoids threading through every call signature.
# Guarded by _usage_lock because block generation runs concurrently (ThreadPoolExecutor).
_usage      = {'by_model': {}, 'api_calls': 0}
_usage_lock = threading.Lock()
_run_start  = 0.0

def _tally_usage(model: str, input_tokens: int, output_tokens: int):
    with _usage_lock:
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
        in_rate, out_rate = MODEL_PRICING.get(model, _COST_FALLBACK)
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

# Progress tracking — emits __PROGRESS__ D/T lines parsed by ai_generate.php.
# _progress_lock keeps the increment + print atomic so lines stay monotonic and
# un-interleaved when blocks are generated concurrently.
_progress_total = 0
_progress_done  = 0
_progress_lock  = threading.Lock()

def _set_total(n):
    global _progress_total, _progress_done
    with _progress_lock:
        _progress_total = n
        _progress_done  = 0
        print(f'__PROGRESS__ 0/{n}', flush=True)

# Per-worker progress: each landing worker sets a thread-local block callback so the
# admin UI can draw one live bar per worker slot. _tick() (fired once per block in the
# worker's thread) advances both the global bar and that worker's own bar.
_tls = threading.local()

def _current_slot() -> int:
    """Worker slot 0..N-1 parsed from the ThreadPoolExecutor thread name
    ('genw_0', 'genw_1', …). Returns 0 on the main/sequential thread."""
    name = threading.current_thread().name
    tail = name.rsplit('_', 1)[-1] if '_' in name else ''
    return int(tail) if tail.isdigit() else 0

def _emit_worker(slot: int, page: str, done: int, total: int):
    with _progress_lock:
        print(f'__WORKER__ {slot} {done} {total} {page}', flush=True)

def _tick():
    global _progress_done
    with _progress_lock:
        _progress_done += 1
        print(f'__PROGRESS__ {_progress_done}/{_progress_total}', flush=True)
    cb = getattr(_tls, 'block_cb', None)   # per-worker bar (set by landing workers)
    if cb:
        cb()

def _count_blocks(blocks, refresh):
    """Count blocks that will actually be called against the API."""
    return sum(
        1 for b in blocks
        if _needs_processing(b) and not (b.get('_ai_locked') and not refresh)
    )


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

def site_paths(base_dir, site_id, site_dir=None):
    # Multisite: an explicit absolute --site-dir (an ephemeral working dir outside
    # sites/) overrides the default sites/{id} layout. Everything else is identical.
    if site_dir is None:
        site_dir = os.path.join(base_dir, 'sites', site_id)
    site_dir = os.path.abspath(site_dir)
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

DEFAULT_HOOD_THRESHOLD = 14000

def _hood_threshold(paths):
    """Population threshold above which a city's researched neighborhoods auto-publish.
    Stored per-site in data/neighborhoods.json (edited on the Landing Cities tab)."""
    cfg = load_json(os.path.join(paths['site_dir'], 'data', 'neighborhoods.json')) or {}
    try:
        t = int(cfg.get('threshold', DEFAULT_HOOD_THRESHOLD))
        return t if t > 0 else DEFAULT_HOOD_THRESHOLD
    except (ValueError, TypeError):
        return DEFAULT_HOOD_THRESHOLD

def _effective_neighborhoods(city_data, threshold):
    """Gate real neighborhood names into a comma-joined string, or '' to stay generic.
    Names publish only when the city is auto-eligible: a per-city `neighborhoods_auto`
    override, OR population >= threshold. Otherwise the names are HELD (page stays
    generic — never a fake name). Empty research also yields '' (fail-safe)."""
    hoods = city_data.get('neighborhoods', [])
    if isinstance(hoods, str):
        hoods = re.split(r'[\n,]', hoods)
    hoods = [str(h).strip() for h in hoods if str(h).strip()]
    if not hoods:
        return ''
    if city_data.get('neighborhoods_auto'):
        return ', '.join(hoods)
    try:
        pop = int(str(city_data.get('population', '') or '0').replace(',', '').strip() or 0)
    except (ValueError, TypeError):
        pop = 0
    return ', '.join(hoods) if pop >= threshold else ''

def build_context(site_vars, city_data, page_data=None, hood_threshold=DEFAULT_HOOD_THRESHOLD):
    """Merge all data sources into a flat substitution context dict."""
    industries = city_data.get('industries', [])
    employers  = city_data.get('top_employers', [])
    projects   = city_data.get('notable_projects', [])

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
        'notable_projects': ', '.join(projects) if isinstance(projects, list) else str(projects),
        'credential_demand': city_data.get('credential_demand', ''),
        'population':    str(city_data.get('population', '') or ''),
        'neighborhoods': _effective_neighborhoods(city_data, hood_threshold),
        'service':       '',
        'keyword':       '',
        'secondary_keywords': '',
        'brand':          '',
        'appliance_type': '',
    }

    if page_data:
        seo     = page_data.get('seo', {})
        title   = page_data.get('title', '')
        service = seo.get('service_name', '') or _strip_city(title, ctx['city'])
        keyword = seo.get('seo_title', '') or f"{service} {ctx['city']}".strip()
        ctx['service'] = service
        ctx['keyword'] = keyword
        # Variant phrasings for this page, from the keyword map (seo.secondary_keywords).
        # Prompts should weave these in naturally where they fit — never keyword-stuff.
        ctx['secondary_keywords'] = seo.get('secondary_keywords', '')
        # Niche facets (generic passthrough): appliance pages set seo.brand /
        # seo.appliance_type so prompts can write brand- and appliance-specific
        # copy. Empty for niches (e.g. pest) that don't populate them.
        ctx['brand']          = seo.get('brand', '')
        ctx['appliance_type'] = seo.get('appliance_type', '')

    return ctx

def _strip_city(title, city):
    """Remove city reference from page title to derive service name."""
    s = title.replace(city, '').replace('{city}', '').replace('{SS}', '')
    return re.sub(r'\s+', ' ', s).strip(' -,|')

def substitute_vars(text, ctx):
    """Replace {var} placeholders with context values; leave unknown ones intact.
    Case-sensitive and allows uppercase so {SS} resolves (ctx key is uppercase);
    unknown tokens (incl. any stray ALLCAPS placeholder) are left untouched."""
    return re.sub(r'\{([a-zA-Z_]+)\}', lambda m: str(ctx.get(m.group(1), m.group(0))), text)


# ── Claude API ────────────────────────────────────────────────────────────────

# Shared Anthropic client — created once and reused across worker threads (the SDK
# client is safe to share and pools HTTP connections, so we don't rebuild it per call).
_client      = None
_client_lock = threading.Lock()

# Transient errors worth retrying under concurrency (rate limits, overload, 5xx, network).
_RETRY_MAX     = 6      # attempts per call
_RETRY_BASE_S  = 2.0    # base backoff, grows exponentially with jitter

def _get_client(api_key):
    global _client
    if _client is None:
        with _client_lock:
            if _client is None:
                import anthropic
                _client = anthropic.Anthropic(api_key=api_key)
    return _client


def call_claude(prompt, model, api_key, dry_run=False):
    """Call the Claude API; return parsed JSON dict or None on failure.

    Retries transient failures (rate limit / overloaded / 5xx / connection) with
    exponential backoff + jitter so concurrent bursts self-heal instead of dropping
    blocks. Bad JSON and other client errors are not retried (they won't fix themselves)."""
    if dry_run:
        _log(f'      [dry-run] {model} · {len(prompt)} char prompt')
        return {'heading_text': '[DRY RUN HEADING]', 'text': '<p>[Dry-run placeholder — no API call made.]</p>'}

    try:
        import anthropic
    except ImportError:
        _err('anthropic package not installed. Run: pip3 install anthropic')
        sys.exit(1)

    retryable = (
        getattr(anthropic, 'RateLimitError', ()),
        getattr(anthropic, 'InternalServerError', ()),
        getattr(anthropic, 'OverloadedError', ()),
        getattr(anthropic, 'APITimeoutError', ()),
        getattr(anthropic, 'APIConnectionError', ()),
    )
    retryable = tuple(e for e in retryable if isinstance(e, type))

    client = _get_client(api_key)
    for attempt in range(1, _RETRY_MAX + 1):
        try:
            message = client.messages.create(
                model=model,
                # Room for the answer PLUS adaptive thinking: models like sonnet-5 think by
                # default, and a 1024 cap let the thinking starve the JSON (truncated output).
                # This is a ceiling, not a target — short blocks still stop at end_turn, so no
                # extra cost — but it must clear thinking + a full research/FAQ payload.
                max_tokens=8000,
                messages=[{'role': 'user', 'content': prompt}],
            )
            # Use the text block(s) only — some models return a thinking/reasoning
            # block first, which has no .text attribute. Assuming content[0] is text
            # crashes on those; select by block type instead.
            texts = [b.text for b in message.content if getattr(b, 'type', None) == 'text']
            raw = (texts[-1] if texts else '').strip()
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
        except retryable as exc:
            if attempt >= _RETRY_MAX:
                _err(f'API error after {attempt} attempts: {exc}')
                return None
            delay = _RETRY_BASE_S * (2 ** (attempt - 1)) + random.uniform(0, 1)
            delay = min(delay, 60.0)
            _warn(f'    transient API error ({type(exc).__name__}); retry {attempt}/{_RETRY_MAX - 1} in {delay:.1f}s')
            time.sleep(delay)
        except Exception as exc:
            _err(f'API error: {exc}')
            return None
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

def _ai_input_hash(prompt, model):
    """Staleness key for the multisite per-domain cache: a hash of the RESOLVED prompt
    (already carrying business/city/service/keyword/gated-neighborhoods/etc.) plus the
    model. Because it's the actual string sent to the model, any input change invalidates
    and nothing that isn't in the prompt does — no shadow model of which fields matter."""
    return hashlib.sha256((str(prompt) + "\0" + str(model)).encode('utf-8')).hexdigest()[:16]

def process_blocks(blocks, registry, ctx, api_key, refresh=False, dry_run=False, model_override=None):
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
            result[idx].pop('_ai_cache_hash', None)   # no stray cache marker on skipped blocks
            _log(f'    [{idx}] {block.get("ai_type_id","?")} — locked, skipping')
            stats['skipped'] += 1
            continue

        type_id   = block.get('ai_type_id', '')
        reg_entry = registry.get(type_id) if type_id else None
        if not reg_entry:
            _warn(f'    [{idx}] Unknown ai_type_id "{type_id}" — skipping')
            stats['errors'] += 1
            _tick()
            continue

        model    = model_override or block.get('ai_model') or reg_entry.get('ai_model', MODEL_DEFAULT)
        prompt_t = block.get('ai_prompt_override') or reg_entry.get('ai_prompt', '')

        if not prompt_t:
            _warn(f'    [{idx}] {type_id} — empty prompt, skipping')
            stats['errors'] += 1
            _tick()
            continue

        # Determine mode: enrich for non-ai_block; otherwise from block/registry
        if block.get('type') != 'ai_block':
            mode = 'enrich'
        else:
            mode = block.get('ai_mode') or reg_entry.get('ai_mode', 'standalone')

        prompt = substitute_vars(prompt_t, ctx)
        in_hash = _ai_input_hash(prompt, model)

        # Multisite read-through cache: ms_ai_inject_from_cache injected a candidate value
        # plus the hash it was generated under (_ai_cache_hash). If the resolved prompt still
        # hashes the same, reuse it with zero API calls. --refresh (--force) always regenerates.
        cache_hash = result[idx].pop('_ai_cache_hash', None)
        if cache_hash is not None and cache_hash == in_hash and not refresh:
            now = datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z')
            result[idx]['_ai_input_hash']   = in_hash
            result[idx]['_ai_generated']    = True
            result[idx]['_ai_generated_at'] = now
            result[idx]['_ai_model']        = model
            result[idx]['_ai_type']         = type_id
            result[idx]['_ai_locked']       = True
            _log(f'    [{idx}] {type_id} — cache hit (reused, no API call)')
            stats['skipped'] += 1
            _tick()
            continue

        _log(f'    [{idx}] {type_id} · {mode} · {model.split("-")[1]}...')

        ai_out = call_claude(prompt, model, api_key, dry_run)
        if ai_out is None:
            stats['errors'] += 1
            _tick()
            continue

        now = datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z')

        if mode == 'standalone':
            result[idx].update(reg_entry.get('default_fields', {}))
            result[idx].update(ai_out)
            result[idx]['_ai_generated']    = True
            result[idx]['_ai_generated_at'] = now
            result[idx]['_ai_model']        = model
            result[idx]['_ai_type']         = type_id
            result[idx]['_ai_input_hash']   = in_hash
            result[idx]['_ai_locked']       = True
            _ok(f'    [{idx}] {type_id} — generated')
            stats['processed'] += 1
            _tick()

        elif mode == 'enrich':
            field    = block.get('ai_inject_field') or reg_entry.get('ai_inject_field', '')
            inj_mode = block.get('ai_inject_mode')  or reg_entry.get('ai_inject_mode', 'replace')

            if not field:
                _warn(f'    [{idx}] {type_id} — no ai_inject_field defined, skipping')
                stats['errors'] += 1
                _tick()
                continue

            apply_inject(result[idx], field, inj_mode, ai_out)
            result[idx]['_ai_generated']    = True
            result[idx]['_ai_generated_at'] = now
            result[idx]['_ai_model']        = model
            result[idx]['_ai_type']         = type_id
            result[idx]['_ai_input_hash']   = in_hash
            result[idx]['_ai_locked']       = True
            _ok(f'    [{idx}] {type_id} — enriched .{field} ({inj_mode})')
            stats['processed'] += 1
            _tick()

    return result, stats


# ── Page-level processors ─────────────────────────────────────────────────────

def _merge_stats(total, s):
    for k in total:
        total[k] += s.get(k, 0)

def generate_site_spotlight(paths, site_data, registry, c_idx, api_key, refresh, dry_run, model_override=None):
    """Generate the City Spotlight prose ONCE PER CITY and store it in cities.json[city].city_spotlight.

    Every page's Map+Info block renders it via the {city_spotlight} shortcode token:
      - single-city sites (pest/appliance): the primary city's profile is mirrored to
        site_vars.city_spotlight and reused on every page;
      - multi-city sites (granite): each city's profile is written into that city's generated
        pages' city_vars, which override site_vars at render — so each city shows its own text.
    Never per page: the spotlight is written once per CITY and shared across that city's pages.
    Idempotent: skips cities that already have a spotlight, unless --refresh. Returns count generated.
    """
    reg_entry = registry.get('city_spotlight')
    if not reg_entry:
        return 0  # capability not installed for this site
    prompt_t = reg_entry.get('ai_prompt', '')
    if not prompt_t:
        return 0

    model     = model_override or reg_entry.get('ai_model', MODEL_DEFAULT)
    site_vars = site_data.setdefault('site_vars', {})

    cities_raw = load_json(paths['cities'])
    if isinstance(cities_raw, list):
        rows = cities_raw
    elif isinstance(cities_raw, dict):
        rows = list(cities_raw.values())
    else:
        rows = []
    if not rows:
        rows = [site_vars]  # no cities.json — treat site_vars as the single "city"

    _log('\n── City Spotlight (once per city) ───────────────────')

    # Seed the primary city's row from an existing site_vars value (no API call) so a
    # single-city site generated under the older site_vars-only path doesn't regenerate.
    prim = resolve_city(c_idx, site_vars)
    if site_vars.get('city_spotlight') and prim.get('id'):
        for row in rows:
            if isinstance(row, dict) and row.get('id') == prim['id'] and not row.get('city_spotlight'):
                row['city_spotlight'] = site_vars['city_spotlight']

    generated = 0
    for row in rows:
        if not isinstance(row, dict):
            continue
        if row.get('city_spotlight') and not refresh:
            _log(f'  {row.get("city","?")}, {row.get("SS","?")} — present, skipping')
            continue
        ctx    = build_context(site_vars, row, site_data, _hood_threshold(paths))
        prompt = substitute_vars(prompt_t, ctx)
        if dry_run:
            _log(f'  [dry-run] {row.get("city","?")}, {row.get("SS","?")}')
            continue
        ai_out = call_claude(prompt, model, api_key, dry_run=False)
        if ai_out is None:
            _warn(f'  {row.get("city","?")} — generation failed')
            continue
        text = (ai_out.get('mi_info_text') or ai_out.get('text') or '').strip()
        if not text:
            _warn(f'  {row.get("city","?")} — empty text')
            continue
        row['city_spotlight'] = text
        generated += 1
        _ok(f'  {row.get("city","?")}, {row.get("SS","?")} ({len(text)} chars)')

    if dry_run:
        return 0

    # Persist cities.json (unless rows were synthesised from site_vars)
    if cities_raw:
        save_json(paths['cities'], cities_raw)

    # Mirror the primary city → site_vars (single-city render + multi-city fallback)
    prim = resolve_city(cities_index(paths), site_vars)
    if prim.get('city_spotlight'):
        site_vars['city_spotlight'] = prim['city_spotlight']
    save_json(paths['site_json'], site_data)

    # Propagate each city's text into its already-generated city pages' city_vars
    by_key = {}
    for row in rows:
        if isinstance(row, dict) and row.get('city_spotlight'):
            for k in (row.get('id'), row.get('city_slug')):
                if k:
                    by_key[k] = row['city_spotlight']
    pages_dir = paths.get('pages_dir')
    synced = 0
    if by_key and pages_dir and os.path.isdir(pages_dir):
        for pf in sorted(glob.glob(os.path.join(pages_dir, '*.json'))):
            pd = load_json(pf)
            if not isinstance(pd, dict):
                continue
            cv  = pd.get('city_vars') or {}
            key = pd.get('city_id') or cv.get('id') or cv.get('city_slug')
            sp  = by_key.get(key)
            if sp and cv.get('city_spotlight') != sp:
                pd.setdefault('city_vars', {})['city_spotlight'] = sp
                save_json(pf, pd)
                synced += 1
    if synced:
        _ok(f'  Propagated city_spotlight into {synced} city page(s) city_vars')

    return generated


def process_homepage(paths, site_data, registry, c_idx, api_key, refresh, dry_run, model_override=None):
    _log('\n── Homepage ─────────────────────────────────────────')
    site_vars = site_data.get('site_vars', {})
    city_data = resolve_city(c_idx, site_vars)
    ctx       = build_context(site_vars, city_data, site_data, _hood_threshold(paths))
    blocks = site_data.get('content_blocks', [])
    n_ai   = sum(1 for b in blocks if _needs_processing(b))

    if n_ai == 0:
        _log('  No processable blocks on homepage')
        return {'processed': 0, 'skipped': 0, 'errors': 0}

    _log(f'  {n_ai} block(s) to process | city: {ctx["city"]}, {ctx["SS"]}')
    new_blocks, stats = process_blocks(blocks, registry, ctx, api_key, refresh, dry_run, model_override)

    if not dry_run:
        site_data['content_blocks'] = new_blocks
        save_json(paths['site_json'], site_data)
        _ok('  Saved site.json')

    return stats

def process_core_pages(paths, site_data, registry, c_idx, api_key, refresh, dry_run, model_override=None):
    _log('\n── Core Pages ───────────────────────────────────────')
    site_vars = site_data.get('site_vars', {})
    pages     = site_data.get('pages', {})
    total     = {'processed': 0, 'skipped': 0, 'errors': 0}
    changed   = False
    threshold = _hood_threshold(paths)

    for pid, page in pages.items():
        blocks = page.get('content_blocks', [])
        n_ai   = sum(1 for b in blocks if _needs_processing(b))
        if n_ai == 0:
            continue
        _log(f'  Page "{page.get("title", pid)}" — {n_ai} block(s) to process')
        city_data = resolve_city(c_idx, site_vars)
        ctx       = build_context(site_vars, city_data, page, threshold)
        new_blocks, stats = process_blocks(blocks, registry, ctx, api_key, refresh, dry_run, model_override)
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

def process_landing_pages(paths, site_data, registry, c_idx, api_key, refresh, dry_run, file_filter=None, model_override=None, workers=1):
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
    threshold = _hood_threshold(paths)

    def _process_one(fpath):
        """Generate one page's blocks and save it. Self-contained so it can run in a
        worker thread — pages are independent (each owns its own file). Returns
        (fname, stats) or None when the page has nothing to do."""
        page_data = load_json(fpath)
        if not page_data:
            return None

        blocks = page_data.get('content_blocks', [])
        n_ai   = sum(1 for b in blocks if _needs_processing(b))
        if n_ai == 0:
            return None

        fname = os.path.basename(fpath)
        city_vars = page_data.get('city_vars', {})
        city_data = resolve_city(c_idx, city_vars)
        merged_city = {**city_vars, **city_data}

        if not city_data.get('industries') and not city_data.get('salary_note'):
            _warn(f'  No research data for {city_vars.get("city","?")} — run research step or enrich cities.json')

        ctx = build_context(site_vars, merged_city, page_data, threshold)
        _log(f'  {fname} · {n_ai} block(s) · {ctx["city"]}, {ctx["SS"]}')

        # Per-worker bar: total = blocks that will actually run (matches _tick, excludes
        # locked-skipped). Hook a thread-local callback so each _tick advances this slot.
        slot  = _current_slot()
        n_gen = _count_blocks(blocks, refresh)
        if n_gen > 0:
            state = {'done': 0}
            def _cb():
                state['done'] += 1
                _emit_worker(slot, fname, state['done'], n_gen)
            _tls.block_cb = _cb
            _emit_worker(slot, fname, 0, n_gen)
        else:
            _tls.block_cb = None

        try:
            new_blocks, stats = process_blocks(blocks, registry, ctx, api_key, refresh, dry_run, model_override)
        finally:
            _tls.block_cb = None

        if not dry_run:
            page_data['content_blocks'] = new_blocks
            save_json(fpath, page_data)
            _ok(f'  Saved {fname}')
        return (fname, stats)

    if workers and workers > 1 and len(json_files) > 1:
        eff = min(workers, len(json_files))
        _log(f'  Concurrency: {eff} workers over {len(json_files)} page(s)')
        print(f'__WORKERS__ {eff}', flush=True)   # tells the UI how many worker bars to draw
        with ThreadPoolExecutor(max_workers=workers, thread_name_prefix='genw') as pool:
            futures = {pool.submit(_process_one, f): f for f in json_files}
            for fut in as_completed(futures):
                try:
                    res = fut.result()
                except Exception as exc:
                    _err(f'  Worker failed on {os.path.basename(futures[fut])}: {exc}')
                    total['errors'] += 1
                    continue
                if res:
                    _merge_stats(total, res[1])
    else:
        for fpath in json_files:
            try:
                res = _process_one(fpath)
            except Exception as exc:
                _err(f'  Failed on {os.path.basename(fpath)}: {exc}')
                total['errors'] += 1
                continue
            if res:
                _merge_stats(total, res[1])

    if total['processed'] == 0 and not total['errors'] and not file_filter:
        _log('  No processable blocks in landing pages')

    return total


# ── Research pre-processor ────────────────────────────────────────────────────

RESEARCH_MODEL = 'claude-sonnet-5'

# Niche-agnostic default. Each master's Niche Brief can override this with its own
# `research_prompt` (edited in the Niche Brief tab) so research fits the niche.
# {business_descriptor}/{service_noun} come from the brief; {city}/{state}/{SS} per city.
DEFAULT_RESEARCH_PROMPT = """Research {city}, {state} ({SS}) for {business_descriptor}.

Return a JSON object with these fields:

1. "industries" — array of 4-6 dominant local industry sectors in {city}.
2. "top_employers" — array of 8-12 major employers with a real physical presence in {city} (exact organization names).
3. "market_blurb" — 2-3 sentences on {city}'s local economy, referencing the city by name and its main industries.
4. "local_note" — one sentence of locally-specific context relevant to {service_noun} in {city}.
5. "neighborhoods" — array of 6-10 real, well-known neighborhoods, subdivisions, or districts in {city}. Only names you are confident actually exist — never invent. If you are not confident, return fewer names or an empty array. Better to return none than a fake one.
6. "population" — {city}'s approximate population as a plain integer (most recent well-known estimate, digits only, no commas or text).

Rules:
- Only verifiable facts — real employers and real neighborhoods, no invented statistics, companies, or place names.
- Return JSON only, no markdown fences, no explanation."""


def _load_research_prompt(paths):
    """Resolve the research prompt for this master: the Niche Brief's `research_prompt`
    (with brief context substituted) if set, else DEFAULT_RESEARCH_PROMPT. Leaves the
    per-city {city}/{state}/{SS} placeholders intact."""
    brief = load_json(os.path.join(paths['site_dir'], 'multisite', 'niche_brief.json')) or {}
    tmpl = (brief.get('research_prompt') or '').strip() or DEFAULT_RESEARCH_PROMPT
    return substitute_vars(tmpl, {
        'business_descriptor': brief.get('business_descriptor', 'a local business'),
        'service_noun':        brief.get('service_noun', 'services'),
        'customer_noun':       brief.get('customer_noun', 'customer'),
        'niche':               brief.get('niche', ''),
    })

def _research_city(city_name, state_name, SS, api_key, prompt_template, dry_run=False):
    """Call Claude to produce research fields for one city, using the niche's prompt
    template. Returns a dict of fields or None. Fields are niche-defined, so validation
    is soft (must be a non-empty dict)."""
    prompt = substitute_vars(prompt_template, {'city': city_name, 'state': state_name, 'SS': SS})

    if dry_run:
        _log(f'    [dry-run] Would call {RESEARCH_MODEL} ({len(prompt)} chars)')
        return {'industries': ['(dry-run)'], 'top_employers': ['(dry-run)'],
                'market_blurb': f'{city_name} — [dry-run]', 'local_note': '[dry-run]'}

    result = call_claude(prompt, RESEARCH_MODEL, api_key, dry_run=False)
    if not result or not isinstance(result, dict):
        return None
    if not any(v not in (None, '', [], {}) for v in result.values()):
        _warn('    Research response had no usable fields')
        return None
    return result

def _needs_research(city_data):
    # Niche-agnostic: a completed research pass stamps `_researched`. Fall back to the
    # legacy PM signal for rows researched before the flag existed.
    if city_data.get('_researched'):
        return False
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
    prompt_template = _load_research_prompt(paths)   # niche-aware (Niche Brief or default)

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
            city_name       = city_name,
            state_name      = city.get('state', ''),
            SS              = city.get('SS', ''),
            api_key         = api_key,
            prompt_template = prompt_template,
            dry_run         = dry_run,
        )

        if result:
            cities[i] = {**city, **result, '_researched': 1}
            _ok(f'  {city_name} — research complete')
            for k, v in list(result.items())[:4]:
                s = ', '.join(str(x) for x in v[:4]) if isinstance(v, list) else str(v)
                _log(f'    {k}: {s[:80]}')
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
    ap.add_argument('--site',            required=False, default=None, help='Site ID, e.g. granitepmacademy')
    ap.add_argument('--site-dir',        default=None, dest='site_dir',
                    help='Absolute path to a site dir (multisite worker). Overrides sites/{--site}.')
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
    ap.add_argument('--model',           default=None,
                    help='Override the model for every block (e.g. claude-sonnet-5)')
    ap.add_argument('--workers',         type=int, default=8,
                    help='Concurrent landing-page workers (default 8; 1 = sequential). '
                         'Landing pages are independent, so this is the main speed lever. '
                         'Transient rate-limit errors are retried automatically.')
    args = ap.parse_args()

    if args.workers < 1:
        args.workers = 1

    # research-only implies --research
    if args.research_only:
        args.research = True

    global _run_start
    _run_start = time.monotonic()
    _started_at_ts = time.time()

    if not args.site and not args.site_dir:
        ap.error('one of --site or --site-dir is required')
    if args.site_dir and not args.site:
        args.site = os.path.basename(os.path.normpath(args.site_dir))

    base_dir = os.path.dirname(os.path.abspath(__file__))
    paths    = site_paths(base_dir, args.site, args.site_dir)

    if not os.path.isdir(paths['site_dir']):
        _err(f'Site directory not found: {paths["site_dir"]}')
        sys.exit(1)

    _log(f'\n{"═"*54}')
    _log(f'generate.py · site={args.site}')
    scope = '--all' if args.all else ('--research-only' if args.research_only else args.page)
    _log(f'Scope    : {scope}' + (f' --file {args.file}' if args.file else ''))
    _log(f'Research : {args.research}  |  Refresh : {args.refresh}  |  Dry run : {args.dry_run}  |  Workers : {args.workers}')
    if args.model:
        _log(f'Model    : {args.model} (override)')
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

    # ── Step 1b: City Spotlight (once per site; every page reuses {city_spotlight}) ──
    generate_site_spotlight(paths, site_data, registry, c_idx, api_key,
                            args.refresh, args.dry_run, args.model or None)

    # ── Step 2: Content generation ────────────────────────────────────────────
    model_override = args.model or None

    # Pre-count total blocks across all selected scopes so UI can show progress
    pre_total = 0
    if args.all or args.page == 'homepage':
        pre_total += _count_blocks(site_data.get('content_blocks', []), args.refresh)
    if args.all or args.page == 'core':
        for pid, pg in site_data.get('pages', {}).items():
            pre_total += _count_blocks(pg.get('content_blocks', []), args.refresh)
    if args.all or args.page == 'landing':
        _lp_dir = paths['pages_dir']
        if os.path.isdir(_lp_dir):
            for _lp_f in sorted(glob.glob(os.path.join(_lp_dir, '*.json'))):
                if args.file and args.file not in os.path.basename(_lp_f):
                    continue
                _lp_data = load_json(_lp_f)
                if _lp_data:
                    pre_total += _count_blocks(_lp_data.get('content_blocks', []), args.refresh)
    _set_total(pre_total)

    if args.all or args.page == 'homepage':
        _merge_stats(total, process_homepage(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run, model_override))

    if args.all or args.page == 'core':
        _merge_stats(total, process_core_pages(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run, model_override))

    if args.all or args.page == 'landing':
        _merge_stats(total, process_landing_pages(paths, site_data, registry, c_idx, api_key, args.refresh, args.dry_run, file_filter=args.file, model_override=model_override, workers=args.workers))

    print_summary(total, researched)
    if not args.dry_run:
        write_generation_log(paths, args, total, researched, _started_at_ts)


if __name__ == '__main__':
    main()
