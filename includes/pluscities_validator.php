<?php
/**
 * includes/pluscities_validator.php — the validator from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 7. Phase 1 builds the 3 checks the
 * spec's own Phase 1 note calls for (Unsupported claim, Uniqueness, and
 * Service radius substituting for Block mix, which is deferred — see Section
 * 7's severity triage). One file for now; splits into one-file-per-check once
 * there are enough checks to make that worthwhile (Phase 2).
 *
 * Every check returns {check, result: pass|fail, detail} — plain language in
 * `detail`, since spec Section 11 requires the page-status popover to explain
 * a failure in plain language, not a raw check name.
 */

/**
 * Unsupported claim: every `editable` block on the page must have at least
 * one fact_id. Re-checks the STORED state independently of what the writer
 * claimed at generation time — the validator doesn't trust the writer, it
 * verifies it.
 */
function pluscities_check_unsupported_claim(array $blocks): array
{
    foreach ($blocks as $b) {
        if ($b['classification'] !== 'editable') continue;
        $factIds = json_decode($b['fact_ids'] ?? '[]', true) ?: [];
        // Zero fact_ids is only a problem if the block ALSO wasn't grounded in
        // client-profile facts — spec Section 4 allows a block (Hero,
        // Overview) to be entirely client-facts-grounded with no external
        // citation at all. client_grounded distinguishes that legitimate case
        // from a block that genuinely has nothing backing it.
        if (!$factIds && !(int) ($b['client_grounded'] ?? 0)) {
            return ['check' => 'unsupported_claim', 'result' => 'fail',
                'detail' => 'The "' . $b['block_type'] . '" block has no cited facts or client facts backing it.'];
        }
    }
    return ['check' => 'unsupported_claim', 'result' => 'pass', 'detail' => ''];
}

/** Word-shingle Jaccard similarity between two bodies of text — the real
 *  comparison function, exercised once a second page exists (Phase 2). */
function pluscities_shingle_similarity(string $a, string $b, int $shingleSize = 5): float
{
    $shingles = function (string $s) use ($shingleSize): array {
        $words = preg_split('/\s+/', strtolower(trim($s)));
        $out = [];
        for ($i = 0; $i + $shingleSize <= count($words); $i++) {
            $out[implode(' ', array_slice($words, $i, $shingleSize))] = true;
        }
        return $out;
    };
    $sa = $shingles($a);
    $sb = $shingles($b);
    if (!$sa && !$sb) return 1.0; // both too short to shingle — nothing to disagree about
    $intersect = count(array_intersect_key($sa, $sb));
    $union     = count($sa) + count($sb) - $intersect;
    return $union > 0 ? $intersect / $union : 1.0;
}

/**
 * Uniqueness: this page's editable-block text must be <50% similar (shingle
 * overlap) to the SAME service's pages on OTHER cities for the same client.
 * Phase 1 has exactly one page for this client/service, so there's nothing to
 * compare against yet — the real function still runs, it just has an empty
 * comparison set, which is a pass by definition, not a special case.
 */
function pluscities_check_uniqueness(array $page, array $blocks, PDO $db): array
{
    $otherPages = $db->prepare(
        'SELECT page_id FROM pages WHERE client_id = ? AND service_key = ? AND page_id != ?'
    );
    $otherPages->execute([$page['client_id'], $page['service_key'], $page['page_id']]);
    $otherIds = $otherPages->fetchAll(PDO::FETCH_COLUMN);
    if (!$otherIds) return ['check' => 'uniqueness', 'result' => 'pass', 'detail' => ''];

    $thisText = pluscities_page_editable_text($blocks);
    $blocksStmt = $db->prepare('SELECT * FROM blocks WHERE page_id = ?');
    foreach ($otherIds as $otherId) {
        $blocksStmt->execute([$otherId]);
        $otherText = pluscities_page_editable_text($blocksStmt->fetchAll(PDO::FETCH_ASSOC));
        if (pluscities_shingle_similarity($thisText, $otherText) >= 0.5) {
            return ['check' => 'uniqueness', 'result' => 'fail',
                'detail' => 'Over 50% similar to this client\'s "' . $page['service_key'] . '" page for another city (' . $otherId . ').'];
        }
    }
    return ['check' => 'uniqueness', 'result' => 'pass', 'detail' => ''];
}

function pluscities_page_editable_text(array $blocks): string
{
    $parts = [];
    foreach ($blocks as $b) {
        if ($b['classification'] !== 'editable') continue;
        $content = json_decode($b['content_json'], true) ?: [];
        $parts[] = (string) ($content['body'] ?? '');
    }
    return implode(' ', $parts);
}

/**
 * Service radius: the page's city must be within the client's radius, or
 * explicitly confirmed by the client (spec's doorway safeguard — never claim
 * service to a city further than the client actually serves).
 */
function pluscities_check_service_radius(array $page, array $client): array
{
    $target = null;
    foreach ($client['target_cities'] ?? [] as $tc) {
        if (($tc['city_id'] ?? '') === $page['city_id']) { $target = $tc; break; }
    }
    if ($target === null) {
        return ['check' => 'service_radius', 'result' => 'fail', 'detail' => 'This city is not on the client\'s target-cities list at all.'];
    }
    $withinRadius = ($target['drive_minutes'] ?? PHP_INT_MAX) <= ($client['radius_minutes'] ?? 0);
    $confirmed    = !empty($target['confirmed_by_client']);
    if (!$withinRadius && !$confirmed) {
        return ['check' => 'service_radius', 'result' => 'fail',
            'detail' => 'This city is ' . $target['drive_minutes'] . ' min away, outside the ' . $client['radius_minutes'] . ' min radius, and not confirmed by the client.'];
    }
    return ['check' => 'service_radius', 'result' => 'pass', 'detail' => ''];
}

/**
 * Wrong city: mechanical, per spec Section 4's writer-contract payoff — every
 * cited fact's scope must actually belong to this page's city. Service-scope
 * facts (shared across every city for the niche) are fine regardless; only a
 * city-scope fact citing a DIFFERENT city is a real error.
 */
function pluscities_check_wrong_city(array $blocks, string $city_id, PDO $db): array
{
    $allFactIds = [];
    foreach ($blocks as $b) $allFactIds = array_merge($allFactIds, json_decode($b['fact_ids'] ?? '[]', true) ?: []);
    if (!$allFactIds) return ['check' => 'wrong_city', 'result' => 'pass', 'detail' => ''];

    $placeholders = implode(',', array_fill(0, count($allFactIds), '?'));
    $st = $db->prepare("SELECT id, scope, scope_key FROM fact_cards WHERE id IN ($placeholders)");
    $st->execute(array_values(array_unique($allFactIds)));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fc) {
        if ($fc['scope'] === 'city' && $fc['scope_key'] !== $city_id) {
            return ['check' => 'wrong_city', 'result' => 'fail',
                'detail' => 'Fact ' . $fc['id'] . ' belongs to "' . $fc['scope_key'] . '", not this page\'s city.'];
        }
    }
    return ['check' => 'wrong_city', 'result' => 'pass', 'detail' => ''];
}

/**
 * Client facts: any phone number or license number pattern appearing in
 * rendered content must match the client's real profile values — catches a
 * stale edit or a copy/paste error, not just AI-asserted content (edited
 * blocks are grounding-exempt for Unsupported Claim, but a WRONG phone
 * number is a factual error regardless of who typed it).
 */
function pluscities_check_client_facts(array $blocks, array $client): array
{
    $realPhones = array_map(fn($h) => preg_replace('/\D/', '', $h['phone'] ?? ''), $client['identity']['hubs'] ?? []);
    $realLicenses = array_map(fn($l) => $l['number'] ?? '', $client['licenses'] ?? []);

    foreach ($blocks as $b) {
        $text = pluscities_block_body_text($b);
        if (preg_match_all('/\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/', $text, $m)) {
            foreach ($m[0] as $found) {
                $digits = preg_replace('/\D/', '', $found);
                if ($realPhones && !in_array($digits, $realPhones, true)) {
                    return ['check' => 'client_facts', 'result' => 'fail',
                        'detail' => 'The "' . $b['block_type'] . '" block shows a phone number that doesn\'t match the client\'s real number.'];
                }
            }
        }
        if (preg_match_all('/\b[A-Z]{2,6}\d{4,}[A-Z]?\b/', $text, $m)) {
            foreach ($m[0] as $found) {
                if ($realLicenses && !in_array($found, $realLicenses, true)) {
                    return ['check' => 'client_facts', 'result' => 'fail',
                        'detail' => 'The "' . $b['block_type'] . '" block shows a license number that doesn\'t match any of the client\'s real licenses.'];
                }
            }
        }
    }
    return ['check' => 'client_facts', 'result' => 'pass', 'detail' => ''];
}

function pluscities_block_body_text(array $b): string
{
    $content = json_decode($b['content_json'], true) ?: [];
    if (isset($content['faqs'])) {
        return implode(' ', array_map(fn($f) => ($f['question'] ?? '') . ' ' . ($f['answer'] ?? ''), $content['faqs']));
    }
    if (isset($content['items'])) {
        return implode(' ', array_map(fn($i) => ($i['heading'] ?? '') . ' ' . ($i['body'] ?? ''), $content['items']));
    }
    return (string) ($content['body'] ?? $content['intro'] ?? '');
}

/**
 * Banned phrases: two sources, checked the same way but with different
 * override eligibility — a niche's compliance preset is never overridable
 * (real regulatory risk), a client's own stylistic preference is (spec
 * Section 7's hard-exception list applies to the phrase's SOURCE, not the
 * check name alone).
 */
function pluscities_check_banned_phrases(array $blocks, array $client, ?array $nicheResolved): array
{
    $compliancePhrases = $nicheResolved['compliance_preset']['banned_phrases'] ?? [];
    $clientPhrases = $client['banned_phrases'] ?? [];

    foreach ($blocks as $b) {
        $text = strtolower(pluscities_block_body_text($b));
        foreach ($compliancePhrases as $phrase) {
            if ($phrase !== '' && str_contains($text, strtolower($phrase))) {
                return ['check' => 'banned_phrases', 'result' => 'fail', 'overridable' => false,
                    'detail' => 'The "' . $b['block_type'] . '" block uses "' . $phrase . '" — banned by this niche\'s compliance preset.'];
            }
        }
        foreach ($clientPhrases as $phrase) {
            if ($phrase !== '' && str_contains($text, strtolower($phrase))) {
                return ['check' => 'banned_phrases', 'result' => 'fail', 'overridable' => true,
                    'detail' => 'The "' . $b['block_type'] . '" block uses "' . $phrase . '" — on this client\'s own banned-phrase list.'];
            }
        }
    }
    return ['check' => 'banned_phrases', 'result' => 'pass', 'detail' => ''];
}

/** Invented prices: a dollar figure in rendered content must come from the
 *  client's own price_ranges — never a model-asserted number. */
function pluscities_check_invented_prices(array $blocks, array $client): array
{
    $allowed = [];
    foreach ($client['price_ranges'] ?? [] as $range) {
        if (is_array($range)) $allowed = array_merge($allowed, array_map('strval', $range));
        else $allowed[] = (string) $range;
    }
    foreach ($blocks as $b) {
        if ($b['classification'] === 'client-supplied') continue; // the client's own words, not a claim to check
        $text = pluscities_block_body_text($b);
        if (preg_match_all('/\$[\d,]+(?:\.\d{2})?/', $text, $m)) {
            foreach ($m[0] as $found) {
                if (!in_array($found, $allowed, true)) {
                    return ['check' => 'invented_prices', 'result' => 'fail',
                        'detail' => 'The "' . $b['block_type'] . '" block states a price (' . $found . ') the client never supplied.'];
                }
            }
        }
    }
    return ['check' => 'invented_prices', 'result' => 'pass', 'detail' => ''];
}

/** Invented reviews: the reviews_from_city block's content must exactly
 *  match the client's own supplied reviews for that city — never anything
 *  the writer generated, and no OTHER block may contain review-like
 *  testimonial content the client didn't supply. */
function pluscities_check_invented_reviews(array $blocks, array $client, string $city_id): array
{
    $realReviews = $client['reviews_by_city'][$city_id] ?? [];
    $realTexts   = array_map(fn($r) => $r['text'] ?? '', $realReviews);

    foreach ($blocks as $b) {
        if ($b['block_type'] !== 'reviews_from_city') continue;
        $content = json_decode($b['content_json'], true) ?: [];
        foreach ($content['reviews'] ?? [] as $rev) {
            if (!in_array($rev['text'] ?? '', $realTexts, true)) {
                return ['check' => 'invented_reviews', 'result' => 'fail',
                    'detail' => 'The reviews block contains a review the client never actually supplied.'];
            }
        }
    }
    return ['check' => 'invented_reviews', 'result' => 'pass', 'detail' => ''];
}

/**
 * Quotes: a verbatim quote from a fact card's own `quote` field, if it
 * appears in rendered content at all, must (a) actually match the card's
 * stored quote and (b) not be reused from the same source twice on one page.
 * Today's writer contract never injects the raw quote field into prose (it
 * paraphrases the claim instead) — this check is the real, correct logic for
 * if/when a future block type does quote directly, not a stub; it simply has
 * nothing to flag against the current content shape.
 */
function pluscities_check_quotes(array $blocks, PDO $db): array
{
    $allFactIds = [];
    foreach ($blocks as $b) $allFactIds = array_merge($allFactIds, json_decode($b['fact_ids'] ?? '[]', true) ?: []);
    if (!$allFactIds) return ['check' => 'quotes', 'result' => 'pass', 'detail' => ''];

    $placeholders = implode(',', array_fill(0, count($allFactIds), '?'));
    $st = $db->prepare("SELECT id, quote, source_url FROM fact_cards WHERE id IN ($placeholders) AND quote != ''");
    $st->execute(array_values(array_unique($allFactIds)));
    $cardsWithQuotes = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$cardsWithQuotes) return ['check' => 'quotes', 'result' => 'pass', 'detail' => ''];

    $fullText = implode(' ', array_map('pluscities_block_body_text', $blocks));
    $sourcesQuoted = [];
    foreach ($cardsWithQuotes as $fc) {
        if (!str_contains($fullText, $fc['quote'])) continue; // not actually rendered verbatim — nothing to check
        if (isset($sourcesQuoted[$fc['source_url']])) {
            return ['check' => 'quotes', 'result' => 'fail',
                'detail' => 'The same source (' . $fc['source_url'] . ') is quoted more than once on this page.'];
        }
        $sourcesQuoted[$fc['source_url']] = true;
    }
    return ['check' => 'quotes', 'result' => 'pass', 'detail' => ''];
}

/** Stale facts: any fact_id a block cites must still be active — a card
 *  expiring/flagged/rejected after a page was built flags it for refresh. */
function pluscities_check_stale_facts(array $blocks, PDO $db): array
{
    $allFactIds = [];
    foreach ($blocks as $b) $allFactIds = array_merge($allFactIds, json_decode($b['fact_ids'] ?? '[]', true) ?: []);
    if (!$allFactIds) return ['check' => 'stale_facts', 'result' => 'pass', 'detail' => ''];

    $placeholders = implode(',', array_fill(0, count($allFactIds), '?'));
    $st = $db->prepare("SELECT id, status FROM fact_cards WHERE id IN ($placeholders)");
    $st->execute(array_values(array_unique($allFactIds)));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fc) {
        if ($fc['status'] !== 'active') {
            return ['check' => 'stale_facts', 'result' => 'fail',
                'detail' => 'Fact ' . $fc['id'] . ' is now "' . $fc['status'] . '" — needs re-research before this page should rely on it.'];
        }
    }
    return ['check' => 'stale_facts', 'result' => 'pass', 'detail' => ''];
}

/** Cannibalization: more than one page for the same client targeting the
 *  same city+service. Structurally can't happen via normal page creation
 *  (page_id is built from client+city+service, so a duplicate insert is the
 *  same row) — this is the real safety-net check for whenever a future path
 *  (e.g. an alternate slug) makes a true duplicate possible, not a stub. */
function pluscities_check_cannibalization(array $page, PDO $db): array
{
    $st = $db->prepare('SELECT COUNT(*) FROM pages WHERE client_id = ? AND city_id = ? AND service_key = ? AND page_id != ?');
    $st->execute([$page['client_id'], $page['city_id'], $page['service_key'], $page['page_id']]);
    if ((int) $st->fetchColumn() > 0) {
        return ['check' => 'cannibalization', 'result' => 'fail',
            'detail' => 'Another page already targets the same city and service for this client.'];
    }
    return ['check' => 'cannibalization', 'result' => 'pass', 'detail' => ''];
}

/** FAQ: every question must have a non-empty answer — the mechanical part of
 *  "answer missing from HTML." Full cross-check against emitted JSON-LD is
 *  deferred until schema assembly itself is built (Phase 2 SEO-completion
 *  item, spec Section 5) — nothing to diverge from yet. */
function pluscities_check_faq(array $blocks): array
{
    foreach ($blocks as $b) {
        if ($b['block_type'] !== 'faq') continue;
        $content = json_decode($b['content_json'], true) ?: [];
        foreach ($content['faqs'] ?? [] as $faq) {
            if (trim((string) ($faq['question'] ?? '')) === '' || trim((string) ($faq['answer'] ?? '')) === '') {
                return ['check' => 'faq', 'result' => 'fail', 'detail' => 'One of the FAQ entries is missing a question or an answer.'];
            }
        }
    }
    return ['check' => 'faq', 'result' => 'pass', 'detail' => ''];
}

/** Same shape of check as pluscities_check_faq() above, for every items-
 *  shaped block type's "items" list (why_choose_us, process, common_problems
 *  — all written by pluscities_write_items_block()) — a regenerated block
 *  missing a heading or body on any item would render as a blank grid cell
 *  or step. An un-regenerated block (still the old {heading, body} shape, no
 *  "items" key at all) has nothing for this check to look at and passes
 *  trivially — that's the generic editable-block path, already covered by
 *  the body-text checks elsewhere. */
function pluscities_check_items(array $blocks): array
{
    $itemsShapedTypes = ['why_choose_us', 'process', 'common_problems', 'seasonal_guide'];
    foreach ($blocks as $b) {
        if (!in_array($b['block_type'], $itemsShapedTypes, true)) continue;
        $content = json_decode($b['content_json'], true) ?: [];
        foreach ($content['items'] ?? [] as $item) {
            if (trim((string) ($item['heading'] ?? '')) === '' || trim((string) ($item['body'] ?? '')) === '') {
                return ['check' => 'items', 'result' => 'fail', 'detail' => 'One of the "' . $b['block_type'] . '" items is missing a heading or a body.'];
            }
        }
    }
    return ['check' => 'items', 'result' => 'pass', 'detail' => ''];
}

/**
 * Runs all Phase 1 checks and writes the combined result into
 * pages.validation_json, keyed against the page's current draft_hash — any
 * further edit invalidates this result, per spec Section 7/8.
 *
 * @return array the validation result that was written
 */
/**
 * The checks that can NEVER be overridden by anyone, regardless of role —
 * spec Section 7: there's no legitimate reason to want to publish past these,
 * since doing so means the content is actively false, not just lower
 * quality. banned_phrases is a partial case — its own result carries an
 * 'overridable' key when the match came from a client preference rather than
 * the niche's compliance preset; this list is the default when a check
 * doesn't say otherwise.
 */
const PLUSCITIES_VALIDATOR_HARD_EXCEPTIONS = [
    'unsupported_claim', 'invented_prices', 'invented_reviews',
    'wrong_city', 'client_facts', 'quotes',
];

function pluscities_run_validator(string $page_id): array
{
    $db = pluscities_db();
    $pageStmt = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
    $pageStmt->execute([$page_id]);
    $page = $pageStmt->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['hash_checked_against' => '', 'results' => [], 'error' => 'Page not found'];

    $blocksStmt = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
    $blocksStmt->execute([$page_id]);
    $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

    $client = json_decode((string) @file_get_contents(PLUSCITIES_CLIENTS_DIR . $page['client_id'] . '.json'), true) ?: [];
    $niche  = pluscities_niche_resolved($client['niche_id'] ?? '');

    $results = [
        pluscities_check_unsupported_claim($blocks),
        pluscities_check_uniqueness($page, $blocks, $db),
        pluscities_check_service_radius($page, $client),
        pluscities_check_wrong_city($blocks, $page['city_id'], $db),
        pluscities_check_client_facts($blocks, $client),
        pluscities_check_banned_phrases($blocks, $client, $niche),
        pluscities_check_invented_prices($blocks, $client),
        pluscities_check_invented_reviews($blocks, $client, $page['city_id']),
        pluscities_check_quotes($blocks, $db),
        pluscities_check_stale_facts($blocks, $db),
        pluscities_check_cannibalization($page, $db),
        pluscities_check_faq($blocks),
        pluscities_check_items($blocks),
    ];

    // Annotate any failing, overridable check with an override already on
    // file for this exact draft_hash — a prior override never silently
    // survives a later edit, since a new edit means a new hash that has no
    // matching row here.
    $overrideStmt = $db->prepare('SELECT reason, actor, created_at FROM overrides WHERE page_id = ? AND check_name = ? AND draft_hash = ?');
    foreach ($results as &$r) {
        if ($r['result'] !== 'fail') continue;
        $overridable = $r['overridable'] ?? !in_array($r['check'], PLUSCITIES_VALIDATOR_HARD_EXCEPTIONS, true);
        $r['overridable'] = $overridable;
        if (!$overridable) continue;
        $overrideStmt->execute([$page_id, $r['check'], $page['draft_hash']]);
        if ($row = $overrideStmt->fetch(PDO::FETCH_ASSOC)) {
            $r['overridden'] = true;
            $r['override_reason'] = $row['reason'];
            $r['override_by'] = $row['actor'];
        }
    }
    unset($r);

    $validation = ['hash_checked_against' => $page['draft_hash'], 'results' => $results];
    $db->prepare('UPDATE pages SET validation_json = ? WHERE page_id = ?')
       ->execute([json_encode($validation), $page_id]);
    return $validation;
}

/** True if every check passed, or every failure is overridden. */
function pluscities_validation_all_pass(array $validation): bool
{
    foreach ($validation['results'] ?? [] as $r) {
        if (($r['result'] ?? '') === 'pass') continue;
        if (!empty($r['overridden'])) continue;
        return false;
    }
    return true;
}

/**
 * The override action itself — Agency Admin (or Platform Owner) only, a
 * reason required, refused outright for the hard-exception checks or an
 * un-overridable banned-phrases hit, since there's no legitimate reason to
 * want to publish past those regardless of who's asking.
 *
 * @return array{ok:bool,msg:string}
 */
function pluscities_override_validator_check(string $page_id, string $check_name, string $reason, string $actor_role, string $actor): array
{
    if (!in_array($actor_role, ['agency_admin', 'super_admin'], true)) {
        return ['ok' => false, 'msg' => 'Only an Agency Admin can override a validator check.'];
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'msg' => 'A reason is required to override a check.'];
    }

    $db = pluscities_db();
    $pageStmt = $db->prepare('SELECT draft_hash, validation_json FROM pages WHERE page_id = ?');
    $pageStmt->execute([$page_id]);
    $page = $pageStmt->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found.'];

    $validation = json_decode($page['validation_json'] ?: '[]', true) ?: [];
    $target = null;
    foreach ($validation['results'] ?? [] as $r) {
        if ($r['check'] === $check_name) { $target = $r; break; }
    }
    if ($target === null || $target['result'] !== 'fail') {
        return ['ok' => false, 'msg' => 'That check isn\'t currently failing — nothing to override.'];
    }
    $overridable = $target['overridable'] ?? !in_array($check_name, PLUSCITIES_VALIDATOR_HARD_EXCEPTIONS, true);
    if (!$overridable) {
        return ['ok' => false, 'msg' => 'This check can never be overridden — the content needs to actually be fixed.'];
    }

    $db->prepare('INSERT INTO overrides (page_id, check_name, draft_hash, reason, actor, created_at) VALUES (?,?,?,?,?,?)
        ON CONFLICT(page_id, check_name, draft_hash) DO UPDATE SET reason = excluded.reason, actor = excluded.actor, created_at = excluded.created_at')
       ->execute([$page_id, $check_name, $page['draft_hash'], $reason, $actor, gmdate('c')]);
    pluscities_log_event($actor, 'override_validator_check', $page_id . ' / ' . $check_name, $reason);

    pluscities_run_validator($page_id); // refresh validation_json with the override now applied
    return ['ok' => true, 'msg' => ''];
}
