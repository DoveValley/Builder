<?php
/**
 * infra/actions/pipeline_golive.php — everything the go-live grid's rows can do:
 * tag a batch, set release dates, schedule a batch, release one domain now.
 *
 * One endpoint because the grid is one form. Nested forms are invalid HTML, and the
 * grid needs a checkbox, a date box and a button on every row plus a Refresh on every
 * column heading — so the column Refreshes use formaction to reach their own endpoint
 * and everything else arrives here, told apart by which button was pressed.
 *
 * POST only, CSRF-guarded, session released after the token check.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/pipeline.php';
require_once __DIR__ . '/../lib/golive.php';

$batch = (string) ($_POST['batch'] ?? '');
$back  = '../index.php?view=bulk' . ($batch !== '' ? '&batch=' . urlencode($batch) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back);
    exit;
}
infra_session_release();
set_time_limit(0);

/** Which domains the row checkboxes selected, lowercased. */
$selected = array_values(array_filter(array_map(
    fn($d) => strtolower(trim((string) $d)), (array) ($_POST['sel'] ?? [])
)));

// A per-row button carries its domain as its own value, which is how one form can
// hold sixty-five of them: only the button actually pressed is submitted.
$release = strtolower(trim((string) ($_POST['release'] ?? '')));
$action  = $release !== '' ? 'release' : (string) ($_POST['action'] ?? '');

switch ($action) {

    case 'tag':
        // A batch is a tag. Writing one is a plain field update on the rows you ticked —
        // there is no batch object to create, and clearing the box un-tags them.
        $tag = trim((string) ($_POST['batch_tag'] ?? ''));
        if (!$selected) { infra_set_flash('err', 'No rows ticked.'); break; }
        $n = infra_state_bulk_set($selected, ['batch' => $tag]);
        infra_set_flash('ok', $tag === ''
            ? "Removed {$n} domain(s) from their batch."
            : "Tagged {$n} domain(s) as \"{$tag}\".");
        // Land on the batch just created, otherwise the rows appear to vanish.
        if ($tag !== '') $back = '../index.php?view=bulk&batch=' . urlencode($tag);
        break;

    case 'save_dates':
        // Only rows whose date actually changed are written, so saving does not stamp
        // updated_at across sixty-five untouched rows.
        $n = 0;
        foreach ((array) ($_POST['date'] ?? []) as $dom => $val) {
            $dom = strtolower(trim((string) $dom));
            $val = trim((string) $val);
            if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) continue;
            $rec = infra_state_get_domain($dom);
            if (!$rec || (string) ($rec['go_live_at'] ?? '') === $val) continue;
            infra_state_upsert_domain(['domain' => $dom, 'go_live_at' => $val,
                                       'status' => $val === '' ? ($rec['status'] ?: '') : 'queued']);
            $n++;
        }
        infra_set_flash($n ? 'ok' : 'warn', $n ? "Saved {$n} date(s)." : 'Nothing changed.');
        break;

    case 'schedule':
        $perDay = max(1, (int) ($_POST['per_day'] ?? 10));
        $start  = trim((string) ($_POST['start_date'] ?? ''));
        $r = infra_golive_schedule($perDay, $start, ['batch' => $batch, 'gate' => true]);
        infra_set_flash($r['skipped'] > 0 ? 'warn' : 'ok',
            "Scheduled {$r['scheduled']} domain(s) at {$perDay}/day"
            . ($r['first'] !== '' ? ", {$r['first']} to {$r['last']}" : '') . '.'
            . ($r['skipped'] > 0 ? " {$r['skipped']} skipped — nothing is uploaded to them yet, so they would have been refused on the day." : ''));
        break;

    case 'release':
        // The one outward-facing action on this page: it switches nameservers at the
        // registrar and the world starts resolving the domain.
        // Keyed by domain, NOT a single `force` field: with one form holding sixty-five
        // rows, a plain checkbox ticked on one row would apply to whichever button was
        // pressed on any other. For the one action here the outside world can see, that
        // is not a risk worth carrying.
        $force = !empty($_POST['force'][$release]);
        $r = infra_golive_release($release, $force);
        infra_set_flash($r['ok'] ? 'ok' : (!empty($r['manual']) || !empty($r['gated']) ? 'warn' : 'err'),
            ($r['ok'] ? 'Released ' : 'Did not release ') . $release . ': ' . $r['message']
            . (!empty($r['gated']) ? ' — tick "override" beside the button if you mean to do it anyway.' : ''));
        // Re-read the two columns the release just changed, so the row tells the truth
        // on the page you land back on instead of a minute later.
        if ($r['ok']) { infra_pipeline_refresh('golive', $batch); infra_pipeline_refresh('dns', $batch); }
        break;

    default:
        infra_set_flash('err', 'Unknown action.');
}

header('Location: ' . $back);
