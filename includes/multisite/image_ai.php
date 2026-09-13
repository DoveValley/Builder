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
 *
 * Stores $blockType alongside the prompt — see ms_generate_ai_images_for_domain()'s
 * docblock for why a bare block INDEX turned out not to be safe even on the homepage.
 */
function ms_image_ai_prompt_capture(string $masterDir, string $slotKey, string $blockType, string $prompt): void {
    if (trim($prompt) === '') return;
    $parts = picdrop_parse_key($slotKey);
    if ($parts === null || !in_array($parts['scope'], ['home', 'global'], true)) return;

    $all = ms_image_ai_prompts_load($masterDir);
    $all[$slotKey] = ['prompt' => $prompt, 'block_type' => $blockType];
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
    $out = ['generated' => 0, 'cached' => 0, 'failed' => 0, 'errors' => []];
    $templates = ms_image_ai_prompts_load($masterDir);
    if (!$templates) return $out;

    $siteFile = $workingDir . '/data/site.json';
    $site = json_decode((string) @file_get_contents($siteFile), true);
    if (!is_array($site)) return $out;
    $siteVars = $site['site_vars'] ?? [];

    $cacheDir = $masterDir . '/multisite/cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
    $domainSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(preg_replace('#^https?://#i', '', rtrim($domain, '/'))));
    $domainSlug = trim((string) $domainSlug, '-');
    $cacheFile  = $cacheDir . '/' . $domainSlug . '.image_ai.json';
    $cache = file_exists($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];

    // Generated photos are persisted HERE, outside the working dir — build_one.php
    // deletes the working dir after every run, so a cache check against a path inside
    // it (the old bug) could never hit on a later, separate build; it would silently
    // regenerate and re-bill every single rebuild. This directory is the actual
    // cross-build memory. $cacheFile above is now just bookkeeping (prompt/timestamp).
    $persistDir = $cacheDir . '/images/' . $domainSlug;

    $mediaDir = $workingDir . '/uploads/media/';
    $dirty    = false;

    foreach ($templates as $slotKey => $entry) {
        // Back-compat with the very first (pre-fix) shape, a bare prompt string with no
        // block_type — can't safely resolve those by type, only by the index they were
        // captured with, which is exactly what turned out not to be safe. Treat as a
        // clean failure rather than silently guessing.
        $basePrompt = is_array($entry) ? (string) ($entry['prompt'] ?? '') : (string) $entry;
        $blockType  = is_array($entry) ? (string) ($entry['block_type'] ?? '') : '';

        $parts = picdrop_parse_key($slotKey);
        if ($parts === null || !in_array($parts['scope'], ['home', 'global'], true)) continue;

        // Resolve the block + field this key points at, directly in this domain's own
        // site.json. NOT by the block INDEX the key carries — the homepage's own section
        // order (structure.home) can and does reorder content_blocks per domain, so
        // "index 2" does not reliably mean the same block it meant on the master. Found
        // live: 2 of 5 real test domains had a different block at that index, both
        // failing with "field not present" until this was fixed to match by TYPE + field
        // name instead — the same field name won't collide across an unrelated block
        // type, and this is the same field-name-is-block-specific assumption
        // picdrop_fields() already relies on. Resolved BEFORE the cache check below —
        // even a cache hit must still write the cached photo into THIS build's
        // site.json, since a fresh clone's block field holds the master's placeholder,
        // not last time's generated photo, until that write happens.
        if ($parts['scope'] === 'global') {
            if (!isset($site['services_links']) || !is_array($site['services_links'])) {
                $out['failed']++; $out['errors'][] = "$slotKey: no services_links block in this domain's site.json"; continue;
            }
            $block = &$site['services_links'];
        } else {
            if ($blockType === '') {
                $out['failed']++; $out['errors'][] = "$slotKey: no block_type recorded for this template (captured before this fix) — re-confirm the AI photo in Pic Drop once to re-capture it"; continue;
            }
            // Reference must chain through a bare variable — foreach-by-reference over
            // an expression like `$site['content_blocks'] ?? []` silently breaks the
            // reference back to $site, so a later `$block[$field] = ...` writes into a
            // throwaway copy and never reaches $site at all. Confirmed with a standalone
            // repro before trusting this: the `?? []` form saved a cache entry claiming
            // success while site.json quietly kept the old photo.
            $block = null;
            if (isset($site['content_blocks']) && is_array($site['content_blocks'])) {
                $blocks = &$site['content_blocks'];
                foreach ($blocks as &$candidate) {
                    if (is_array($candidate) && ($candidate['type'] ?? '') === $blockType && array_key_exists($parts['field'], $candidate)) {
                        $block = &$candidate;
                        break;
                    }
                }
                unset($candidate);
            }
            if ($block === null) {
                $out['failed']++; $out['errors'][] = "$slotKey: no '$blockType' block with field '{$parts['field']}' found on this domain's homepage"; continue;
            }
        }

        $field = $parts['field'];
        if (!array_key_exists($field, $block)) {
            $out['failed']++; $out['errors'][] = "$slotKey: field '$field' not present on that block"; continue;
        }

        // Filename is keyed by slot + the MASTER TEMPLATE prompt (not the per-domain
        // filled-in one, which always differs by city/business) — same convention the
        // AI text cache already uses. A domain never changes filename on rebuild, but
        // editing the prompt in Pic Drop changes this hash, so it regenerates once
        // rather than silently reusing a photo made from a prompt Scott has since
        // replaced.
        $filename    = 'ai_' . substr(md5($slotKey . '|' . $basePrompt), 0, 10) . '.webp';
        $persistFile = $persistDir . '/' . $filename;
        $url         = 'uploads/media/' . $filename;

        if (is_file($persistFile)) {
            if (!is_dir($mediaDir)) mkdir($mediaDir, 0775, true);
            if (!copy($persistFile, $mediaDir . $filename)) {
                $out['failed']++; $out['errors'][] = "$slotKey: cached photo exists but could not be copied into this build"; continue;
            }
            $block[$field]   = $url;
            $cache[$slotKey] = ($cache[$slotKey] ?? []) + ['url' => $url];
            $dirty = true;
            $out['cached']++;
            continue;
        }

        $currentValue = (string) $block[$field];
        if (str_contains($currentValue, '{')) {
            $out['failed']++; $out['errors'][] = "$slotKey: current value is a locked {token}, not ours to touch"; continue;
        }

        [$tw, $th] = @getimagesize($workingDir . '/' . ltrim($currentValue, '/')) ?: [0, 0];
        if ($tw < 1 || $th < 1) {
            $out['failed']++; $out['errors'][] = "$slotKey: could not read size of existing photo ($currentValue)"; continue;
        }

        $styleIdx = ms_variant($domain, count(ms_image_ai_style_pool()), 'image_style');
        $prompt   = ms_image_ai_fill_tokens($basePrompt, $siteVars) . ', ' . ms_image_ai_style_pool()[$styleIdx] . '.';

        $size = ($tw > 0 && $th > 0)
            ? ($tw >= $th * 1.2 ? '1536x1024' : ($th >= $tw * 1.2 ? '1024x1536' : '1024x1024'))
            : '1024x1024';

        $r = openai_images_generate($prompt, ['size' => $size, 'quality' => 'medium', 'output_format' => 'webp']);
        if (!$r['ok']) {
            $out['failed']++; $out['errors'][] = "$slotKey: openai_images_generate failed (HTTP {$r['code']}): {$r['error']}"; continue;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'msai');
        file_put_contents($tmpFile, $r['bytes']);
        if (!is_dir($mediaDir)) mkdir($mediaDir, 0775, true);
        $dest = $mediaDir . $filename;
        [$fitOk] = img_fit_to($tmpFile, $dest, 'image/webp', $tw, $th);
        @unlink($tmpFile);
        if (!$fitOk) {
            $out['failed']++; $out['errors'][] = "$slotKey: img_fit_to() could not process the generated image"; continue;
        }

        // Persist OUTSIDE the working dir so the next, separate build of this same
        // domain hits cache instead of re-billing OpenAI — see the note by $persistDir.
        if (!is_dir($persistDir)) mkdir($persistDir, 0775, true);
        copy($dest, $persistFile);

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
