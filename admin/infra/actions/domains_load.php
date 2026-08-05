<?php
/**
 * infra/actions/domains_load.php — load new domain names into the table at
 * 'begin' state. Accepts a pasted list and/or a CSV upload in the same POST.
 * CSRF-guarded, PRG. Spends nothing; the optional availability check is read-only.
 *
 * Additive by design: a domain already tracked is left untouched, so re-loading
 * the same list can never reset progress on domains already moving through.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/acquire.php';   // infra_domains_apply_availability()

$back = '../index.php?view=domains';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

$defaultNiche = trim((string) ($_POST['niche'] ?? ''));
$candidates   = [];   // domain => niche

/* ---- pasted list ------------------------------------------------------- */
foreach (preg_split('/[\s,;]+/', (string) ($_POST['domains'] ?? '')) as $tok) {
    $tok = strtolower(trim($tok));
    if ($tok !== '') $candidates[$tok] = $defaultNiche;
}

/* ---- CSV upload -------------------------------------------------------- */
$csvNote = '';
if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
    if (($_FILES['csv']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $csvNote = "\nCSV upload failed (error {$_FILES['csv']['error']}).";
    } elseif (($_FILES['csv']['size'] ?? 0) > 2 * 1024 * 1024) {
        $csvNote = "\nCSV ignored — larger than 2 MB.";
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if ($fh) {
            $domCol = 0; $nicheCol = null; $first = true; $n = 0;
            while (($row = fgetcsv($fh)) !== false) {
                if ($row === [null] || $row === false) continue;
                $cells = array_map(fn($c) => trim((string) $c), $row);
                if ($first) {
                    $first = false;
                    // Header detection: a header's first cell is not a valid domain.
                    $lower = array_map('strtolower', $cells);
                    if (!infra_valid_domain($cells[0] ?? '')) {
                        foreach ($lower as $i => $h) {
                            if (in_array($h, ['domain', 'domain_name', 'domainname', 'name'], true)) $domCol = $i;
                            if (in_array($h, ['niche', 'vertical', 'category'], true))               $nicheCol = $i;
                        }
                        continue;   // skip the header row itself
                    }
                }
                $dom = strtolower($cells[$domCol] ?? '');
                if ($dom === '') continue;
                $niche = $nicheCol !== null ? ($cells[$nicheCol] ?? '') : $defaultNiche;
                $candidates[$dom] = $niche !== '' ? $niche : $defaultNiche;
                $n++;
            }
            fclose($fh);
            $csvNote = "\nCSV: {$n} row(s) read.";
        }
    }
}

if (!$candidates) {
    infra_set_flash('err', 'Nothing to load — paste some domains or upload a CSV.' . $csvNote);
    header('Location: ' . $back); exit;
}

/* ---- validate + insert ------------------------------------------------- */
$added = []; $valid = []; $existing = 0; $invalid = [];
foreach ($candidates as $dom => $niche) {
    if (!infra_valid_domain($dom)) { $invalid[] = $dom; continue; }
    $valid[] = $dom;
    if (infra_state_add_new_domain($dom, $niche)) $added[] = $dom;
    else                                          $existing++;
}

$msg = 'Loaded ' . count($added) . ' new domain(s) at begin state.'
     . ($existing ? "  {$existing} already in the table (left unchanged)." : '')
     . (count($invalid) ? "\nSkipped " . count($invalid) . ' invalid: ' . implode(', ', array_slice($invalid, 0, 8))
        . (count($invalid) > 8 ? ' …' : '') : '')
     . $csvNote;

/* ---- optional availability check (read-only, no money) -----------------
   Checks every VALID domain in the submission, not just the newly-added ones:
   re-pasting a list you already loaded is the normal way to re-check it, and
   silently skipping those would look like the button did nothing. */
if (!empty($_POST['check_now']) && $valid) {
    $registrar = (string) ($_POST['check_registrar'] ?? '');
    $res = infra_domains_apply_availability($valid, $registrar);
    $msg .= "\n" . $res['summary'];
}

infra_set_flash(count($invalid) ? 'warn' : 'ok', $msg);
header('Location: ' . $back);
