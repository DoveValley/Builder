<?php
/**
 * The Variant Engine's PHP side: variant assignment (propose/reroll/approve) and launching/
 * polling the two Python passes (content, build). This file is additive only — it never
 * touches multisite/run_campaign.php, multisite/build_one.php, or generate.py.
 *
 * The ONE governing rule for this file: nothing here may contain a literal list of
 * architecture/type/color/title/voice/research-prompt NAMES. Every dimension's available
 * options are discovered by scanning a folder at request time (ms_variant_scan_pool()) —
 * adding a new option anywhere in variants/ or a niche's own variant folders must never
 * require touching this file.
 */

require_once __DIR__ . '/batch.php';
require_once __DIR__ . '/params.php';
require_once BASE_DIR . '/includes/layout_variations.php';

/** The fixed dimension TAXONOMY (not their options — see the file-level rule above). */
function ms_variant_dimensions(): array
{
    return ['architecture', 'type', 'color', 'titles', 'research_prompt', 'voice'];
}

/** Shared (niche-agnostic) dimensions live under the repo's own variants/ library. */
function ms_variant_shared_dirname(string $dimension): ?string
{
    return ['architecture' => 'architectures', 'type' => 'types', 'color' => 'colors', 'titles' => 'titles'][$dimension] ?? null;
}

/** Niche-scoped dimensions live per-master, next to that master's other multisite config. */
function ms_variant_niche_dirname(string $dimension): ?string
{
    return ['research_prompt' => 'research_prompts', 'voice' => 'voices'][$dimension] ?? null;
}

function ms_variant_dimension_dir(string $masterId, string $dimension): ?string
{
    if ($shared = ms_variant_shared_dirname($dimension)) {
        return BASE_DIR . '/variants/' . $shared;
    }
    if ($niche = ms_variant_niche_dirname($dimension)) {
        return ms_master_dir($masterId) . '/variants/' . $niche;
    }
    return null;
}

/**
 * The discovery mechanism. Globs a dimension's folder and returns one pool entry per option
 * found — never a hand-maintained list. Handles both shapes that exist in variants/:
 *   (A) one subfolder per option, each with its own meta.json (architectures/)
 *   (B) one flat JSON file per option, each file itself carrying name/description (everything else)
 */
function ms_variant_scan_pool(?string $dir): array
{
    $pool = [];
    if ($dir === null || !is_dir($dir)) return $pool;

    foreach (glob($dir . '/*/meta.json') ?: [] as $metaFile) {
        $id = basename(dirname($metaFile));
        $meta = json_decode((string) file_get_contents($metaFile), true) ?: [];
        $pool[$id] = ['id' => $id, 'name' => $meta['name'] ?? $id, 'description' => $meta['description'] ?? ''];
    }
    if ($pool) { ksort($pool); return array_values($pool); }

    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        $meta = json_decode((string) file_get_contents($file), true) ?: [];
        $pool[$id] = ['id' => $id, 'name' => $meta['name'] ?? $id, 'description' => $meta['description'] ?? ''];
    }
    ksort($pool);
    return array_values($pool);
}

/**
 * The scan plus an OPTIONAL per-master curation overlay — never a duplicate authoritative
 * list. Absent variant_pools.json (the common case for a fresh master) = every discovered
 * option is in rotation, no configuration required at all.
 */
function ms_variant_pool(string $masterId, string $dimension): array
{
    $pool = ms_variant_scan_pool(ms_variant_dimension_dir($masterId, $dimension));
    $poolsFile = ms_master_dir($masterId) . '/variant_pools.json';
    if (is_file($poolsFile)) {
        $cfg = json_decode((string) file_get_contents($poolsFile), true) ?: [];
        $exclude = $cfg[$dimension]['exclude'] ?? [];
        if ($exclude) {
            $pool = array_values(array_filter($pool, fn($p) => !in_array($p['id'], $exclude, true)));
        }
    }
    return $pool;
}

// ── variant_plan.json — proposed/approved/rerolled assignment per batch ─────────────────

function ms_variant_plan_file(string $masterId, string $batchId): string
{
    return ms_batch_dir($masterId, $batchId) . '/variant_plan.json';
}

function ms_variant_read_plan(string $masterId, string $batchId): array
{
    $file = ms_variant_plan_file($masterId, $batchId);
    if (!is_file($file)) return ['approved' => false, 'rows' => []];
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : ['approved' => false, 'rows' => []];
}

function ms_variant_write_plan(string $masterId, string $batchId, array $plan): void
{
    $file = ms_variant_plan_file($masterId, $batchId);
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
}

/**
 * Auto-proposes a value for every dimension, for every row in the batch's params.csv, using
 * the SAME deterministic ms_variant() primitive already proven for theme/logo/layout — just
 * new salts, new pools. A row whose dimension was explicitly rerolled keeps that value; it is
 * never re-derived by a later Propose click. Always resets the whole plan's approval (a fresh
 * proposal needs a fresh Approve), but never touches already-generated content.
 */
function ms_variant_propose_plan(string $masterId, string $batchId): array
{
    $csv = ms_batch_dir($masterId, $batchId) . '/params.csv';
    $parsed = is_file($csv) ? ms_parse_csv($csv) : ['rows' => []];

    $dims = ms_variant_dimensions();
    $pools = [];
    foreach ($dims as $d) $pools[$d] = ms_variant_pool($masterId, $d);

    $existing = ms_variant_read_plan($masterId, $batchId);
    $rows = $existing['rows'] ?? [];

    foreach ($parsed['rows'] as $row) {
        $domain = trim($row['domain'] ?? '');
        if ($domain === '') continue;

        $current = $rows[$domain] ?? [];
        $variant = $current['variant'] ?? [];
        $source = $variant['source'] ?? [];

        foreach ($dims as $d) {
            if (($source[$d] ?? '') === 'reroll') continue; // locked in — see docblock
            $pool = $pools[$d];
            if (!$pool) { $variant[$d] = null; continue; }
            $idx = ms_variant($domain, count($pool), 'variant_' . $d);
            $variant[$d] = $pool[$idx]['id'];
            $source[$d] = 'auto';
        }
        $variant['source'] = $source;

        $rows[$domain] = [
            'variant' => $variant,
            'approved' => false,
            'content_generated' => $current['content_generated'] ?? false,
            'content_needs_review' => $current['content_needs_review'] ?? false,
            'content_approved' => false,
            'built' => $current['built'] ?? false,
        ];
    }

    $plan = ['approved' => false, 'proposed_at' => gmdate('c'), 'rows' => $rows];
    ms_variant_write_plan($masterId, $batchId, $plan);
    return $plan;
}

/** Reroll ONE row's ONE dimension to the next pool option (cycling). Persists explicitly —
 * never re-derived by hash again, matching "reject = reroll one row, keep the rest." */
function ms_variant_reroll_row(string $masterId, string $batchId, string $domain, string $dimension): array
{
    if (!in_array($dimension, ms_variant_dimensions(), true)) {
        throw new InvalidArgumentException("Unknown dimension: $dimension");
    }
    $plan = ms_variant_read_plan($masterId, $batchId);
    if (!isset($plan['rows'][$domain])) {
        throw new InvalidArgumentException("Unknown row: $domain");
    }
    $pool = ms_variant_pool($masterId, $dimension);
    if (!$pool) {
        throw new RuntimeException("No options available for dimension: $dimension");
    }
    $ids = array_column($pool, 'id');
    $currentId = $plan['rows'][$domain]['variant'][$dimension] ?? null;
    $curIdx = array_search($currentId, $ids, true);
    $nextIdx = ($curIdx === false) ? 0 : ($curIdx + 1) % count($ids);

    $plan['rows'][$domain]['variant'][$dimension] = $ids[$nextIdx];
    $plan['rows'][$domain]['variant']['source'][$dimension] = 'reroll';
    ms_variant_write_plan($masterId, $batchId, $plan);
    return $plan;
}

/** One click approves the whole batch's proposed plan — the gate the content pass checks. */
function ms_variant_approve_plan(string $masterId, string $batchId): array
{
    $plan = ms_variant_read_plan($masterId, $batchId);
    $plan['approved'] = true;
    $plan['approved_at'] = gmdate('c');
    foreach ($plan['rows'] as $domain => $row) {
        $plan['rows'][$domain]['approved'] = true;
    }
    ms_variant_write_plan($masterId, $batchId, $plan);
    return $plan;
}

/** Approve one row's generated content for the build pass (per-row, since content quality is
 * judged per business, not per batch). */
function ms_variant_approve_content(string $masterId, string $batchId, string $domain, bool $approved = true): array
{
    $plan = ms_variant_read_plan($masterId, $batchId);
    if (!isset($plan['rows'][$domain])) {
        throw new InvalidArgumentException("Unknown row: $domain");
    }
    $plan['rows'][$domain]['content_approved'] = $approved;
    ms_variant_write_plan($masterId, $batchId, $plan);
    return $plan;
}

// ── Launching + polling the two Python passes ────────────────────────────────────────────

function ms_variant_runs_dir(string $masterId, string $batchId): string
{
    return ms_batch_dir($masterId, $batchId) . '/variant_runs';
}

/** Launches run_batch.py detached (same setsid+background idiom as the existing
 * ms_launch_campaign() for run_campaign.php) and writes an initial run-status file. */
function ms_variant_launch_pass(string $masterId, string $batchId, string $passName, array $opts = []): string
{
    if (!in_array($passName, ['content', 'build'], true)) {
        throw new InvalidArgumentException("Unknown pass: $passName");
    }
    $runsDir = ms_variant_runs_dir($masterId, $batchId);
    if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);

    $runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $outFile = $runsDir . '/' . $runId . '.out';
    $script = BASE_DIR . '/variants/pipeline/run_batch.py';

    $cmd = 'python3 ' . escapeshellarg($script)
         . ' --master ' . escapeshellarg($masterId)
         . ' --batch ' . escapeshellarg($batchId)
         . ' --pass ' . escapeshellarg($passName);
    if (!empty($opts['only'])) $cmd .= ' --only=' . escapeshellarg($opts['only']);
    if (!empty($opts['force'])) $cmd .= ' --force';
    if (!empty($opts['dry_run'])) $cmd .= ' --dry-run';

    $envKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    $fullCmd = 'ANTHROPIC_API_KEY=' . escapeshellarg($envKey) . ' setsid ' . $cmd
             . ' > ' . escapeshellarg($outFile) . ' 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($fullCmd));

    ms_variant_write_run($masterId, $batchId, $runId, [
        'run_id' => $runId, 'pass' => $passName, 'state' => 'running',
        'pid' => $pid, 'started_at' => gmdate('c'), 'out_file' => $outFile,
    ]);
    return $runId;
}

function ms_variant_run_file(string $masterId, string $batchId, string $runId): string
{
    return ms_variant_runs_dir($masterId, $batchId) . '/' . $runId . '.json';
}

function ms_variant_write_run(string $masterId, string $batchId, string $runId, array $status): void
{
    $file = ms_variant_run_file($masterId, $batchId, $runId);
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($status, JSON_PRETTY_PRINT));
    rename($tmp, $file);
}

/**
 * Reads a run's progress: tails its jsonlines .out file for per-row events and, once the
 * process has exited, resolves a final state from the last "batch_done"/"fatal" line — the
 * same self-healing shape ms_read_run() already uses for run_campaign.php (a dead process
 * that never reports back is marked 'crashed', not left 'running' forever).
 */
function ms_variant_run_status(string $masterId, string $batchId, string $runId): ?array
{
    $file = ms_variant_run_file($masterId, $batchId, $runId);
    if (!is_file($file)) return null;
    $status = json_decode((string) file_get_contents($file), true);
    if (!is_array($status)) return null;

    $events = [];
    if (is_file($status['out_file'] ?? '')) {
        foreach (file($status['out_file']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['event'])) $events[] = $decoded;
        }
    }
    $status['events'] = $events;

    if (($status['state'] ?? '') === 'running' && !ms_pid_alive((int) ($status['pid'] ?? 0))) {
        $last = end($events) ?: null;
        if ($last && $last['event'] === 'batch_done') {
            $status['state'] = ($last['failed'] ?? 0) > 0 ? 'failed' : 'done';
            $status['ok'] = $last['ok'] ?? 0;
            $status['failed'] = $last['failed'] ?? 0;
        } elseif ($last && $last['event'] === 'fatal') {
            $status['state'] = 'failed';
            $status['reason'] = $last['reason'] ?? '';
        } else {
            $status['state'] = 'crashed';
        }
        ms_variant_write_run($masterId, $batchId, $runId, $status);
    }
    return $status;
}

/** The newest run for a batch, or null — mirrors ms_active_run()'s "latest file by mtime". */
function ms_variant_latest_run(string $masterId, string $batchId): ?array
{
    $runsDir = ms_variant_runs_dir($masterId, $batchId);
    $files = glob($runsDir . '/*.json') ?: [];
    if (!$files) return null;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $runId = basename($files[0], '.json');
    return ms_variant_run_status($masterId, $batchId, $runId);
}
