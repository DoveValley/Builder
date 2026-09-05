"""
PASS 1 — research + write. Turns a config.json's facts (identity, niche, services, empty
cities[]/faqs[]/pages.guides[]) into a fully-populated content.json, using real web-grounded
research for city-specific claims and the niche's guardrails to keep legal pages meaning-locked.

Never touches images/ or output/ — that's PASS 2 (render.py + images.py), gated on a human
approving this pass's output first. See run_batch.py for how the two passes are invoked.
"""

import hashlib
import json

import claude_client as cc
import legal_meaning_lock as lock
from claude_client import call_claude_json, call_claude_web_search, log_warn, log_ok

CONTENT_DEPTH_INSTRUCTION = (
    "Do not write generic filler that could apply to any city. Explain HOW this specific "
    "city's own real characteristics (climate, geography, housing stock, infrastructure — "
    "whatever is actually relevant to this topic) affect this exact topic differently than "
    "they would in a different kind of city. For example, a coastal city's exposure to storm "
    "surge is a different problem than an inland city's exposure to snowmelt or river "
    "flooding — reason about which real local factors actually apply here, don't just append "
    "a fact after generic copy."
)


def _hash(*parts):
    h = hashlib.sha256()
    for p in parts:
        h.update(json.dumps(p, sort_keys=True, default=str).encode('utf-8'))
    return h.hexdigest()[:16]


def _cache_get(cache, key, input_hash):
    entry = (cache.get('fields') or {}).get(key)
    if entry and entry.get('input_hash') == input_hash:
        return entry.get('value')
    return None


def _cache_set(cache, key, input_hash, value):
    cache.setdefault('fields', {})[key] = {'input_hash': input_hash, 'value': value}


def _niche_header(config, voice_directive):
    niche = config['niche']
    return (
        f"Business: {config['identity']['business']}. {niche['business_descriptor']}. "
        f"Tone: {niche['tone']}. Voice: {voice_directive}. "
        "Write in original wording — do not copy competitor or template phrasing.\n"
        f"Compliance guardrails (must always hold true): {niche['guardrails']}"
    )


def research_city(city, config, research_prompt_template, api_key, dry_run=False):
    """
    Mutates `city` in place with stats/local_facts/research_meta. Keeps a claim only if its
    self-reported source_url is one the web_search tool actually returned in this same call —
    a plain membership check, not a second verify call, per the "keep it simple" rule.
    """
    prompt = research_prompt_template.format(
        city=city['name'], state=city.get('state', city['SS']), SS=city['SS'],
        business_descriptor=config['niche']['business_descriptor'],
        service_noun=config['niche']['service_noun'],
    ) + (
        "\n\n" + CONTENT_DEPTH_INSTRUCTION +
        "\n\nAfter researching, respond with ONLY this JSON shape (no other text): "
        "{\"stats\": [{\"label\":\"...\",\"value\":0,\"unit\":\"...\",\"source_url\":\"...\"}], "
        "\"local_facts\": [{\"topic\":\"...\",\"text\":\"...\",\"source_url\":\"...\"}], "
        "\"neighborhoods\": [\"...\"], \"population\": 0, \"industries\": [\"...\"], "
        "\"top_employers\": [\"...\"]}"
    )
    result = call_claude_web_search(prompt, cc.RESEARCH_MODEL, api_key, dry_run=dry_run)
    real_urls = {s['url'] for s in result.get('sources', [])}

    try:
        parsed = cc.parse_json(result['text']) if result.get('text') else {}
    except Exception:
        log_warn(f"research for {city['name']} did not return parseable JSON — leaving city facts empty")
        parsed = {}

    kept_stats = [s for s in parsed.get('stats', []) if isinstance(s, dict) and s.get('source_url') in real_urls]
    kept_facts = [f for f in parsed.get('local_facts', []) if isinstance(f, dict) and f.get('source_url') in real_urls]
    dropped = (len(parsed.get('stats', [])) - len(kept_stats)) + (len(parsed.get('local_facts', [])) - len(kept_facts))
    if dropped and not dry_run:
        log_warn(f"{city['name']}: dropped {dropped} uncited claim(s) — kept only sourced facts")

    city['stats'] = kept_stats
    city['local_facts'] = kept_facts
    city['neighborhoods'] = parsed.get('neighborhoods', [])
    city['population'] = parsed.get('population')
    city['industries'] = parsed.get('industries', [])
    city['top_employers'] = parsed.get('top_employers', [])
    city['research_meta'] = {
        'sources': [{'url': u, 'kept': True} for u in real_urls],
        'model': cc.RESEARCH_MODEL,
    }


def write_hero(config, voice_directive, api_key, dry_run=False):
    prompt = (
        _niche_header(config, voice_directive) +
        "\n\nWrite a homepage hero headline (under 12 words) and a one-sentence subhead for "
        f"{config['identity']['business']}, serving {len(config['cities'])} nearby cities. "
        "Return JSON only: {\"headline\": \"...\", \"subhead\": \"...\"}"
    )
    result = call_claude_json(prompt, cc.WRITE_MODEL, api_key, dry_run=dry_run)
    return result or {'headline': '[dry-run] Fast, Local Help — Every Time', 'subhead': '[dry-run] Placeholder subhead text.'}


def write_faq(config, question_hint, voice_directive, api_key, dry_run=False):
    prompt = (
        _niche_header(config, voice_directive) +
        f"\n\nWrite one FAQ entry addressing: {question_hint}. "
        "Return JSON only: {\"question\": \"...\", \"answer\": \"...\"}"
    )
    result = call_claude_json(prompt, cc.WRITE_MODEL, api_key, dry_run=dry_run)
    return result or {'question': question_hint, 'answer': '[dry-run] Placeholder answer.'}


def write_service(config, service, voice_directive, api_key, dry_run=False):
    prompt = (
        _niche_header(config, voice_directive) +
        f"\n\nWrite a short (1 sentence) and a long (2-3 paragraph, HTML) description of the "
        f"service '{service['name']}'. Return JSON only: "
        "{\"short_desc\": \"...\", \"body_html\": \"<p>...</p>\"}"
    )
    result = call_claude_json(prompt, cc.WRITE_MODEL, api_key, dry_run=dry_run)
    return result or {'short_desc': service.get('short_desc') or '[dry-run] Placeholder short description.', 'body_html': '<p>[dry-run] Placeholder service body.</p>'}


def write_guide(config, city, guide, voice_directive, api_key, dry_run=False):
    local_facts_text = "\n".join(f"- {f['text']}" for f in city.get('local_facts', []))
    prompt = (
        _niche_header(config, voice_directive) +
        f"\n\nWrite a local guide page titled around the topic '{guide['topic']}' for "
        f"{city['name']}, {city['SS']}. " + CONTENT_DEPTH_INSTRUCTION +
        f"\n\nReal, sourced local facts you may use (do not invent additional statistics beyond "
        f"these):\n{local_facts_text or '(none available — write generally about the topic without inventing local statistics)'}"
        "\n\nReturn JSON only: {\"title\": \"...\", \"body_html\": \"<p>...</p>\"}"
    )
    result = call_claude_json(prompt, cc.WRITE_MODEL, api_key, dry_run=dry_run)
    return result or {'title': guide['topic'], 'body_html': '<p>[dry-run] Placeholder guide body.</p>'}


def run_content_pass(config, content_cache, niche_extra, guardrail_clauses, api_key, dry_run=False):
    """
    Returns (content_dict, updated_cache). Idempotent/cheap on re-run: every field is keyed by
    an input hash, so re-running after fixing one thing only re-bills what actually changed —
    same guarantee includes/multisite/ai_cache.php already relies on for the existing system.
    """
    voice_directive = niche_extra['voice_directive']
    research_template = niche_extra['research_prompt_template']
    content = {'hero': {}, 'faqs': {}, 'services': {}, 'guides': {}, 'legal': {}, 'images': {}}

    # Research — one call per city, skipped if already researched with the same template.
    for city in config['cities']:
        h = _hash('research', city.get('id'), research_template)
        cached = _cache_get(content_cache, f"research.{city.get('id')}", h)
        if cached is not None:
            city.update(cached)
            continue
        research_city(city, config, research_template, api_key, dry_run=dry_run)
        _cache_set(content_cache, f"research.{city.get('id')}", h,
                   {k: city[k] for k in ('stats', 'local_facts', 'neighborhoods', 'population',
                                          'industries', 'top_employers', 'research_meta')})

    # Hero
    h = _hash('hero', config['identity']['business'], voice_directive)
    cached = _cache_get(content_cache, 'hero', h)
    content['hero'] = cached if cached is not None else write_hero(config, voice_directive, api_key, dry_run)
    if cached is None:
        _cache_set(content_cache, 'hero', h, content['hero'])

    # Services (flat mode only in Phase 1 — matrix mode content is deferred, see plan §2)
    if config['services']['mode'] == 'flat':
        for svc in config['services']['items']:
            h = _hash('service', svc['id'], voice_directive)
            cached = _cache_get(content_cache, f"services.{svc['id']}", h)
            value = cached if cached is not None else write_service(config, svc, voice_directive, api_key, dry_run)
            content['services'][svc['id']] = value
            if cached is None:
                _cache_set(content_cache, f"services.{svc['id']}", h, value)

    # FAQs
    for faq in config['faqs']:
        h = _hash('faq', faq['id'], faq.get('question_hint', faq['id']), voice_directive)
        cached = _cache_get(content_cache, f"faqs.{faq['id']}", h)
        value = cached if cached is not None else write_faq(config, faq.get('question_hint', faq['id']), voice_directive, api_key, dry_run)
        content['faqs'][faq['id']] = value
        if cached is None:
            _cache_set(content_cache, f"faqs.{faq['id']}", h, value)

    # Guides (city-scoped — this is where the content-depth bar matters most)
    cities_by_id = {c['id']: c for c in config['cities']}
    for guide in config['pages']['guides']:
        city = cities_by_id.get(guide.get('city_id'))
        if not city:
            continue
        h = _hash('guide', guide['slug'], city.get('research_meta'), voice_directive)
        cached = _cache_get(content_cache, f"guides.{guide['slug']}", h)
        value = cached if cached is not None else write_guide(config, city, guide, voice_directive, api_key, dry_run)
        content['guides'][guide['slug']] = value
        if cached is None:
            _cache_set(content_cache, f"guides.{guide['slug']}", h, value)

    # Legal — meaning-locked
    business_descriptor = config['niche']['business_descriptor']
    business_name = config['identity']['business']
    for key in ('privacy', 'terms', 'disclaimer', 'about'):
        h = _hash('legal', key, guardrail_clauses, voice_directive, business_name)
        cached = _cache_get(content_cache, f"legal.{key}", h)
        if cached is not None:
            content['legal'][key] = cached
            continue
        value = lock.write_legal_page(key, business_descriptor, business_name, voice_directive,
                                        guardrail_clauses, cc.WRITE_MODEL, api_key, dry_run=dry_run)
        content['legal'][key] = value
        _cache_set(content_cache, f"legal.{key}", h, value)
        if value['meaning_lock']['status'] == 'needs_review':
            log_warn(f"{key} page needs_review after 1 retry — flagged for human review, not auto-shipped")

    log_ok(f"content pass done for {config['domain']} — {cc.usage_summary()}")
    return content, content_cache
