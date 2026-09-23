<?php
/**
 * The OpenAI Images client. One per service, per CLAUDE.md — everything that
 * generates an image goes through here; no page or admin script calls the
 * images API directly.
 *
 * Mirrors includes/anthropic.php's shape on purpose (key/ready/payload/call
 * split) so the two service modules read the same way side by side.
 */

if (!defined('OPENAI_IMAGES_API_URL')) define('OPENAI_IMAGES_API_URL', 'https://api.openai.com/v1/images/generations');
if (!defined('OPENAI_IMAGES_EDIT_URL')) define('OPENAI_IMAGES_EDIT_URL', 'https://api.openai.com/v1/images/edits');
/** Centralized so a model rename is a one-line change here, not a hunt through callers. */
if (!defined('OPENAI_IMAGE_MODEL')) define('OPENAI_IMAGE_MODEL', 'gpt-image-2');

/** The exact pixel dimensions behind each of the 3 fixed sizes /images/edits accepts. */
function openai_image_bucket_dims(string $size): array {
    return match ($size) {
        '1536x1024' => [1536, 1024],
        '1024x1536' => [1024, 1536],
        default     => [1024, 1024],
    };
}

/** The configured key, or '' when the factory has not been given one. */
function openai_images_key(): string
{
    return defined('OPENAI_API_KEY') ? trim((string) OPENAI_API_KEY) : '';
}

/**
 * Can we call at all? Both reasons are worth telling apart in a UI: no key is a
 * settings problem, no cURL is a server problem.
 *
 * @return array{ok:bool,error:string}
 */
function openai_images_ready(): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'PHP cURL extension is not available.'];
    if (openai_images_key() === '')    return ['ok' => false, 'error' => 'OPENAI_API_KEY is not configured (Admin → AI, or config.php).'];
    return ['ok' => true, 'error' => ''];
}

/**
 * The request body every call shares.
 *
 * @param array $opts model, n, size, quality, output_format, background, moderation
 */
function openai_images_payload(string $prompt, array $opts): string
{
    $body = [
        'model'  => (string) ($opts['model'] ?? OPENAI_IMAGE_MODEL),
        'prompt' => $prompt,
        'n'      => max(1, (int) ($opts['n'] ?? 1)),
    ];
    // Every other field is genuinely optional on the API — only send what the
    // caller actually asked for, so "auto" defaults on OpenAI's side stay in effect.
    foreach (['size', 'quality', 'output_format', 'background', 'moderation'] as $k) {
        if (isset($opts[$k]) && $opts[$k] !== '') $body[$k] = $opts[$k];
    }
    if (isset($opts['output_compression'])) $body['output_compression'] = (int) $opts['output_compression'];

    return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Headers every call shares. */
function openai_images_headers(): array
{
    return [
        'Authorization: Bearer ' . openai_images_key(),
        'Content-type: application/json',
    ];
}

/**
 * Builds (but does not execute) a ready-to-run curl handle for one /images/generations
 * request. Shared by openai_images_generate() (executes it directly) and
 * openai_images_generate_many()'s job-builder (hands it, unexecuted, to the
 * concurrency engine) — this was previously duplicated between the two.
 */
function openai_images_build_generate_ch(string $prompt, array $opts)
{
    $ch = curl_init(OPENAI_IMAGES_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => openai_images_headers(),
        CURLOPT_POSTFIELDS     => openai_images_payload($prompt, $opts),
        CURLOPT_TIMEOUT        => max(10, (int) ($opts['timeout'] ?? 90)),
    ]);
    return $ch;
}

/**
 * Builds (but does not execute) a ready-to-run curl handle for one /images/edits
 * (multipart, edit-from-reference) request. Shared by openai_images_edit() and
 * openai_images_edit_many()'s job-builder — this was previously duplicated between
 * the two.
 */
function openai_images_build_edit_ch(string $prompt, string $refImagePath, array $opts)
{
    $mime = (string) (@getimagesize($refImagePath)['mime'] ?? 'image/webp');
    $fields = [
        'model'  => (string) ($opts['model'] ?? OPENAI_IMAGE_MODEL),
        'prompt' => $prompt,
        'image'  => new CURLFile($refImagePath, $mime, basename($refImagePath)),
    ];
    foreach (['size', 'quality', 'output_format', 'input_fidelity'] as $k) {
        if (isset($opts[$k]) && $opts[$k] !== '') $fields[$k] = $opts[$k];
    }
    $ch = curl_init(OPENAI_IMAGES_EDIT_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        // No content-type header — cURL sets the multipart boundary itself when
        // POSTFIELDS is an array containing a CURLFile. Forcing a JSON content-type
        // (openai_images_headers() does that) would break the upload silently.
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . openai_images_key()],
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_TIMEOUT        => max(10, (int) ($opts['timeout'] ?? 90)),
    ]);
    return $ch;
}

/**
 * One image. The whole point: callers get decoded bytes or a decided error,
 * never a raw HTTP/base64 problem to untangle themselves.
 *
 * @param array $opts model, n, size, quality, output_format, background, moderation, timeout
 * @return array{ok:bool,bytes:string,format:string,revised_prompt:string,error:string,code:int}
 */
function openai_images_generate(string $prompt, array $opts = []): array
{
    $fail = fn(string $err, int $code = 0) =>
        ['ok' => false, 'bytes' => '', 'format' => '', 'revised_prompt' => '', 'error' => $err, 'code' => $code];

    $ready = openai_images_ready();
    if (!$ready['ok']) return $fail($ready['error']);

    $ch = openai_images_build_generate_ch($prompt, $opts);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr = curl_error($ch);
    curl_close($ch);

    return openai_images_parse_response($resp, $code, $cErr, $opts);
}

/**
 * Generate several images at once instead of one-after-another. A domain with 30+
 * approved prompts (the multisite AI-photo step) took real minutes waiting on each
 * request to fully finish before starting the next — this runs up to $concurrency
 * of them concurrently via curl_multi, so wall-clock time is roughly (count /
 * concurrency) requests instead of (count) requests.
 *
 * PHP itself stays single-threaded throughout — curl_multi overlaps NETWORK WAITS,
 * not code execution, so results still arrive and get processed one at a time.
 * Callers doing file writes / cache updates from $onEach or the returned array get
 * that for free; no locking needed.
 *
 * @param array         $jobs        list of ['prompt' => string, 'opts' => array]
 * @param int           $concurrency how many requests may be in flight at once
 * @param callable|null $onEach      called (int $index, array $result) as each
 *                                   finishes, for progress reporting — optional
 * @return array indexed exactly like $jobs; each element is the same shape
 *               openai_images_generate() returns
 */
function openai_images_generate_many(array $jobs, int $concurrency = 5, ?callable $onEach = null): array
{
    return openai_images_run_concurrent(
        $jobs, $concurrency, $onEach,
        fn(array $job) => openai_images_build_generate_ch($job['prompt'], $job['opts'] ?? [])
    );
}

/**
 * Same idea as openai_images_generate_many(), but for edits FROM a reference image
 * — several "keep this photo, change one thing" requests at once instead of one at
 * a time. Multipart (CURLFile), not JSON, so it gets its own handle-builder; the
 * concurrency engine underneath is shared with the plain-generate version.
 *
 * @param array $jobs list of ['prompt' => string, 'ref_path' => string, 'opts' => array]
 */
function openai_images_edit_many(array $jobs, int $concurrency = 5, ?callable $onEach = null): array
{
    return openai_images_run_concurrent(
        $jobs, $concurrency, $onEach,
        fn(array $job) => openai_images_build_edit_ch($job['prompt'], $job['ref_path'], $job['opts'] ?? [])
    );
}

/**
 * The concurrency engine shared by openai_images_generate_many() and
 * openai_images_edit_many() — a bounded curl_multi sliding window. $buildHandle
 * turns one job into a ready-to-run curl handle; everything about HOW the request
 * is shaped (JSON vs multipart, which endpoint) lives in the caller, not here.
 */
function openai_images_run_concurrent(array $jobs, int $concurrency, ?callable $onEach, callable $buildHandle): array
{
    $fail = fn(string $err, int $code = 0) =>
        ['ok' => false, 'bytes' => '', 'format' => '', 'revised_prompt' => '', 'error' => $err, 'code' => $code];

    $n = count($jobs);
    $results = array_fill(0, $n, null);
    if ($n === 0) return $results;

    $ready = openai_images_ready();
    if (!$ready['ok']) {
        foreach ($jobs as $i => $j) { $results[$i] = $fail($ready['error']); if ($onEach) $onEach($i, $results[$i]); }
        return $results;
    }

    $concurrency = max(1, min($concurrency, $n));
    $mh     = curl_multi_init();
    $active = [];   // (int) curl handle => job index
    $next   = 0;

    $addNext = function () use (&$next, &$active, $n, $mh, $buildHandle, $jobs) {
        if ($next >= $n) return;
        $ch = $buildHandle($jobs[$next]);
        curl_multi_add_handle($mh, $ch);
        $active[(int) $ch] = $next;
        $next++;
    };
    for ($k = 0; $k < $concurrency; $k++) $addNext();

    do {
        do { $status = curl_multi_exec($mh, $stillRunning); } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($stillRunning) curl_multi_select($mh, 1.0);

        while (($info = curl_multi_info_read($mh)) !== false) {
            $ch = $info['handle'];
            $i  = $active[(int) $ch] ?? null;
            unset($active[(int) $ch]);
            if ($i === null) { curl_multi_remove_handle($mh, $ch); curl_close($ch); continue; }

            $resp = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cErr = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $results[$i] = openai_images_parse_response($resp === false ? false : $resp, $code, $cErr, $jobs[$i]['opts'] ?? []);
            if ($onEach) $onEach($i, $results[$i]);

            $addNext();   // keep the window full until the queue is empty
        }
    } while ($stillRunning > 0 || count($active) > 0);

    curl_multi_close($mh);
    return $results;
}

/** Shared by generate() and edit() — same reply shape from both endpoints. */
function openai_images_parse_response($resp, int $code, string $cErr, array $opts): array
{
    $fail = fn(string $err, int $code = 0) =>
        ['ok' => false, 'bytes' => '', 'format' => '', 'revised_prompt' => '', 'error' => $err, 'code' => $code];

    if ($resp === false) return $fail($cErr ?: 'Request failed.', $code);

    $j = json_decode((string) $resp, true);
    if ($code !== 200) {
        // The API says why in error.message; surfacing that beats "HTTP 400".
        $msg = $j['error']['message'] ?? ('HTTP ' . $code);
        return $fail((string) $msg, $code);
    }

    $b64 = $j['data'][0]['b64_json'] ?? '';
    if ($b64 === '') return $fail('No image data in response.', $code);

    $bytes = base64_decode($b64, true);
    if ($bytes === false) return $fail('Response image data was not valid base64.', $code);

    return [
        'ok'             => true,
        'bytes'          => $bytes,
        'format'         => (string) ($opts['output_format'] ?? 'png'),
        'revised_prompt' => (string) ($j['data'][0]['revised_prompt'] ?? ''),
        'error'          => '',
        'code'           => $code,
    ];
}

/**
 * One image, generated FROM a reference image plus instructions — "same subject/
 * style, but change X" — instead of describing a scene from nothing. Real OpenAI
 * capability (/v1/images/edits), confirmed working with a live call before this
 * was wired in: same technician, same pose, same uniform, different equipment.
 *
 * @param string $refImagePath absolute path to an existing image on disk
 * @param array  $opts model, size, quality, output_format, input_fidelity, timeout
 * @return array{ok:bool,bytes:string,format:string,revised_prompt:string,error:string,code:int}
 */
function openai_images_edit(string $prompt, string $refImagePath, array $opts = []): array
{
    $fail = fn(string $err, int $code = 0) =>
        ['ok' => false, 'bytes' => '', 'format' => '', 'revised_prompt' => '', 'error' => $err, 'code' => $code];

    $ready = openai_images_ready();
    if (!$ready['ok']) return $fail($ready['error']);
    if (!is_file($refImagePath)) return $fail('Reference image not found on disk.');

    $ch = openai_images_build_edit_ch($prompt, $refImagePath, $opts);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr = curl_error($ch);
    curl_close($ch);

    return openai_images_parse_response($resp, $code, $cErr, $opts);
}

/**
 * Rough per-image $ estimate for gpt-image-2, for display only — OpenAI bills by
 * output image tokens, not a published per-image table, so this is a reasonable
 * approximation to show alongside a Generate button, not an invoice.
 */
function openai_images_estimate_cost(string $size, string $quality): float
{
    $wide = ($size === '1536x1024' || $size === '1024x1536');
    return match ($quality) {
        'low'    => $wide ? 0.011 : 0.011,
        'high'   => $wide ? 0.165 : 0.211,
        default  => $wide ? 0.041 : 0.053, // medium, and 'auto' assumed medium
    };
}
