<?php
// AI niche-library drafter. POST only. Requires admin auth + CSRF.
// Returns JSON: {niche: {...full niche_library shape...}} or {error:"..."}.
// One-shot call to the Anthropic Messages API, same pattern as schema_suggest.php
// and keyword_suggest.php — strip fences, extract the object, validate it parses,
// hand it back for the admin screen to drop into the (still editable) form. This
// is a DRAFT only; nothing is written to niche_library/ until the human reviews
// it and clicks Save, same "AI drafts, Scott approves" rule as every other AI
// tool in this codebase.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if (lpc_current_actor_role() !== 'super_admin') { http_response_code(403); echo json_encode(['error' => 'The niche library is managed by the platform only.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Invalid request token.']); exit; }

$description = trim($_POST['description'] ?? '');
if ($description === '' || mb_strlen($description) > 4000) {
    echo json_encode(['error' => 'Description is empty or too long.']); exit;
}

require_once __DIR__ . '/../includes/anthropic.php';
$ready = anthropic_ready();
if (!$ready['ok']) { echo json_encode(['error' => $ready['error']]); exit; }

$existing = implode(', ', array_filter(niche_library_list(), fn($id) => niche_library_load($id) !== null));

$prompt = <<<PROMPT
You are drafting one entry for a "niche library" — a file of FACTS about a local-service-business
industry vertical that are true regardless of who runs the business. This file is shared between two
different systems that each layer their OWN business-model framing on top of it afterward — so it must
contain ONLY neutral facts, never claim the business is a referral network, and never claim the
business performs the work directly either. Just the facts about the industry and what varies by city.

Niche description, in the operator's own words:
"{$description}"

Existing niche ids already in the library (for context only — pick a different, new slug unless this
description is clearly meant to update one of these): {$existing}

Return ONLY a JSON object with exactly these keys:
- niche_id: lowercase-hyphenated slug, new and not in the existing list above
- label: short display name
- service_noun: the generic noun for what this niche does (e.g. "water damage restoration")
- customer_noun: what to call the customer (e.g. "homeowner", "property owner")
- tone: a short phrase describing the right tone for this niche's content
- local_angle: 1-2 sentences on what's genuinely different about this niche FROM CITY TO CITY — climate,
  regulation, housing stock, whatever actually varies. Neutral fact, not a sales pitch.
- seasonal_calendar: 1 sentence on when demand/failure patterns peak through the year, if relevant;
  empty string if this niche has no real seasonal pattern
- services: array of 5-8 {slug, label, keyword_pattern} — keyword_pattern should be "" for a normal
  service (the system defaults to "{service} in {city}, {state}") and only set to something else
  (e.g. "Emergency {service} in {city}, {state}") when that service genuinely needs different framing
- trust_bar_items: array of 3-5 short checkmark phrases (e.g. "24/7 Emergency Service", "Licensed &
  Insured")
- match_keywords: array of 3-6 lowercase words/phrases that would appear in a real business description
  in this industry, used elsewhere to auto-detect this niche from free text
- custom_research_fields: array of 0-3 {key, ask} pairs for genuinely niche-specific per-city research
  BEYOND the standard facts every niche already gets (population, local industries, major employers,
  neighborhoods). Most niches need ZERO of these — only add one if there's a real fact specific to this
  niche that a generic city lookup wouldn't already cover (e.g. pest control's regional pest prevalence,
  a legal niche's court jurisdiction name). key must be lowercase_with_underscores.
- blog_topics: array of 8-12 {slug, title, focus_keyword, tag, related_topics}. related_topics is an
  array of OTHER blog topic slugs from this SAME list (never a URL, never a slug not in this list) —
  omit or leave empty if nothing in the list is really related.

Return ONLY the JSON object, no markdown fences, no explanation before or after it.
PROMPT;

$r = anthropic_message($prompt, ['model' => ANTHROPIC_SMART, 'max_tokens' => 4000, 'timeout' => 90]);
if (!$r['ok']) { echo json_encode(['error' => $r['error']]); exit; }
$text = trim($r['text']);

if (strpos($text, '```') !== false) {
    $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
    $text = preg_replace('/\s*```\s*$/', '', $text);
    $text = trim($text);
}
$first = strpos($text, '{');
$last  = strrpos($text, '}');
if ($first !== false && $last !== false && $last > $first) {
    $text = substr($text, $first, $last - $first + 1);
}

$decoded = json_decode($text, true);
if (!is_array($decoded)) {
    echo json_encode(['error' => 'The model did not return valid JSON. Try again, or adjust the description.']); exit;
}

// Same defaulting every manual save goes through — a field the model omitted
// (or got the wrong type for) falls back cleanly instead of breaking the form.
$niche = niche_library_with_defaults(array_filter($decoded, function ($v, $k) {
    return in_array($k, ['niche_id', 'label', 'service_noun', 'customer_noun', 'tone', 'local_angle',
        'seasonal_calendar', 'services', 'trust_bar_items', 'match_keywords',
        'custom_research_fields', 'blog_topics'], true);
}, ARRAY_FILTER_USE_BOTH));

if (!niche_library_valid_id((string) ($niche['niche_id'] ?? ''))) {
    $niche['niche_id'] = slugify((string) ($niche['label'] ?: 'new-niche'));
}
// Restrict related_topics to slugs that actually exist in this same draft —
// the model is told to do this already, but it's cheap to enforce rather than
// trust, same "never link to a page that doesn't exist" rule everywhere else
// in this codebase applies to.
$validSlugs = array_column((array) $niche['blog_topics'], 'slug');
foreach ($niche['blog_topics'] as &$bt) {
    $bt['related_topics'] = array_values(array_intersect((array) ($bt['related_topics'] ?? []), $validSlugs));
}
unset($bt);

echo json_encode(['niche' => $niche]);
