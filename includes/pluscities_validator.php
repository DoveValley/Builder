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
 * Runs all Phase 1 checks and writes the combined result into
 * pages.validation_json, keyed against the page's current draft_hash — any
 * further edit invalidates this result, per spec Section 7/8.
 *
 * @return array the validation result that was written
 */
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

    $results = [
        pluscities_check_unsupported_claim($blocks),
        pluscities_check_uniqueness($page, $blocks, $db),
        pluscities_check_service_radius($page, $client),
    ];

    $validation = ['hash_checked_against' => $page['draft_hash'], 'results' => $results];
    $db->prepare('UPDATE pages SET validation_json = ? WHERE page_id = ?')
       ->execute([json_encode($validation), $page_id]);
    return $validation;
}

/** True if every check in a validation result passed (or was overridden — Phase 1 has no override UI yet). */
function pluscities_validation_all_pass(array $validation): bool
{
    foreach ($validation['results'] ?? [] as $r) {
        if (($r['result'] ?? '') !== 'pass') return false;
    }
    return true;
}
