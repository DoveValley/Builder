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
    $biz = 'Recovery Dawn';
    return "You write website copy for {$biz}, " . ($b['business_descriptor'] ?? 'a free informational and referral service that helps people find addiction treatment covered by their health insurance') . ".\n"
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
