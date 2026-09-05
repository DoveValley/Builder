"""
Claude API primitives for the variant engine's content pipeline.

Deliberately a DUPLICATE of the retry/backoff/cost-tracking logic already proven in
generate.py, not an import of it — generate.py stays byte-for-byte untouched so the existing
multisite generator has zero blast radius from this build. Both read the SAME
includes/models.json pricing table, so pricing itself never drifts even though the code paths
are separate. If this duplication becomes annoying later, a shared includes/generation/
claude_client.py both files import is the obvious follow-up cleanup — not done here on purpose.
"""

import base64
import json
import os
import random
import sys
import threading
import time

INDENT = 2


def _load_model_catalog():
    path = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'includes', 'models.json')
    try:
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    except Exception:
        return {}


_MODEL_CATALOG = _load_model_catalog()
_MODELS = _MODEL_CATALOG.get('models', {}) if isinstance(_MODEL_CATALOG, dict) else {}
MODEL_DEFAULT = _MODEL_CATALOG.get('default') or 'claude-haiku-4-5-20251001'
MODEL_PRICING = {mid: (float(m.get('input', 0)), float(m.get('output', 0)))
                  for mid, m in _MODELS.items() if isinstance(m, dict)}
_COST_FALLBACK = max(MODEL_PRICING.values(), default=(5.00, 25.00))

RESEARCH_MODEL = 'claude-sonnet-5'          # supports the dynamic-filtering web_search tool
WRITE_MODEL = MODEL_DEFAULT                  # cheaper model for straightforward prose
VISION_MODEL = 'claude-sonnet-5'             # needs real image understanding

_usage = {'by_model': {}, 'api_calls': 0}
_usage_lock = threading.Lock()


def tally_usage(model, input_tokens, output_tokens):
    with _usage_lock:
        bucket = _usage['by_model'].setdefault(model, {'input_tokens': 0, 'output_tokens': 0})
        bucket['input_tokens'] += input_tokens
        bucket['output_tokens'] += output_tokens
        _usage['api_calls'] += 1


def estimated_cost_usd():
    total = 0.0
    for model, counts in _usage['by_model'].items():
        in_rate, out_rate = MODEL_PRICING.get(model, _COST_FALLBACK)
        total += (counts['input_tokens'] / 1_000_000) * in_rate
        total += (counts['output_tokens'] / 1_000_000) * out_rate
    return round(total, 6)


def usage_summary():
    total_in = sum(v['input_tokens'] for v in _usage['by_model'].values())
    total_out = sum(v['output_tokens'] for v in _usage['by_model'].values())
    return {'api_calls': _usage['api_calls'], 'input_tokens': total_in, 'output_tokens': total_out,
            'cost_usd': estimated_cost_usd()}


_TTY = sys.stdout.isatty()
def _c(code, text): return f'\033[{code}m{text}\033[0m' if _TTY else text
def log_ok(msg):   print(_c('32', '✓') + ' ' + msg)
def log_warn(msg): print(_c('33', '!') + ' ' + msg)
def log_err(msg):  print(_c('31', '✗') + ' ' + msg)


_RETRY_MAX = 6
_RETRY_BASE_S = 2.0
_client = None
_client_lock = threading.Lock()


def _get_client(api_key):
    global _client
    if _client is None:
        with _client_lock:
            if _client is None:
                import anthropic
                _client = anthropic.Anthropic(api_key=api_key)
    return _client


def _retryable_exceptions():
    import anthropic
    names = ('RateLimitError', 'InternalServerError', 'OverloadedError', 'APITimeoutError', 'APIConnectionError')
    return tuple(e for e in (getattr(anthropic, n, ()) for n in names) if isinstance(e, type))


def _call_with_retry(client, model, messages, api_key, max_tokens=8000, tools=None):
    """Shared retry loop. Returns the raw anthropic Message, or None on unrecoverable failure."""
    retryable = _retryable_exceptions()
    for attempt in range(1, _RETRY_MAX + 1):
        try:
            kwargs = {'model': model, 'max_tokens': max_tokens, 'messages': messages}
            if tools:
                kwargs['tools'] = tools
            message = client.messages.create(**kwargs)
            tally_usage(model, message.usage.input_tokens, message.usage.output_tokens)
            return message
        except retryable as exc:
            if attempt >= _RETRY_MAX:
                log_err(f'API error after {attempt} attempts: {exc}')
                return None
            delay = min(_RETRY_BASE_S * (2 ** (attempt - 1)) + random.uniform(0, 1), 60.0)
            log_warn(f'transient API error ({type(exc).__name__}); retry {attempt}/{_RETRY_MAX - 1} in {delay:.1f}s')
            time.sleep(delay)
        except Exception as exc:
            log_err(f'API error: {exc}')
            return None
    return None


def _text_of(message):
    return ''.join(b.text for b in message.content if getattr(b, 'type', None) == 'text').strip()


def parse_json(raw):
    if raw.startswith('```'):
        parts = raw.split('```')
        raw = parts[1] if len(parts) > 1 else raw
        if raw.startswith('json'):
            raw = raw[4:]
        raw = raw.strip()
    return json.loads(raw)


def call_claude_json(prompt, model, api_key, dry_run=False):
    """A plain single-turn call whose answer is a JSON object. Returns dict or None.
    Dry-run returns None so each call site's own `result or {fallback}` pattern kicks in —
    lets the rest of the pipeline (render/images/validate) be exercised end-to-end with no
    API cost, without dry-run silently looking like a successful real generation."""
    if dry_run:
        return None
    try:
        import anthropic  # noqa: F401
    except ImportError:
        log_err('anthropic package not installed. Run: pip3 install anthropic')
        sys.exit(1)
    client = _get_client(api_key)
    message = _call_with_retry(client, model, [{'role': 'user', 'content': prompt}], api_key)
    if message is None:
        return None
    try:
        return parse_json(_text_of(message))
    except json.JSONDecodeError as exc:
        log_err(f'JSON parse failed: {exc}')
        return None


def call_claude_web_search(prompt, model, api_key, max_uses=5, dry_run=False):
    """
    Web-grounded research call. Returns {'text': str, 'sources': [{'url','title'}]} — sources
    are ONLY the ones the model actually cited via a real search result, never invented.
    One pass, no second verify call — the caller (generate_content.py) decides what to keep by
    requiring a source_url on every fact, not by re-checking this call's own output.
    """
    if dry_run:
        return {'text': '[DRY RUN] no web search performed.', 'sources': []}
    try:
        import anthropic  # noqa: F401
    except ImportError:
        log_err('anthropic package not installed. Run: pip3 install anthropic')
        sys.exit(1)
    client = _get_client(api_key)
    tools = [{'type': 'web_search_20260209', 'name': 'web_search', 'max_uses': max_uses}]
    message = _call_with_retry(client, model, [{'role': 'user', 'content': prompt}], api_key, tools=tools)
    if message is None:
        return {'text': '', 'sources': []}

    sources = []
    for block in message.content:
        btype = getattr(block, 'type', None)
        if btype == 'web_search_tool_result':
            content = getattr(block, 'content', None)
            if isinstance(content, list):
                for item in content:
                    url = getattr(item, 'url', None)
                    if url:
                        sources.append({'url': url, 'title': getattr(item, 'title', '') or ''})
    return {'text': _text_of(message), 'sources': sources}


def call_claude_vision(image_path, prompt, model, api_key, dry_run=False):
    """Describe the actual pixels of one image. Returns a plain string (the alt text)."""
    if dry_run:
        return '[dry-run alt text placeholder]'
    try:
        import anthropic  # noqa: F401
    except ImportError:
        log_err('anthropic package not installed. Run: pip3 install anthropic')
        sys.exit(1)
    ext = os.path.splitext(image_path)[1].lower().lstrip('.')
    media_type = {'jpg': 'image/jpeg', 'jpeg': 'image/jpeg', 'png': 'image/png', 'webp': 'image/webp'}.get(ext, 'image/jpeg')
    with open(image_path, 'rb') as fh:
        b64 = base64.standard_b64encode(fh.read()).decode('ascii')
    client = _get_client(api_key)
    messages = [{
        'role': 'user',
        'content': [
            {'type': 'image', 'source': {'type': 'base64', 'media_type': media_type, 'data': b64}},
            {'type': 'text', 'text': prompt},
        ],
    }]
    message = _call_with_retry(client, VISION_MODEL, messages, api_key, max_tokens=300)
    if message is None:
        return ''
    return _text_of(message)
