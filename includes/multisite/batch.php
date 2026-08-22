<?php
/**
 * Multisite batches.
 *
 * A batch is one master + one target list + its run history:
 *
 *   sites/{master}/batches/{batch}/
 *       batch.json         id, name, master_id, created_at, updated_at
 *       params.csv         the target rows (one per site to build)
 *       params.version     pointer to the current upload
 *       params_versions/   rolling upload history
 *       runs/              run logs
 *       run.lock           one run at a time, per batch
 *       research/          city research output
 *
 * What deliberately does NOT live here: everything that belongs to the MASTER and is
 * shared by every batch off it — niche_brief.json, ai/archetypes.json,
 * theme_presets.json, icons/, hero_style.json, cache/. Those stay in
 * sites/{master}/multisite/ and are untouched by this file.
 *
 * Requires params.php itself (rather than trusting the caller to have loaded it)
 * because ms_swap_master_warnings() below calls ms_parse_csv() — its old
 * function_exists('ms_parse_csv') guard silently did nothing everywhere admin/
 * batch_api.php is the entry point, since that file never loads params.php, and
 * the missing-preset warning it exists to show never fired.
 */
require_once __DIR__ . '/params.php';
/**
 * This file owns the batch layout. Nothing else should build a batch path by hand —
 * ask ms_batch_dir() (or ms_active_batch_dir()) for it.
 */

/** Batch ids are generated, never user-supplied: b1, b2, b3… */
function ms_valid_batch_id(string $id): bool {
    return (bool) preg_match('/^b[0-9]{1,6}$/', $id);
}

/** Same rule the site manager uses for a site folder name. */
function ms_valid_master_id(string $id): bool {
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $id)
        && is_dir(BASE_DIR . '/sites/' . $id);
}

/** Where a master keeps its batches. */
function ms_batches_root(string $masterId): string {
    return BASE_DIR . '/sites/' . $masterId . '/batches';
}

/** One batch's folder. Does not create it. */
function ms_batch_dir(string $masterId, string $batchId): string {
    return ms_batches_root($masterId) . '/' . $batchId;
}

/** Master-level multisite config shared by every batch (niche brief, presets, icons…). */
function ms_master_dir(string $masterId): string {
    return BASE_DIR . '/sites/' . $masterId . '/multisite';
}

function ms_batch_exists(string $masterId, string $batchId): bool {
    return ms_valid_master_id($masterId) && ms_valid_batch_id($batchId)
        && is_file(ms_batch_dir($masterId, $batchId) . '/batch.json');
}

// ── The batch record ──────────────────────────────────────────────────────────

function ms_batch_meta(string $masterId, string $batchId): ?array {
    if (!ms_valid_master_id($masterId) || !ms_valid_batch_id($batchId)) return null;
    $f = ms_batch_dir($masterId, $batchId) . '/batch.json';
    if (!is_file($f)) return null;
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/**
 * Write JSON atomically: to a name unique to THIS call, then rename() over the
 * real path. A fixed, shared tmp filename ("foo.json.tmp") used to mean two
 * concurrent writers (two tabs, a double-click before a button disables) raced
 * on the very file meant to make the write atomic — whichever rename() ran
 * first would silently pick up whatever the OTHER writer had just put in that
 * shared tmp file, and the request that "succeeded" was not necessarily the
 * one whose data ended up on disk.
 */
function ms_atomic_write_json(string $finalPath, $data): bool {
    $tmp = $finalPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json) === false) return false;
    if (@rename($tmp, $finalPath)) return true;
    @unlink($tmp);
    return false;
}

function ms_save_batch_meta(string $masterId, string $batchId, array $meta): bool {
    $dir = ms_batch_dir($masterId, $batchId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    $meta['updated_at'] = gmdate('c');
    return ms_atomic_write_json($dir . '/batch.json', $meta);
}

// ── Listing ───────────────────────────────────────────────────────────────────

/** Every batch belonging to one master, newest-touched first. */
function ms_master_batches(string $masterId): array {
    if (!ms_valid_master_id($masterId)) return [];
    $out = [];
    foreach (glob(ms_batches_root($masterId) . '/*/batch.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d)) continue;
        $d['master_id'] = $masterId;          // the folder is the truth, not the field
        $out[] = $d;
    }
    usort($out, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
    return $out;
}

/** Every batch across every master — what the home panel lists. */
function ms_all_batches(): array {
    $out = [];
    foreach (glob(BASE_DIR . '/sites/*/batches/*/batch.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d)) continue;
        $d['master_id'] = basename(dirname(dirname(dirname($f))));
        $out[] = $d;
    }
    usort($out, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
    return $out;
}

// ── Create / rename / delete / re-point ───────────────────────────────────────

/** Next free batch id for this master. */
function ms_next_batch_id(string $masterId): string {
    $max = 0;
    foreach (glob(ms_batches_root($masterId) . '/b*', GLOB_ONLYDIR) ?: [] as $d) {
        if (preg_match('/^b([0-9]{1,6})$/', basename($d), $m)) $max = max($max, (int) $m[1]);
    }
    return 'b' . ($max + 1);
}

/** @return array ['id'=>string] on success, ['error'=>string] on failure. */
function ms_create_batch(string $masterId, string $name): array {
    if (!ms_valid_master_id($masterId)) return ['error' => 'Pick a master site.'];
    $name = trim($name);
    if ($name === '')            return ['error' => 'Give the batch a name.'];
    if (mb_strlen($name) > 80)   return ['error' => 'That name is too long (80 characters max).'];

    // Pick-the-next-id-then-claim-it is one locked step: without it, two requests
    // computing the same id from ms_next_batch_id() at nearly the same moment could
    // both "succeed" into the same folder — whichever wrote batch.json last wins the
    // name, and the other request believes it created a distinct batch that never
    // existed.
    $lock = ms_batches_root($masterId) . '/.newid.lock';
    $claim = ms_with_launch_lock($lock, function () use ($masterId) {
        $id  = ms_next_batch_id($masterId);
        $dir = ms_batch_dir($masterId, $id);
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) return ['error' => 'Could not create the batch folder.'];
        return ['id' => $id, 'dir' => $dir];
    });
    if (isset($claim['error'])) return $claim;
    $id = $claim['id'];

    $ok = ms_save_batch_meta($masterId, $id, [
        'id'         => $id,
        'name'       => $name,
        'master_id'  => $masterId,
        'created_at' => gmdate('c'),
    ]);
    if (!$ok) return ['error' => 'Could not write the batch record.'];
    return ['id' => $id];
}

function ms_rename_batch(string $masterId, string $batchId, string $name): array {
    $meta = ms_batch_meta($masterId, $batchId);
    if (!$meta) return ['error' => 'Batch not found.'];
    $name = trim($name);
    if ($name === '')          return ['error' => 'Give the batch a name.'];
    if (mb_strlen($name) > 80) return ['error' => 'That name is too long (80 characters max).'];
    $meta['name'] = $name;
    return ms_save_batch_meta($masterId, $batchId, $meta) ? ['ok' => true] : ['error' => 'Could not save.'];
}

/** Recursively remove a directory tree. Refuses anything outside sites/. */
function ms_rmtree(string $dir): bool {
    $root = BASE_DIR . '/sites/';
    $real = realpath($dir);
    if ($real === false || strncmp($real, realpath($root) . '/', strlen(realpath($root)) + 1) !== 0) return false;
    foreach (scandir($real) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $real . '/' . $e;
        is_dir($p) && !is_link($p) ? ms_rmtree($p) : @unlink($p);
    }
    return @rmdir($real);
}

/**
 * Copy a batch: same master, same targets, a fresh history.
 *
 * WHAT IT TAKES — the inputs, which are the expensive part: the target list
 * (params.csv and its version history) and any city research already paid for.
 *
 * WHAT IT DELIBERATELY LEAVES — runs/ and run.lock. Run history is a record of
 * what a batch DID, and a copy has done nothing. Carrying the logs over would
 * make a brand-new batch report sites it never built, costs it never spent, and
 * a "done" state for work that has not started — and the home panel reads
 * exactly those files to decide what to show.
 *
 * The point of copying is the case where the target list took work to assemble
 * and you want to run it again off a different angle — not to clone a result.
 *
 * @return array ['id'=>string] on success, ['error'=>string] on failure.
 */
function ms_copy_batch(string $masterId, string $batchId, string $name = ''): array {
    $meta = ms_batch_meta($masterId, $batchId);
    if (!$meta) return ['error' => 'Batch not found.'];

    $name = trim($name);
    if ($name === '')          $name = trim((string) ($meta['name'] ?? $batchId)) . ' (copy)';
    if (mb_strlen($name) > 80) return ['error' => 'That name is too long (80 characters max).'];

    // Same locked pick-then-claim as ms_create_batch() — otherwise two concurrent
    // copies could land on the same new id and interleave writes of params.csv/
    // params_versions/research from two DIFFERENT source batches into one folder,
    // corrupting both copies' target lists.
    $lock  = ms_batches_root($masterId) . '/.newid.lock';
    $claim = ms_with_launch_lock($lock, function () use ($masterId) {
        $id  = ms_next_batch_id($masterId);
        $dir = ms_batch_dir($masterId, $id);
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) return ['error' => 'Could not create the batch folder.'];
        return ['id' => $id, 'dir' => $dir];
    });
    if (isset($claim['error'])) return $claim;
    $newId  = $claim['id'];
    $srcDir = ms_batch_dir($masterId, $batchId);
    $dstDir = $claim['dir'];

    // Named explicitly rather than "everything except runs/": a copy should gain
    // new inputs only when someone decides it should, not because a later feature
    // happened to drop a folder in here.
    foreach (['params.csv', 'params.version'] as $f) {
        if (is_file($srcDir . '/' . $f)) @copy($srcDir . '/' . $f, $dstDir . '/' . $f);
    }
    foreach (['params_versions', 'research'] as $sub) {
        if (is_dir($srcDir . '/' . $sub)) ms_copytree($srcDir . '/' . $sub, $dstDir . '/' . $sub);
    }

    $ok = ms_save_batch_meta($masterId, $newId, [
        'id'          => $newId,
        'name'        => $name,
        'master_id'   => $masterId,
        'created_at'  => gmdate('c'),
        'copied_from' => $batchId,
    ]);
    if (!$ok) { ms_rmtree($dstDir); return ['error' => 'Could not write the batch record.']; }
    return ['id' => $newId, 'name' => $name];
}

/** Recursive copy, same containment rule as ms_rmtree(): nothing outside sites/. */
function ms_copytree(string $src, string $dst): bool {
    $root = realpath(BASE_DIR . '/sites');
    $real = realpath($src);
    if ($root === false || $real === false || strncmp($real, $root . '/', strlen($root) + 1) !== 0) return false;
    if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) return false;
    foreach (scandir($real) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $s = $real . '/' . $e; $d = $dst . '/' . $e;
        if (is_link($s)) continue;                       // never follow links out of the tree
        is_dir($s) ? ms_copytree($s, $d) : @copy($s, $d);
    }
    return true;
}

function ms_delete_batch(string $masterId, string $batchId): array {
    if (!ms_batch_exists($masterId, $batchId)) return ['error' => 'Batch not found.'];
    $dir = ms_batch_dir($masterId, $batchId);
    // A background run_campaign.php process writes into this exact tree while it
    // works (runs/*.json, output/*) — deleting out from under it doesn't error here,
    // it just makes the running process start failing its own writes silently,
    // matching the same "moved/removed files under a live process" pattern already
    // fixed once for Go Live's origin-cert step.
    if (ms_active_run($dir . '/runs')) return ['error' => 'This batch has a run in progress — wait for it to finish (or check its status) before deleting.'];
    return ms_rmtree($dir) ? ['ok' => true] : ['error' => 'Could not delete the batch folder.'];
}

/**
 * Point a batch at a different master. The batch folder physically moves, because a
 * batch lives inside its master. Callers are expected to have shown the warnings first
 * (see ms_swap_master_warnings()).
 */
function ms_set_batch_master(string $masterId, string $batchId, string $newMasterId): array {
    $meta = ms_batch_meta($masterId, $batchId);
    if (!$meta)                          return ['error' => 'Batch not found.'];
    if (!ms_valid_master_id($newMasterId)) return ['error' => 'Pick a master site.'];
    if ($newMasterId === $masterId)      return ['ok' => true, 'id' => $batchId];
    // Moving the folder out from under a background run_campaign.php process that's
    // mid-write to it would silently break that process rather than error here.
    if (ms_active_run(ms_batch_dir($masterId, $batchId) . '/runs')) {
        return ['error' => 'This batch has a run in progress — wait for it to finish before moving it.'];
    }

    // Locked the same way as ms_create_batch()/ms_copy_batch(): pick the id and move
    // the folder into place as one step, so two concurrent moves onto the same new
    // master can't compute the same id and race each other's rename().
    $lock = ms_batches_root($newMasterId) . '/.newid.lock';
    $moved = ms_with_launch_lock($lock, function () use ($masterId, $batchId, $newMasterId) {
        $newId  = ms_next_batch_id($newMasterId);
        $newDir = ms_batch_dir($newMasterId, $newId);
        if (!is_dir(dirname($newDir)) && !@mkdir(dirname($newDir), 0775, true) && !is_dir(dirname($newDir))) {
            return ['error' => 'Could not create the batch folder on the new master.'];
        }
        if (!@rename(ms_batch_dir($masterId, $batchId), $newDir)) return ['error' => 'Could not move the batch.'];
        return ['id' => $newId];
    });
    if (isset($moved['error'])) return $moved;
    $newId = $moved['id'];

    $meta['id']        = $newId;
    $meta['master_id'] = $newMasterId;
    // Checked, unlike its siblings above weren't: a rename() that succeeded but a
    // metadata write that then failed used to still report ok:true, re-point the
    // session at the new id, and leave batch.json on disk holding the OLD id/
    // master_id — every later action keyed off that stale id then failed with
    // "Batch not found" for a batch whose data was actually intact.
    if (!ms_save_batch_meta($newMasterId, $newId, $meta)) {
        return ['error' => 'Batch moved, but its record could not be updated — reload before continuing.', 'id' => $newId, 'master_id' => $newMasterId];
    }
    return ['ok' => true, 'id' => $newId, 'master_id' => $newMasterId];
}

/**
 * The PHP CLI binary to launch detached work with.
 *
 * PHP_BINARY is EMPTY under mod_php (apache2handler) — documented behaviour — so
 * escapeshellarg(PHP_BINARY) yields '' and the shell runs `setsid '' script.php`,
 * answers "Permission denied" and exits 127. The job reports as started and nothing
 * happens. Only the CLI, where PHP_BINARY is populated, was ever unaffected.
 *
 * Every detached launcher in multisite_api.php now uses this — run, research, create
 * hosts and upload. Before it, pressing any of those from the browser produced
 * "setsid: failed to execute : No such file or directory" in the run log and a job
 * that reported as started. It only ever worked from the CLI.
 */
function ms_php_cli(): string
{
    static $bin = null;
    if ($bin !== null) return $bin;

    $candidates = [];
    if (PHP_BINARY !== '' && @is_executable(PHP_BINARY) && !is_dir(PHP_BINARY)) $candidates[] = PHP_BINARY;
    if (defined('PHP_BINDIR')) $candidates[] = rtrim(PHP_BINDIR, '/') . '/php';
    $which = trim((string) @shell_exec('command -v php 2>/dev/null'));
    if ($which !== '') $candidates[] = $which;
    $candidates[] = '/usr/bin/php';

    foreach ($candidates as $c) {
        if ($c !== '' && @is_executable($c) && !is_dir($c)) return $bin = $c;
    }
    return $bin = 'php';   // let the shell resolve it; better than a certain-empty string
}

/**
 * Where a generated site waits between being built and being uploaded.
 *
 *   sites/{master}/batches/{batch}/output/{domain-slug}/
 *
 * Inside the batch because that is what it belongs to, and under a gitignored path
 * because it is build output, not source. It persists deliberately: generating and
 * uploading are two acts now, and the second needs the first to have left something
 * behind. A re-generate overwrites in place, so the disk cost is one copy per domain.
 */
function ms_batch_output_dir(string $masterId, string $batchId, string $domain): string
{
    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($domain)));
    return ms_batch_dir($masterId, $batchId) . '/output/' . trim($slug, '_');
}

/** Every domain that currently has generated output waiting, newest first. */
function ms_batch_built(string $masterId, string $batchId): array
{
    $base = ms_batch_dir($masterId, $batchId) . '/output';
    $out  = [];
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $files = 0;
        $it = @new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
        if ($it) foreach ($it as $f) if ($f->isFile()) $files++;
        $out[basename($d)] = ['dir' => $d, 'files' => $files, 'built_at' => filemtime($d)];
    }
    return $out;
}

// ── Deployment plan: which boxes this batch lands on ─────────────────────────

/**
 * Which servers this batch deploys to and how many sites each takes.
 *
 * Deliberately NO ordering field: the sequence boxes are filled in is decided
 * elsewhere, so storing a number here would be a second answer to a question this
 * file does not own — and the stale one, since nothing would keep it current.
 *
 * Stored per BATCH (servers.json beside params.csv) rather than per master, because
 * two batches off the same master routinely go to different boxes — that is the whole
 * point of running eight of them.
 *
 * It is a PLAN, not a record: it says where sites are meant to go. What is actually on
 * a box is read from the box. Keeping those apart matters, because the interesting
 * failure is precisely when they disagree.
 *
 * @return array<int,array{server_id:string,host:string,label:string,count:int}>
 */
function ms_batch_servers(string $masterId, string $batchId): array
{
    $f = ms_batch_dir($masterId, $batchId) . '/servers.json';
    if (!is_file($f)) return [];
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d['plan'] ?? null) ? $d['plan'] : [];
}

/** @return array ['ok'=>true] | ['error'=>string] */
function ms_save_batch_servers(string $masterId, string $batchId, array $plan): array
{
    if (!ms_batch_exists($masterId, $batchId)) return ['error' => 'Batch not found.'];

    $clean = [];
    $seen  = [];
    foreach ($plan as $p) {
        $id = trim((string) ($p['server_id'] ?? ''));
        if ($id === '' || isset($seen[$id])) continue;   // a box cannot be picked twice
        $seen[$id] = true;
        $clean[] = [
            'server_id' => $id,
            'host'      => trim((string) ($p['host'] ?? '')),
            'label'     => trim((string) ($p['label'] ?? $id)),
            // 0 means "no cap" — take whatever is left.
            'count'     => max(0, (int) ($p['count'] ?? 0)),
        ];
    }

    $dir = ms_batch_dir($masterId, $batchId);
    $ok = ms_atomic_write_json($dir . '/servers.json', ['updated_at' => gmdate('c'), 'plan' => $clean]);
    return $ok ? ['ok' => true, 'plan' => $clean] : ['error' => 'Could not save the server plan.'];
}

// ── Run reading (shared by the batch page and the home panel) ─────────────────

/** True if a process id is alive (Linux /proc, or posix). */
function ms_pid_alive(int $pid): bool {
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return file_exists('/proc/' . $pid);
}

/** The newest run status file in a runs dir, or ''. */
function ms_latest_run_file(string $runsDir): string {
    $files = glob($runsDir . '/*.json') ?: [];
    if (!$files) return '';
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0];
}

/** Read a run status file and mark a dead 'running' run as 'stale'. */
function ms_read_run(string $file): ?array {
    if (!is_file($file)) return null;
    $d = json_decode((string) file_get_contents($file), true);
    if (!is_array($d)) return null;
    if (($d['state'] ?? '') === 'running' && !ms_pid_alive((int) ($d['pid'] ?? 0))) $d['state'] = 'stale';
    return $d;
}

/** The genuinely-running run in a runs dir, or null. */
function ms_active_run(string $runsDir): ?array {
    $latest = ms_latest_run_file($runsDir);
    if (!$latest) return null;
    $cur = ms_read_run($latest);
    return ($cur && ($cur['state'] ?? '') === 'running') ? $cur : null;
}

/**
 * Run $fn() while holding an exclusive file lock, so a "check something,
 * then act on it" sequence — that would otherwise be two separate steps a
 * second request could interleave with — happens as one atomic step instead.
 * Two uses in this file: "is a job already running?" then "start it" (a
 * double-click, or a retried request on a slow connection, could otherwise
 * see "not running" twice and launch two background jobs for the same
 * batch), and "pick the next batch id" then "claim its folder" (two
 * concurrent creates/copies could otherwise both compute the same id and
 * collide on one folder).
 *
 * The lock is held only for $fn()'s own duration — a glob+exec, or an
 * id-scan+mkdir, both fast — never for a background job or a big copy
 * itself, so this does not reintroduce the whole-request session-lock bug
 * this project already hit once.
 */
function ms_with_launch_lock(string $lockFile, callable $fn) {
    $dir = dirname($lockFile);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $fh = fopen($lockFile, 'c');
    if (!$fh) return $fn();   // can't lock — fail open rather than block the action entirely
    try {
        flock($fh, LOCK_EX);
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** How many target rows a batch holds (cheap line count — no CSV parse). */
function ms_batch_target_count(string $masterId, string $batchId): int {
    $csv = ms_batch_dir($masterId, $batchId) . '/params.csv';
    if (!is_file($csv)) return 0;
    $n = 0;
    $fh = fopen($csv, 'r');
    if (!$fh) return 0;
    while (($cells = fgetcsv($fh)) !== false) {
        // fgetcsv() returns [null], not false, for a blank line — a params.csv with
        // trailing newlines (common from some CSV writers/editors) would otherwise
        // inflate this count by one per trailing blank line. Same skip ms_parse_csv()
        // already applies.
        if (count($cells) === 1 && trim((string) $cells[0]) === '') continue;
        $n++;
    }
    fclose($fh);
    return max(0, $n - 1);   // minus the header line
}

/** The newest run record for a batch, in full (results included), or null. */
function ms_batch_latest_run(string $masterId, string $batchId): ?array {
    $file = ms_latest_run_file(ms_batch_dir($masterId, $batchId) . '/runs');
    return $file ? ms_read_run($file) : null;
}

/** One-line summary for a batch row on the home panel. */
function ms_batch_status(string $masterId, string $batchId): array {
    $s = [
        'targets' => ms_batch_target_count($masterId, $batchId),
        'has_run' => false, 'state' => '', 'run_id' => '',
        'total' => 0, 'ok' => 0, 'failed' => 0, 'cost' => 0.0, 'finished_at' => null,
    ];
    $file = ms_latest_run_file(ms_batch_dir($masterId, $batchId) . '/runs');
    if (!$file) return $s;
    $d = ms_read_run($file);
    if (!$d) return $s;
    return array_merge($s, [
        'has_run'     => true,
        'state'       => $d['state'] ?? '?',
        'run_id'      => $d['run_id'] ?? basename($file, '.json'),
        'total'       => (int) ($d['total'] ?? 0),
        'ok'          => (int) ($d['ok'] ?? 0),
        'failed'      => (int) ($d['failed'] ?? 0),
        'cost'        => (float) ($d['totals']['cost_usd'] ?? 0),
        'finished_at' => $d['finished_at'] ?? $d['started_at'] ?? null,
    ]);
}

// ── The batch you currently have open ─────────────────────────────────────────

/** ['master_id'=>..,'batch_id'=>..] for the open batch, or null. */
function ms_active_batch(): ?array {
    $m = $_SESSION['active_site']  ?? '';
    $b = $_SESSION['active_batch'] ?? '';
    if ($m === '' || $b === '' || !ms_batch_exists($m, $b)) return null;
    return ['master_id' => $m, 'batch_id' => $b];
}

/** Folder of the open batch, or '' when none is open. */
function ms_active_batch_dir(): string {
    $a = ms_active_batch();
    return $a ? ms_batch_dir($a['master_id'], $a['batch_id']) : '';
}

// ── Swapping a batch onto a different master ──────────────────────────────────

/** A master's niche, from its niche brief ('' when unknown). */
function ms_master_niche(string $masterId): string {
    $b = @json_decode((string) @file_get_contents(ms_master_dir($masterId) . '/niche_brief.json'), true);
    return is_array($b) ? trim((string) ($b['niche'] ?? '')) : '';
}

/** The theme-preset ids and names a master offers (lowercased, for matching). */
function ms_master_preset_keys(string $masterId): array {
    $doc = @json_decode((string) @file_get_contents(ms_master_dir($masterId) . '/theme_presets.json'), true);
    $keys = [];
    foreach (($doc['presets'] ?? []) as $i => $p) {
        if (isset($p['id']))   $keys[] = strtolower(trim((string) $p['id']));
        if (isset($p['name'])) $keys[] = strtolower(trim((string) $p['name']));
        $keys[] = (string) ($i + 1);              // presets are also addressable by position
    }
    return array_values(array_unique(array_filter($keys, fn($k) => $k !== '')));
}

/** Display name for a site (meta.json name, else the id). */
function ms_site_name(string $siteId): string {
    $m = @json_decode((string) @file_get_contents(BASE_DIR . '/sites/' . $siteId . '/meta.json'), true);
    return trim((string) ($m['name'] ?? '')) ?: $siteId;
}

/**
 * Everything the operator should see before re-pointing a batch at a new master.
 * Returns plain sentences, worst first. An empty array means the swap is harmless.
 *
 * The three real dangers: overwriting sites that are already live, silently building
 * the wrong niche onto the right domains, and theme_preset names that only existed on
 * the old master (which fail quietly rather than erroring).
 */
function ms_swap_master_warnings(string $masterId, string $batchId, string $newMasterId): array {
    $w = [];

    // 1. Already-live sites get their content replaced on the next run.
    $st = ms_batch_status($masterId, $batchId);
    if ($st['has_run'] && $st['ok'] > 0) {
        $w[] = $st['ok'] . ' site(s) were built from "' . ms_site_name($masterId) . '". Changing the master means your '
             . 'next run REPLACES their content at the same web addresses.';
    }

    // 2. Different niche: the build succeeds and quietly makes the wrong kind of site.
    $oldNiche = ms_master_niche($masterId);
    $newNiche = ms_master_niche($newMasterId);
    if ($oldNiche !== '' && $newNiche !== '' && strcasecmp($oldNiche, $newNiche) !== 0) {
        $w[] = 'DIFFERENT NICHE: this batch\'s domains and business names are for "' . $oldNiche . '", but "'
             . ms_site_name($newMasterId) . '" builds "' . $newNiche . '" sites. The run will NOT error — it will '
             . 'just build the wrong kind of site on every domain.';
    }

    // 3. theme_preset values that do not exist on the new master fail silently.
    $csv = ms_batch_dir($masterId, $batchId) . '/params.csv';
    if (is_file($csv)) {
        $p = ms_parse_csv($csv);
        if (empty($p['error'])) {
            $have = ms_master_preset_keys($newMasterId);
            $missing = [];
            foreach ($p['rows'] as $r) {
                $v = strtolower(trim((string) ($r['theme_preset'] ?? '')));
                if ($v !== '' && !in_array($v, $have, true)) $missing[$v] = true;
            }
            if ($missing) {
                $w[] = 'These theme_preset values in your target list do not exist on the new master and will be '
                     . 'ignored: ' . implode(', ', array_keys($missing)) . '.';
            }
        }
    }
    return $w;
}

// ── Migration from the pre-batch layout ───────────────────────────────────────

/** The per-batch pieces that used to sit directly in sites/{master}/multisite/. */
const MS_BATCH_MOVES = ['params.csv', 'params.version', 'params_versions', 'runs', 'run.lock', 'research'];

/**
 * Fold a master's legacy target list + history into an auto-created "Batch 1".
 * Safe to run repeatedly: does nothing when there is no legacy params.csv, and never
 * touches a master that already has batches.
 *
 * @return string|null the new batch id, or null when there was nothing to migrate.
 */
function ms_migrate_legacy_batch(string $masterId): ?string {
    if (!ms_valid_master_id($masterId)) return null;
    if (ms_master_batches($masterId)) return null;               // already has batches
    $old = ms_master_dir($masterId);
    if (!is_file($old . '/params.csv')) return null;             // nothing to migrate
    // Same "don't move files out from under a live process" rule as
    // ms_delete_batch()/ms_set_batch_master() — narrow (this only runs once, the
    // first time a pre-batch master's sites.php loads), but a legacy run mid-flight
    // at that exact moment would otherwise have its runs/ moved out from under it.
    if (ms_active_run($old . '/runs')) return null;

    $res = ms_create_batch($masterId, 'Batch 1');
    if (isset($res['error'])) return null;
    $dir = ms_batch_dir($masterId, $res['id']);

    foreach (MS_BATCH_MOVES as $item) {
        $src = $old . '/' . $item;
        if (file_exists($src)) @rename($src, $dir . '/' . $item);
    }
    return $res['id'];
}

/** Run the migration for every master. @return array masterId => new batch id */
function ms_migrate_all_legacy_batches(): array {
    $done = [];
    foreach (glob(BASE_DIR . '/sites/*', GLOB_ONLYDIR) ?: [] as $d) {
        $id = basename($d);
        if (!ms_valid_master_id($id)) continue;
        $new = ms_migrate_legacy_batch($id);
        if ($new !== null) $done[$id] = $new;
    }
    return $done;
}
