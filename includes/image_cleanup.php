<?php
/**
 * Image Name Cleanup — core logic (admin/tabs/media.php, "Image Name Cleanup" card).
 *
 * What this does, and why it's built this way:
 *
 *   1. SCAN — find every image under uploads/media/ that isn't referenced anywhere
 *      in the site's data. No AI involved; this is a plain reference count, the
 *      same kind of check media_api.php's "usage" action already does for the
 *      Unused badge. Deliberately a separate, small implementation here rather
 *      than refactoring that already-working code — this feature must not risk
 *      breaking the existing Media Library duplicate-finder/usage badges.
 *
 *   2. ANALYZE (real AI cost) — for every REFERENCED content image, send the
 *      actual photo to Claude with the page(s)/block(s) it's used on as context,
 *      and ask three things: what's really in the photo, does it genuinely match
 *      that context, and a clean filename (one real topic keyword + honest
 *      description + city — never several keywords stacked, that reads as spam,
 *      not SEO). A mismatch is flagged, never silently forced to match — a false
 *      keyword on the wrong photo is worse than an honest generic name.
 *
 *   3. NOTHING RENAMES ITSELF. Every result is a proposal shown in a review
 *      table; only images you tick and click Apply for actually change. Orphan
 *      deletion re-checks that a file is still unreferenced at apply time (data
 *      can change between scan and apply), never trusts the scan snapshot blindly.
 *
 *   4. APPLY — rename the file, update the matching media.json entry (if any),
 *      and rewrite every reference across site.json + every generated page file
 *      in one pass. Each file is re-validated as JSON after the edit; a file that
 *      would come out broken is left untouched and reported, never half-written.
 *
 * Logo/icon/favicon/badge/sprite files are excluded from analysis (same exclusion
 * list already used by ms_is_content_image() in the multisite image pipeline) —
 * there's nothing for "does this match the page's topic" to mean for a logo.
 */

require_once __DIR__ . '/anthropic.php';

/** Filenames that don't need a topic match — logos, icons, favicons, etc. */
function imgclean_is_content_file(string $filename): bool
{
    if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $filename)) return false;
    $base = strtolower($filename);
    foreach (['logo', 'icon', 'favicon', 'badge', 'sprite'] as $ex) {
        if (strpos($base, $ex) !== false) return false;
    }
    return true;
}

/**
 * Every image referenced anywhere in this site's data, mapped to the page
 * titles/context it appears on. Deliberately mirrors media_api.php's own
 * 'usage' action in shape (same "which page, which block" idea) but is its
 * own small implementation — see file docblock for why.
 *
 * @return array<string,string[]> "uploads/media/x.webp" => ["Page Title", ...]
 */
function imgclean_usage_map(array $siteData): array
{
    $usage = [];
    $add = function (string $path, string $context) use (&$usage): void {
        if (!in_array($context, $usage[$path] ?? [], true)) {
            $usage[$path][] = $context;
        }
    };
    $scanBlocks = function (array $blocks, string $pageLabel) use ($add): void {
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            array_walk_recursive($block, function ($v) use ($pageLabel, $add): void {
                if (is_string($v) && preg_match('/\.(jpe?g|png|webp|gif)$/i', $v)
                    && (str_starts_with($v, 'uploads/') || str_starts_with($v, 'sites/'))) {
                    $add($v, $pageLabel);
                }
            });
        }
    };

    if (isset($siteData['content_blocks'])) $scanBlocks($siteData['content_blocks'], 'Home');

    foreach (($siteData['pages'] ?? []) as $page) {
        if (!is_array($page)) continue;
        $label = trim(preg_replace('/\s*[|\-–—].*$/', '', (string) ($page['title'] ?? $page['slug'] ?? 'Page')));
        $scanBlocks($page['content_blocks'] ?? [], $label);
    }

    if (defined('PAGES_DIR')) {
        foreach (glob(PAGES_DIR . '*.json') ?: [] as $pageFile) {
            $page = json_decode((string) @file_get_contents($pageFile), true);
            if (!is_array($page)) continue;
            $label = trim(preg_replace('/\s*[|\-–—].*$/', '', (string) ($page['title'] ?? $page['slug'] ?? basename($pageFile))));
            $scanBlocks($page['content_blocks'] ?? [], $label);
        }
    }

    // array_walk_recursive() requires an addressable variable, not an expression —
    // "$siteData['header'] ?? []" is a temporary value and fatals with "could not
    // be passed by reference". Assign to a real local first.
    $headerData = $siteData['header'] ?? [];
    array_walk_recursive($headerData, function ($v) use ($add): void {
        if (is_string($v) && preg_match('/\.(jpe?g|png|webp|gif)$/i', $v) && str_starts_with($v, 'uploads/')) {
            $add($v, 'Global — Header');
        }
    });
    $footerData = $siteData['footer'] ?? [];
    array_walk_recursive($footerData, function ($v) use ($add): void {
        if (is_string($v) && preg_match('/\.(jpe?g|png|webp|gif)$/i', $v) && str_starts_with($v, 'uploads/')) {
            $add($v, 'Global — Footer');
        }
    });

    return $usage;
}

/** Every file physically in uploads/media/, basename only. */
function imgclean_all_media_files(): array
{
    if (!defined('MEDIA_DIR') || !is_dir(MEDIA_DIR)) return [];
    $files = [];
    foreach (scandir(MEDIA_DIR) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        if (is_file(MEDIA_DIR . $f)) $files[] = $f;
    }
    sort($files);
    return $files;
}

/**
 * Split every media file into referenced vs. orphaned, using the SAME usage map
 * scan every time it's needed (scan and apply both call this fresh — never a
 * stale cached list) so a file that became referenced (or stopped being) between
 * two calls is never treated as the wrong bucket.
 *
 * @return array{referenced: array<string,string[]>, orphaned: string[]}
 *   referenced: basename => [page labels]; orphaned: [basename, ...]
 */
function imgclean_classify(array $siteData): array
{
    $usageByPath = imgclean_usage_map($siteData);
    $usageByBase = [];
    foreach ($usageByPath as $path => $contexts) {
        $usageByBase[basename($path)] = $contexts;
    }

    $referenced = [];
    $orphaned   = [];
    foreach (imgclean_all_media_files() as $f) {
        if (isset($usageByBase[$f])) {
            $referenced[$f] = $usageByBase[$f];
        } else {
            $orphaned[] = $f;
        }
    }
    return ['referenced' => $referenced, 'orphaned' => $orphaned];
}

/** The vision prompt for one image. Strict JSON out, nothing else. */
function imgclean_build_prompt(string $filename, array $pageContexts, string $businessDescriptor): string
{
    $contextStr = implode(', ', $pageContexts) ?: 'no specific page found';
    return "You are reviewing one photo from a website's image library for {$businessDescriptor}.\n\n"
         . "This file is currently named \"{$filename}\" and is used on: {$contextStr}.\n\n"
         . "Look at the photo and answer honestly. Return ONLY a JSON object, no markdown fences, no explanation:\n\n"
         . "{\n"
         . "  \"description\": \"one plain sentence describing what is literally visible in the photo\",\n"
         . "  \"matches_topic\": true or false — does the photo genuinely show something related to the page(s) listed above?,\n"
         . "  \"suggested_filename\": \"a clean filename: one real topic keyword (from the page context if matches_topic is true, otherwise based on what the photo actually shows), a short honest description of the photo, then the city if one is relevant. Lowercase, hyphen-separated, ending in the same file extension as the original. Do NOT stack multiple keywords or phrases — one real topic term is enough.\"\n"
         . "}";
}

/**
 * Call Claude vision for one image. Returns null on any failure (bad response,
 * unparseable JSON, API error) rather than guessing — a skipped image is far
 * safer than a wrong rename.
 */
function imgclean_analyze(string $absPath, string $filename, array $pageContexts, string $businessDescriptor): ?array
{
    if (!is_file($absPath)) return null;
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $mediaType = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        default       => 'image/webp',
    };
    $bytes = @file_get_contents($absPath);
    if ($bytes === false) return null;

    $prompt = imgclean_build_prompt($filename, $pageContexts, $businessDescriptor);
    $result = anthropic_message($prompt, [
        'model'            => ANTHROPIC_FAST,
        'max_tokens'       => 400,
        'image_base64'     => base64_encode($bytes),
        'image_media_type' => $mediaType,
        'timeout'          => 60,
    ]);
    if (!$result['ok']) return null;

    $text = trim($result['text']);
    // Models sometimes wrap JSON in a fence despite being told not to — strip it
    // rather than fail the whole item over a formatting slip.
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
    $parsed = json_decode($text, true);
    if (!is_array($parsed) || !isset($parsed['suggested_filename'])) return null;

    $suggested = preg_replace('/[^a-z0-9\-.]/', '', strtolower(trim((string) $parsed['suggested_filename'])));
    if ($suggested === '' || !str_contains($suggested, '.')) return null;

    return [
        'description'        => (string) ($parsed['description'] ?? ''),
        'matches_topic'       => !empty($parsed['matches_topic']),
        'suggested_filename'  => $suggested,
    ];
}

/**
 * Apply one approved rename: move the file, update media.json if an entry
 * exists, and rewrite every reference across site.json + every page file.
 * Each data file is re-validated as JSON immediately after the edit; if the
 * result doesn't parse, that file's edit is thrown away and reported — never
 * leave a data file half-written.
 *
 * @return array{ok:bool, error:string, files_updated:int, replacements:int}
 */
function imgclean_apply_rename(string $oldFilename, string $newFilename): array
{
    $oldFilename = basename($oldFilename);
    $newFilename = basename($newFilename);

    if (!defined('MEDIA_DIR') || !is_dir(MEDIA_DIR)) {
        return ['ok' => false, 'error' => 'Media directory not found.', 'files_updated' => 0, 'replacements' => 0];
    }
    $oldPath = MEDIA_DIR . $oldFilename;
    $newPath = MEDIA_DIR . $newFilename;
    if (!is_file($oldPath)) {
        return ['ok' => false, 'error' => "Source file no longer exists: {$oldFilename}", 'files_updated' => 0, 'replacements' => 0];
    }
    if (is_file($newPath)) {
        return ['ok' => false, 'error' => "A file named {$newFilename} already exists.", 'files_updated' => 0, 'replacements' => 0];
    }
    if (!rename($oldPath, $newPath)) {
        return ['ok' => false, 'error' => "Could not rename {$oldFilename}.", 'files_updated' => 0, 'replacements' => 0];
    }

    // media.json — update the matching entry's filename/url, if one exists.
    if (function_exists('media_load') && defined('MEDIA_JSON') && defined('UPLOAD_URL')) {
        $items = media_load();
        $changed = false;
        foreach ($items as &$item) {
            if (($item['filename'] ?? '') === $oldFilename) {
                $item['filename'] = $newFilename;
                $item['url']      = UPLOAD_URL . 'media/' . $newFilename;
                $changed = true;
            }
        }
        unset($item);
        if ($changed) media_save($items);
    }

    // Rewrite every reference across site.json + every generated page.
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $dataFiles = [DATA_FILE];
    if (defined('PAGES_DIR')) $dataFiles = array_merge($dataFiles, glob(PAGES_DIR . '*.json') ?: []);

    $filesUpdated = 0;
    $replacements = 0;
    foreach ($dataFiles as $f) {
        if (!is_file($f)) continue;
        $content = file_get_contents($f);
        if ($content === false) continue;
        $count = 0;
        $newContent = str_replace($oldFilename, $newFilename, $content, $count);
        if ($count === 0) continue;

        $decoded = json_decode($newContent, true);
        if ($decoded === null) {
            // Refuse to write a file that no longer parses — surface it instead
            // of corrupting live site data.
            return [
                'ok' => false,
                'error' => "Renamed the file, but updating references in " . basename($f) . " would have produced invalid JSON — left that file untouched. Reference update incomplete; please check manually.",
                'files_updated' => $filesUpdated,
                'replacements'  => $replacements,
            ];
        }
        file_put_contents($f, json_encode($decoded, $flags));
        $filesUpdated++;
        $replacements += $count;
    }

    return ['ok' => true, 'error' => '', 'files_updated' => $filesUpdated, 'replacements' => $replacements];
}

/** Delete one orphaned file, after re-confirming (fresh scan) it's still unused. */
function imgclean_delete_orphan(string $filename, array $siteData): array
{
    $filename = basename($filename);
    $classified = imgclean_classify($siteData);
    if (!in_array($filename, $classified['orphaned'], true)) {
        return ['ok' => false, 'error' => "{$filename} is now referenced somewhere — not deleting."];
    }
    if (!defined('MEDIA_DIR') || !is_file(MEDIA_DIR . $filename)) {
        return ['ok' => false, 'error' => "{$filename} no longer exists."];
    }
    if (!@unlink(MEDIA_DIR . $filename)) {
        return ['ok' => false, 'error' => "Could not delete {$filename}."];
    }
    if (function_exists('media_load')) {
        $items = media_load();
        $items = array_values(array_filter($items, fn($i) => ($i['filename'] ?? '') !== $filename));
        media_save($items);
    }
    return ['ok' => true, 'error' => ''];
}
