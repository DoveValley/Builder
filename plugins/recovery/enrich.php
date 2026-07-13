<?php
/**
 * Recovery plugin — per-entity AI enrichment.
 *
 * Generates the archetype content set (hero_subtext, intro_html, feature_points, faq,
 * local_note) for each city / state / carrier ONCE, grounded by the niche brief's YMYL
 * guardrails. Stored on each row's `ai` field; composed onto matrix pages by pages.php.
 * Plugin-owned Anthropic client (mirrors generate.py) — no factory AI code touched.
 */

require_once __DIR__ . '/data.php';

/** Raw Anthropic messages call. Returns text, '__RATE__' on 429, or null on failure. */
function recovery_ai_call(string $prompt, int $maxTokens = 1500): ?string {
    $key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if ($key === '') return null;
    $body = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => $maxTokens,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return null;
    if ($code === 429 || $code === 529) return '__RATE__';
    if ($code !== 200) return null;
    $j = json_decode($resp, true);
    return $j['content'][0]['text'] ?? null;
}

/**
 * Concurrent Anthropic calls via curl_multi. $prompts = [key => promptString].
 * Returns [key => text | '__RATE__' | null], running at most $conc requests at once.
 * Single process → single writer for callers (no file race). Used by the parallel
 * enrichment runner; the sequential recovery_ai_call() is still used for smoke tests.
 */
function recovery_ai_call_many(array $prompts, int $conc = 6, int $maxTokens = 1900): array {
    $key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if ($key === '') return array_fill_keys(array_keys($prompts), null);
    $items = [];
    foreach ($prompts as $k => $p) $items[] = [$k, $p];
    $n = count($items); $i = 0; $out = [];
    $hdr = ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    while ($i < $n) {
        $mh = curl_multi_init();
        $batch = [];
        for ($c = 0; $c < $conc && $i < $n; $c++, $i++) {
            [$k, $p] = $items[$i];
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['model' => 'claude-haiku-4-5-20251001', 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $p]]]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => $hdr,
            ]);
            curl_multi_add_handle($mh, $ch);
            $batch[$k] = $ch;
        }
        do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
        foreach ($batch as $k => $ch) {
            $resp = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            if ($code === 429 || $code === 529)      $out[$k] = '__RATE__';
            elseif ($code !== 200 || $resp === false) $out[$k] = null;
            else { $j = json_decode($resp, true); $out[$k] = $j['content'][0]['text'] ?? null; }
        }
        curl_multi_close($mh);
    }
    return $out;
}

/** Parse+normalize a batch of intersection replies. $texts = [key => text|__RATE__|null]. */
function recovery_ai_parse_intersections(array $texts): array {
    $out = [];
    foreach ($texts as $k => $t) {
        if ($t === null || $t === '__RATE__') { $out[$k] = $t; continue; }
        $d = recovery_ai_json($t);
        $out[$k] = ($d && !empty($d['intro_html'])) ? _recovery_ai_normalize($d) : null;
    }
    return $out;
}

/** Extract a JSON object from a model reply (tolerates code fences / prose). */
function recovery_ai_json(string $text): ?array {
    $text = trim($text);
    $s = strpos($text, '{');
    $e = strrpos($text, '}');
    if ($s === false || $e === false || $e < $s) return null;
    $d = json_decode(substr($text, $s, $e - $s + 1), true);
    return is_array($d) ? $d : null;
}

/** Guardrail + tone context pulled from the site's niche brief. */
function recovery_ai_brief(): string {
    $f = (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR !== '') ? ACTIVE_SITE_DIR . '/multisite/niche_brief.json' : '';
    $b = ($f && is_file($f)) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    // Brand name is shortcode-driven: the model must write the literal token "{business}"
    // wherever it refers to the service by name, so copy survives any future rebrand.
    return "You write website copy for {business}, " . ($b['business_descriptor'] ?? 'a free informational and referral service that helps people find addiction treatment covered by their health insurance') . ".\n"
        . "BRAND NAME: whenever you refer to the service by name, write the EXACT literal token \"{business}\" (with the curly braces) — never invent or spell out a brand name.\n"
        . "TONE: " . ($b['tone'] ?? 'compassionate, hopeful, stigma-free, person-first') . ".\n"
        . "HARD RULES: " . ($b['guardrails'] ?? '') . "\n"
        . "Also: never invent specific treatment centers, facility names, addresses, statistics, success rates, or prices. Do not guarantee coverage or admission — say benefits must be verified. Keep it concrete and useful, not fluffy. US English.";
}

/**
 * Build the prompt for one entity. $type = city|state|carrier.
 * City/state copy must NOT name a specific insurance carrier; carrier copy must NOT
 * name a specific city — so the pieces compose cleanly on intersection pages.
 */
function recovery_ai_prompt(string $type, string $primary, string $secondary = ''): string {
    $brief = recovery_ai_brief();
    if ($type === 'carrier') {
        $subject = "the insurance carrier \"$primary\" and its coverage for addiction and substance-use treatment (national, general — do NOT mention any specific city or state)";
    } elseif ($type === 'state') {
        $subject = "finding drug and alcohol rehab that accepts insurance in the state of $primary (state-level: access, parity, general options — do NOT name a specific insurance carrier or a specific city)";
    } else { // city
        $subject = "finding drug and alcohol rehab that accepts insurance in $primary, $secondary (local framing — do NOT name a specific insurance carrier)";
    }
    return $brief . "\n\n"
        . "Write website content about: $subject.\n\n"
        . "Respond with ONLY a JSON object, no prose, in this exact shape:\n"
        . "{\n"
        . "  \"hero_subtext\": \"one sentence, ~20-30 words\",\n"
        . "  \"intro_html\": \"2 short paragraphs wrapped in <p>...</p> tags\",\n"
        . "  \"feature_points\": [ {\"heading\": \"3-5 words\", \"text\": \"1 sentence\"}, (exactly 3 items) ],\n"
        . "  \"faq\": [ {\"q\": \"question\", \"a\": \"1-3 sentence answer\"}, (exactly 4 items) ],\n"
        . "  \"local_note\": \"2 sentences for a map sidebar\"\n"
        . "}";
}

/**
 * Prompt for a UNIQUE city×carrier (or state×carrier) intersection page. Unlike the
 * per-entity prompts (which are deliberately carrier-blind or city-blind so fragments
 * compose), this one names BOTH so the copy is specific to the exact intersection —
 * killing the cross-page boilerplate that composed fragments produce.
 */
function recovery_ai_prompt_intersection(string $carrier, string $city, string $ss, string $state): string {
    $brief  = recovery_ai_brief();
    $where  = $city !== '' ? "$city, $ss" : $state;
    $scope  = $city !== '' ? "in and around $where" : "across the state of $where";
    $geoTok = $city !== '' ? '{city}, {SS}' : '{state}';
    return $brief . "\n\n"
        . "Write UNIQUE, page-specific website copy for a directory page about finding drug and alcohol rehab that accepts \"$carrier\" insurance $scope.\n"
        . "This page targets the SPECIFIC intersection of ONE carrier ($carrier) AND ONE location ($where). Make every part specific to BOTH — reference $carrier by name and the $where area naturally. Vary sentence structure so this reads differently from other carrier/city pages.\n"
        . "LOCATION TOKENS: whenever you name the location, write the literal token " . ($city !== '' ? '{city} for the city and {state} for the state (with braces)' : '{state} for the state (with braces)') . " instead of spelling it out — e.g. write \"rehab in $geoTok\". This keeps the copy portable.\n"
        . "Do NOT invent facility names, addresses, statistics, success rates, or prices. Do not guarantee coverage or admission — say benefits must be verified. US English.\n\n"
        . "Respond with ONLY a JSON object, no prose, in this exact shape:\n"
        . "{\n"
        . "  \"intro_html\": \"2 short <p> paragraphs on $carrier coverage for addiction treatment $scope\",\n"
        . "  \"intro2_html\": \"2 short <p> paragraphs on using $carrier benefits and the local treatment landscape near $where\",\n"
        . "  \"feature_points\": [ {\"heading\": \"3-5 words\", \"text\": \"1 sentence\"}, (exactly 3 items) ],\n"
        . "  \"faq\": [ {\"q\": \"question naming $carrier and $where\", \"a\": \"1-3 sentence answer\"}, (exactly 4 items) ],\n"
        . "  \"local_note\": \"2 sentences for a map sidebar about $carrier rehab options near $where\"\n"
        . "}";
}

/** Normalize the shared bundle shape (used by both entity + intersection generators). */
function _recovery_ai_normalize(array $d): array {
    return [
        'intro_html'     => trim((string)($d['intro_html'] ?? '')),
        'intro2_html'    => trim((string)($d['intro2_html'] ?? '')),
        'hero_subtext'   => trim((string)($d['hero_subtext'] ?? '')),
        'feature_points' => array_values(array_filter(array_map(fn($x) => [
                                'heading' => trim((string)($x['heading'] ?? '')),
                                'text'    => trim((string)($x['text'] ?? '')),
                            ], is_array($d['feature_points'] ?? null) ? $d['feature_points'] : []), fn($x) => $x['heading'] !== '')),
        'faq'            => array_values(array_filter(array_map(fn($x) => [
                                'q' => trim((string)($x['q'] ?? '')),
                                'a' => trim((string)($x['a'] ?? '')),
                            ], is_array($d['faq'] ?? null) ? $d['faq'] : []), fn($x) => $x['q'] !== '' && $x['a'] !== '')),
        'local_note'     => trim((string)($d['local_note'] ?? '')),
    ];
}

/** Generate one intersection bundle (city×carrier or state×carrier), or null on failure. */
function recovery_ai_generate_intersection(string $carrier, string $city, string $ss, string $state): ?array {
    $txt = recovery_ai_call(recovery_ai_prompt_intersection($carrier, $city, $ss, $state), 1900);
    if ($txt === null || $txt === '__RATE__') return $txt === '__RATE__' ? ['__rate__' => true] : null;
    $d = recovery_ai_json($txt);
    if (!$d || empty($d['intro_html'])) return null;
    return _recovery_ai_normalize($d);
}

/** Generate the ai bundle for one entity, or null on failure. */
function recovery_ai_generate(string $type, string $primary, string $secondary = ''): ?array {
    $txt = recovery_ai_call(recovery_ai_prompt($type, $primary, $secondary));
    if ($txt === null || $txt === '__RATE__') return $txt === '__RATE__' ? ['__rate__' => true] : null;
    $d = recovery_ai_json($txt);
    if (!$d || empty($d['intro_html'])) return null;
    // normalize
    return [
        'hero_subtext'   => trim((string)($d['hero_subtext'] ?? '')),
        'intro_html'     => trim((string)($d['intro_html'] ?? '')),
        'feature_points' => array_values(array_filter(array_map(fn($x) => [
                                'heading' => trim((string)($x['heading'] ?? '')),
                                'text'    => trim((string)($x['text'] ?? '')),
                            ], is_array($d['feature_points'] ?? null) ? $d['feature_points'] : []), fn($x) => $x['heading'] !== '')),
        'faq'            => array_values(array_filter(array_map(fn($x) => [
                                'q' => trim((string)($x['q'] ?? '')),
                                'a' => trim((string)($x['a'] ?? '')),
                            ], is_array($d['faq'] ?? null) ? $d['faq'] : []), fn($x) => $x['q'] !== '' && $x['a'] !== '')),
        'local_note'     => trim((string)($d['local_note'] ?? '')),
    ];
}
