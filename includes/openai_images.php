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
/** Centralized so a model rename is a one-line change here, not a hunt through callers. */
if (!defined('OPENAI_IMAGE_MODEL')) define('OPENAI_IMAGE_MODEL', 'gpt-image-2.5-sunburst');

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

    $ch = curl_init(OPENAI_IMAGES_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => openai_images_headers(),
        CURLOPT_POSTFIELDS     => openai_images_payload($prompt, $opts),
        CURLOPT_TIMEOUT        => max(10, (int) ($opts['timeout'] ?? 90)),
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr = curl_error($ch);
    curl_close($ch);

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
