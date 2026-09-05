"""
Legal-page meaning-locking: the ONE deliberate exception to "no second verify call" in this
pipeline, because it's the one place a wrong answer is a compliance problem, not a taste
problem. Wording and structure may vary freely between sites; the underlying legal meaning
(referral-only, independent contractors, no outcome guarantee, etc. — whatever this niche's
guardrails actually say) must survive every rewrite intact.
"""

from datetime import datetime, timezone

from claude_client import call_claude_json, log_warn

LEGAL_LABELS = {
    'privacy': 'Privacy Policy',
    'terms': 'Terms of Service',
    'disclaimer': 'Disclaimer',
    'about': 'About Us',
}


def derive_guardrail_clauses(guardrails_text, model, api_key, dry_run=False):
    """
    Once per niche: turn the prose guardrails paragraph in niche_brief.json into a short,
    atomized checklist a rewrite can be checked against. This output should be eyeballed by a
    human once (the niche fact-review pass) before being trusted as the meaning-lock source of
    truth — this function only produces the first draft.
    """
    if dry_run:
        return ["[dry-run] no guardrail clauses derived"]
    prompt = (
        "Break the following compliance guardrail paragraph for a home-service referral "
        "business into a short checklist of distinct, atomic rules — one short sentence per "
        "rule, each one independently checkable against a piece of text. Do not add rules that "
        "aren't in the source text. Return JSON only: {\"clauses\": [\"...\", \"...\"]}\n\n"
        f"Guardrail paragraph:\n{guardrails_text}"
    )
    result = call_claude_json(prompt, model, api_key, dry_run=dry_run)
    if not result or not isinstance(result.get('clauses'), list):
        log_warn('guardrail clause derivation failed — falling back to the raw paragraph as one clause')
        return [guardrails_text]
    return [c for c in result['clauses'] if isinstance(c, str) and c.strip()]


def write_legal_page(legal_key, business_descriptor, business_name, voice_directive,
                      guardrail_clauses, model, api_key, dry_run=False):
    """
    Rewrite one legal page freely in structure/wording, then verify every guardrail clause
    survived. One retry with the failing clause(s) highlighted; a second failure ships with
    meaning_lock.status = 'needs_review' rather than silently going out wrong.
    """
    label = LEGAL_LABELS.get(legal_key, legal_key)
    base_prompt = (
        f"Write the {label} page for {business_name}, {business_descriptor}. "
        f"Tone: {voice_directive}. Write original wording and an original structure — do not "
        "copy or lightly paraphrase a competitor's or template's legal page; this must read as "
        "its own document, distinguishable from any other business's page on the same topic.\n\n"
        "Every one of the following points MUST remain true and clearly present in the page, "
        "even though you should phrase and organize them however reads best:\n"
        + "\n".join(f"- {c}" for c in guardrail_clauses)
        + "\n\nReturn JSON only: {\"body_html\": \"<p>...</p><p>...</p>...\"}"
    )

    result = call_claude_json(base_prompt, model, api_key, dry_run=dry_run)
    body_html = (result or {}).get('body_html') or f'<p>[dry-run] Placeholder {label} body.</p>'

    lock = _verify_meaning_lock(body_html, guardrail_clauses, model, api_key, dry_run)
    if lock['status'] == 'needs_review' and not dry_run:
        retry_prompt = base_prompt + (
            "\n\nA previous draft did not clearly preserve these points — make sure they are "
            "unambiguous this time:\n" + "\n".join(f"- {c}" for c in lock['missing'])
        )
        result = call_claude_json(retry_prompt, model, api_key, dry_run=dry_run)
        body_html = (result or {}).get('body_html', body_html) if result else body_html
        lock = _verify_meaning_lock(body_html, guardrail_clauses, model, api_key, dry_run)

    return {'body_html': body_html, 'meaning_lock': lock}


def _verify_meaning_lock(body_html, guardrail_clauses, model, api_key, dry_run=False):
    if dry_run:
        return {'status': 'pass', 'missing': [], 'checked_at': datetime.now(timezone.utc).isoformat()}
    prompt = (
        "Read the page text below. For each numbered rule, answer whether the page text clearly "
        "and unambiguously preserves that rule's meaning (paraphrase is fine, weakening or "
        "omitting it is not). Return JSON only: "
        "{\"results\": [{\"rule\": \"...\", \"present\": true|false}]}\n\n"
        "Rules:\n" + "\n".join(f"{i+1}. {c}" for i, c in enumerate(guardrail_clauses))
        + f"\n\nPage text:\n{body_html}"
    )
    result = call_claude_json(prompt, model, api_key, dry_run=dry_run)
    results = (result or {}).get('results') if result else None
    if not isinstance(results, list):
        # Verification call itself failed — flag for a human rather than assume pass.
        return {'status': 'needs_review', 'missing': guardrail_clauses,
                'checked_at': datetime.now(timezone.utc).isoformat()}
    missing = [r.get('rule') for r in results if isinstance(r, dict) and not r.get('present')]
    return {
        'status': 'needs_review' if missing else 'pass',
        'missing': missing,
        'checked_at': datetime.now(timezone.utc).isoformat(),
    }
