<?php
/**
 * The Anthropic client. One per service, per CLAUDE.md — everything that talks to
 * the Messages API goes through here.
 *
 * It exists because three callers hand-rolled it and had already drifted: different
 * models (haiku-4-5, sonnet-5, haiku-4-5), different token limits, different timeouts,
 * and two different ways of reading the reply — one of which was wrong in a way that
 * only shows up occasionally (see anthropic_text_of()).
 *
 * Callers ask for a TIER, not a model string. When a model is superseded, this file
 * changes and every caller moves with it; nobody goes hunting for version strings in
 * page handlers. The two tiers are the ones this codebase actually uses:
 *
 *   ANTHROPIC_FAST   short, cheap, high-volume work (keyword lists, per-row enrichment)
 *   ANTHROPIC_SMART  longer or more careful output (schema generation)
 */

if (!defined('ANTHROPIC_FAST'))  define('ANTHROPIC_FAST',  'claude-haiku-4-5-20251001');
if (!defined('ANTHROPIC_SMART')) define('ANTHROPIC_SMART', 'claude-sonnet-5');
if (!defined('ANTHROPIC_API_URL')) define('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages');
if (!defined('ANTHROPIC_VERSION')) define('ANTHROPIC_VERSION', '2023-06-01');
/** Sentinel for "rate limited, retry this one" in batch results. */
if (!defined('ANTHROPIC_RATE')) define('ANTHROPIC_RATE', '__RATE__');

/** The configured key, or '' when the factory has not been given one. */
function anthropic_key(): string
{
    return defined('ANTHROPIC_API_KEY') ? trim((string) ANTHROPIC_API_KEY) : '';
}

/**
 * Can we call at all? Both reasons are worth telling apart in a UI: no key is a
 * settings problem, no cURL is a server problem.
 *
 * @return array{ok:bool,error:string}
 */
function anthropic_ready(): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'PHP cURL extension is not available.'];
    if (anthropic_key() === '')        return ['ok' => false, 'error' => 'ANTHROPIC_API_KEY is not configured (Admin → AI, or config.php).'];
    return ['ok' => true, 'error' => ''];
}

/**
 * Pull the text out of a Messages reply.
 *
 * Concatenates every text block rather than taking content[0], which is what one
 * caller did: a reply whose first block is not text (or that arrives in several
 * blocks) silently yields nothing or a fragment. Costs nothing to do properly.
 */
function anthropic_text_of(?array $json): string
{
    $text = '';
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= (string) ($block['text'] ?? '');
    }
    return $text;
}

/** 429 is the documented rate limit; 529 is "overloaded" and wants the same backoff. */
function anthropic_is_rate_limit(int $code): bool { return $code === 429 || $code === 529; }

/** The request body every call shares. */
function anthropic_payload(string $prompt, array $opts): string
{
    // Plain string content, unless the caller passes an image — vision calls need
    // the multi-block content form. Opt-in per call: every existing caller keeps
    // sending a plain string and gets byte-identical behavior to before this existed.
    $content = $prompt;
    if (!empty($opts['image_base64'])) {
        $content = [
            ['type' => 'image', 'source' => [
                'type'       => 'base64',
                'media_type' => (string) ($opts['image_media_type'] ?? 'image/webp'),
                'data'       => (string) $opts['image_base64'],
            ]],
            ['type' => 'text', 'text' => $prompt],
        ];
    }

    $body = [
        'model'      => (string) ($opts['model'] ?? ANTHROPIC_FAST),
        'max_tokens' => max(1, (int) ($opts['max_tokens'] ?? 1024)),
        'messages'   => [['role' => 'user', 'content' => $content]],
    ];
    if (trim((string) ($opts['system'] ?? '')) !== '') $body['system'] = (string) $opts['system'];

    /**
     * 'no_thinking' => true turns the model's reasoning off for this call.
     *
     * It exists because the SMART tier (claude-sonnet-5) THINKS BY DEFAULT when no
     * thinking field is sent, and thinking is both slow and charged against the same
     * max_tokens as the answer. That is right for judgement work and wrong for
     * "fill in this JSON shape", where it turned a 2-second call into 17.
     *
     * ⚠ Only send this on a model documented to accept it. Sonnet 5 does. It is NOT
     * universally safe: Fable 5 rejects an explicit disable outright, and Opus 5
     * rejects it above 'high' effort — so this stays opt-in per call rather than
     * becoming a default here.
     */
    if (!empty($opts['no_thinking'])) $body['thinking'] = ['type' => 'disabled'];

    // Opt-in server-side tools (currently just web search) — Anthropic runs the
    // search and folds the results into this same call; no client-side tool loop
    // needed. 'max_uses' is the real cost control: it caps how many searches a
    // single call can make, since each search is billed separately from tokens.
    if (!empty($opts['web_search'])) {
        $body['tools'] = [[
            'type'     => 'web_search_20260209',
            'name'     => 'web_search',
            'max_uses' => max(1, (int) ($opts['web_search_max_uses'] ?? 3)),
        ]];
    }

    return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Headers every call shares. */
function anthropic_headers(): array
{
    return [
        'x-api-key: ' . anthropic_key(),
        'anthropic-version: ' . ANTHROPIC_VERSION,
        'content-type: application/json',
    ];
}

/**
 * One message. The whole point: callers get a decided answer, not an HTTP problem.
 *
 * @param array $opts model, max_tokens, timeout, system
 * Rate limiting is reported separately from failure. 429 and 529 mean "ask again
 * shortly", which is a different instruction to the caller than "this did not work",
 * and a batch runner needs to back off rather than mark rows bad.
 *
 * @return array{ok:bool,text:string,error:string,code:int,rate_limited:bool}
 */
function anthropic_message(string $prompt, array $opts = []): array
{
    $ready = anthropic_ready();
    if (!$ready['ok']) return ['ok' => false, 'text' => '', 'error' => $ready['error'], 'code' => 0, 'rate_limited' => false];

    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => anthropic_headers(),
        CURLOPT_POSTFIELDS     => anthropic_payload($prompt, $opts),
        CURLOPT_TIMEOUT        => max(5, (int) ($opts['timeout'] ?? 60)),
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'text' => '', 'error' => $cErr ?: 'Request failed.', 'code' => $code, 'rate_limited' => false];
    $j = json_decode((string) $resp, true);
    if (anthropic_is_rate_limit($code)) {
        return ['ok' => false, 'text' => '', 'error' => 'Rate limited — try again shortly.', 'code' => $code, 'rate_limited' => true];
    }
    if ($code !== 200) {
        // The API says why in error.message; surfacing that beats "HTTP 400".
        $msg = $j['error']['message'] ?? ('HTTP ' . $code);
        return ['ok' => false, 'text' => '', 'error' => $msg, 'code' => $code, 'rate_limited' => false];
    }
    // stop_reason is returned because "the model finished" and "the model was cut
    // off at max_tokens" are different facts and only one of them is a bug. Without
    // it a caller parsing JSON out of the reply sees a syntax error and has no way
    // to tell a truncated answer from a malformed one.
    //
    // ⚠ Watch max_tokens on the SMART tier. It is claude-sonnet-5, and when no
    // `thinking` field is sent that model runs ADAPTIVE THINKING by default —
    // thinking and the visible answer share the max_tokens budget. A limit sized
    // for the answer alone silently truncates it.
    return ['ok' => true, 'text' => anthropic_text_of(is_array($j) ? $j : null), 'error' => '',
            'code' => $code, 'rate_limited' => false,
            'stop_reason' => (string) ($j['stop_reason'] ?? '')];
}

/**
 * Many messages at once, over one curl_multi handle.
 *
 * Keyed in, keyed out, so a caller enriching rows keeps its own identifiers. A failed
 * item is null rather than an exception — one bad row must not lose the batch — and a
 * rate-limited item is ANTHROPIC_RATE, which is a different instruction: retry it, do
 * not mark it bad.
 *
 * Runs at most $opts['concurrency'] at a time, in waves. Firing 500 prompts at once
 * would rate-limit the whole batch and produce nothing but backoff.
 *
 * $opts['on_result'] => function(string|int $key, ?string $text): void — called the
 * MOMENT each individual item finishes (via curl_multi_info_read(), not after the
 * whole wave completes), so a caller with a slow item (e.g. a web-search-backed call
 * that can take 10-40s) can report real progress instead of going silent until every
 * concurrent item in the wave is done. Purely a notification — $out is still built
 * and returned the normal way regardless of whether a callback is given.
 *
 * @param array<string|int,string> $prompts
 * @return array<string|int,?string> same keys; null = failed, ANTHROPIC_RATE = retry
 */
function anthropic_message_many(array $prompts, array $opts = []): array
{
    $out = [];
    foreach ($prompts as $k => $_) $out[$k] = null;
    if (!$prompts) return $out;
    if (!anthropic_ready()['ok']) return $out;

    $timeout  = max(5, (int) ($opts['timeout'] ?? 120));
    $conc     = max(1, (int) ($opts['concurrency'] ?? 6));
    $onResult = is_callable($opts['on_result'] ?? null) ? $opts['on_result'] : null;
    $items    = [];
    foreach ($prompts as $k => $p) $items[] = [$k, (string) $p];
    $n = count($items); $i = 0;

    while ($i < $n) {
        $mh = curl_multi_init();
        $keyOf = [];   // (int) curl handle => the caller's key, since curl_multi_info_read() only gives back the handle
        for ($c = 0; $c < $conc && $i < $n; $c++, $i++) {
            [$k, $prompt] = $items[$i];
            $ch = curl_init(ANTHROPIC_API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => anthropic_headers(),
                CURLOPT_POSTFIELDS     => anthropic_payload($prompt, $opts),
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            curl_multi_add_handle($mh, $ch);
            $keyOf[(int) $ch] = $k;
        }

        $pending = count($keyOf);
        do { $mrc = curl_multi_exec($mh, $running); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        while ($pending > 0) {
            // Process every handle that finished since the last read before blocking
            // again — info_read() drains what's ready right now, it does not itself wait.
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $k  = $keyOf[(int) $ch] ?? null;
                if ($k === null) { curl_multi_remove_handle($mh, $ch); curl_close($ch); continue; }
                $resp = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                if (anthropic_is_rate_limit($code))        { $out[$k] = ANTHROPIC_RATE; }
                elseif ($resp === false || $code !== 200)  { $out[$k] = null; }
                else {
                    $j = json_decode((string) $resp, true);
                    $t = anthropic_text_of(is_array($j) ? $j : null);
                    $out[$k] = $t !== '' ? $t : null;
                }
                if ($onResult) $onResult($k, $out[$k]);
                $pending--;
            }
            if ($pending > 0 && $running) curl_multi_select($mh, 1.0);
            if ($pending > 0) do { $mrc = curl_multi_exec($mh, $running); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        }
        curl_multi_close($mh);
    }
    return $out;
}
