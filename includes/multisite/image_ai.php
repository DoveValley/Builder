<?php
/**
 * AI photo generation at batch build time — the automated counterpart to Pic Drop's
 * manual "generate, review, use" flow.
 *
 * The moment an AI photo is confirmed in Pic Drop (picdrop_api.php), the prompt that
 * produced it is captured here as that slot's standing template for the master
 * (multisite/image_prompts.json). A batch run with images.ai_photos on then fills in
 * this domain's own {city}/{SS}/{business} and a domain-seeded style phrase, generates
 * once, and caches the result — same "never re-bill a rebuild" rule as every other
 * per-domain AI step in this pipeline.
 *
 * SCOPE: home-page and site-wide (services_links) slots only. A landing page's block
 * order can shift under page-pool pruning, and trusting a block INDEX there without
 * checking it still means the same field would risk writing an image into the wrong
 * spot. Not a silent gap — the batch card says exactly this.
 */

require_once __DIR__ . '/../openai_images.php';
require_once __DIR__ . '/../picdrop.php';   // picdrop_parse_key()
require_once __DIR__ . '/../media_lib.php'; // img_fit_to()
require_once __DIR__ . '/../layout_variations.php'; // ms_variant()

function ms_image_ai_prompts_file(string $masterDir): string {
    return $masterDir . '/multisite/image_prompts.json';
}

function ms_image_ai_prompts_load(string $masterDir): array {
    $f = ms_image_ai_prompts_file($masterDir);
    if (!file_exists($f)) return [];
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

/**
 * Called from Pic Drop the instant an AI photo is confirmed for a home/global slot —
 * records the prompt that produced it as this slot's standing template for every
 * future domain build. Landing/core slots are silently ignored here on purpose: they
 * are out of batch scope (see module docblock), so there is nothing to remember yet.
 */
function ms_image_ai_prompt_capture(string $masterDir, string $slotKey, string $prompt): void {
    if (trim($prompt) === '') return;
    $parts = picdrop_parse_key($slotKey);
    if ($parts === null || !in_array($parts['scope'], ['home', 'global'], true)) return;

    $all = ms_image_ai_prompts_load($masterDir);
    $all[$slotKey] = $prompt;
    $f = ms_image_ai_prompts_file($masterDir);
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true);
    file_put_contents($f, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * A small, fixed pool, picked deterministically per domain (ms_variant, own salt) —
 * so two domains sharing a template don't read as the same photo shoot, but the same
 * domain never changes pick on a rebuild.
 */
function ms_image_ai_style_pool(): array {
    return [
        'natural morning light, soft shadows',
        'bright midday light, clean and crisp',
        'warm late-afternoon light, golden tone',
        'overcast daylight, even and neutral',
        'early-evening light, warm indoor glow',
        'cool north-facing window light',
    ];
}

/** Only the tokens virtually every site_vars carries — see module docblock: an
 *  unresolved {token} is left visible on purpose rather than guessed at, the same
 *  way the SEO gate already treats an unresolved shortcode as a real bug to catch. */
function ms_image_ai_fill_tokens(string $prompt, array $siteVars): string {
    return strtr($prompt, [
        '{business}' => (string) ($siteVars['business'] ?? ''),
        '{city}'     => (string) ($siteVars['city'] ?? ''),
        '{SS}'       => (string) ($siteVars['SS'] ?? ''),
    ]);
}

/**
 * Generates (or reuses a cached) AI photo for every home/global slot the master has a
 * locked prompt for, writing each straight into the working dir's own site.json.
 *
 * @return array{generated:int,cached:int,failed:int}
 */
function ms_generate_ai_images_for_domain(string $workingDir, string $domain, string $masterDir): array {
    $out = ['generated' => 0, 'cached' => 0, 'failed' => 0];
    $templates = ms_image_ai_prompts_load($masterDir);
    if (!$templates) return $out;

    $siteFile = $workingDir . '/data/site.json';
    $site = json_decode((string) @file_get_contents($siteFile), true);
    if (!is_array($site)) return $out;
    $siteVars = $site['site_vars'] ?? [];

    $cacheDir = $masterDir . '/multisite/cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
    $domainSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(preg_replace('#^https?://#i', '', rtrim($domain, '/'))));
    $cacheFile  = $cacheDir . '/' . trim((string) $domainSlug, '-') . '.image_ai.json';
    $cache = file_exists($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];

    $mediaDir = $workingDir . '/uploads/media/';
    $dirty    = false;

    foreach ($templates as $slotKey => $basePrompt) {
        $parts = picdrop_parse_key($slotKey);
        if ($parts === null || !in_array($parts['scope'], ['home', 'global'], true)) continue;

        if (!empty($cache[$slotKey]['url']) && is_file($workingDir . '/' . $cache[$slotKey]['url'])) {
            $out['cached']++;
            continue;
        }

        // Resolve the block + field this key points at, directly in this domain's own
        // site.json. Home/global scope only (see module docblock), so the block index
        // the key carries still means the same thing it did on the master.
        if ($parts['scope'] === 'global') {
            if (!isset($site['services_links']) || !is_array($site['services_links'])) { $out['failed']++; continue; }
            $block = &$site['services_links'];
        } else {
            if (!isset($site['content_blocks'][$parts['block']]) || !is_array($site['content_blocks'][$parts['block']])) {
                $out['failed']++; continue;
            }
            $block = &$site['content_blocks'][$parts['block']];
        }

        $field = $parts['field'];
        if (!array_key_exists($field, $block)) { $out['failed']++; continue; }
        $currentValue = (string) $block[$field];
        if (str_contains($currentValue, '{')) { $out['failed']++; continue; } // locked per-city token, not ours to touch

        [$tw, $th] = @getimagesize($workingDir . '/' . ltrim($currentValue, '/')) ?: [0, 0];
        if ($tw < 1 || $th < 1) { $out['failed']++; continue; } // no existing photo to size against

        $styleIdx = ms_variant($domain, count(ms_image_ai_style_pool()), 'image_style');
        $prompt   = ms_image_ai_fill_tokens($basePrompt, $siteVars) . ', ' . ms_image_ai_style_pool()[$styleIdx] . '.';

        $size = ($tw > 0 && $th > 0)
            ? ($tw >= $th * 1.2 ? '1536x1024' : ($th >= $tw * 1.2 ? '1024x1536' : '1024x1024'))
            : '1024x1024';

        $r = openai_images_generate($prompt, ['size' => $size, 'quality' => 'medium', 'output_format' => 'webp']);
        if (!$r['ok']) { $out['failed']++; continue; }

        $tmpFile = tempnam(sys_get_temp_dir(), 'msai');
        file_put_contents($tmpFile, $r['bytes']);
        if (!is_dir($mediaDir)) mkdir($mediaDir, 0775, true);
        $filename = 'ai_' . substr(md5($slotKey), 0, 6) . '_' . substr(md5(uniqid('', true)), 0, 6) . '.webp';
        $dest = $mediaDir . $filename;
        [$fitOk] = img_fit_to($tmpFile, $dest, 'image/webp', $tw, $th);
        @unlink($tmpFile);
        if (!$fitOk) { $out['failed']++; continue; }

        $url = 'uploads/media/' . $filename;
        $block[$field]   = $url;
        $cache[$slotKey] = ['url' => $url, 'prompt' => $prompt, 'generated_at' => date('Y-m-d H:i:s')];
        $dirty = true;
        $out['generated']++;
    }
    unset($block);

    if ($dirty) {
        file_put_contents($siteFile, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if ($dirty || $out['failed'] > 0) {
        file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    return $out;
}
