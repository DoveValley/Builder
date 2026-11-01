<?php
/**
 * One-time setup tool: approves an AI-photo prompt for every leaf-page template's
 * hero_split/hs_photo slot, so ms_generate_ai_images_for_domain() has something to
 * act on for leaf pages (today, only the homepage hero has an approved prompt on
 * any niche — leaf pages fall back to one shared static photo per template, reused
 * identically across ~96-100% of live domains carrying that template).
 *
 * Shared, niche-agnostic — the same script and logic run against any master; only
 * the service phrase in the prompt changes per niche.
 *
 * Usage: php tools/capture_leaf_hero_prompts.php <master_id> "<service phrase>" [--dry-run] [--force]
 *   <service phrase> e.g. "Appliance repair technician", "Water damage restoration
 *   technician", "Mold remediation technician", "Pest control technician"
 *
 * --force re-captures even templates that already have an approved prompt (normally
 * skipped, so a re-run after adding new templates only touches the new ones).
 *
 * De-duplicates by TEMPLATE_ID, not by file — some masters (e.g. pest-template) keep
 * many pre-built real-city page files per template in data/pages/ (not the one-
 * placeholder-city-per-template convention most masters use), so a naive per-file
 * loop would capture the same template dozens of times over. Confirmed empirically
 * 2026-11-01: pest-template has 1,158 page files across only 32 real templates.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/picdrop.php';
require __DIR__ . '/../includes/multisite/image_ai.php';

$masterId = null; $servicePhrase = null; $dryRun = false; $force = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dryRun = true;
    elseif ($a === '--force') $force = true;
    elseif ($masterId === null) $masterId = $a;
    elseif ($servicePhrase === null) $servicePhrase = $a;
}
if (!$masterId || !preg_match('/^[a-z0-9_-]+$/', $masterId) || !$servicePhrase) {
    fwrite(STDERR, "usage: php tools/capture_leaf_hero_prompts.php <master_id> \"<service phrase>\" [--dry-run] [--force]\n");
    exit(2);
}
$masterDir = BASE_DIR . '/sites/' . $masterId;
if (!is_dir($masterDir)) { fwrite(STDERR, "No such master: $masterId\n"); exit(2); }

// Leaf heroes aren't all the same shape — some are a technician at work, some are a
// plain subject close-up with nobody in frame (e.g. a macro pest photo). One prompt
// has to handle both without knowing which it's looking at, so it branches instead of
// assuming a person is present: told to "change the technician's face" on a photo
// with no technician, the model ignores "keep the same scene" entirely and invents a
// different picture outright — confirmed empirically 2026-11-01 on pest-template's
// bug-macro templates (ant/roach/spider/termite/etc.), the worst case but not unique
// to pest (one water-site template had the same no-person shape).
$prompt = "Same picture, for: {$servicePhrase} — keep the exact same scene, composition, "
    . "equipment, and setting. Do not add, remove, or restyle anything in the background "
    . "or on the equipment/subject. If a technician or other person is visible in the "
    . "photo, change only their hair and face so they read as a different person in the "
    . "same role — do not change their clothing, pose, or position; if a second person is "
    . "visible, change their hair and face too. If no person is visible in the photo, "
    . "leave the subject and scene as-is and make only a small, believable variation "
    . "(e.g. a slightly different angle or pose of the same subject) so it reads as a "
    . "distinct photo rather than an identical copy — do not add a person. Bright, "
    . "natural daylight. No visible text or logos.";

$existing = $force ? [] : ms_image_ai_prompts_load($masterDir);
$seenTemplateIds = [];
foreach ($existing as $k => $v) {
    if (str_starts_with($k, 'landing:') && !empty($v['template_id'])) {
        $seenTemplateIds[$v['template_id']] = true;
    }
}

$files = glob($masterDir . '/data/pages/*.json') ?: [];
sort($files);
$capturedTemplateIds = [];
$captured = 0; $skippedExisting = 0; $skippedNoHero = 0; $skippedDuplicateTemplate = 0;

foreach ($files as $f) {
    if (str_ends_with($f, '.bak')) continue;
    $filename = basename($f);
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) continue;

    $templateId = (string) ($d['template_id'] ?? '');
    if ($templateId === '') continue;

    // One capture per TEMPLATE, not per file — see module docblock.
    if (isset($capturedTemplateIds[$templateId])) { $skippedDuplicateTemplate++; continue; }
    if (isset($seenTemplateIds[$templateId])) { $skippedExisting++; continue; }

    $heroIdx = null; $heroBlock = null;
    foreach (($d['content_blocks'] ?? []) as $i => $b) {
        if (($b['type'] ?? '') === 'hero_split') { $heroIdx = $i; $heroBlock = $b; break; }
    }
    if ($heroIdx === null) { $skippedNoHero++; continue; }

    $blockId = (string) ($heroBlock['id'] ?? '');
    $slotKey = "landing:{$filename}:{$heroIdx}:hs_photo";

    if (!$dryRun) {
        ms_image_ai_prompt_capture($masterDir, $slotKey, 'hero_split', $prompt, $blockId);
    }
    $capturedTemplateIds[$templateId] = true;
    $captured++;
}

echo ($dryRun ? "[dry-run] " : "") . "Captured: $captured template(s)\n";
echo "Skipped (already had a prompt): $skippedExisting\n";
echo "Skipped (duplicate template, same master): $skippedDuplicateTemplate\n";
echo "Skipped (no hero_split block): $skippedNoHero\n";
echo "Total page files scanned: " . count($files) . "\n";
