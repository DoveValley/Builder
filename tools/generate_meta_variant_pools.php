<?php
/**
 * One-time content-authoring tool: writes a small pool of genuinely different
 * meta_description / shared-description phrasings into each page of a master,
 * so `ms_meta_variant_pool_apply()` (includes/multisite/differentiate.php) has
 * real variants to pick from per domain instead of every site sharing one
 * fixed string. Real severity measured 2026-11-01: ~96-100% of live domains
 * were sharing each leaf-page template's exact meta/og/schema description text.
 *
 * Writes TWO new array fields per page's `seo` block, alongside the existing
 * singular ones (which stay untouched as the fallback for any page that never
 * gets a pool):
 *   - meta_description_pool   — variants of `meta_description`
 *   - og_description_pool     — variants of the text shared by `og_description`,
 *                                `service_description`, and the schema's
 *                                Service/LocalBusiness `description` field
 *
 * Usage: php tools/generate_meta_variant_pools.php <master_id> [--count=4]
 *        [--pages=home,landing] [--limit=N] [--dry-run] [--force]
 *
 * --force re-generates a page that already has a pool (normally skipped, so a
 * re-run after an interruption costs nothing for pages already done).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/anthropic.php';

$masterId = null; $count = 4; $pagesArg = 'home,landing'; $limit = null; $dryRun = false; $force = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--count=')) $count = max(2, (int) substr($a, 8));
    elseif (str_starts_with($a, '--pages=')) $pagesArg = substr($a, 8);
    elseif (str_starts_with($a, '--limit=')) $limit = (int) substr($a, 8);
    elseif ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--force') $force = true;
    elseif ($masterId === null) $masterId = $a;
}
if (!$masterId || !preg_match('/^[a-z0-9_-]+$/', $masterId)) {
    fwrite(STDERR, "usage: php tools/generate_meta_variant_pools.php <master_id> [--count=4] [--pages=home,landing] [--limit=N] [--dry-run] [--force]\n");
    exit(2);
}
$wantPages = array_flip(array_filter(array_map('trim', explode(',', $pagesArg))));
$masterDir = BASE_DIR . '/sites/' . $masterId;
if (!is_dir($masterDir)) { fwrite(STDERR, "No such master: $masterId\n"); exit(2); }

/** Every {token}-style placeholder in a string, as a sorted set — same check
 *  generate.py's reword functions use, so a variant that drops/alters a token
 *  gets rejected rather than shipped. */
function mvp_tokens(string $s): array {
    preg_match_all('/\{[a-z_]+\}/i', $s, $m);
    $t = array_unique($m[0]);
    sort($t);
    return $t;
}

function mvp_prompt(string $label, string $original, string $primaryKeyword, int $count, string $guardrails): string {
    return
        "You are writing {$count} alternative versions of a {$label} for a local-service repair " .
        "business's web page. Below is the CURRENT text. Every one of the {$count} versions must:\n" .
        "1. Preserve every {token}-style placeholder (e.g. {city_state}, {phone}, {business}) EXACTLY " .
        "as written, wherever one appears in the original — never remove, translate, or reword around one.\n" .
        "2. Keep the exact same factual claims — do not invent credentials, guarantees, pricing, or stats " .
        "not already present in the original.\n" .
        "3. Center the primary keyword \"{$primaryKeyword}\" and the city token prominently, near the " .
        "FRONT of the string where a search snippet won't truncate it — not buried at the end.\n" .
        "4. Read as a genuinely distinct, independently-written sentence from each other and from the " .
        "original — vary structure and wording meaningfully, not a synonym swap.\n" .
        "5. Stay realistic for a meta description / short service summary length (roughly 140-160 " .
        "characters once the tokens are filled in with a typical city/phone)."
        . ($guardrails !== '' ? "\n6. Follow these guardrails strictly: {$guardrails}" : '') .
        "\n\nCurrent text:\n{$original}\n\n" .
        "Return JSON only, a bare array of {$count} strings, no other keys or commentary: " .
        "[\"variant 1\", \"variant 2\", ...]";
}

/** @return string[]|null null on any failure (API error, bad JSON, token mismatch, wrong count) */
function mvp_generate(string $label, string $original, string $primaryKeyword, int $count, string $guardrails): ?array {
    if (trim($original) === '') return null;
    $prompt = mvp_prompt($label, $original, $primaryKeyword, $count, $guardrails);
    $r = anthropic_message($prompt, ['model' => ANTHROPIC_SMART, 'max_tokens' => 1024, 'no_thinking' => true]);
    if (!$r['ok']) { fwrite(STDERR, "    API error: {$r['error']}\n"); return null; }
    $text = trim($r['text']);
    // Model sometimes wraps in a fenced code block despite instructions — strip it.
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
    $arr = json_decode($text, true);
    if (!is_array($arr) || count($arr) !== $count) {
        fwrite(STDERR, "    Bad response shape (expected $count strings): " . substr($text, 0, 200) . "\n");
        return null;
    }
    $origTokens = mvp_tokens($original);
    $out = [];
    foreach ($arr as $v) {
        if (!is_string($v) || trim($v) === '') return null;
        if (mvp_tokens($v) !== $origTokens) {
            fwrite(STDERR, "    Token mismatch in a variant — rejecting whole batch for safety\n");
            return null;
        }
        $out[] = trim($v);
    }
    return $out;
}

/** Walks one page's seo block, fills both pools, returns true if the file changed. */
function mvp_process_page(array &$data, string $label, int $count, bool $force, string $guardrails): bool {
    $seo = &$data['seo'];
    $changed = false;

    if ($force || empty($seo['meta_description_pool'])) {
        $pool = mvp_generate('meta description', (string) ($seo['meta_description'] ?? ''),
            (string) ($seo['primary_keyword'] ?? ''), $count, $guardrails);
        if ($pool) { $seo['meta_description_pool'] = $pool; $changed = true; echo "    meta_description_pool: " . count($pool) . " variants\n"; }
    }

    if ($force || empty($seo['og_description_pool'])) {
        $pool = mvp_generate('short service description (used for social-share + schema)',
            (string) ($seo['og_description'] ?? ''), (string) ($seo['primary_keyword'] ?? ''), $count, $guardrails);
        if ($pool) { $seo['og_description_pool'] = $pool; $changed = true; echo "    og_description_pool: " . count($pool) . " variants\n"; }
    }

    return $changed;
}

$briefFile = $masterDir . '/multisite/niche_brief.json';
$brief = is_file($briefFile) ? json_decode((string) file_get_contents($briefFile), true) : null;
$guardrails = trim((string) ($brief['guardrails'] ?? ''));
echo $guardrails !== '' ? "Loaded guardrails from niche_brief.json (" . strlen($guardrails) . " chars)\n\n" : "WARNING: no guardrails found in niche_brief.json\n\n";

$totalChanged = 0; $totalSeen = 0;

if (isset($wantPages['home'])) {
    $homeFile = $masterDir . '/data/site.json';
    $data = json_decode((string) file_get_contents($homeFile), true);
    if (is_array($data) && isset($data['seo'])) {
        echo "Home page ($homeFile):\n";
        $totalSeen++;
        if (mvp_process_page($data, 'home page', $count, $force, $guardrails)) {
            $totalChanged++;
            if (!$dryRun) file_put_contents($homeFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}

if (isset($wantPages['landing'])) {
    // The REAL source for per-city landing pages is data/templates.json (a compiled
    // registry generate_city_pages_locked() reads — see includes/generation/engine.php
    // line ~264, `'seo' => $tpl['seo'] ?? []`), NOT the individual data/pages/*.json
    // files. Those get unconditionally deleted and regenerated FROM templates.json on
    // every single build (multisite/build_one.php, right before the 'landing' step) —
    // confirmed by real testing 2026-11-01: a pool written into data/pages/*.json
    // directly was silently discarded before differentiate.php ever ran. Writing here
    // instead means generate_city_pages_locked()'s whole-object seo copy carries the
    // pool into every freshly generated per-city page automatically.
    $templatesFile = $masterDir . '/data/templates.json';
    if (!is_file($templatesFile)) {
        fwrite(STDERR, "No templates.json at $templatesFile — nothing to do for landing pages.\n");
    } else {
        $templates = json_decode((string) file_get_contents($templatesFile), true);
        if (!is_array($templates)) {
            fwrite(STDERR, "templates.json did not decode to an array — aborting landing pass.\n");
        } else {
            $done = 0; $landingChanged = 0;
            foreach ($templates as &$tpl) {
                if ($limit !== null && $done >= $limit) break;
                if (!is_array($tpl) || !isset($tpl['seo'])) continue;
                $totalSeen++;
                echo ($tpl['id'] ?? '(no id)') . ":\n";
                if (mvp_process_page($tpl, 'repair-page meta description', $count, $force, $guardrails)) {
                    $totalChanged++; $landingChanged++;
                }
                $done++;
            }
            unset($tpl);
            if (!$dryRun && $landingChanged > 0) {
                file_put_contents($templatesFile, json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }
}

echo "\n" . ($dryRun ? "[dry-run] " : "") . "Done: $totalChanged of $totalSeen pages got a new pool.\n";
