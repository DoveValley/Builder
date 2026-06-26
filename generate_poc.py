#!/usr/bin/env python3
"""
Phase 1 Proof of Concept — AI City Market Intro Block
Generates a city_market_intro text block for the San Antonio PMP Certification landing page.
Inserts it at position 2 (after feature_columns, before schedule widget).
"""

import json
import os
import sys
from datetime import datetime

# ── Config ────────────────────────────────────────────────────────────────────

SITE_ID       = 'granitepmacademy'
BASE_DIR      = os.path.dirname(os.path.abspath(__file__))
SITES_DIR     = os.path.join(BASE_DIR, 'sites', SITE_ID)
CITIES_FILE   = os.path.join(SITES_DIR, 'data', 'cities.json')
PAGES_DIR     = os.path.join(SITES_DIR, 'data', 'pages')
TARGET_FILE   = os.path.join(PAGES_DIR, 'tpl_pmp_certification_training_city_san-antonio-tx.json')
TARGET_CITY   = 'san-antonio-tx'
SERVICE       = 'PMP Certification Training'
KEYWORD       = 'PMP certification training San Antonio'
INSERT_AT     = 2   # insert after feature_columns (index 1), before schedule (index 2)
MODEL         = 'claude-haiku-4-5-20251001'

# ── Prompt ────────────────────────────────────────────────────────────────────

PROMPT_TEMPLATE = """You are writing SEO content for Granite PM Academy, a PMI Premier Authorized Training Partner offering live-virtual PMP certification training.

Target page: {service} in {city}, {SS}
Target keyword: "{keyword}"

City context (use this to write specific, accurate content — do not invent facts):
- Key industries: {industries}
- Major employers actively hiring PMP-certified PMs: {top_employers}
- Salary context: {salary_note}
- Market background: {market_blurb}

Write a city market intro section with:
1. A heading (H2 level) — 6–10 words, includes "{city}" and references the PM market
2. Body text — exactly 2 paragraphs, 160–190 words total
   - Paragraph 1: Why PMP certification is valuable for {city} professionals specifically. Reference 2–3 real local employers or industries by name.
   - Paragraph 2: Career impact — how PMP certification affects salary and advancement in {city}. Reference the salary note. End with a forward-looking sentence.

Rules:
- Use "{city}" naturally 2–3 times across both paragraphs
- Use "PMP certification" or "PMP-certified" at least 3 times
- Do NOT invent statistics, companies, or facts not provided above
- Do NOT use bullet points — flowing paragraphs only
- Professional tone, not sales-y

Return JSON only, no explanation, no markdown:
{{"heading_text": "...", "text": "<p>...</p><p>...</p>"}}"""

# ── Load city data ─────────────────────────────────────────────────────────────

def load_city(city_id):
    with open(CITIES_FILE) as f:
        cities = json.load(f)
    for c in cities:
        if c['id'] == city_id:
            return c
    raise ValueError(f'City not found: {city_id}')

# ── Build prompt ───────────────────────────────────────────────────────────────

def build_prompt(city):
    industries = ', '.join(city.get('industries', []))
    employers  = ', '.join(city.get('top_employers', []))
    return PROMPT_TEMPLATE.format(
        service      = SERVICE,
        city         = city['city'],
        SS           = city['SS'],
        keyword      = KEYWORD,
        industries   = industries,
        top_employers= employers,
        salary_note  = city.get('salary_note', ''),
        market_blurb = city.get('market_blurb', ''),
    )

# ── Call Claude ────────────────────────────────────────────────────────────────

def call_claude(prompt):
    try:
        import anthropic
    except ImportError:
        print('ERROR: anthropic package not installed. Run: pip3 install anthropic')
        sys.exit(1)

    api_key = os.environ.get('ANTHROPIC_API_KEY')
    if not api_key:
        print('ERROR: ANTHROPIC_API_KEY environment variable not set.')
        sys.exit(1)

    client = anthropic.Anthropic(api_key=api_key)

    print(f'Calling Claude ({MODEL})...')
    message = client.messages.create(
        model      = MODEL,
        max_tokens = 1024,
        messages   = [{'role': 'user', 'content': prompt}]
    )

    raw = message.content[0].text.strip()
    print(f'Response received ({len(raw)} chars)')

    # Strip markdown code fences if present
    if raw.startswith('```'):
        raw = raw.split('```')[1]
        if raw.startswith('json'):
            raw = raw[4:]
        raw = raw.strip()

    return json.loads(raw)

# ── Build the block ────────────────────────────────────────────────────────────

def build_block(ai_output):
    return {
        'type'          : 'text',
        'heading_text'  : ai_output['heading_text'],
        'heading_level' : 'h2',
        'text'          : ai_output['text'],
        'skin'          : 'light',
        '_ai_generated' : True,
        '_ai_type'      : 'city_market_intro',
        '_ai_generated_at': datetime.utcnow().isoformat() + 'Z',
        '_ai_model'     : MODEL,
        '_ai_locked'    : True,
    }

# ── Insert into page file ──────────────────────────────────────────────────────

def insert_block(block):
    with open(TARGET_FILE) as f:
        page = json.load(f)

    blocks = page.get('content_blocks', [])

    # Check if city_market_intro already exists — replace it rather than duplicating
    for i, b in enumerate(blocks):
        if b.get('_ai_type') == 'city_market_intro':
            print(f'Replacing existing city_market_intro at index {i}')
            blocks[i] = block
            page['content_blocks'] = blocks
            with open(TARGET_FILE, 'w') as f:
                json.dump(page, f, indent=2, ensure_ascii=False)
            return i

    # Insert at target position
    blocks.insert(INSERT_AT, block)
    page['content_blocks'] = blocks

    with open(TARGET_FILE, 'w') as f:
        json.dump(page, f, indent=2, ensure_ascii=False)

    print(f'Inserted city_market_intro at index {INSERT_AT}')
    return INSERT_AT

# ── Main ───────────────────────────────────────────────────────────────────────

def main():
    print('=' * 60)
    print('Phase 1 POC — City Market Intro Block')
    print(f'City: San Antonio, TX')
    print(f'Page: {TARGET_FILE}')
    print('=' * 60)

    # 1. Load city data
    city = load_city(TARGET_CITY)
    print(f'\nCity data loaded:')
    print(f'  industries    : {", ".join(city.get("industries", []))}')
    print(f'  top_employers : {", ".join(city.get("top_employers", [])[:3])}...')
    print(f'  salary_note   : {city.get("salary_note", "")[:60]}...')

    # 2. Build prompt
    prompt = build_prompt(city)
    print(f'\nPrompt built ({len(prompt)} chars)')

    # 3. Call Claude
    ai_output = call_claude(prompt)

    # 4. Show output
    print('\n--- AI OUTPUT ---')
    print(f'Heading : {ai_output["heading_text"]}')
    print(f'Text    : {ai_output["text"][:200]}...')
    print('-' * 40)

    # 5. Build and insert block
    block = build_block(ai_output)
    idx   = insert_block(block)

    print(f'\nDone. Block inserted at position {idx}.')
    print(f'Preview: http://localhost:8080/gpma_page_preview.php?slug=pmp-certification-training-san-antonio')
    print('=' * 60)

if __name__ == '__main__':
    main()
