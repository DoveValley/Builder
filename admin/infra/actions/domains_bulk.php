<?php
/**
 * infra/actions/domains_bulk.php — edits on the Domains table (CSRF-guarded, PRG).
 *
 * action = save_edits     inline Register / Buy date fields on the current page
 *        | set_registrar  assign a registrar to the ticked rows (or spread them)
 *        | set_buy_at     one date onto the ticked rows
 *        | schedule_buys  spread the ticked rows N/day from a start date
 *        | check_avail    availability check for the ticked rows (read-only)
 *        | remove         untrack the ticked rows (no infrastructure touched)
 *        | to_dfinder     hand ticked TAKEN rows back to D.Finder as "Not available"
 *        | to_bulk        hand ticked OWNED rows to Bulk Provision's target textarea
 *
 * Nothing here spends money — buying is a separate, deliberately separate step.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/acquire.php';

$backQs = (string) ($_POST['back'] ?? 'view=domains');
// Only our own query string may come back through — never an absolute URL.
// \z rather than $ — PCRE's $ also matches before a trailing newline.
if (!preg_match('/^view=domains[A-Za-z0-9=&_.\-%]*\z/', $backQs)) $backQs = 'view=domains';
$back = '../index.php?' . $backQs;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

// The session has been read (auth + CSRF). Let go of its lock so a slow job
// here does not queue up every other click in the console — see
// infra_session_release() in bootstrap.php.
infra_session_release();

$action = (string) ($_POST['action'] ?? '');
$sel    = array_values(array_unique(array_filter(array_map(
    fn($d) => strtolower(trim((string) $d)), (array) ($_POST['sel'] ?? [])))));

/** Actions that operate on ticked rows all need at least one tick. */
$needsSelection = ['set_registrar', 'set_buy_at', 'schedule_buys', 'check_avail', 'remove', 'to_dfinder', 'to_bulk'];
if (in_array($action, $needsSelection, true) && !$sel) {
    infra_set_flash('warn', 'No rows ticked — nothing to do.');
    header('Location: ' . $back); exit;
}

/**
 * Scheduling a buy for a domain that isn't marked ready is allowed but reported:
 * the purchase would just fail later, and a silent date on a taken name is the
 * kind of thing that reads as "scheduled" right up until it doesn't work.
 */
$notReadyNote = function (array $domains): string {
    $bad = [];
    foreach ($domains as $d) {
        $rec = infra_state_get_domain($d);
        if (($rec['ready_to_buy'] ?? '') !== 'yes') $bad[] = $d;
    }
    if (!$bad) return '';
    return "\n⚠ " . count($bad) . ' of these are not marked ready to buy (unchecked or unavailable): '
         . implode(', ', array_slice($bad, 0, 6)) . (count($bad) > 6 ? ' …' : '')
         . "\nRun Check availability on them first — a buy against a taken name will fail.";
};

switch ($action) {

    /* ---- inline edits on the visible page ------------------------------- */
    case 'save_edits':
        $regs    = (array) ($_POST['reg'] ?? []);
        $buys    = (array) ($_POST['buy'] ?? []);
        $niches  = (array) ($_POST['niche'] ?? []);
        $valid   = infra_registrar_names();
        $changed = 0; $badDate = 0; $locked = 0;
        foreach (array_keys($regs + $buys + $niches) as $dom) {
            $dom = strtolower(trim((string) $dom));
            $rec = infra_state_get_domain($dom);
            if (!$rec) continue;

            // Niche stays editable after purchase — it describes what the domain is
            // FOR, which is a plan you can change, unlike where and when it was bought.
            $newNiche = infra_niche((string) ($niches[$dom] ?? $rec['niche']));
            if (array_key_exists($dom, $niches) && $newNiche !== $rec['niche']) {
                infra_state_upsert_domain(['domain' => $dom, 'niche' => $newNiche]);
                $changed++;
            }

            // An owned domain's registrar and buy date are history. The view stops
            // rendering the inputs, but a POST must be refused too — and counted,
            // or the "N already owned" line below can never appear.
            if (($rec['owned'] ?? '') === 'yes') {
                if (array_key_exists($dom, $regs) || array_key_exists($dom, $buys)) $locked++;
                continue;
            }

            $newReg = strtolower(trim((string) ($regs[$dom] ?? '')));
            if ($newReg !== '' && !in_array($newReg, $valid, true)) $newReg = '';

            $newBuy = trim((string) ($buys[$dom] ?? ''));
            if ($newBuy !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newBuy)) { $badDate++; $newBuy = $rec['buy_at']; }

            if ($newReg === $rec['buy_registrar'] && $newBuy === $rec['buy_at']) continue;
            infra_state_upsert_domain(['domain' => $dom, 'buy_registrar' => $newReg, 'buy_at' => $newBuy]);
            $changed++;
        }
        infra_set_flash(($badDate || $locked) ? 'warn' : 'ok',
            "Saved {$changed} row(s)."
            . ($badDate ? "  {$badDate} date(s) ignored — bad format." : '')
            . ($locked ? "  {$locked} already owned — registrar and buy date are fixed once bought." : ''));
        break;

    /* ---- bulk: registrar ------------------------------------------------ */
    case 'set_registrar':
        $choice = (string) ($_POST['bulk_registrar'] ?? '');
        if ($choice === '') {
            infra_set_flash('warn', 'Pick a registrar first.');
            break;
        }
        if ($choice !== '__rr__' && !in_array($choice, infra_registrar_names(), true)) {
            infra_set_flash('err', 'Unknown registrar.');
            break;
        }
        $r = infra_domains_assign_registrars($sel, $choice);
        if ($choice === '__rr__' && !$r['n']) {
            infra_set_flash('err', 'No registrar in the pool can complete a purchase over its API — nothing assigned.');
            break;
        }
        $detail = '';
        if ($choice === '__rr__') {
            $bits = [];
            foreach ($r['spread'] as $name => $c) if ($c) $bits[] = "{$name} {$c}";
            $detail = "\nSpread: " . implode(' · ', $bits);
        }
        infra_set_flash('ok', "Registrar set on {$r['n']} domain(s)." . $detail);
        break;

    /* ---- bulk: one buy date --------------------------------------------- */
    case 'set_buy_at':
        $date = trim((string) ($_POST['bulk_buy_at'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            infra_set_flash('err', 'Buy date must be YYYY-MM-DD.');
            break;
        }
        $n = infra_state_bulk_set($sel, ['buy_at' => $date]);
        if ($date === '') {
            infra_set_flash('ok', "Cleared the buy date on {$n} domain(s).");
            break;
        }
        $warn = $notReadyNote($sel);
        infra_set_flash($warn ? 'warn' : 'ok', "Buy date set to {$date} on {$n} domain(s)." . $warn);
        break;

    /* ---- bulk: spread buys over days ------------------------------------ */
    case 'schedule_buys':
        $perDay = (int) ($_POST['per_day'] ?? 20);
        $from   = trim((string) ($_POST['spread_from'] ?? ''));
        if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            infra_set_flash('err', 'Start date must be YYYY-MM-DD.');
            break;
        }
        $n    = infra_state_schedule_buys($sel, $perDay, $from);
        $days = $perDay > 0 ? (int) ceil($n / max(1, $perDay)) : 0;
        $warn = $notReadyNote($sel);
        infra_set_flash($warn ? 'warn' : 'ok', "Scheduled {$n} domain(s) at {$perDay}/day from "
            . ($from ?: infra_today()) . " — {$days} day(s) of buying." . $warn);
        break;

    /* ---- bulk: availability (read-only) ---------------------------------
       Checked with ONE chosen registrar, not each domain's assigned one.
       Availability is a public fact — every registrar reads the same registry —
       so the only thing that differs is speed. Asking each domain's buyer meant a
       400-name list crawling through Porkbun at one per ten seconds and skipping
       Cloudflare, which has no availability endpoint at all. */
    case 'check_avail':
        $checkers = infra_registrar_checkers();
        if (!$checkers) {
            infra_set_flash('err', 'No registrar that can check availability is configured — add one on the Registrars tab.');
            break;
        }
        $reg = (string) ($_POST['check_with'] ?? '');
        if ($reg === '' || !isset($checkers[$reg])) $reg = infra_default_checker();

        $res  = infra_domains_apply_availability($sel, $reg);
        $note = '';
        if (($checkers[$reg]['bulk'] ?? 1) < 10 && count($sel) > 20) {
            $note = "\n(" . $checkers[$reg]['label'] . ' is ' . $checkers[$reg]['speed']
                  . ' — for long lists pick a faster one.)';
        }
        infra_set_flash('ok', $res['summary'] . $note);
        break;

    /* ---- bulk: set niche ------------------------------------------------- */
    case 'set_niche':
        $n = infra_niche((string) ($_POST['bulk_niche'] ?? ''));
        if ($n === '') { infra_set_flash('err', 'Pick a niche.'); break; }
        $c = infra_state_bulk_set($sel, ['niche' => $n]);
        infra_set_flash('ok', "Niche set to {$n} on {$c} domain(s).");
        break;

    /* ---- bulk: set ready-to-buy by hand ----------------------------------
       The availability check normally decides this. This is the override for when
       you know better than it does — a check that could not run, or a name you
       have already confirmed elsewhere. Recorded as a manual decision so it is
       never mistaken for a verified one. */
    case 'set_ready':
        $val = (string) ($_POST['ready_val'] ?? '');
        if (!in_array($val, ['yes', 'no', ''], true)) {
            infra_set_flash('err', 'Ready-to-buy must be yes, no, or cleared.');
            break;
        }
        $n = 0;
        foreach ($sel as $d) {
            $rec = infra_state_get_domain($d);
            if (!$rec) continue;
            $w = ['domain' => $d, 'ready_to_buy' => $val,
                  'avail_note' => $val === '' ? '' : 'set by hand'];
            // Only move the lifecycle inside the acquisition stage — never drag an
            // owned or staged domain backwards.
            if (in_array($rec['status'] ?: 'begin', ['', 'begin', 'ready'], true)) {
                $w['status'] = ($val === 'yes') ? 'ready' : 'begin';
            }
            infra_state_upsert_domain($w);
            $n++;
        }
        infra_set_flash('ok', $val === ''
            ? "Cleared ready-to-buy on {$n} domain(s)."
            : "Ready to buy set to {$val} by hand on {$n} domain(s).");
        break;

    /* ---- bulk: untrack -------------------------------------------------- */
    case 'remove':
        $kept = [];
        foreach ($sel as $d) {
            $rec = infra_state_get_domain($d);
            // Refuse to silently drop a domain that has real infrastructure behind
            // it — that belongs in the per-domain Danger Zone, with a typed confirm.
            if ($rec && (($rec['owned'] ?? '') === 'yes' || ($rec['cf_zone_id'] ?? '') !== '' || ($rec['ftp_user'] ?? '') !== '')) {
                $kept[] = $d; continue;
            }
            infra_state_delete_domain($d);
        }
        $removed = count($sel) - count($kept);
        $msg = "Removed {$removed} domain(s) from the table.";
        if ($kept) {
            $msg .= "\nKept " . count($kept) . ' with a purchase or live infrastructure — use the domain\'s own page to tear those down: '
                  . implode(', ', array_slice($kept, 0, 6)) . (count($kept) > 6 ? ' …' : '');
        }
        infra_set_flash($kept ? 'warn' : 'ok', $msg);
        break;

    /* ---- bulk: hand taken names back to D.Finder ------------------------- */
    case 'to_dfinder':
        $state = infra_dfinder_load();
        if ($state === null) {
            infra_set_flash('err', 'D.Finder has no saved state yet — open it once first.');
            break;
        }

        /* Match D.Buy's niche to a workbench niche by keyword, not by an exact name.
           The workbench's niches are user-named ("Water restoration" for what D.Buy
           calls "restoration") and renameable, so an exact-name map would quietly
           stop matching the first time one is edited. */
        $nicheKey = function (string $name): string {
            $n = strtolower($name);
            foreach (['pest' => 'pest', 'mold' => 'mold', 'appliance' => 'appliance',
                      'restoration' => 'restoration', 'water' => 'restoration'] as $needle => $key) {
                if (str_contains($n, $needle)) return $key;
            }
            return '';
        };
        $nicheIdx = [];
        foreach ($state['niches'] as $i => $n) {
            $k = $nicheKey((string) ($n['name'] ?? ''));
            if ($k !== '' && !isset($nicheIdx[$k])) $nicheIdx[$k] = $i;
        }

        $moved = 0; $skipped = []; $noNiche = []; $alreadyThere = 0;
        foreach ($sel as $d) {
            $rec = infra_state_get_domain($d);
            if (!$rec) continue;

            // Only names that were checked and came back taken, and that this console
            // never bought. Anything owned or provisioned is not a D.Finder candidate.
            if (($rec['avail_note'] ?? '') !== 'taken'
                || ($rec['status'] ?: 'begin') !== 'begin'
                || ($rec['owned'] ?? '') === 'yes') { $skipped[] = $d; continue; }
            // Subdomains are infrastructure, never candidates.
            if (substr_count($d, '.') >= 2) { $skipped[] = $d; continue; }

            $k = $nicheKey((string) ($rec['niche'] ?? ''));
            if ($k === '' || !isset($nicheIdx[$k])) { $noNiche[] = $d; continue; }
            $ni = $nicheIdx[$k];

            foreach (($state['niches'][$ni]['candidates'] ?? []) as $c) {
                if (strtolower((string) ($c['domain'] ?? '')) === $d) { $alreadyThere++; break; }
            }

            /* 'person' is the name without the service keyword — the workbench groups
               by it. Strip the LONGEST matching pattern so "adamspest" and
               "adamspestcontrol" both reduce to "adams". */
            $label  = preg_replace('/\.[a-z]+$/i', '', $d);
            $person = $label;
            $best   = '';
            foreach (($state['niches'][$ni]['patterns'] ?? []) as $p) {
                $kw = strtolower((string) ($p['kw'] ?? ''));
                if ($kw !== '' && str_ends_with($label, $kw) && strlen($kw) > strlen($best)) $best = $kw;
            }
            if ($best !== '') $person = substr($label, 0, -strlen($best));

            /* QUEUE it rather than writing the blob. The workbench autosaves its whole
               in-memory state 500ms after any change, so a tab that was already open
               posts its older copy over the top and the addition disappears with
               nothing said — which is exactly what happened the first time this ran.
               dfinder_state.php folds the queue into every read instead, so the
               hand-off survives an overwrite and reloading D.Finder is enough.

               Queue FIRST, untrack second: the reverse order meant a failed write left
               the domain deleted and nowhere to be found. */
            infra_dfinder_taken_add($d, (string) $state['niches'][$ni]['name'], $person);
            infra_state_delete_domain($d);
            $moved++;
        }

        /* Deliberately NOT touching state['registry']. The workbench treats 'taken'
           as NOT_SPENT — "if every domain built on it came back taken, the name was
           never really used, reuse it" — so retiring the name here would fight its
           own design and cost you every other keyword on that name. */

        $msg = $moved
            ? "Moved {$moved} taken domain(s) to D.Finder as \u{201C}Not available\u{201D} and removed them here."
                . "\nReload D.Finder to see them — it only reads its state when the page loads."
            : 'Nothing moved.';
        if ($alreadyThere) $msg .= "\n{$alreadyThere} were already candidates there — their status will be set to Not available.";
        if ($noNiche) {
            $msg .= "\nLeft " . count($noNiche) . ' with no matching D.Finder niche: '
                  . implode(', ', array_slice($noNiche, 0, 6)) . (count($noNiche) > 6 ? ' …' : '');
        }
        if ($skipped) {
            $msg .= "\nSkipped " . count($skipped) . ' that are not an unbought taken name: '
                  . implode(', ', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? ' …' : '');
        }
        infra_set_flash(($noNiche || $skipped) ? 'warn' : 'ok', $msg);
        break;

    /* ---- bulk: send ticked rows to Bulk Provision's target list -----------
       A one-time UI handoff, not a data move: nothing here is provisioned and
       nothing leaves this table — the domain still shows up next time this
       page loads (it only drops off once Bulk actually changes its status).
       Only owned domains make sense to hand off — anything else has no
       purchase receipt yet, so it is reported and left behind rather than
       silently included in the count. */
    case 'to_bulk':
        $ready = []; $notOwned = [];
        foreach ($sel as $d) {
            $rec = infra_state_get_domain($d);
            if ($rec && ($rec['owned'] ?? '') === 'yes') $ready[] = $d;
            else $notOwned[] = $d;
        }
        if (!$ready) {
            infra_set_flash('err', 'None of the ticked rows are owned yet — only owned domains can be sent to Bulk.');
            break;
        }
        // Read once by bulk.php via infra_session_take(), which clears the key on
        // read — a later plain visit to Bulk starts with an empty textarea again.
        infra_session_resume();
        $_SESSION['infra_bulk_prefill'] = implode("\n", $ready);
        $msg = 'Sent ' . count($ready) . ' domain(s) to Bulk — check the textarea there.';
        if ($notOwned) {
            $msg .= "\nLeft " . count($notOwned) . ' not yet owned: '
                  . implode(', ', array_slice($notOwned, 0, 6)) . (count($notOwned) > 6 ? ' …' : '');
        }
        infra_set_flash($notOwned ? 'warn' : 'ok', $msg);
        header('Location: ../index.php?view=bulk'); exit;

    default:
        infra_set_flash('err', 'Unknown action.');
}

header('Location: ' . $back);
