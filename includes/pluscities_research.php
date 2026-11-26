<?php
/**
 * includes/pluscities_research.php — the research module from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 3: two distinct input types, fact
 * cards (cited, per claim) and datasets (flat provider values, no citation).
 *
 * Callers must require_once admin/infra/lib/pluscities_db.php and
 * admin/infra/lib/http.php first (self-contained libs, not loaded via
 * includes/functions.php — same convention as admin/infra/lib/keywords.php).
 */

// ── Datasets — flat, cached, no citation (the provider IS the source) ──────

/**
 * Read-through cache against the `datasets` table. Phase 1 wires exactly one
 * provider, census_acs, reusing the EXISTING Census fetch/cache in
 * admin/infra/lib/census.php rather than re-implementing the API call — that
 * data is already backfilled for ~43,900 cities (infra_census_lookup()), so
 * this just copies it into the new shape instead of re-fetching.
 */
function pluscities_dataset_get(string $city_id, string $provider, bool $force = false): ?array
{
    $db = pluscities_db();
    if (!$force) {
        $st = $db->prepare('SELECT * FROM datasets WHERE city_id = ? AND provider = ?');
        $st->execute([$city_id, $provider]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['city_id' => $row['city_id'], 'provider' => $row['provider'],
                'fetched_at' => $row['fetched_at'], 'values' => json_decode($row['values_json'], true) ?: []];
        }
    }
    // $force bypasses the cache-hit path above and re-fetches — needed when
    // a provider's own source gains a new field after a city was already
    // cached (confirmed live: adding avg_temp_monthly to an existing niche
    // folder left already-cached cities without it, since chart_fetch_
    // decls() only returns what the niche folder declared AT FETCH TIME).
    // The INSERT below's ON CONFLICT already overwrites the row either way.

    $values = null;
    if ($provider === 'census_acs') {
        if (!function_exists('infra_census_lookup')) return null; // caller forgot to require census.php
        $c = infra_census_lookup($city_id);
        if ($c === null) return null;
        $values = [
            'income_median'     => $c['income_median'],
            'home_value_median' => $c['home_value_median'],
            'year_built_median' => $c['year_built_median'],
            'homeownership_pct' => $c['homeownership_pct'],
        ];
    } elseif (str_starts_with($provider, 'noaa_climate:')) {
        // Keyed per OLD-SYSTEM NICHE SLUG, not a bare 'noaa_climate' — two
        // different niches picking different charts for the same city must
        // not share or overwrite one cached row (see includes/pluscities_
        // climate_chart.php's own docblock). chart_fetch_decls() (the old
        // system's own function, called via the shell bridge) returns every
        // chart a niche declares in one call, so this single cached row
        // already holds fields for every future chart that niche might add.
        if (!function_exists('pluscities_climate_chart_shell_fetch')) return null; // caller forgot to require pluscities_climate_chart.php
        if (!function_exists('infra_city_get')) return null; // caller forgot to require cities.php
        $oldNicheSlug = substr($provider, strlen('noaa_climate:'));
        $city = infra_city_get($city_id);
        if (!$city) return null;
        $result = pluscities_climate_chart_shell_fetch($city, $oldNicheSlug);
        if ($result === null || empty($result['updates'])) return null;
        $values = $result['updates'];
    }
    if ($values === null) return null; // unknown provider or no data available yet

    $fetchedAt = gmdate('c');
    $db->prepare('INSERT INTO datasets (city_id, provider, fetched_at, values_json) VALUES (?, ?, ?, ?)
        ON CONFLICT(city_id, provider) DO UPDATE SET fetched_at = excluded.fetched_at, values_json = excluded.values_json')
       ->execute([$city_id, $provider, $fetchedAt, json_encode($values)]);
    return ['city_id' => $city_id, 'provider' => $provider, 'fetched_at' => $fetchedAt, 'values' => $values];
}

// ── Fact cards — one claim, one real citation, shared across clients ───────

/** Expiry windows per topic, spec Section 3. */
const PLUSCITIES_TOPIC_EXPIRY_MONTHS = [
    'neighborhoods' => 24, 'permits' => 6, 'utilities' => 12, 'hoa' => 12,
    'hazards' => 12, 'seasons' => 24, 'civic' => 24,
    'climate_data' => 60, // official climate normals (1991-2020 period) barely move year to year
    'authority' => 12, 'problems' => 24, 'cost-drivers' => 12, 'options' => 24,
    'maintenance' => 24, 'urgent-steps' => 24, 'niche-table' => 12,
];

/**
 * Checks for an active card first (shared across every client — spec's
 * "research once, reuse everywhere"); only calls out for new research if
 * missing/stale/flagged/rejected. Never runs speculatively.
 */
function pluscities_fact_card_get_active(string $scope, string $scope_key, string $topic): array
{
    $st = pluscities_db()->prepare(
        'SELECT * FROM fact_cards WHERE scope = ? AND scope_key = ? AND topic = ? AND status = "active"'
    );
    $st->execute([$scope, $scope_key, $topic]);
    return array_map('pluscities_fact_card_row_decode', $st->fetchAll(PDO::FETCH_ASSOC));
}

function pluscities_fact_card_row_decode(array $row): array
{
    $row['value'] = json_decode($row['value_json'] ?? '', true);
    unset($row['value_json']);
    return $row;
}

/**
 * Extracts plain text from a fetched PDF via `pdftotext` (poppler-utils) —
 * shelling out to an available system tool, same spirit as the fleet's own
 * NOAA python3 subprocess bridge, rather than pulling in a PHP PDF library
 * for one conversion. @return array{0:?string,1:string} [text, source title]
 */
function pluscities_extract_pdf_text(string $pdfBytes, string $sourceUrl): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'pcpdf_');
    file_put_contents($tmp, $pdfBytes);
    $text = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    // PDF metadata title, if set — falls back to the URL's filename otherwise,
    // since PDFs often lack a meaningful <title>-equivalent.
    $infoRaw = shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>/dev/null');
    unlink($tmp);

    $title = basename(parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl);
    if ($infoRaw && preg_match('/^Title:\s*(.+)$/m', $infoRaw, $m) && trim($m[1]) !== '') {
        $title = trim($m[1]);
    }
    if (!is_string($text) || trim($text) === '') return [null, $title];
    return [trim(preg_replace('/\s+/', ' ', $text)), $title];
}

/**
 * Researches ONE fact card from a real, already-fetched source page — never
 * from the model's own knowledge. $sourceUrl is the candidate page to fetch;
 * Phase 1 passes one known-good URL per topic by hand (finding the right
 * official source automatically is a real but separate Phase 2 capability —
 * see spec Section 3's source-priority rule, which this still enforces, it
 * just doesn't yet choose the URL on its own).
 *
 * @return array{ok:bool,msg:string,card:?array}
 */
function pluscities_research_run(string $scope, string $scope_key, string $topic, string $sourceUrl, string $claimHint): array
{
    // infra_http() is built for JSON APIs and doesn't follow redirects — real
    // city/county sites redirect their own pages often enough that one hop
    // needs handling here rather than growing that shared, generic client for
    // this one caller's need. A real User-Agent is also required — confirmed
    // in testing that energy.gov (and likely other government CDN-fronted
    // sites) returns a flat 403 to a request with no UA at all.
    $uaHeader = ['User-Agent: Mozilla/5.0 (compatible; PlusCitiesResearchBot/1.0)'];
    $fetch = infra_http('GET', $sourceUrl, ['timeout' => 20, 'capture_headers' => true, 'headers' => $uaHeader]);
    if (in_array($fetch['code'], [301, 302, 303, 307, 308], true) && !empty($fetch['headers']['location'])) {
        $redirectUrl = $fetch['headers']['location'];
        if (!preg_match('#^https?://#i', $redirectUrl)) {
            $parts = parse_url($sourceUrl);
            $redirectUrl = $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim($redirectUrl, '/');
        }
        $fetch = infra_http('GET', $redirectUrl, ['timeout' => 20, 'headers' => $uaHeader]);
    }
    if ($fetch['code'] !== 200 || $fetch['raw'] === '') {
        return ['ok' => false, 'msg' => 'Could not fetch ' . $sourceUrl . ' (HTTP ' . $fetch['code'] . ')', 'card' => null];
    }

    // Official city/county sources publish exactly this kind of informational
    // content (planning flyers, zoning/hazard documents) as PDFs at least as
    // often as HTML — detected by magic bytes, not just Content-Type, since
    // municipal servers don't always set that header correctly.
    if (str_starts_with($fetch['raw'], '%PDF')) {
        [$text, $sourceTitle] = pluscities_extract_pdf_text($fetch['raw'], $sourceUrl);
        if ($text === null) return ['ok' => false, 'msg' => 'Could not extract text from PDF at ' . $sourceUrl, 'card' => null];
    } else {
        // Strip tags/scripts to plain text — the model is grounded in this
        // text only, per the writer/research contract's "nothing from the
        // model's own knowledge" rule.
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $fetch['raw']);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
        $titleMatch = null;
        preg_match('/<title[^>]*>(.*?)<\/title>/is', $fetch['raw'], $titleMatch);
        $sourceTitle = $titleMatch ? trim(html_entity_decode($titleMatch[1])) : $sourceUrl;
    }
    $text = mb_substr($text, 0, 60000); // keep the prompt bounded — real municipal pages/documents run long

    $prompt = "You are extracting ONE factual claim from the page text below, for the topic \"$topic\" "
        . "about \"$scope_key\". What we're looking for: $claimHint\n\n"
        . "Rules: use ONLY what's actually in the page text — never add anything from your own general "
        . "knowledge. If the page text doesn't actually support a clear claim about this, say so. "
        . "Reply as JSON only: {\"found\": true|false, \"claim\": \"one plain sentence\", "
        . "\"quote\": \"a verbatim substring from the page text, under 15 words, or empty string\", "
        . "\"value\": {}}\n\nPAGE TEXT:\n" . $text;

    $res = anthropic_message($prompt, ['max_tokens' => 400]);
    if (!($res['ok'] ?? false)) return ['ok' => false, 'msg' => $res['error'] ?? 'Research call failed.', 'card' => null];

    // Claude wraps JSON in a ```json fence more often than not despite being
    // asked for "JSON only" — strip it rather than fighting the model on it.
    $raw  = trim((string) $res['text']);
    $raw  = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
    $json = json_decode(trim($raw), true);
    if (!is_array($json) || empty($json['found'])) {
        return ['ok' => false, 'msg' => 'The source page did not support a clear claim for this topic.', 'card' => null];
    }
    // Quote rule, spec Section 3: verbatim (must actually appear in the
    // fetched text, not just be plausible-sounding) AND under 15 words —
    // models readily return both a true substring AND one well over length,
    // so both checks are needed, not just the verbatim one.
    $quote = (string) ($json['quote'] ?? '');
    if ($quote !== '' && (stripos($text, $quote) === false || str_word_count($quote) >= 15)) $quote = '';

    $months    = PLUSCITIES_TOPIC_EXPIRY_MONTHS[$topic] ?? 12;
    $checkedAt = gmdate('Y-m-d');
    $expiresAt = gmdate('Y-m-d', strtotime("+$months months"));
    $id        = 'fc_' . $scope_key . '_' . $topic . '_' . substr(md5($sourceUrl . $checkedAt), 0, 8);

    $card = [
        'id' => $id, 'scope' => $scope, 'scope_key' => $scope_key, 'topic' => $topic,
        'claim' => (string) $json['claim'], 'value' => $json['value'] ?? [],
        'source_url' => $sourceUrl, 'source_title' => $sourceTitle, 'quote' => $quote,
        'checked_at' => $checkedAt, 'expires_at' => $expiresAt, 'status' => 'active',
    ];
    pluscities_db()->prepare(
        'INSERT INTO fact_cards (id, scope, scope_key, topic, claim, value_json, source_url, source_title,
                                   quote, checked_at, expires_at, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $card['id'], $card['scope'], $card['scope_key'], $card['topic'], $card['claim'],
        json_encode($card['value']), $card['source_url'], $card['source_title'], $card['quote'],
        $card['checked_at'], $card['expires_at'], $card['status'],
    ]);
    return ['ok' => true, 'msg' => '', 'card' => $card];
}

/** Marks a card inactive — expiry, Platform Owner rejection, or an agency
 *  flag all funnel through here, per spec Section 3's "one mechanism, three
 *  reasons" rule. $reason is stored as the new status for human review. */
function pluscities_fact_card_deactivate(string $card_id, string $reason): void
{
    pluscities_db()->prepare('UPDATE fact_cards SET status = ? WHERE id = ?')->execute([$reason, $card_id]);
}
