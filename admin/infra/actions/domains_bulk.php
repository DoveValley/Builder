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
 *
 * Nothing here spends money — buying is a separate, deliberately separate step.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/acquire.php';

$backQs = (string) ($_POST['back'] ?? 'view=domains');
// Only our own query string may come back through — never an absolute URL.
if (!preg_match('/^view=domains[A-Za-z0-9=&_.\-%]*$/', $backQs)) $backQs = 'view=domains';
$back = '../index.php?' . $backQs;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

$action = (string) ($_POST['action'] ?? '');
$sel    = array_values(array_unique(array_filter(array_map(
    fn($d) => strtolower(trim((string) $d)), (array) ($_POST['sel'] ?? [])))));

/** Actions that operate on ticked rows all need at least one tick. */
$needsSelection = ['set_registrar', 'set_buy_at', 'schedule_buys', 'check_avail', 'remove'];
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
            // rendering the inputs, but a POST must be refused too.
            if (($rec['owned'] ?? '') === 'yes') { continue; }

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

    default:
        infra_set_flash('err', 'Unknown action.');
}

header('Location: ' . $back);
