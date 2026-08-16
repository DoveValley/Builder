<?php
/**
 * infra/index.php — Infrastructure console (READ-ONLY scaffold).
 *   dashboard → fleet overview + server list
 *   domains   → fleet-wide domain inventory: registrar + Cloudflare + VPS, reconciled
 *   server    → one VPS: its Plesk sites + each domain's stack wiring
 *   plesk|cloudflare|golive → stubs (next build steps)
 * "Configure" = VPS→Plesk→Cloudflare→registrar wiring. No mutations.
 */
require_once __DIR__ . '/bootstrap.php';

$view = $_GET['view'] ?? 'dashboard';
if (!empty($_GET['refresh'])) infra_cache_force();   // ?refresh=1 → bypass cache, re-sweep live

function infra_find_server(string $id): ?array
{
    foreach (infra_servers() as $s) if (($s['id'] ?? '') === $id) return $s;
    return null;
}

/* small render helpers ------------------------------------------------- */
function infra_cf_cell(?array $z, bool $hasCf): string
{
    if ($z) {
        $cls = ($z['status'] === 'active') ? 'b-ok' : 'b-warn';
        return '<span class="badge ' . $cls . '">' . ih($z['account_label']) . ' · ' . ih($z['status'] ?: '?') . '</span>';
    }
    return $hasCf ? '<span class="badge b-warn">no zone</span>'
                  : '<span class="badge b-mut">no CF account</span>';
}
function infra_state_cell(string $state): string
{
    $map = [
        'live' => 'b-ok', 'owned' => 'b-ok',
        'staged' => 'b-warn', 'queued' => 'b-warn', 'releasing' => 'b-warn', 'awaiting-ns' => 'b-warn',
        'ready' => 'b-warn',
        'buy-failed' => 'b-err', 'register-failed' => 'b-err', 'partial' => 'b-err',
        'begin' => 'b-mut', 'unknown' => 'b-mut',
    ];
    return '<span class="badge ' . ($map[$state] ?? 'b-mut') . '">' . ih($state) . '</span>';
}

/** Col 2 — "Ready to buy", with the reason when the answer is no. */
function infra_ready_cell(array $r): string
{
    // Once it is owned the question no longer applies — showing a stale yes/no
    // there just invites you to read it as something still to be done.
    if (($r['owned'] ?? '') === 'yes') return '<span style="color:#9ca3af">&mdash;</span>';
    $note = (string) ($r['avail_note'] ?? '');
    $price = (string) ($r['avail_price'] ?? '');
    if ($r['ready_to_buy'] === 'yes') {
        return '<span class="badge b-ok">Yes</span>'
             . ($price !== '' ? ' <span style="color:#6b7280;font-size:11px">$' . ih($price) . '</span>' : '')
             . ($note === 'premium' ? ' <span class="badge b-warn">premium</span>' : '');
    }
    if ($r['ready_to_buy'] === 'no') {
        $label = $note === 'self-owned' ? 'you own it' : ($note !== '' ? $note : 'no');
        return '<span class="badge b-err">No</span> <span style="color:#6b7280;font-size:11px">' . ih($label) . '</span>';
    }
    // Never checked, or the check itself failed — say so rather than implying "no".
    if ($note !== '') return '<span class="badge b-mut">?</span> <span style="color:#b45309;font-size:11px">' . ih($note) . '</span>';
    return '<span class="badge b-mut">not checked</span>';
}

/** Col 5 — "Own": the receipt of a purchase, not an editable opinion. */
function infra_own_cell(array $r): string
{
    if (($r['owned'] ?? '') === 'yes') {
        // Verified auto-renew is still recorded on the row (and shown on the
        // domain's own page); the per-registrar explanation lives on the
        // Registrars tab rather than shouting from every line of the table.
        $ar    = (string) ($r['auto_renew'] ?? '');
        $title = $ar !== '' ? ' title="auto-renew: ' . ih($ar) . '"' : '';
        return '<span class="badge b-ok"' . $title . '>Yes</span>';
    }
    if (($r['state'] ?? '') === 'buy-failed') {
        return '<span class="badge b-err">failed</span>'
             . (($r['buy_error'] ?? '') !== '' ? '<br><span style="color:#991b1b;font-size:11px">' . ih(substr($r['buy_error'], 0, 60)) . '</span>' : '');
    }
    $ready = ($r['ready_to_buy'] ?? '') === 'yes' && ($r['buy_registrar'] ?? '') !== '';
    $due   = ($r['buy_at'] ?? '') !== '' && $r['buy_at'] <= infra_today();
    if ($ready) {
        return ($due ? '<span class="badge b-warn">due</span> ' : '')
             . '<a class="btn sec" style="padding:2px 8px;font-size:11px" href="index.php?view=domain&d='
             . ih($r['domain']) . '">Buy &rarr;</a>';
    }
    if ($due) return '<span class="badge b-warn">due</span>';
    return '<span style="color:#9ca3af">No</span>';
}
function infra_drift_cell(?string $drift): string
{
    if (!$drift) return '<span style="color:#9ca3af">—</span>';
    return '<span class="badge b-err">' . ih($drift) . '</span>';
}

/* ============================= DOMAIN MANAGE (edit/remove) ============================= */
if ($view === 'domain') {
    $d = strtolower(trim($_GET['d'] ?? ''));
    infra_header('domains');
    $rec = infra_state_get_domain($d);
    if (!$rec) {
        echo '<div class="ic-note">Not tracked in fleet state: <code>' . ih($d) . '</code> (only provisioned domains are manageable here). <a href="index.php?view=domains">&larr; domains</a></div>';
        infra_footer(); exit;
    }
    $regs = infra_registrar_names();
    // Only the infrastructure statuses are hand-settable, and only once the domain
    // has left the acquisition stage — see INFRA_STATUSES_MANUAL.
    $statuses  = INFRA_STATUSES_MANUAL;
    $acquiring = infra_is_acquiring($rec);
    ?>
    <div style="margin-bottom:14px"><a class="ic-back" style="color:#2563eb" href="index.php?view=domains">&larr; All domains</a></div>
    <div class="ic-card"><h2><?= ih($d) ?> <span class="badge b-mut"><?= ih($rec['status'] ?: '—') ?></span></h2><div class="body">
      <table>
        <tr><th style="width:170px">Server</th><td><?= ih($rec['server_id'] ?: '—') ?></td></tr>
        <tr><th>Cloudflare</th><td>acct <?= ih($rec['cf_account_id'] ?: '—') ?> · zone <code><?= ih($rec['cf_zone_id'] ? substr($rec['cf_zone_id'], 0, 16) : '—') ?></code></td></tr>
        <tr><th>Nameservers</th><td style="font-size:12px"><?= $rec['nameservers'] ? ih(str_replace(',', ', ', $rec['nameservers'])) : '—' ?></td></tr>
        <tr><th>FTP user</th><td><code><?= ih($rec['ftp_user'] ?: '—') ?></code></td></tr>
        <tr><th>Go-live</th><td><?= ih($rec['go_live_at'] ?: '—') ?></td></tr>
        <tr><th>Created</th><td><?= ih($rec['created_at'] ?: '—') ?></td></tr>
      </table>
    </div></div>

    <?php
    /* ---------- Acquisition: buy it, or record a purchase made by hand ---------- */
    $owned    = ($rec['owned'] ?? '') === 'yes';
    $buyReg   = (string) ($rec['buy_registrar'] ?? '');
    $buyDef   = $buyReg !== '' ? infra_registrar_type_def($buyReg) : [];
    $canBuy   = !empty($buyDef['buy_wired']);
    $isReady  = ($rec['ready_to_buy'] ?? '') === 'yes';
    ?>
    <div class="ic-card" style="<?= $owned ? 'border-color:#86efac' : '' ?>">
      <h2>Acquisition
        <?= $owned ? '<span class="badge b-ok">owned</span>' : '<span class="badge b-mut">not owned</span>' ?>
      </h2>
      <div class="body">
        <?php if ($owned): ?>
          <?php // Central, not UTC — infra_now() stamps every timestamp in INFRA_TZ. ?>
          <p style="margin-top:0">Bought <?= $rec['owned_at'] ? 'on <strong>' . ih($rec['owned_at']) . '</strong> Central' : '' ?><?= $rec['registrar'] ? ' at <strong>' . ih($rec['registrar']) . '</strong>' : '' ?>. Nothing further to do here — it is ready to provision.</p>
        <?php else: ?>
          <table style="margin-bottom:12px">
            <tr><th style="width:170px">Ready to buy</th><td><?= infra_ready_cell($rec + ['state' => $rec['status']]) ?></td></tr>
            <tr><th>Assigned registrar</th><td><?= $buyReg !== '' ? '<strong>' . ih($buyReg) . '</strong>' : '<span class="badge b-warn">none — set it below</span>' ?></td></tr>
            <tr><th>Scheduled buy date</th><td><?= $rec['buy_at'] ? ih($rec['buy_at']) : '<span style="color:#9ca3af">—</span>' ?></td></tr>
            <?php if (($rec['buy_error'] ?? '') !== ''): ?>
              <tr><th style="color:#991b1b">Last failure</th><td style="color:#991b1b"><?= ih($rec['buy_error']) ?></td></tr>
            <?php endif; ?>
          </table>

          <?php if ($buyReg === ''): ?>
            <div class="ic-note">Assign a registrar below before this domain can be bought.</div>
          <?php elseif (!$canBuy): ?>
            <div class="ic-note">
              <strong><?= ih($buyDef['label'] ?? $buyReg) ?> cannot complete a purchase from here.</strong>
              <?= empty($buyDef['buy'])
                  ? 'It has no registration endpoint at all — buy it in their dashboard, then record it below.'
                  : 'Its purchase adapter is not written here yet — buy it by hand for now and record it below.' ?>
            </div>
          <?php endif; ?>

          <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
            <?php if ($canBuy): ?>
              <form method="post" action="actions/domain_manage.php" style="border:1px solid #fca5a5;border-radius:8px;padding:12px 14px;background:#fff7f7">
                <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                <input type="hidden" name="action" value="buy">
                <input type="hidden" name="domain" value="<?= ih($d) ?>">
                <div style="font-weight:600;margin-bottom:4px;color:#991b1b">Buy this domain now</div>
                <div style="color:#6b7280;font-size:12px;margin-bottom:8px;max-width:420px">
                  Spends real money at <strong><?= ih($buyReg) ?></strong>. Availability is re-checked immediately
                  before the purchase, so a name taken since the last check is never paid for.
                </div>
                for <input type="number" name="years" value="1" min="1" max="10" style="width:56px;padding:6px 8px;border:1px solid #d1d5db;border-radius:8px"> year(s)
                <label style="margin-left:10px;font-size:13px"><input type="checkbox" name="auto_renew" value="1" checked> auto-renew</label>
                <div style="margin-top:10px">
                  <input name="confirm" placeholder="type <?= ih($d) ?>" style="width:250px;padding:6px 8px;border:1px solid #d1d5db;border-radius:8px">
                  <button class="btn" style="background:#991b1b" type="submit"
                          onclick="return confirm('⚠ BUY <?= ih($d) ?> at <?= ih($buyReg) ?>?\n\nThis spends real money and cannot be undone.');">Buy</button>
                </div>
                <?php if (!$isReady): ?>
                  <div style="color:#b45309;font-size:12px;margin-top:8px">⚠ Not marked ready to buy. The purchase will re-check first and refuse if it is taken.</div>
                <?php endif; ?>
              </form>
            <?php endif; ?>

            <form method="post" action="actions/domain_manage.php" style="border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px">
              <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
              <input type="hidden" name="action" value="mark_owned">
              <input type="hidden" name="domain" value="<?= ih($d) ?>">
              <div style="font-weight:600;margin-bottom:4px">Already bought it by hand?</div>
              <div style="color:#6b7280;font-size:12px;margin-bottom:8px;max-width:380px">
                Records the purchase without spending anything. Use this for Porkbun and
                Cloudflare, which have no registration API.
              </div>
              at
              <select name="registrar" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:8px">
                <?php foreach (infra_registrar_names() as $rn): ?>
                  <option value="<?= ih($rn) ?>" <?= $buyReg === $rn ? 'selected' : '' ?>><?= ih($rn) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn sec" type="submit" onclick="return confirm('Mark <?= ih($d) ?> as owned? No purchase is made.');">Mark as owned</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="ic-card"><h2>Edit</h2><div class="body">
      <form method="post" action="actions/domain_manage.php">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="domain" value="<?= ih($d) ?>">
        <table>
          <tr><th style="width:170px">Registrar</th><td>
            <select name="registrar" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
              <option value="">—</option>
              <?php foreach ($regs as $rn): ?><option value="<?= ih($rn) ?>" <?= $rec['registrar'] === $rn ? 'selected' : '' ?>><?= ih($rn) ?></option><?php endforeach; ?>
            </select></td></tr>
          <tr><th>Niche</th><td>
            <select name="niche" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
              <option value="">—</option>
              <?php foreach (INFRA_NICHES as $nz): ?><option value="<?= ih($nz) ?>" <?= $rec['niche'] === $nz ? 'selected' : '' ?>><?= ih($nz) ?></option><?php endforeach; ?>
            </select></td></tr>
          <tr><th>Status</th><td>
            <?php if ($acquiring): ?>
              <?= infra_state_cell($rec['status'] ?: 'begin') ?>
              <input type="hidden" name="status" value="<?= ih($rec['status']) ?>">
              <div style="color:#6b7280;font-size:12px;margin-top:4px">
                Set by the acquisition stage, not by hand — a domain becomes <code>ready</code> when an
                availability check says so, and <code>owned</code> when a purchase completes. It becomes
                settable here once it has been provisioned.
              </div>
            <?php else: ?>
              <select name="status" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                <?php foreach ($statuses as $st): ?><option value="<?= ih($st) ?>" <?= $rec['status'] === $st ? 'selected' : '' ?>><?= ih($st) ?></option><?php endforeach; ?>
              </select>
            <?php endif; ?>
            </td></tr>
        </table>
        <div style="margin-top:12px"><button class="btn" type="submit">Save changes</button></div>
      </form>
    </div></div>

    <div class="ic-card" style="border-color:#fca5a5"><h2 style="color:#991b1b">⚠ Danger zone</h2><div class="body">
      <p style="font-size:13px;color:#6b7280;margin-top:0">Each action requires typing <code><?= ih($d) ?></code> to confirm.</p>
      <?php
      $dz = [
        ['delete_zone', 'Delete Cloudflare zone', 'Removes the CF zone (DNS/SSL/HSTS). Plesk site + fleet record kept.'],
        ['delete_site', 'Delete Plesk site', 'Removes the site + files on the VPS. CF zone + fleet record kept.'],
        ['untrack', 'Remove from fleet (untrack)', 'Forgets this domain here. Leaves Plesk + Cloudflare intact.'],
        ['teardown', 'Full teardown', 'Deletes CF zone + Plesk site AND removes from fleet. Irreversible.'],
      ];
      foreach ($dz as [$act, $label, $desc]): ?>
        <form method="post" action="actions/domain_manage.php" style="margin:10px 0;padding:10px;border:1px solid #f0f0f0;border-radius:8px" onsubmit="return confirm('<?= ih($label) ?> for <?= ih($d) ?>? This cannot be undone.');">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="action" value="<?= ih($act) ?>">
          <input type="hidden" name="domain" value="<?= ih($d) ?>">
          <div><strong><?= ih($label) ?></strong> <span style="color:#6b7280;font-size:12px"><?= ih($desc) ?></span></div>
          <input name="confirm" placeholder="type <?= ih($d) ?>" style="width:280px;padding:6px 8px;border:1px solid #d1d5db;border-radius:8px;margin:6px 8px 0 0">
          <button class="btn" style="background:#991b1b" type="submit"><?= ih($label) ?></button>
        </form>
      <?php endforeach; ?>
    </div></div>
    <?php infra_footer(); exit;
}

/* ============================= DOMAINS ============================= */
if ($view === 'domains') {
    infra_header('domains');
    $allRows = infra_fleet_domains();
    $hasCf   = count(infra_cf_accounts()) > 0;
    $regs           = infra_registrar_names();
    $buyable        = infra_registrar_buyable();
    $checkers       = infra_registrar_checkers();      // who can answer "is it available?"
    $defaultChecker = infra_default_checker();         // the fastest of them

    // Tiles reflect the WHOLE fleet, not the filtered/paged slice. The acquisition
    // buckets come first because with 400 loaded-but-unbought rows the old
    // Live/Staged/Drift trio said nothing useful.
    $tally = ['begin' => 0, 'ready' => 0, 'owned' => 0, 'staged' => 0, 'live' => 0, 'drift' => 0, 'failed' => 0];
    foreach ($allRows as $r) {
        switch ($r['state']) {
            case 'begin':                                     $tally['begin']++;  break;
            case 'ready':                                     $tally['ready']++;  break;
            case 'owned':                                     $tally['owned']++;  break;
            case 'live':                                      $tally['live']++;   break;
            case 'buy-failed': case 'register-failed':
            case 'partial':                                   $tally['failed']++; break;
            default:                                          $tally['staged']++; break;
        }
        if ($r['drift']) $tally['drift']++;
    }

    /* search across the full set ------------------------------------------ */
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $ql = strtolower($q);
        $rows = array_values(array_filter($allRows, function ($r) use ($ql) {
            foreach (['domain', 'registrar', 'state', 'drift', 'buy_registrar', 'buy_at', 'niche', 'avail_note'] as $f) {
                if (strpos(strtolower((string) ($r[$f] ?? '')), $ql) !== false) return true;
            }
            return false;
        }));
    } else {
        $rows = $allRows;
    }

    /* sort across the full set (not just the visible page) ----------------- */
    $sort = (string) ($_GET['sort'] ?? 'domain');
    $dir  = (($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
    $sortable = [
        'domain' => fn($r) => $r['domain'],
        'niche'  => fn($r) => $r['niche'] !== '' ? $r['niche'] : 'zzz',   // unset last
        'ready'  => fn($r) => ['yes' => '0', 'no' => '2'][$r['ready_to_buy']] ?? '1',
        'buyreg' => fn($r) => strtolower((string) $r['buy_registrar']),
        'buy_at' => fn($r) => $r['buy_at'] !== '' ? $r['buy_at'] : '9999-99-99',   // unscheduled last
        'owned'  => fn($r) => $r['owned'] === 'yes' ? '0' : '1',
        'cf'     => fn($r) => $r['cf'] ? ($r['cf']['status'] ?? '') : 'zzz',
        'vps'    => fn($r) => $r['plesk'] ? ($r['plesk']['server_label'] ?? '') : 'zzz',
        'state'  => fn($r) => array_search($r['state'], INFRA_STATUSES, true) === false
                                ? '99' : sprintf('%02d', array_search($r['state'], INFRA_STATUSES, true)),
        'drift'  => fn($r) => $r['drift'] ?: 'zzz',
    ];
    if (!isset($sortable[$sort])) $sort = 'domain';
    $key = $sortable[$sort];
    usort($rows, function ($a, $b) use ($key, $dir) {
        $c = [$key($a), $a['domain']] <=> [$key($b), $b['domain']];
        return $dir === 'desc' ? -$c : $c;
    });

    /* paginate ------------------------------------------------------------- */
    $perPage = 100;
    $total   = count($rows);
    $pages   = max(1, (int) ceil($total / $perPage));
    $page    = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    $slice   = array_slice($rows, ($page - 1) * $perPage, $perPage);

    $baseQs = 'view=domains'
        . ($q !== '' ? '&q=' . urlencode($q) : '')
        . '&sort=' . urlencode($sort) . '&dir=' . $dir;
    // column header link that toggles direction on the active column
    $sortLink = function (string $k, string $label) use ($sort, $dir, $q, $page) {
        $nd  = ($sort === $k && $dir === 'asc') ? 'desc' : 'asc';
        $ar  = $sort === $k ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '';
        $url = 'index.php?view=domains' . ($q !== '' ? '&q=' . urlencode($q) : '')
             . '&sort=' . urlencode($k) . '&dir=' . $nd . '&page=' . $page;
        return '<a href="' . ih($url) . '" style="color:inherit;text-decoration:none">' . $label . $ar . '</a>';
    };
    ?>
    <div class="ic-tiles">
      <div class="ic-tile"><div class="n"><?= count($allRows) ?></div><div class="l">Domains</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['begin'] ?></div><div class="l">Begin</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['ready'] ?></div><div class="l">Ready to buy</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['owned'] ?></div><div class="l">Owned</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['staged'] ?></div><div class="l">Staged</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['live'] ?></div><div class="l">Live</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['drift'] + $tally['failed'] ?></div><div class="l">Needs attention</div></div>
    </div>

    <?php if (!$regs): ?>
      <div class="ic-note">No registrar configured yet — add one on the <a href="index.php?view=registrars"><strong>Registrars</strong></a> tab before you can check availability or schedule buys.</div>
    <?php endif; ?>

    <!-- ============ LOAD NEW DOMAINS (→ begin state) ============ -->
    <details class="ic-card" <?= empty($allRows) ? 'open' : '' ?>>
      <summary style="padding:14px 16px;font-size:15px;font-weight:600;cursor:pointer;border-bottom:1px solid #f0f0f0">
        &#43; Load new domains <span style="color:#9ca3af;font-weight:400;font-size:13px">— paste a list or upload a CSV; they land in <em>begin</em> state</span>
      </summary>
      <div class="body">
        <form method="post" action="actions/domains_load.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <div style="display:grid;grid-template-columns:1fr 300px;gap:18px">
            <div>
              <label style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">Paste — one domain per line</label>
              <textarea name="domains" rows="7" placeholder="littletonpestpros.com&#10;auroramoldpros.com&#10;castlerockwaterpros.com" style="width:100%;margin-top:6px;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-family:monospace;font-size:13px"></textarea>
            </div>
            <div>
              <label style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">…or upload a CSV</label>
              <input type="file" name="csv" accept=".csv,.txt" style="width:100%;margin-top:6px;padding:8px;border:1px solid #d1d5db;border-radius:8px;background:#fff">
              <div style="color:#6b7280;font-size:12px;margin-top:6px">First column = domain. A <code>niche</code> column is used if present; a header row is detected and skipped.</div>
              <label style="display:block;margin-top:12px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">Niche (applied to all)</label>
              <select name="niche" style="width:100%;margin-top:6px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                <option value="">—</option>
                <?php foreach (INFRA_NICHES as $nz): ?><option value="<?= ih($nz) ?>"><?= ih($nz) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn" type="submit">Load domains</button>
            <label style="font-size:13px;color:#374151"><input type="checkbox" name="check_now" value="1" <?= $checkers ? 'checked' : 'disabled' ?>> also check availability now</label>
            <?php if ($checkers): ?>
              <span style="font-size:13px;color:#6b7280">using</span>
              <select name="check_registrar" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:8px">
                <?php foreach ($checkers as $cn => $c): ?>
                  <option value="<?= ih($cn) ?>" <?= $cn === $defaultChecker ? 'selected' : '' ?>><?= ih($cn) ?> — <?= ih($c['speed']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <div style="color:#6b7280;font-size:12px;margin-top:8px">Loading is additive and safe to repeat — a domain already in the table is left exactly as it is, never reset.</div>
        </form>
      </div>
    </details>

    <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <a class="btn" href="index.php?<?= ih($baseQs) ?>&refresh=1">&#8635; Discover / Refresh</a>
      <form method="get" style="display:inline-flex;gap:6px;margin:0">
        <input type="hidden" name="view" value="domains">
        <input type="hidden" name="sort" value="<?= ih($sort) ?>"><input type="hidden" name="dir" value="<?= ih($dir) ?>">
        <input class="ic-search" type="search" name="q" value="<?= ih($q) ?>" placeholder="Search domain / registrar / state / note…" style="margin:0">
        <button class="btn sec" type="submit">Search</button>
        <?php if ($q !== ''): ?><a class="btn sec" href="index.php?view=domains">Clear</a><?php endif; ?>
      </form>
    </div>

    <div class="ic-card">
      <h2>Domain inventory <span style="color:#9ca3af;font-weight:400;font-size:13px">— <?= $total ?><?= $q !== '' ? ' match' . ($total === 1 ? '' : 'es') : '' ?>, page <?= $page ?>/<?= $pages ?>, sorted by <?= ih($sort) ?> <?= $dir ?></span></h2>
      <div class="body">
        <?php if (empty($slice)): ?>
          <div class="ic-empty"><?= $q !== '' ? 'No domains match “' . ih($q) . '”.' : 'No domains yet — load some above.' ?></div>
        <?php else: ?>
        <form method="post" action="actions/domains_bulk.php" id="domForm">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="back" value="<?= ih($baseQs . '&page=' . $page) ?>">
          <input type="hidden" name="from" value="<?= ih($baseQs . '&page=' . $page) ?>">

          <!-- bulk bar: acts on ticked rows -->
          <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13px">
            <strong id="selCount">0 selected</strong>
            <button class="btn sec" type="submit" name="action" value="check_avail" <?= $checkers ? '' : 'disabled' ?>>Check availability</button>
            <?php if ($checkers): ?>
              <span style="color:#6b7280">using</span>
              <select name="check_with" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px" title="Availability is a public fact — any registrar gives the same answer, so pick the fastest. This does not affect who buys.">
                <?php foreach ($checkers as $cn => $c): ?>
                  <option value="<?= ih($cn) ?>" <?= $cn === $defaultChecker ? 'selected' : '' ?>><?= ih($cn) ?> — <?= ih($c['speed']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <span style="color:#d1d5db">|</span>
            <span>Ready to buy</span>
            <select name="ready_val" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
              <option value="yes">yes</option><option value="no">no</option><option value="">clear</option>
            </select>
            <button class="btn sec" type="submit" name="action" value="set_ready" title="Override the availability check by hand">Set</button>
            <span style="color:#d1d5db">|</span>
            <span>Niche</span>
            <select name="bulk_niche" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
              <?php foreach (INFRA_NICHES as $nz): ?><option value="<?= ih($nz) ?>"><?= ih($nz) ?></option><?php endforeach; ?>
            </select>
            <button class="btn sec" type="submit" name="action" value="set_niche">Set</button>
            <span style="color:#d1d5db">|</span>
            <span>Registrar</span>
            <select name="bulk_registrar" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
              <option value="">—</option>
              <?php foreach ($regs as $rn): ?><option value="<?= ih($rn) ?>"><?= ih($rn) ?><?= in_array($rn, $buyable, true) ? '' : ' (no auto-buy)' ?></option><?php endforeach; ?>
              <?php if (count($buyable) > 1): ?><option value="__rr__">🔀 spread round-robin</option><?php endif; ?>
            </select>
            <button class="btn sec" type="submit" name="action" value="set_registrar">Set</button>
            <span style="color:#d1d5db">|</span>
            <span>Buy date</span>
            <input type="date" name="bulk_buy_at" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
            <button class="btn sec" type="submit" name="action" value="set_buy_at">Set</button>
            <span style="color:#d1d5db">|</span>
            <span>Spread</span>
            <input type="number" name="per_day" value="20" min="1" style="width:60px;padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
            <span>/day from</span>
            <input type="date" name="spread_from" value="<?= ih(infra_today()) ?>" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
            <button class="btn sec" type="submit" name="action" value="schedule_buys">Schedule</button>
            <span style="color:#d1d5db">|</span>
            <button class="btn sec" type="submit" name="action" value="remove" style="color:#991b1b" onclick="return confirm('Remove the ticked domains from the table? Only untracks them here — no infrastructure is touched.')">Remove</button>
          </div>

          <table>
            <thead><tr>
              <th style="width:26px"><input type="checkbox" id="selAll" title="Select all on this page"></th>
              <th><?= $sortLink('domain', '1. Domain') ?></th>
              <th><?= $sortLink('niche',  '2. Niche') ?></th>
              <th><?= $sortLink('ready',  '3. Ready to buy') ?></th>
              <th><?= $sortLink('buyreg', '4. Register') ?></th>
              <th>5. Buy</th>
              <th><?= $sortLink('buy_at', '6. Buy date') ?></th>
              <th><?= $sortLink('owned',  '7. Own') ?></th>
              <th><?= $sortLink('cf',     '8. Cloudflare') ?></th>
              <th><?= $sortLink('vps',    '9. VPS / Plesk') ?></th>
              <th><?= $sortLink('state',  '10. State') ?></th>
              <th><?= $sortLink('drift',  '11. Drift') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($slice as $r):
              $d   = $r['domain'];
              $vps = $r['plesk']
                  ? '<a href="index.php?view=server&id=' . ih($r['plesk']['server_id']) . '">' . ih($r['plesk']['server_label']) . '</a>'
                  : '<span style="color:#9ca3af">—</span>';
            ?>
              <tr>
                <td><input type="checkbox" class="selBox" name="sel[]" value="<?= ih($d) ?>"></td>
                <td>
                  <?php if (!empty($r['managed'])): ?><a href="index.php?view=domain&d=<?= ih($d) ?>"><strong><?= ih($d) ?></strong></a><?php else: ?><strong><?= ih($d) ?></strong><?php endif; ?>
                </td>
                <td>
                  <select name="niche[<?= ih($d) ?>]" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
                    <option value="">—</option>
                    <?php foreach (INFRA_NICHES as $nz): ?>
                      <option value="<?= ih($nz) ?>" <?= $r['niche'] === $nz ? 'selected' : '' ?>><?= ih($nz) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><?= infra_ready_cell($r) ?></td>
                <?php // Once a domain is owned, the registrar and buy date are history: where
                      // it was bought and when. Leaving them editable would invite a change
                      // that contradicts the purchase and cannot be acted on. ?>
                <?php if (($r['owned'] ?? '') === 'yes'): ?>
                  <td><strong><?= ih($r['registrar'] ?: $r['buy_registrar'] ?: '—') ?></strong></td>
                  <td><span class="badge b-ok">bought</span></td>
                  <td><?= ih($r['buy_at'] ?: '—') ?><br><span style="color:#9ca3af;font-size:11px">bought</span></td>
                <?php else: ?>
                  <td>
                    <select name="reg[<?= ih($d) ?>]" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;max-width:130px">
                      <option value="">—</option>
                      <?php foreach ($regs as $rn): ?>
                        <option value="<?= ih($rn) ?>" <?= $r['buy_registrar'] === $rn ? 'selected' : '' ?>><?= ih($rn) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td style="white-space:nowrap">
                    <?php
                    // Buyable = checked available, a registrar chosen, and that registrar
                    // able to complete a purchase from here. Anything else shows why not,
                    // rather than a button that fails when pressed.
                    $bReg  = (string) $r['buy_registrar'];
                    $bDef  = $bReg !== '' ? infra_registrar_type_def($bReg) : [];
                    $bOk   = $r['ready_to_buy'] === 'yes' && $bReg !== '' && !empty($bDef['buy_wired']);
                    $bCost = $r['avail_price'] !== '' ? '$' . $r['avail_price'] : 'the quoted price';
                    // Every registrar buys a 1-year term. Namecheap gets a warning in the
                    // confirm rather than a longer term: its auto-renew cannot be set over
                    // the API, so this one really does need a dashboard visit.
                    $bWarn = strtolower($bReg) === 'namecheap'
                        ? '\n\nNote: Namecheap cannot set auto-renew over its API, so this expires in 1 year and will not renew itself.'
                        : '';
                    ?>
                    <?php if ($bOk): ?>
                      <button class="btn" style="background:#991b1b;padding:3px 11px;font-size:11px"
                              type="submit" formaction="actions/domain_buy.php"
                              name="domain" value="<?= ih($d) ?>"
                              onclick="return confirm('Buy <?= ih($d) ?>\n\nat <?= ih($bReg) ?> for <?= ih($bCost) ?>, 1 year.<?= $bWarn ?>\n\nThis spends real money and cannot be undone.');">Buy</button>
                    <?php elseif ($r['ready_to_buy'] !== 'yes'): ?>
                      <span style="color:#9ca3af;font-size:11px">not ready</span>
                    <?php elseif ($bReg === ''): ?>
                      <span style="color:#9ca3af;font-size:11px">pick registrar</span>
                    <?php else: ?>
                      <span style="color:#9ca3af;font-size:11px" title="<?= ih($bDef['label'] ?? $bReg) ?> cannot buy from here">no adapter</span>
                    <?php endif; ?>
                  </td>
                  <td><input type="date" name="buy[<?= ih($d) ?>]" value="<?= ih($r['buy_at']) ?>" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px"></td>
                <?php endif; ?>
                <td><?= infra_own_cell($r) ?></td>
                <td><?= infra_cf_cell($r['cf'], $hasCf) ?></td>
                <td><?= $vps ?></td>
                <td><?= infra_state_cell($r['state']) ?></td>
                <td><?= infra_drift_cell($r['drift']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn" type="submit" name="action" value="save_edits">Save registrar &amp; date edits</button>
            <span style="color:#6b7280;font-size:12px">Saves every Register / Buy date field on this page. Ticks are only needed for the bulk actions above.</span>
          </div>

          <?php if ($pages > 1): ?>
          <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
            <?php if ($page > 1): ?><a class="btn sec" href="index.php?<?= ih($baseQs) ?>&page=<?= $page - 1 ?>">&larr; Prev</a><?php endif; ?>
            <span style="color:#6b7280;font-size:13px">Showing <?= ($page - 1) * $perPage + 1 ?>&ndash;<?= min($total, $page * $perPage) ?> of <?= $total ?></span>
            <?php if ($page < $pages): ?><a class="btn sec" href="index.php?<?= ih($baseQs) ?>&page=<?= $page + 1 ?>">Next &rarr;</a><?php endif; ?>
          </div>
          <?php endif; ?>
        </form>
        <script>
        (function () {
          var all = document.getElementById('selAll'),
              boxes = Array.prototype.slice.call(document.querySelectorAll('.selBox')),
              out = document.getElementById('selCount');
          function tally() {
            var n = boxes.filter(function (b) { return b.checked; }).length;
            out.textContent = n + ' selected';
          }
          all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; }); tally();
          });
          boxes.forEach(function (b) { b.addEventListener('change', tally); });
          tally();
        })();
        </script>
        <?php endif; ?>
      </div>
    </div>
    <?php infra_footer(); exit;
}

/* ============================= SERVER DETAIL ============================= */
if ($view === 'server') {
    $srv = infra_find_server($_GET['id'] ?? '');
    infra_header('dashboard');
    if (!$srv) { echo '<div class="ic-note">Unknown server. <a href="index.php">&larr; back</a></div>'; infra_footer(); exit; }

    $disc  = infra_discover_hestia($srv);
    $probe = ['ok' => $disc['ok'], 'error' => $disc['error']];
    $info  = $disc['info'];
    $sites = $disc['sites'];
    $cfIdx = infra_cf_zone_index();
    $reg   = infra_registrar_map();
    $hasCf = count(infra_cf_accounts()) > 0;
    $badge = $probe['ok'] ? '<span class="badge b-ok">reachable</span>' : '<span class="badge b-err">unreachable</span>';
    ?>
    <div style="margin-bottom:14px"><a class="ic-back" style="color:#2563eb" href="index.php">&larr; All servers</a></div>
    <div class="ic-card">
      <h2><?= ih($srv['label'] ?? $srv['id']) ?> <?= $badge ?></h2>
      <div class="body"><table>
        <tr><th style="width:200px">Server ID</th><td><code><?= ih($srv['id'] ?? '') ?></code></td></tr>
        <tr><th>Plesk host</th><td><code><?= ih($srv['host'] ?? '') ?>:<?= ih($srv['port'] ?? 8443) ?></code></td></tr>
        <tr><th>Default IP (CF targets this)</th><td><code><?= ih($srv['default_ip'] ?? $srv['host'] ?? '') ?></code></td></tr>
        <tr><th>Plesk version</th><td><?= ih($info['panel_version'] ?? '—') ?><?= isset($info['hostname']) ? ' · ' . ih($info['hostname']) : '' ?></td></tr>
        <tr><th>Sites on this VPS</th><td><strong><?= count($sites) ?></strong></td></tr>
      </table></div>
    </div>
    <div class="ic-card">
      <h2>Sites &amp; stack wiring</h2>
      <div class="body">
        <?php if (!$probe['ok']): ?>
          <div class="badge b-err">API error: <?= ih($probe['error']) ?></div>
        <?php elseif (empty($sites)): ?>
          <div class="ic-empty">No domains on this VPS yet.</div>
        <?php else: ?>
          <input class="ic-search" type="search" placeholder="Filter domains…" data-target="tbl-sites">
          <table id="tbl-sites">
            <thead><tr><th>Domain</th><th>Plesk</th><th>Cloudflare</th><th>Registrar</th><th>State</th></tr></thead>
            <tbody>
            <?php foreach ($sites as $d):
              $name = strtolower($d['name'] ?? '');
              $z = $cfIdx[$name] ?? null;
              $state = ($z && $z['status'] === 'active') ? 'live' : 'staged';
              $rg = $reg[$name]['registrar'] ?? '';
            ?>
              <tr>
                <td><strong><?= ih($d['name'] ?? '') ?></strong><br><span style="color:#9ca3af;font-size:11px"><?= ih($d['hosting_type'] ?? '') ?> · <?= ih($d['www_root'] ?? '') ?></span></td>
                <td><span class="badge b-ok">✓ created</span></td>
                <td><?= infra_cf_cell($z, $hasCf) ?></td>
                <td><?= $rg !== '' ? ih($rg) : '<span class="badge b-mut">unknown</span>' ?></td>
                <td><?= infra_state_cell($state) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <?php infra_search_js(); infra_footer(); exit;
}

/* ============================= DEPLOY (Phase 2 bridge) ============================= */
if ($view === 'deploy') {
    infra_header('deploy');
    $servers = [];
    foreach (infra_hestia_servers() as $s) $servers[$s['id'] ?? ''] = $s;
    $rows = [];
    foreach (infra_state_all_domains() as $dom => $r) {
        if (($r['ftp_user'] ?? '') === '') continue;
        $srv = $servers[$r['server_id'] ?? ''] ?? [];
        $rows[$dom] = ['user' => $r['ftp_user'], 'host' => $srv['default_ip'] ?? ($srv['host'] ?? ''), 'server' => $r['server_id'] ?? ''];
    }
    ?>
    <div class="ic-card"><h2>Deploy — hand provisioned creds to content upload</h2><div class="body">
      <div class="ic-note">This bridges Phase&nbsp;1 (provisioning) → Phase&nbsp;2 (content). The console generated an FTP user for each provisioned domain; export them as a <strong>params CSV</strong> and merge into a master site's params (MultiSite tab) — then <strong>Build&nbsp;+&nbsp;Deploy</strong> uploads the generated content to the provisioned Plesk box (docroot <code>/httpdocs</code>, FTP:21) using these creds. Columns match the multisite params format exactly.</div>
      <p><strong><?= count($rows) ?></strong> provisioned domain(s) have FTP credentials.</p>
      <?php if ($rows): ?>
        <a class="btn" href="actions/export_creds.php">&#8681; Download params-CSV (creds)</a>
        <div style="margin-top:14px"><input class="ic-search" type="search" placeholder="Filter…" data-target="tbl-dep">
        <table id="tbl-dep"><thead><tr><th>Domain</th><th>FTP host</th><th>FTP user</th><th>Password</th><th>Path</th></tr></thead><tbody>
        <?php foreach ($rows as $dom => $r): ?>
          <tr><td><strong><?= ih($dom) ?></strong></td><td><code><?= ih($r['host']) ?></code></td><td><code><?= ih($r['user']) ?></code></td><td><span class="badge b-mut">•••• (in CSV)</span></td><td><code>/httpdocs</code></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?>
        <div class="ic-empty">No provisioned domains with FTP creds yet — provision some first (New Site / Bulk).</div>
      <?php endif; ?>
    </div></div>
    <?php infra_search_js(); infra_footer(); exit;
}

/* ============================= NEW SITE (CRUD) ============================= */
if ($view === 'new') {
    infra_header('new');
    $servers = infra_hestia_servers();
    $accts   = infra_cf_accounts();
    $regs    = infra_registrar_names();
    ?>
    <div class="ic-card">
      <h2>New Site — Phase 1 provisioning</h2>
      <div class="body">
        <div class="ic-note">Creates the infrastructure only (Plesk site + Cloudflare zone). No content is uploaded and no nameservers are switched — the site stays <strong>staged</strong> until go-live. Safe to run; re-running skips anything that already exists.</div>
        <form method="post" action="actions/provision.php" onsubmit="return confirm(((this.do_register && this.do_register.checked) ? '⚠ This BUYS ' + this.domain.value + ' (real money) then provisions it.\n\n' : 'Provision ') + 'Proceed with ' + this.domain.value + '?');">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <table>
            <tr><th style="width:200px">Domain</th>
              <td><input name="domain" required placeholder="dallaspestpros.com" style="width:320px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px"></td></tr>
            <tr><th>Registrar</th><td>
              <?php if ($regs): ?>
                <select name="registrar" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                  <?php foreach ($regs as $rn): ?><option value="<?= ih($rn) ?>"><?= ih($rn) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:12px"><input type="checkbox" name="do_register"> Register (buy) &mdash; <strong style="color:#991b1b">costs money</strong></label>
                for <input type="number" name="years" value="1" min="1" max="10" style="width:56px;padding:6px 8px;border:1px solid #d1d5db;border-radius:8px"> yr
                <div style="color:#6b7280;font-size:12px;margin-top:4px">Auto-buy is wired for all five registrars, and goes through the same guards as the Buy button — availability is re-checked immediately before paying. Leave unchecked if the domain is already registered; the selected registrar is still recorded for the go-live NS switch.</div>
              <?php else: ?><span class="badge b-mut">no registrar configured</span><?php endif; ?>
            </td></tr>
            <tr><th>Plesk server</th><td>
              <select name="server_id" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                <?php foreach ($servers as $s): ?>
                  <option value="<?= ih($s['id'] ?? '') ?>"><?= ih(($s['label'] ?? $s['id']) . ' — ' . ($s['host'] ?? '')) ?></option>
                <?php endforeach; ?>
              </select></td></tr>
            <tr><th>Cloudflare account</th><td>
              <?php if ($accts): ?>
                <select name="cf_account_id" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                  <?php foreach ($accts as $a): ?>
                    <option value="<?= ih($a['id'] ?? '') ?>"><?= ih($a['label'] ?? $a['id']) ?><?= empty($a['account_id']) ? ' (account_id not set — zone create will fail)' : '' ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?><span class="badge b-mut">no CF account configured</span><?php endif; ?>
            </td></tr>
            <tr><th>Steps</th><td>
              <label style="display:block;margin-bottom:6px"><input type="checkbox" name="do_site" checked> Create the site on the box (vhost + folder + FTP login)</label>
              <label style="display:block"><input type="checkbox" name="do_cf" <?= $accts ? 'checked' : 'disabled' ?>> Create Cloudflare zone (needs Edit-scoped token + account_id)</label>
            </td></tr>
          </table>
          <div style="margin-top:14px"><button class="btn" type="submit">Provision (staged)</button></div>
        </form>
      </div>
    </div>
    <?php infra_footer(); exit;
}

/* ============================= BULK PROVISION ============================= */
if ($view === 'bulk') {
    infra_header('bulk');
    $servers = infra_hestia_servers();
    $accts   = infra_cf_accounts();
    $regs    = infra_registrar_names();
    ?>
    <div class="ic-card">
      <h2>Bulk Provision — Phase 1 at scale</h2>
      <div class="body">
        <div class="ic-note">Paste one domain per line. Each is created on Plesk + fully staged in Cloudflare (DNS→VPS IP proxied, SSL, HSTS) and saved to fleet state. Idempotent (existing sites/zones are skipped/updated), staged only — no nameservers switched. Progress streams live below.</div>
        <form id="bulkForm">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <textarea name="domains" rows="8" placeholder="dallaspestpros.com&#10;katypestpros.com&#10;austinpestpros.com" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-family:monospace;font-size:13px"></textarea>
          <table style="margin-top:10px">
            <tr><th style="width:180px">Plesk server</th><td>
              <select name="server_id" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                <option value="__auto__">🔀 Auto — round-robin (footprint)</option><?php foreach ($servers as $s): ?><option value="<?= ih($s['id'] ?? '') ?>"><?= ih(($s['label'] ?? $s['id']) . ' — ' . ($s['host'] ?? '')) ?></option><?php endforeach; ?>
              </select></td></tr>
            <tr><th>Cloudflare account</th><td>
              <?php if ($accts): ?><select name="cf_account_id" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                <option value="__auto__">🔀 Auto — round-robin (footprint)</option><?php foreach ($accts as $a): ?><option value="<?= ih($a['id'] ?? '') ?>"><?= ih($a['label'] ?? $a['id']) ?></option><?php endforeach; ?>
              </select><?php else: ?><span class="badge b-mut">no CF account</span><?php endif; ?></td></tr>
            <tr><th>Registrar</th><td>
              <?php if ($regs): ?>
                <select name="registrar" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                  <option value="__auto__">🔀 Auto — round-robin (footprint)</option><?php foreach ($regs as $rn): ?><option value="<?= ih($rn) ?>"><?= ih($rn) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:12px"><input type="checkbox" name="do_register"> Register (buy) &mdash; <strong style="color:#991b1b">costs money ×N</strong></label>
                for <input type="number" name="years" value="1" min="1" max="10" style="width:56px;padding:6px 8px;border:1px solid #d1d5db;border-radius:8px"> yr
              <?php else: ?><span class="badge b-mut">no registrar configured</span><?php endif; ?>
            </td></tr>
            <tr><th>Steps</th><td>
              <label style="margin-right:16px"><input type="checkbox" name="do_site" checked> Site on the box</label>
              <label><input type="checkbox" name="do_cf" <?= $accts ? 'checked' : 'disabled' ?>> Cloudflare zone (staged)</label>
            </td></tr>
          </table>
          <div style="margin-top:12px"><button class="btn" type="submit" id="bulkBtn">Run bulk provision</button></div>
        </form>
        <pre id="bulkLog" style="display:none;margin-top:16px;background:#0b1020;color:#d1e0ff;padding:14px;border-radius:8px;max-height:460px;overflow:auto;font-size:12px;line-height:1.5;white-space:pre-wrap"></pre>
      </div>
    </div>
    <script>
    document.getElementById('bulkForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      var doms = (this.domains.value.match(/\S+/g) || []).length;
      if (!doms) { alert('Paste at least one domain.'); return; }
      var buying = this.do_register && this.do_register.checked;
      if (!confirm(buying ? ('⚠ This BUYS ' + doms + ' domain(s) (real money) then provisions them. Proceed?') : ('Provision ' + doms + ' domain(s)? Creates Plesk sites + Cloudflare zones (staged).'))) return;
      var log = document.getElementById('bulkLog'), btn = document.getElementById('bulkBtn');
      log.style.display = 'block'; log.textContent = 'Starting…\n'; btn.disabled = true; btn.textContent = 'Running…';
      try {
        var resp = await fetch('actions/bulk_run.php', { method: 'POST', body: new FormData(this), credentials: 'same-origin' });
        var reader = resp.body.getReader(), dec = new TextDecoder();
        log.textContent = '';
        while (true) {
          var r = await reader.read();
          if (r.done) break;
          log.textContent += dec.decode(r.value, { stream: true });
          log.scrollTop = log.scrollHeight;
        }
      } catch (err) { log.textContent += '\n[connection error] ' + err; }
      btn.disabled = false; btn.textContent = 'Run bulk provision';
    });
    </script>
    <?php infra_footer(); exit;
}

/* ============================= GO-LIVE (Phase 3) ============================= */
if ($view === 'golive') {
    infra_header('golive');
    $all = infra_state_all_domains();
    $queue = []; $live = [];
    $cStaged = $cQueued = $cLive = $cAcquiring = 0;
    foreach ($all as $dom => $r) {
        // Go-live switches nameservers at the registrar to the Cloudflare pair, so
        // it only ever applies to a domain that HAS a Cloudflare zone. A domain
        // still being bought has none — counting those as "staged" made this tab
        // claim 402 domains were ready to release when none of them were, and put
        // a Release button on rows nobody owns.
        if (infra_is_acquiring($r)) { $cAcquiring++; continue; }
        $st = $r['status'] ?? '';
        if ($st === 'live') { $live[$dom] = $r; $cLive++; }
        else {
            if ($st === 'queued' || $st === 'releasing' || $st === 'awaiting-ns') $cQueued++;
            else $cStaged++;
            $queue[$dom] = $r;
        }
    }
    // order queue by go_live_at then domain
    uksort($queue, function ($a, $b) use ($queue) {
        return [$queue[$a]['go_live_at'] ?? '', $a] <=> [$queue[$b]['go_live_at'] ?? '', $b];
    });
    $today = infra_today();
    ?>
    <div class="ic-tiles">
      <div class="ic-tile"><div class="n"><?= $cStaged ?></div><div class="l">Staged</div></div>
      <div class="ic-tile"><div class="n"><?= $cQueued ?></div><div class="l">Queued</div></div>
      <div class="ic-tile"><div class="n"><?= $cLive ?></div><div class="l">Live</div></div>
      <div class="ic-tile" style="background:#f9fafb"><div class="n" style="color:#6b7280"><?= $cAcquiring ?></div><div class="l">Still acquiring</div></div>
    </div>

    <div class="ic-note">Go-live = switching the domain's <strong>nameservers at the registrar</strong> to the Cloudflare pair. Cloudflare then flips the zone to <em>active</em> and the console detects it. Registrars with API credentials (e.g. NameSilo, Namecheap) switch NS <strong>automatically</strong> on Release / the daily cron; any others surface the NS to set manually. Use <strong>Refresh</strong> to poll Cloudflare and mark domains live.</div>

    <div class="ic-card"><h2>Schedule rollout</h2><div class="body">
      <form method="post" action="actions/golive.php">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="schedule">
        Release <input type="number" name="per_day" value="20" min="1" style="width:70px;padding:6px 8px;border:1px solid #d1d5db;border-radius:8px"> per day,
        starting <input type="date" name="start_date" value="<?= ih($today) ?>" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:8px">
        <button class="btn" type="submit" onclick="return confirm('Schedule all non-live domains into daily batches?')">Schedule</button>
      </form>
      <form method="post" action="actions/golive.php" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="refresh">
        <button class="btn sec" type="submit">&#8635; Refresh live status (poll Cloudflare)</button>
      </form>
    </div></div>

    <div class="ic-card"><h2>Queue (<?= count($queue) ?>)</h2><div class="body">
      <?php if (!$queue): ?><div class="ic-empty">Nothing staged or queued.<?= $cAcquiring
          ? ' <strong>' . $cAcquiring . '</strong> domain(s) are still in the acquisition stage —'
            . ' buy them and provision them (New Site / Bulk) before they can go live.'
              . ' <a href="index.php?view=domains">Domains &rarr;</a>'
          : ' Provision domains first.' ?></div>
      <?php else: ?>
        <input class="ic-search" type="search" placeholder="Filter…" data-target="tbl-q">
        <table id="tbl-q"><thead><tr><th>Domain</th><th>Registrar</th><th>Target nameservers</th><th>Go-live</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($queue as $dom => $r):
          $due = ($r['go_live_at'] ?? '') !== '' && $r['go_live_at'] <= $today;
          $stCls = ($r['status'] === 'releasing') ? 'b-warn' : (($r['status'] === 'awaiting-ns') ? 'b-warn' : 'b-mut');
        ?>
          <tr>
            <td><strong><?= ih($dom) ?></strong></td>
            <td><?= $r['registrar'] !== '' ? ih($r['registrar']) : '<span class="badge b-mut">?</span>' ?></td>
            <td style="font-size:11px;color:#374151"><?= $r['nameservers'] !== '' ? ih(str_replace(',', ', ', $r['nameservers'])) : '<span class="badge b-warn">not staged</span>' ?></td>
            <td><?= $r['go_live_at'] !== '' ? ih($r['go_live_at']) . ($due ? ' <span class="badge b-warn">due</span>' : '') : '<span style="color:#9ca3af">—</span>' ?></td>
            <td><span class="badge <?= $stCls ?>"><?= ih($r['status'] ?: 'staged') ?></span></td>
            <td style="text-align:right">
              <form method="post" action="actions/golive.php" style="display:inline">
                <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                <input type="hidden" name="action" value="release">
                <input type="hidden" name="domain" value="<?= ih($dom) ?>">
                <button class="btn sec" type="submit" onclick="return confirm('Release <?= ih($dom) ?> now? (switch NS to Cloudflare)')">Release now</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div></div>

    <?php if ($live): ?>
    <div class="ic-card"><h2>Live (<?= count($live) ?>)</h2><div class="body">
      <table><thead><tr><th>Domain</th><th>Registrar</th><th>Server</th></tr></thead><tbody>
      <?php foreach ($live as $dom => $r): ?>
        <tr><td><strong><?= ih($dom) ?></strong> <span class="badge b-ok">live</span></td><td><?= ih($r['registrar'] ?: '—') ?></td><td><?= ih($r['server_id'] ?: '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div></div>
    <?php endif; ?>
    <?php infra_search_js(); infra_footer(); exit;
}

/* ============================= CITIES / NICHE =============================
 * Pick the cities a niche will target, score them, choose an area code, and
 * point a domain at each one. Everything on this page is a PLAN — re-pointable,
 * re-scorable, deletable. Nothing here spends money.
 */
if ($view === 'cities') {
    require_once __DIR__ . '/lib/cities.php';
    require_once __DIR__ . '/lib/keywords.php';
    require_once __DIR__ . '/lib/serp.php';
    infra_header('cities');

    $niches = infra_niches();
    $niche  = infra_niche_slug($_GET['niche'] ?? '');
    if ($niche === '' || !isset($niches[$niche])) $niche = (string) array_key_first($niches);
    $counts = infra_cn_counts();
    $total  = infra_cities_count();

    // "selected" = the cities already picked for this niche; "pool" = browse all.
    $show   = ($_GET['show'] ?? 'selected') === 'pool' ? 'pool' : 'selected';
    $q      = trim((string) ($_GET['q'] ?? ''));
    $stateF = strtoupper(trim((string) ($_GET['state'] ?? '')));
    $minPop = (int) ($_GET['min_pop'] ?? 0);
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $per    = 100;

    // Round-trip the filters through every form so a save comes back where you were.
    $sortQ = (string) ($_GET['sort'] ?? '');
    $dirQ  = (string) ($_GET['dir'] ?? '');
    $qs = http_build_query(array_filter([
        'show' => $show !== 'selected' ? $show : null, 'q' => $q ?: null,
        'state' => $stateF ?: null, 'min_pop' => $minPop ?: null, 'page' => $page > 1 ? $page : null,
        'sort' => $sortQ ?: null, 'dir' => $dirQ ?: null,
    ]));
    $selfUrl = fn(array $over = []) => 'index.php?view=cities&' . http_build_query(array_filter(array_merge([
        'niche' => $niche, 'show' => $show, 'q' => $q, 'state' => $stateF,
        'min_pop' => $minPop ?: null, 'page' => $page > 1 ? $page : null,
        'sort' => $sortQ ?: null, 'dir' => $dirQ ?: null,
    ], $over), fn($v) => $v !== null && $v !== ''));

    // Domains this niche could use: owned, right niche, not already on another city.
    $taken = infra_cn_domains_taken();
    $avail = [];
    foreach (infra_state_all_domains() as $d => $r) {
        if (($r['niche'] ?? '') !== $niche) continue;
        $avail[$d] = ['owned' => ($r['owned'] ?? '') === 'yes', 'taken' => $taken[$d] ?? ''];
    }
    ksort($avail);
    $freeCount = count(array_filter($avail, fn($a) => $a['owned'] && $a['taken'] === ''));
    ?>

    <!-- niche tabs -->
    <div class="ic-card" style="margin-bottom:14px"><div class="body" style="padding:10px 14px">
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <?php foreach ($niches as $s => $n): $c = $counts[$s] ?? ['selected' => 0, 'linked' => 0]; ?>
          <a href="index.php?view=cities&niche=<?= urlencode($s) ?>"
             class="btn <?= $s === $niche ? '' : 'sec' ?>" style="padding:4px 12px;font-size:13px">
            <?= ih($n['label']) ?>
            <span style="opacity:.7;font-size:11px"><?= (int) $c['selected'] ?>&nbsp;cities · <?= (int) $c['linked'] ?>&nbsp;linked</span>
          </a>
        <?php endforeach; ?>
        <details style="margin-left:auto"><summary style="cursor:pointer;font-size:12px;color:#6b7280">Manage niches</summary>
          <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
            <form method="post" action="actions/cities_save.php" style="display:flex;gap:6px;align-items:flex-end">
              <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
              <input type="hidden" name="action" value="niche_add">
              <label style="font-size:12px">Add a niche<br><input name="new_niche" placeholder="roofing" required style="padding:5px 8px"></label>
              <label style="font-size:12px">Label<br><input name="new_label" placeholder="Roofing" style="padding:5px 8px"></label>
              <button class="btn" type="submit">Add</button>
            </form>
            <form method="post" action="actions/cities_save.php" style="display:flex;gap:6px;align-items:flex-end"
                  onsubmit="return confirm('Remove this niche and every city selection under it? Cities and domains are not touched.')">
              <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
              <input type="hidden" name="action" value="niche_delete">
              <label style="font-size:12px">Remove<br>
                <select name="slug" style="padding:5px 8px">
                  <?php foreach ($niches as $s => $n): ?><option value="<?= ih($s) ?>"><?= ih($n['label']) ?></option><?php endforeach; ?>
                </select></label>
              <button class="btn sec" type="submit">Remove</button>
            </form>
          </div>
        </details>
      </div>
    </div></div>

    <?php if (!$total): ?>
      <div class="ic-card"><div class="body">
        <p style="margin-top:0">The city list has not been loaded yet. It comes from
          <code>admin/infra/data/us_cities.csv</code> — the 10,000 largest US cities by
          2024 Census population, ranked 1 (largest) down, with suggested area codes.</p>
        <form method="post" action="actions/cities_save.php">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="action" value="seed">
          <input type="hidden" name="niche" value="<?= ih($niche) ?>">
          <button class="btn" type="submit">Load the city list</button>
        </form>
      </div></div>
      <?php infra_footer(); exit;
    endif; ?>

    <?php
    $kwOn    = infra_kw_configured();
    $tpl     = $niches[$niche]['template'] ?? '';
    $primary = infra_niche_source($niche);
    $provs   = infra_kw_types();
    $groups  = infra_city_name_groups($tpl);

    /** What the first page looks like: open / tight / crowded, detail on hover. */
    $serpCell = function (array $r): string {
        if (($r['serp_at'] ?? '') === '') return '<span style="color:#d1d5db">&mdash;</span>';
        $d = ['map' => $r['serp_map'] ?? '', 'ads' => $r['serp_ads'] ?? '', 'dirs' => $r['serp_dirs'] ?? '',
              'first' => $r['serp_first'] ?? ''];
        $v = infra_serp_verdict($d);
        $cls = $v === 'open' ? 'b-ok' : ($v === 'tight' ? 'b-warn' : 'b-err');
        $tip = ($d['map'] === 'yes' ? 'Map pack present' : 'No map pack')
             . ' \u{b7} ' . (int) $d['ads'] . ' ads above organic'
             . ' \u{b7} ' . (int) $d['dirs'] . ' directories in the results'
             . ($d['first'] !== '' ? ' \u{b7} first organic at position ' . (int) $d['first'] : '')
             . (($r['serp_top'] ?? '') !== '' ? ' \u{b7} top: ' . $r['serp_top'] : '')
             . ' \u{b7} checked ' . substr((string) $r['serp_at'], 0, 10);
        return '<span class="badge ' . $cls . '" title="' . ih($tip) . '">' . ih($v) . ' ' . (int) $r['serp_score'] . '</span>';
    };

    $sort = (string) ($_GET['sort'] ?? 'rank');
    $dir  = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    if (!isset(infra_cities_sorts()[$sort])) $sort = 'rank';

    /** A sortable column heading. Clicking the active one flips direction. */
    $th = function (string $key, string $label, string $style = '', string $tip = '') use (&$selfUrl, $sort, $dir): string {
        $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        // Population, volume, CPC and score are most useful biggest-first, so
        // that is what the first click on them gives you.
        if ($sort !== $key && in_array($key, ['pop', 'score'], true) === false
            && preg_match('/_(volume|cpc)$/', $key)) $next = 'desc';
        if ($sort !== $key && in_array($key, ['pop', 'score'], true)) $next = 'desc';
        $arrow = $sort === $key ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '';
        return '<th style="' . $style . '"' . ($tip ? ' title="' . ih($tip) . '"' : '') . '>'
             . '<a href="' . ih($selfUrl(['sort' => $key, 'dir' => $next, 'page' => null])) . '"'
             . ' style="color:inherit;text-decoration:none' . ($sort === $key ? ';color:#2563eb' : '') . '">'
             . $label . $arrow . '</a></th>';
    };

    /** Badge for a city whose keyword is shared with a same-named city elsewhere. */
    $sharedBadge = function (array $city) use ($groups): string {
        $s = infra_city_shared($city, $groups);
        if (!$s) return '';
        $names = implode(', ', array_map(fn($c) => $c['city'] . ' ' . $c['ss'], array_slice($s['others'], 0, 4)));
        if (count($s['others']) > 4) $names .= ' and ' . (count($s['others']) - 4) . ' more';
        $tip = $s['is_primary']
            ? 'Shares this keyword with ' . $names . '. As the largest, these numbers are most likely about this one.'
            : 'Shares this keyword with ' . $names . '. The figures probably describe '
              . $s['primary']['city'] . ' ' . $s['primary']['ss'] . ' (population '
              . number_format((int) $s['primary']['population']) . '), not this city.';
        return ' <span class="badge ' . ($s['is_primary'] ? 'b-mut' : 'b-warn') . '" title="' . ih($tip) . '">'
             . ($s['is_primary'] ? 'shared name' : 'shared &mdash; not this one') . '</span>';
    };
    ?>
    <div class="ic-note">
      Pick the cities <strong><?= ih($niches[$niche]['label']) ?></strong> will target, score them, choose an
      area code, then point a domain at each. This is a <strong>plan</strong> — re-point a domain any time;
      nothing here buys anything. <strong><?= $freeCount ?></strong> owned <?= ih($niche) ?> domain<?= $freeCount === 1 ? '' : 's' ?>
      not yet assigned to a city. Phone numbers are typed in for now.
    </div>

    <!-- keyword + fetch -->
    <div class="ic-card" style="margin-bottom:14px"><div class="body" style="padding:10px 14px;display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <form method="post" action="actions/cities_save.php" style="display:flex;gap:6px;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="template">
        <input type="hidden" name="niche" value="<?= ih($niche) ?>">
        <input type="hidden" name="qs" value="<?= ih($qs) ?>">
        <label style="font-size:12px">Keyword looked up for each city<br>
          <input name="template" value="<?= ih($tpl) ?>" style="padding:5px 8px;width:280px" placeholder="appliance repair {city}"></label>
        <button class="btn sec" type="submit">Save keyword</button>
      </form>
      <div style="font-size:12px;color:#6b7280;max-width:300px">
        <code>{city}</code>, <code>{state}</code> and <code>{ss}</code> are replaced per row.
        <?php if ($tpl !== ''): ?>Rank 1 becomes “<strong><?= ih(infra_kw_phrase($tpl, ['city' => 'New York', 'state' => 'New York', 'ss' => 'NY'])) ?></strong>”.<?php endif; ?>
      </div>
      <?php if (count(infra_kw_types()) > 1): ?>
      <form method="post" action="actions/cities_save.php" style="display:flex;gap:6px;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="source">
        <input type="hidden" name="niche" value="<?= ih($niche) ?>">
        <input type="hidden" name="qs" value="<?= ih($qs) ?>">
        <label style="font-size:12px" title="Which provider the Score is computed from. The two do not share a scale, so a niche commits to one.">Score from<br>
          <select name="source" style="padding:5px 8px">
            <?php foreach (infra_kw_types() as $t => $m): ?>
              <option value="<?= ih($t) ?>" <?= $t === $primary ? 'selected' : '' ?>><?= ih($m['label']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <button class="btn sec" type="submit">Apply &amp; re-score</button>
      </form>
      <?php endif; ?>
      <?php if ($kwOn): ?>
        <form method="post" action="actions/cities_save.php" style="margin-left:auto;display:flex;gap:6px;align-items:flex-end">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="action" value="fetch">
          <input type="hidden" name="niche" value="<?= ih($niche) ?>">
          <input type="hidden" name="qs" value="<?= ih($qs) ?>">
          <?php if (count($kwOn) > 1): ?>
            <label style="font-size:12px">Source<br>
              <select name="provider" style="padding:5px 8px">
                <?php foreach ($kwOn as $t => $m): ?><option value="<?= ih($t) ?>"><?= ih($m['label']) ?></option><?php endforeach; ?>
              </select></label>
          <?php else: ?>
            <input type="hidden" name="provider" value="<?= ih((string) array_key_first($kwOn)) ?>">
          <?php endif; ?>
          <label style="font-size:12px">Re-fetch older than<br>
            <select name="stale_days" style="padding:5px 8px">
              <option value="0">never fetched</option>
              <option value="30" selected>30 days</option>
              <option value="90">90 days</option>
              <option value="-1">everything</option>
            </select></label>
          <button class="btn" type="submit">Fetch<?= count($kwOn) > 1 ? '' : ' from ' . ih($kwOn[array_key_first($kwOn)]['label']) ?></button>
        </form>
      <?php endif; ?>
    </div></div>

    <!-- filters + mode -->
    <form method="get" class="ic-card" style="margin-bottom:14px"><div class="body" style="padding:10px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="view" value="cities">
      <input type="hidden" name="niche" value="<?= ih($niche) ?>">
      <label style="font-size:12px">Show<br>
        <select name="show" style="padding:5px 8px">
          <option value="selected" <?= $show === 'selected' ? 'selected' : '' ?>>Selected cities</option>
          <option value="pool"     <?= $show === 'pool' ? 'selected' : '' ?>>All <?= number_format($total) ?> cities</option>
        </select></label>
      <label style="font-size:12px">Search<br><input name="q" value="<?= ih($q) ?>" placeholder="city or state" style="padding:5px 8px"></label>
      <label style="font-size:12px">State<br>
        <select name="state" style="padding:5px 8px"><option value="">any</option>
          <?php foreach (infra_states_list() as $s): ?>
            <option value="<?= ih($s) ?>" <?= $s === $stateF ? 'selected' : '' ?>><?= ih($s) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label style="font-size:12px">Min population<br><input name="min_pop" type="number" min="0" step="1000" value="<?= $minPop ?: '' ?>" placeholder="any" style="padding:5px 8px;width:110px"></label>
      <button class="btn sec" type="submit">Apply</button>
      <?php if ($q || $stateF || $minPop): ?><a class="btn sec" href="<?= ih($selfUrl(['q' => null, 'state' => null, 'min_pop' => null, 'page' => null])) ?>">Clear</a><?php endif; ?>
    </div></form>

    <?php
    /* ---------- SELECTED: the editable plan ---------- */
    if ($show === 'selected'):
        $rows = infra_cn_selected($niche, $sort, $dir);
        if ($q !== '' || $stateF !== '' || $minPop > 0) {
            $rows = array_values(array_filter($rows, function ($r) use ($q, $stateF, $minPop) {
                if ($stateF !== '' && $r['ss'] !== $stateF) return false;
                if ($minPop > 0 && (int) $r['population'] < $minPop) return false;
                if ($q !== '' && stripos($r['city'] . ' ' . $r['state'] . ' ' . $r['ss'], $q) === false) return false;
                return true;
            }));
        }
    ?>
    <form method="post" action="actions/cities_save.php">
      <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
      <input type="hidden" name="niche" value="<?= ih($niche) ?>">
      <input type="hidden" name="qs" value="<?= ih($qs) ?>">
      <div class="ic-card">
        <h2><?= count($rows) ?> selected <?= ih($niches[$niche]['label']) ?> cit<?= count($rows) === 1 ? 'y' : 'ies' ?></h2>
        <div class="body">
        <?php if (!$rows): ?>
          <div class="ic-empty">No cities picked for this niche yet — switch <em>Show</em> to
            <a href="<?= ih($selfUrl(['show' => 'pool'])) ?>">all cities</a> and tick the ones you want.</div>
        <?php else: ?>
          <table><thead>
          <tr>
            <th colspan="5"></th>
            <?php foreach ($provs as $t => $m): ?>
              <th colspan="3" style="text-align:center;border-bottom:2px solid <?= $t === $primary ? '#2563eb' : '#e5e7eb' ?>">
                <?= ih($m['label']) ?><?= $t === $primary ? ' <span style="font-weight:400;font-size:10px;color:#2563eb">scoring</span>' : '' ?>
              </th>
            <?php endforeach; ?>
            <th colspan="4"></th>
          </tr>
          <tr>
            <th style="width:24px"></th>
            <?= $th('rank','#','width:46px') ?><?= $th('city','City') ?><?= $th('ss','St','width:34px') ?>
            <?= $th('pop','Pop','width:70px') ?>
            <?php foreach ($provs as $t => $m): ?>
              <?= $th($t.'_volume','Vol','width:58px','Monthly searches') ?>
              <?= $th($t.'_kd','KD','width:42px',"Keyword difficulty on ".$m['label']."'s own 0-100 scale") ?>
              <?= $th($t.'_cpc','CPC','width:58px','Cost per click, US dollars') ?>
            <?php endforeach; ?>
            <?= $th('score','Score','width:58px') ?><?= $th('serp','SERP','width:96px','How open the first page is: map pack, ads, directories') ?>
            <th style="width:96px">Area code</th><th style="width:118px">Phone</th><th>Domain</th>
          </tr></thead><tbody>
          <?php foreach ($rows as $r): $id = $r['id']; ?>
            <tr>
              <td><input type="checkbox" name="city_id[]" value="<?= ih($id) ?>"></td>
              <td style="color:#9ca3af"><?= (int) $r['rank'] ?></td>
              <td><strong><?= ih($r['city']) ?></strong><?= $sharedBadge($r) ?></td>
              <td><?= ih($r['ss']) ?></td>
              <td style="text-align:right;color:#6b7280"><?= number_format((int) $r['population']) ?></td>
              <?php foreach ($provs as $t => $pm):
                  $mm  = infra_cn_metrics($r, $t);
                  $dim = $t === $primary ? '' : 'color:#9ca3af;';
                  $tip = $mm['at'] !== '' ? ih($pm['label']) . ', fetched ' . ih(substr($mm['at'], 0, 10)) : 'not fetched from ' . ih($pm['label']); ?>
                <td style="text-align:right;<?= $dim ?>" title="<?= $tip ?>"><?= $mm['volume'] !== '' ? number_format((int) $mm['volume']) : '<span style="color:#d1d5db">—</span>' ?></td>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['kd'] !== '' ? ih($mm['kd']) : '<span style="color:#d1d5db">—</span>' ?></td>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['cpc'] !== '' ? '$' . ih($mm['cpc']) : '<span style="color:#d1d5db">—</span>' ?></td>
              <?php endforeach; ?>
              <td><input name="row[<?= ih($id) ?>][score]" value="<?= ih($r['score']) ?>" type="number" min="1" max="10" step="1"
                         style="width:52px;padding:3px 6px<?= ($r['score_src'] ?? '') === 'auto' ? ';background:#f8fafc' : '' ?>"
                         title="<?= ($r['score_src'] ?? '') === 'hand' ? 'Set by hand — a fetch will not overwrite it' : (($r['score_src'] ?? '') === 'auto' ? 'Computed from the metrics' : 'Not scored yet') ?>"></td>
              <td><?= $serpCell($r) ?></td>
              <td>
                <input name="row[<?= ih($id) ?>][area_code]" value="<?= ih($r['area_code']) ?>" list="ac-<?= ih($id) ?>"
                       placeholder="<?= ih(implode('/', array_slice(infra_city_area_codes($r), 0, 2))) ?>" style="width:96px;padding:3px 6px">
                <datalist id="ac-<?= ih($id) ?>">
                  <?php foreach (infra_city_area_codes($r) as $c): ?><option value="<?= ih($c) ?>"><?php endforeach; ?>
                </datalist>
              </td>
              <td><input name="row[<?= ih($id) ?>][phone]" value="<?= ih($r['phone']) ?>" placeholder="—" style="width:128px;padding:3px 6px"></td>
              <td>
                <select name="row[<?= ih($id) ?>][domain]" style="padding:3px 6px;max-width:250px">
                  <option value="">— none —</option>
                  <?php foreach ($avail as $d => $a):
                      $mine = ($d === strtolower($r['domain'] ?? ''));
                      if ($a['taken'] !== '' && !$mine) continue; ?>
                    <option value="<?= ih($d) ?>" <?= $mine ? 'selected' : '' ?>>
                      <?= ih($d) ?><?= $a['owned'] ? '' : ' (not owned yet)' ?></option>
                  <?php endforeach; ?>
                  <?php if (($r['domain'] ?? '') !== '' && !isset($avail[strtolower($r['domain'])])): ?>
                    <option value="<?= ih($r['domain']) ?>" selected><?= ih($r['domain']) ?> (other niche)</option>
                  <?php endif; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
          <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
            <button class="btn" type="submit" name="action" value="save">Save changes</button>
            <button class="btn sec" type="submit" name="action" value="unselect"
                    onclick="return confirm('Remove the ticked cities from this niche? The domain link is released; the Ahrefs figure and score are kept.')">Remove ticked</button>
            <span style="font-size:12px;color:#6b7280">A score you type is recorded as set by hand.</span>
          </div>
        <?php endif; ?>
        </div>
      </div>
    </form>

    <?php else:
    /* ---------- POOL: browse and pick ---------- */
        $browse = infra_cities_browse([
            'q' => $q, 'state' => $stateF, 'min_pop' => $minPop,
            'limit' => $per, 'offset' => ($page - 1) * $per,
            'niche' => $niche, 'sort' => $sort, 'dir' => $dir,
        ]);
        $mine  = infra_cn_all($niche);
        $pages = max(1, (int) ceil($browse['total'] / $per));
    ?>
    <form method="post" action="actions/cities_save.php">
      <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
      <input type="hidden" name="niche" value="<?= ih($niche) ?>">
      <input type="hidden" name="qs" value="<?= ih($qs) ?>">
      <div class="ic-card">
        <?php
        $sortNames = ['rank' => 'population', 'city' => 'name', 'ss' => 'state', 'pop' => 'population', 'score' => 'score'];
        foreach ($provs as $t => $m) {
            $sortNames[$t . '_volume'] = $m['label'] . ' volume';
            $sortNames[$t . '_kd']     = $m['label'] . ' difficulty';
            $sortNames[$t . '_cpc']    = $m['label'] . ' CPC';
        }
        ?>
        <h2><?= number_format($browse['total']) ?> cities <span style="color:#9ca3af;font-weight:400;font-size:13px">&mdash; page <?= $page ?> of <?= $pages ?>, sorted by <?= ih($sortNames[$sort] ?? $sort) ?><?= $dir === 'desc' ? ', highest first' : '' ?></span></h2>
        <div class="body">
          <table><thead>
          <tr>
            <th colspan="5"></th>
            <?php foreach ($provs as $t => $m): ?>
              <th colspan="3" style="text-align:center;border-bottom:2px solid <?= $t === $primary ? '#2563eb' : '#e5e7eb' ?>">
                <?= ih($m['label']) ?><?= $t === $primary ? ' <span style="font-weight:400;font-size:10px;color:#2563eb">scoring</span>' : '' ?>
              </th>
            <?php endforeach; ?>
            <th colspan="3"></th>
          </tr>
          <tr>
            <th style="width:24px"></th>
            <?= $th('rank','#','width:46px') ?><?= $th('city','City') ?><?= $th('ss','St','width:34px') ?>
            <?= $th('pop','Population','width:78px') ?>
            <?php foreach ($provs as $t => $m): ?>
              <?= $th($t.'_volume','Vol','width:58px') ?><?= $th($t.'_kd','KD','width:42px') ?><?= $th($t.'_cpc','CPC','width:58px') ?>
            <?php endforeach; ?>
            <?= $th('score','Score','width:52px') ?><?= $th('serp','SERP','width:96px','How open the first page is: map pack, ads, directories') ?>
            <th>Area codes</th><th style="width:84px">In niche</th>
          </tr></thead><tbody>
          <?php foreach ($browse['rows'] as $r):
              // A row in city_niche means "we know something about this city", NOT
              // that it is picked — fetching metrics creates one. Reading it as
              // picked put a "selected" badge on every researched city and took
              // away its checkbox, so the ones you had just looked up were the ones
              // you could no longer choose.
              $picked = (($mine[$r['id']]['selected'] ?? '') === 'yes'); ?>
            <tr<?= $picked ? ' style="background:#f8fafc"' : '' ?>>
              <td><?php if (!$picked): ?><input type="checkbox" name="city_id[]" value="<?= ih($r['id']) ?>"><?php endif; ?></td>
              <td style="color:#9ca3af"><?= (int) $r['rank'] ?></td>
              <td><strong><?= ih($r['city']) ?></strong> <span style="color:#9ca3af;font-size:12px"><?= ih($r['state']) ?></span><?= $sharedBadge($r) ?></td>
              <td><?= ih($r['ss']) ?></td>
              <td style="text-align:right;color:#6b7280"><?= number_format((int) $r['population']) ?></td>
              <?php $m = $mine[$r['id']] ?? [];
              foreach ($provs as $t => $pm):
                  $mm  = infra_cn_metrics($m, $t);
                  $dim = $t === $primary ? '' : 'color:#9ca3af;'; ?>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['volume'] !== '' ? number_format((int) $mm['volume']) : '<span style="color:#d1d5db">—</span>' ?></td>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['kd'] !== '' ? ih($mm['kd']) : '<span style="color:#d1d5db">—</span>' ?></td>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['cpc'] !== '' ? '$' . ih($mm['cpc']) : '<span style="color:#d1d5db">—</span>' ?></td>
              <?php endforeach; ?>
              <td style="text-align:right"><?= ($m['score'] ?? '') !== '' ? '<strong>' . ih($m['score']) . '</strong>' : '<span style="color:#d1d5db">—</span>' ?></td>
              <td><?= $serpCell($m ?: []) ?></td>
              <td style="font-size:12px">
                <?= ih(implode(' · ', infra_city_area_codes($r))) ?: '<span style="color:#9ca3af">none known</span>' ?>
                <?php if (($r['ac_source'] ?? '') === 'near'): ?><span class="badge b-mut" title="Borrowed from nearby cities in the same state — check before using">nearby</span><?php endif; ?>
              </td>
              <td><?= $picked ? '<span class="badge b-ok">selected</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
          <div style="display:flex;gap:8px;margin-top:12px;align-items:center;flex-wrap:wrap">
            <?php if ($kwOn): ?>
              <!-- The filter, carried so the sweep covers every match rather than
                   the 100 rows that happen to be on screen. -->
              <input type="hidden" name="f_q" value="<?= ih($q) ?>">
              <input type="hidden" name="f_state" value="<?= ih($stateF) ?>">
              <input type="hidden" name="f_min_pop" value="<?= $minPop ?: '' ?>">
              <button class="btn" type="submit" name="action" value="fetch"
                      onclick="this.form.scope.value='filter'"
                      title="Every city matching the filter above, not just this page">
                Fetch all <?= number_format($browse['total']) ?> matching
              </button>
              <input type="hidden" name="scope" value="">
              <button class="btn sec" type="submit" name="action" value="fetch"
                      onclick="this.form.scope.value=''">Fetch ticked only</button>
              <?php if (count($kwOn) > 1): ?>
                <select name="provider" style="padding:5px 8px;font-size:13px">
                  <?php foreach ($kwOn as $t => $m): ?><option value="<?= ih($t) ?>"><?= ih($m['label']) ?></option><?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="hidden" name="provider" value="<?= ih((string) array_key_first($kwOn)) ?>">
              <?php endif; ?>
              <select name="stale_days" style="padding:5px 8px;font-size:13px" title="Which cities count as needing a fetch">
                <option value="0">never fetched</option>
                <option value="30" selected>fetched over 30 days ago</option>
                <option value="-1">everything, again</option>
              </select>
            <?php endif; ?>
            <?php if (isset($kwOn['dataforseo'])): ?>
              <button class="btn sec" type="submit" name="action" value="serp"
                      title="Reads the live first page for each city: map pack, ads above organic, directories holding the slots. About $0.002 a city.">SERP check top 50</button>
              <input type="hidden" name="serp_limit" value="50">
            <?php endif; ?>
            <button class="btn <?= $kwOn ? 'sec' : '' ?>" type="submit" name="action" value="select">Add ticked to <?= ih($niches[$niche]['label']) ?></button>
            <?php if ($page > 1): ?><a class="btn sec" href="<?= ih($selfUrl(['page' => $page - 1])) ?>">&larr; Prev</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="btn sec" href="<?= ih($selfUrl(['page' => $page + 1])) ?>">Next &rarr;</a><?php endif; ?>
            <span style="font-size:12px;color:#6b7280">Fetching does not select a city — look the numbers up first, pick after.
              Ticks are lost when you change page.</span>
          </div>
        </div>
      </div>
    </form>
    <?php endif; ?>

    <?php
    // Name every provider and its state in the summary. Collapsing the panel the
    // moment one provider connects hid the one still waiting to be set up.
    $kwBits = [];
    foreach (infra_kw_types() as $t => $m) {
        $kwBits[] = ih($m['label']) . (isset($kwOn[$t]) ? ' connected' : ' <strong>not connected</strong>');
    }
    ?>
    <details style="margin-top:14px" <?= $kwOn ? '' : 'open' ?>>
      <summary style="cursor:pointer;font-size:12px;color:#6b7280">Keyword providers — <?= implode(' · ', $kwBits) ?> <span style="color:#2563eb">(add or change credentials)</span></summary>
      <div class="ic-card" style="margin-top:8px"><div class="body">
        <?php foreach (infra_kw_types() as $type => $meta): $stored = infra_kw_provider($type); $on = isset($kwOn[$type]); ?>
          <h3 style="margin:18px 0 8px;font-size:14px">
            <?= ih($meta['label']) ?>
            <span class="badge <?= $on ? 'b-ok' : 'b-mut' ?>"><?= $on ? 'connected' : 'not connected' ?></span>
          </h3>
          <form method="post" action="actions/cities_save.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="niche" value="<?= ih($niche) ?>">
            <input type="hidden" name="qs" value="<?= ih($qs) ?>">
            <input type="hidden" name="type" value="<?= ih($type) ?>">
            <?php foreach ($meta['fields'] as $f => $spec):
                $has = trim((string) ($stored[$f] ?? '')) !== ''; ?>
              <label style="font-size:12px"><?= ih($spec['label']) ?><br>
                <input name="f[<?= ih($f) ?>]"
                       type="<?= !empty($spec['secret']) ? 'password' : 'text' ?>"
                       value="<?= !empty($spec['secret']) ? '' : ih($stored[$f] ?? ($spec['default'] ?? '')) ?>"
                       placeholder="<?= !empty($spec['secret']) ? ($has ? 'stored — leave blank to keep' : 'paste key') : ih($spec['default'] ?? '') ?>"
                       style="padding:5px 8px;width:<?= !empty($spec['secret']) ? '260' : '110' ?>px"></label>
            <?php endforeach; ?>
            <button class="btn" type="submit" name="action" value="kw_save">Save</button>
            <button class="btn sec" type="submit" name="action" value="kw_test">Test</button>
          </form>
          <div class="ic-note" style="margin-top:10px"><?= $meta['note'] ?></div>
        <?php endforeach; ?>
        <div class="ic-note" style="margin-top:10px">
          Credentials are stored in <code>admin/infra/config/keywords.json</code> — gitignored, <code>0600</code>,
          never printed back into this page. <strong>Test costs nothing at either provider</strong> and reports
          what is <em>left</em> — Ahrefs units, DataForSEO dollars — rather than just "the key works", because a
          key with no quota fails on the day you need it, not the day you test it.
          <br><br><strong>CPC is stored in dollars whichever provider it came from.</strong> Ahrefs returns cents
          and DataForSEO returns dollars; each adapter converts, so the column never mixes units.
          <br><br><strong>Score formula (<?= ih($provs[$primary]['label']) ?>):</strong> <?= ih(infra_kw_score_formula($primary)) ?>
          A score you type overrides it and is never overwritten by a fetch.
        </div>
      </div></div>
    </details>

    <details style="margin-top:14px"><summary style="cursor:pointer;font-size:12px;color:#6b7280">Where this data comes from</summary>
      <div class="ic-note" style="margin-top:8px">
        Population and rank: <strong>US Census sub-est2024</strong> (rank 1 = largest of the 10,000 loaded).
        Area codes: exact city matches from a public area-code database, otherwise borrowed from
        cities nearby <em>in the same state</em> and flagged <span class="badge b-mut">nearby</span> —
        a suggestion to check, not a fact. Rebuild the file with
        <code>python3 admin/infra/data/build_cities.py</code>, then re-load it here.
        <form method="post" action="actions/cities_save.php" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="action" value="seed">
          <input type="hidden" name="niche" value="<?= ih($niche) ?>">
          <button class="btn sec" type="submit">Re-load the city list</button>
          <span style="font-size:12px;color:#6b7280">Refreshes populations and area codes. Your selections are keyed by city, not by rank, so they survive.</span>
        </form>
      </div>
    </details>

    <?php infra_footer(); exit;
}

/* ============================= BUY QUEUE ============================= */
if ($view === 'buyqueue') {
    infra_header('buyqueue');
    $today = infra_today();
    $due = $ahead = $bought = $failed = $undated = [];
    foreach (infra_state_all_domains() as $dom => $r) {
        if (($r['owned'] ?? '') === 'yes')            { $bought[$dom] = $r; continue; }
        if (($r['status'] ?? '') === 'buy-failed')    { $failed[$dom] = $r; continue; }
        if (($r['buy_at'] ?? '') === '')              { $undated[$dom] = $r; continue; }
        if ($r['buy_at'] <= $today)                    $due[$dom] = $r;
        else                                           $ahead[$dom] = $r;
    }
    $bydate = [];
    foreach ($ahead as $dom => $r) $bydate[$r['buy_at']][] = $dom;
    ksort($bydate);
    uasort($due, fn($a, $b) => [$a['buy_at'], $a['domain']] <=> [$b['buy_at'], $b['domain']]);

    $spend = 0.0;
    foreach ($due as $r) $spend += (float) ($r['avail_price'] ?: 11);
    ?>
    <div class="ic-tiles">
      <div class="ic-tile"><div class="n"><?= count($due) ?></div><div class="l">Due today</div></div>
      <div class="ic-tile"><div class="n"><?= count($ahead) ?></div><div class="l">Scheduled ahead</div></div>
      <div class="ic-tile"><div class="n"><?= count($bought) ?></div><div class="l">Bought</div></div>
      <div class="ic-tile"><div class="n"><?= count($failed) ?></div><div class="l">Failed</div></div>
      <div class="ic-tile"><div class="n"><?= count($undated) ?></div><div class="l">No date yet</div></div>
    </div>

    <div class="ic-note">
      This is the buying schedule at a glance — <strong>nothing on this page buys anything</strong>.
      Dates are <strong>US Central</strong>. Set them on the <a href="index.php?view=domains">Domains</a> tab
      (tick rows &rarr; <em>Spread N/day from</em>).
    </div>

    <div class="ic-card">
      <h2>Due today <span style="color:#9ca3af;font-weight:400;font-size:13px">&mdash; <?= (new DateTime('now', new DateTimeZone(INFRA_TZ)))->format('D j M Y') ?> (Central) &middot; about $<?= number_format($spend, 2) ?> if all of it went through</span></h2>
      <div class="body">
        <?php if (!$due): ?>
          <div class="ic-empty">Nothing due today.</div>
        <?php else: ?>
          <table><thead><tr><th>Domain</th><th>Registrar</th><th>Ready</th><th>Price</th><th>Scheduled</th><th></th></tr></thead><tbody>
          <?php foreach ($due as $dom => $r):
            $overdue = $r['buy_at'] < $today; ?>
            <tr>
              <td><a href="index.php?view=domain&d=<?= ih($dom) ?>"><strong><?= ih($dom) ?></strong></a></td>
              <td><?= $r['buy_registrar'] !== '' ? ih($r['buy_registrar']) : '<span class="badge b-err">none set</span>' ?></td>
              <td><?= $r['ready_to_buy'] === 'yes' ? '<span class="badge b-ok">yes</span>' : '<span class="badge b-warn">' . ih($r['ready_to_buy'] ?: 'not checked') . '</span>' ?></td>
              <td><?= $r['avail_price'] !== '' ? '$' . ih($r['avail_price']) : '<span style="color:#9ca3af">—</span>' ?></td>
              <td><?= ih($r['buy_at']) ?><?= $overdue ? ' <span class="badge b-warn">overdue</span>' : '' ?></td>
              <td style="text-align:right;white-space:nowrap">
                <?php
                $canBuy = ($r['ready_to_buy'] ?? '') === 'yes'
                       && ($r['buy_registrar'] ?? '') !== ''
                       && !empty(infra_registrar_type_def($r['buy_registrar'])['buy_wired']);
                $price  = $r['avail_price'] !== '' ? '$' . $r['avail_price'] : 'the quoted price';
                ?>
                <?php if ($canBuy): ?>
                  <form method="post" action="actions/domain_manage.php" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                    <input type="hidden" name="action" value="buy">
                    <input type="hidden" name="quick" value="1">
                    <input type="hidden" name="from" value="view=buyqueue">
                    <input type="hidden" name="domain" value="<?= ih($dom) ?>">
                    <input type="hidden" name="years" value="1">
                    <input type="hidden" name="auto_renew" value="1">
                    <button class="btn" style="background:#991b1b;padding:4px 12px;font-size:12px" type="submit"
                      onclick="return confirm('Buy <?= ih($dom) ?> at <?= ih($r['buy_registrar']) ?> for <?= ih($price) ?>, 1 year.<?= strtolower($r['buy_registrar']) === 'namecheap' ? '\n\nNote: Namecheap cannot set auto-renew over its API, so this will not renew itself.' : '' ?>\n\nThis spends real money and cannot be undone.');">Buy</button>
                  </form>
                <?php endif; ?>
                <a class="btn sec" style="padding:4px 10px;font-size:12px" href="index.php?view=domain&d=<?= ih($dom) ?>">Open &rarr;</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
          <div class="ic-note" style="margin-top:14px;margin-bottom:0">
            <strong>Buy</strong> purchases that one domain immediately &mdash; real money, no undo. Availability is
            re-checked in the moment before paying, so a name taken since the last check is never paid for.
            Every registrar is bought on a <strong>1-year</strong> term; use the years field on New Site or Bulk
            for anything longer. <strong>Namecheap domains will not renew themselves</strong> &mdash; its auto-renew
            cannot be set over the API, so each one needs a dashboard visit or a longer term chosen deliberately.
            Nothing here buys on its own.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($failed): ?>
    <div class="ic-card" style="border-color:#fca5a5">
      <h2 style="color:#991b1b">Failed (<?= count($failed) ?>) <span style="color:#9ca3af;font-weight:400;font-size:13px">&mdash; these do not retry on their own</span></h2>
      <div class="body"><table><thead><tr><th>Domain</th><th>Registrar</th><th>Why</th><th></th></tr></thead><tbody>
        <?php foreach ($failed as $dom => $r): ?>
          <tr>
            <td><a href="index.php?view=domain&d=<?= ih($dom) ?>"><strong><?= ih($dom) ?></strong></a></td>
            <td><?= ih($r['buy_registrar'] ?: '—') ?></td>
            <td style="color:#991b1b;font-size:12px"><?= ih($r['buy_error'] ?: 'unknown') ?></td>
            <td style="text-align:right"><a class="btn sec" href="index.php?view=domain&d=<?= ih($dom) ?>">Open &rarr;</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php endif; ?>

    <div class="ic-card">
      <h2>Scheduled ahead (<?= count($ahead) ?>)</h2>
      <div class="body">
        <?php if (!$bydate): ?>
          <div class="ic-empty">Nothing scheduled beyond today.</div>
        <?php else: ?>
          <table><thead><tr><th style="width:150px">Date</th><th style="width:70px">Count</th><th>Domains</th></tr></thead><tbody>
          <?php foreach ($bydate as $date => $doms):
            sort($doms);
            $show = array_slice($doms, 0, 8); ?>
            <tr>
              <td><strong><?= ih($date) ?></strong><br><span style="color:#9ca3af;font-size:11px"><?= ih(date('D', strtotime($date))) ?></span></td>
              <td><span class="badge b-mut"><?= count($doms) ?></span></td>
              <td style="font-size:12px;color:#374151"><?= ih(implode(', ', $show)) ?><?= count($doms) > 8 ? ' <span style="color:#9ca3af">+ ' . (count($doms) - 8) . ' more</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($undated): ?>
    <div class="ic-card">
      <h2>No buy date yet (<?= count($undated) ?>)</h2>
      <div class="body">
        <p style="margin-top:0;color:#6b7280;font-size:13px">Loaded but never scheduled. Tick them on the Domains tab and use <em>Spread N/day from</em>.</p>
        <div style="font-size:12px;color:#374151"><?= ih(implode(', ', array_slice(array_keys($undated), 0, 30))) ?><?= count($undated) > 30 ? ' …' : '' ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($bought): ?>
    <div class="ic-card">
      <h2>Bought (<?= count($bought) ?>)</h2>
      <div class="body"><table><thead><tr><th>Domain</th><th>Registrar</th><th>When</th><th>Auto-renew</th></tr></thead><tbody>
        <?php
        uasort($bought, fn($a, $b) => strcmp((string) $b['owned_at'], (string) $a['owned_at']));
        foreach ($bought as $dom => $r): ?>
          <tr>
            <td><a href="index.php?view=domain&d=<?= ih($dom) ?>"><strong><?= ih($dom) ?></strong></a></td>
            <td><?= ih($r['registrar'] ?: $r['buy_registrar'] ?: '—') ?></td>
            <td style="font-size:12px"><?= ih($r['owned_at'] ?: '—') ?></td>
            <td><?php $ar = $r['auto_renew'] ?? '';
              echo $ar === 'yes' ? '<span class="badge b-ok">yes</span>'
                 : ($ar === 'no' ? '<span class="badge b-warn">no</span>' : '<span class="badge b-mut">?</span>'); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php endif; ?>
    <?php infra_footer(); exit;
}

/* ============================= REGISTRARS ============================= */
if ($view === 'registrars') {
    infra_header('registrars');
    $types = infra_registrar_types();
    $saved = infra_load_json(infra_config_path('registrar.json'), [])['registrars'] ?? [];
    // Test results arrive via the session so a redirect can carry them (creds never in a URL).
    $tests = $_SESSION['infra_reg_tests'] ?? [];
    unset($_SESSION['infra_reg_tests']);
    ?>
    <div class="ic-note">These are the registrars the console can buy from and switch nameservers at. Credentials are stored in <code>admin/infra/config/registrar.json</code> — gitignored, <code>0600</code>, and never printed back into this page. <strong>Test</strong> is read-only: it verifies the credentials and reports the account balance, which is what decides whether a scheduled buy can actually complete.</div>

    <?php foreach ($types as $type => $def):
      $external = !empty($def['creds_from']);          // credentials live elsewhere (Cloudflare)
      $cfg   = null; $savedName = null;
      foreach ($saved as $name => $c) if (strtolower($c['type'] ?? $name) === $type) { $cfg = $c; $savedName = $name; }
      // An externally-credentialed registrar counts as configured when the other
      // page has what it needs — asking for the same token twice would be worse.
      if ($external && $cfg === null && infra_registrar_config($type)) $cfg = infra_registrar_config($type);
      $has   = $cfg !== null && $cfg !== [];
      $t     = $tests[$type] ?? null;
      $caps  = [];
      foreach (['check' => 'availability', 'buy' => 'auto-buy', 'ns' => 'nameservers', 'balance' => 'balance'] as $k => $lbl) {
          $on = !empty($def[$k]);
          // auto-buy is only genuinely usable when the adapter is written here too.
          $partial = ($k === 'buy' && $on && empty($def['buy_wired']));
          $caps[] = '<span class="badge ' . ($partial ? 'b-warn' : ($on ? 'b-ok' : 'b-mut')) . '"'
                  . ($partial ? ' title="the registrar API supports it; the purchase adapter is not written here yet"' : '')
                  . '>' . ($partial ? '~ ' : ($on ? '✓ ' : '✗ ')) . $lbl . '</span>';
      }
    ?>
      <div class="ic-card">
        <h2>
          <?= ih($def['label']) ?>
          <?= $has ? '<span class="badge b-ok">configured</span>' : '<span class="badge b-mut">not configured</span>' ?>
          <span style="margin-left:auto;font-weight:400;display:flex;gap:5px"><?= implode(' ', $caps) ?></span>
        </h2>
        <div class="body">
          <div style="color:#6b7280;font-size:12.5px;margin-bottom:12px"><?= ih($def['note']) ?></div>

          <?php if (!empty($def['autorenew'])):
            $ar = $def['autorenew'];
            $bad = empty($ar['ok']); ?>
            <div style="border:<?= $bad ? '2px solid #dc2626' : '1px solid #e5e7eb' ?>;background:<?= $bad ? '#fef2f2' : '#f9fafb' ?>;border-radius:8px;padding:11px 13px;margin-bottom:12px">
              <div style="font-weight:700;font-size:13px;color:<?= $bad ? '#b91c1c' : '#15803d' ?>">
                <?= $bad ? '⛔ Auto-renew CANNOT be set through this API' : '✓ Auto-renew works' ?>
              </div>
              <div style="font-size:12.5px;color:<?= $bad ? '#7f1d1d' : '#374151' ?>;margin-top:5px"><?= $ar['note'] ?></div>
            </div>
          <?php endif; ?>

          <?php if (!empty($def['buy']) && empty($def['buy_wired'])): ?>
            <div class="ic-note" style="background:#fffbeb;border-color:#fde047;color:#854d0e">
              <strong>Auto-buy: API-capable, adapter not written yet.</strong> You can assign and schedule domains to <?= ih($def['label']) ?> now — the plan is valid — but the purchase itself lands with the buying step. Only NameSilo can complete a purchase today.
            </div>
          <?php endif; ?>

          <?php if ($external): ?>
            <div class="ic-note">
              <strong>Credentials come from <?= ih($def['creds_from']['label']) ?>, not this page.</strong>
              It reuses <?= ih($def['creds_from']['why']) ?> — stored in <code><?= ih($def['creds_from']['file']) ?></code>.
              Storing the same token twice would only create two things to rotate and one to forget.
              <?= $has ? '' : ' <strong>No Cloudflare account is configured yet</strong>, so there is nothing to test.' ?>
            </div>
          <?php endif; ?>

          <?php if ($t): ?>
            <div class="ic-note" style="<?= $t['ok']
                ? 'background:#ecfdf5;border-color:#86efac;color:#166534'
                : 'background:#fef2f2;border-color:#fca5a5;color:#991b1b' ?>">
              <strong><?= $t['ok'] ? '✓ ' : '✗ ' ?><?= ih($t['message']) ?></strong>
              <?php if ($t['ok']): ?>
                <?php if ($t['balance'] !== null && $t['balance'] !== ''): ?>
                  &nbsp;·&nbsp; balance <strong><?= ih($t['balance']) ?> <?= ih($t['currency']) ?></strong>
                  <?php if (is_numeric($t['balance']) && (float) $t['balance'] < 15): ?>
                    <span class="badge b-warn">too low to buy a .com</span>
                  <?php endif; ?>
                <?php else: ?>
                  &nbsp;·&nbsp; <span style="opacity:.75">no balance endpoint — check funds in the dashboard</span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <form method="post" action="actions/registrar_save.php">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="type" value="<?= ih($type) ?>">
            <table>
              <?php foreach ($def['fields'] as $fname => $f):
                $cur = (string) ($cfg[$fname] ?? ($f['default'] ?? ''));
                $isSecret = !empty($f['secret']);
                $hasVal   = $cur !== '';
              ?>
                <tr>
                  <th style="width:200px"><?= ih($f['label']) ?></th>
                  <td>
                    <?php if ($isSecret): ?>
                      <input name="f[<?= ih($fname) ?>]" type="password" autocomplete="new-password"
                             placeholder="<?= $hasVal ? 'saved — leave blank to keep' : 'not set' ?>"
                             style="width:380px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                      <?php if ($hasVal): ?><span class="badge b-ok">saved (<?= strlen($cur) ?> chars)</span><?php endif; ?>
                    <?php else: ?>
                      <input name="f[<?= ih($fname) ?>]" value="<?= ih($cur) ?>"
                             style="width:380px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
            <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
              <?php if (!$external): ?>
                <button class="btn" type="submit" name="action" value="save">Save credentials</button>
              <?php endif; ?>
              <button class="btn <?= $external ? '' : 'sec' ?>" type="submit" name="action" value="test" <?= $has ? '' : 'disabled' ?>>Test connection<?= !empty($def['balance']) ? ' &amp; balance' : '' ?></button>
              <?php if ($has && !$external): ?>
                <button class="btn sec" type="submit" name="action" value="delete" style="color:#991b1b;margin-left:auto"
                        onclick="return confirm('Remove <?= ih($def['label']) ?> credentials? Domains assigned to it keep the assignment but cannot be bought until it is reconfigured.');">Remove</button>
              <?php endif; ?>
              <?php if ($external): ?>
                <a class="btn sec" href="index.php?view=cloudflare" style="margin-left:auto">Cloudflare accounts &rarr;</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="ic-card"><h2>Test all configured</h2><div class="body">
      <form method="post" action="actions/registrar_save.php">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <button class="btn" type="submit" name="action" value="test_all">&#8635; Test every configured registrar</button>
        <span style="color:#6b7280;font-size:12px;margin-left:8px">Read-only. Also refreshes the owned-domain list used to tell &ldquo;taken&rdquo; from &ldquo;you already own it&rdquo;.</span>
      </form>
    </div></div>
    <?php infra_footer(); exit;
}

/* ============================= STUB VIEWS ============================= */
/* ============================ CLOUDFLARE ============================
   What is actually in Cloudflare, per account: the zones, whether their
   nameservers have been switched, and where that disagrees with fleet state. */
if ($view === 'cloudflare') {

    /** Add and edit are the same fields, so the same function renders both. */
    function infra_cf_form(?array $a): void {
        $isEdit = $a !== null;
        $v = fn(string $k, string $d = '') => ih((string) ($a[$k] ?? $d));
        ?>
        <form method="post" action="actions/cf_save.php">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $v('id') ?>"><?php endif; ?>
            <table>
                <tbody>
                <tr>
                    <td style="width:250px"><strong>Name</strong><br>
                        <span style="color:#6b7280;font-size:12px">Anything that helps you tell accounts apart</span></td>
                    <td><input name="label" value="<?= $v('label') ?>" placeholder="e.g. CF #2" style="width:100%;max-width:420px" required></td>
                </tr>
                <tr>
                    <td><strong>Account ID</strong><br>
                        <span style="color:#6b7280;font-size:12px">Cloudflare dashboard &rarr; any domain &rarr; right-hand sidebar</span></td>
                    <td><input name="account_id" value="<?= $v('account_id') ?>" style="width:100%;max-width:420px" required></td>
                </tr>
                <tr>
                    <td><strong>API token</strong><br>
                        <span style="color:#6b7280;font-size:12px">My Profile &rarr; API Tokens &rarr; Create Token</span></td>
                    <td><input name="api_token" type="password" autocomplete="new-password"
                               placeholder="<?= $isEdit ? 'leave blank to keep the current token' : 'paste the token' ?>"
                               style="width:100%;max-width:420px" <?= $isEdit ? '' : 'required' ?>>
                        <?php if ($isEdit): ?><br><span style="color:#9ca3af;font-size:12px">A token is stored. It is never shown here.</span><?php endif; ?></td>
                </tr>
                </tbody>
            </table>
            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Add this account' ?></button>
                <button class="btn sec" type="submit" name="action" value="test">Test without saving</button>
                <?php if ($isEdit): ?><a class="btn sec" href="index.php?view=cloudflare">Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php
    }

    $editId   = (string) ($_GET['edit'] ?? '');
    $accounts = infra_cf_accounts();

    // Looking inside the zones is on demand: one API call each, so 400 zones is 400
    // calls. Same rule as checking whether websites answer.
    $scanId = (string) ($_GET['scan'] ?? '');
    if ($scanId !== '') {
        $n = 0;
        foreach ($accounts as $a) {
            if (($a['id'] ?? '') !== $scanId) continue;
            foreach (infra_discover_cf_zones($a) as $z) {
                if (!empty($z['id'])) { infra_zone_contents_run($a, $z['id']); $n++; }
            }
        }
        infra_set_flash('ok', 'Looked inside ' . $n . ' zone' . ($n === 1 ? '' : 's') . '.');
        header('Location: index.php?view=cloudflare'); exit;
    }

    // What fleet state believes, so the page can show where the two disagree.
    $recorded = [];   // domain(lower) => true when a zone id is already on the record
    $known    = [];   // domain(lower) => status, for domains we track at all
    try {
        foreach (infra_state_db()->query("SELECT lower(domain) d, status, COALESCE(cf_zone_id,'') z FROM domains") as $row) {
            $known[$row['d']] = $row['status'];
            if ($row['z'] !== '') $recorded[$row['d']] = true;
        }
    } catch (Throwable $e) { /* fleet state unreadable — the zone list still stands alone */ }

    infra_header('cloudflare');
    ?>
    <div style="margin-bottom:16px">
        <a class="btn" href="index.php?view=cloudflare&amp;refresh=1">&#8635; Refresh</a>
    </div>

    <?php foreach ($accounts as $a):
        $probe    = cf_probe($a);
        $ok       = !empty($probe['ok']);
        $zones    = $ok ? infra_discover_cf_zones($a) : [];
        $pending  = array_values(array_filter($zones, fn($z) => ($z['status'] ?? '') !== 'active'));
        $unlinked = array_values(array_filter($zones, fn($z) => !isset($recorded[strtolower($z['name'] ?? '')])));
        $strays   = array_values(array_filter($zones, fn($z) => !isset($known[strtolower($z['name'] ?? '')])));
        $usesGlobalKey = !empty($a['email']) && !empty($a['global_key']);
    ?>
    <div class="ic-card">
        <h2><?= ih($a['label'] ?? $a['id'] ?? 'Cloudflare account') ?>
            <?= $ok ? '<span class="badge b-ok">connected</span>' : '<span class="badge b-err">credentials rejected</span>' ?>
        </h2>
        <div class="body">

            <?php if (!$ok): ?>
            <div class="ic-note" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b">
                <strong>Cloudflare would not accept these credentials.</strong><br>
                <?= ih($probe['error'] ?? $probe['message'] ?? 'no reply') ?>
            </div>
            <?php endif; ?>

            <table style="margin-bottom:18px">
                <tbody>
                    <tr><td style="width:250px;color:#6b7280">Cloudflare account ID</td>
                        <td><code><?= ih($a['account_id'] ?? '—') ?></code></td></tr>
                    <tr><td style="color:#6b7280">How the console signs in</td>
                        <td><?= $usesGlobalKey
                            ? 'Global API key <span style="color:#92400e">— full access to the whole account; a scoped token is also stored and would be used if this were removed</span>'
                            : 'Scoped API token' ?></td></tr>
                    <tr><td style="color:#6b7280">Domains in this account</td>
                        <td><strong><?= count($zones) ?></strong>
                            <?php if ($zones): ?>
                            <span style="color:#6b7280">— <?= count($zones) - count($pending) ?> with nameservers set, <?= count($pending) ?> waiting</span>
                            <?php endif; ?>
                            <div style="color:#9ca3af;font-size:12px">Nameservers set means Cloudflare is answering for the domain. It does not mean a website is behind it.</div></td></tr>
                </tbody>
            </table>

            <?php if ($pending || $unlinked || $strays): ?>
            <div style="background:#fffbeb;border:1px solid #fde047;border-radius:8px;padding:12px 14px;margin-bottom:18px">
                <strong style="color:#854d0e">Worth a look</strong>
                <ul style="margin:8px 0 0 18px;color:#854d0e;line-height:1.8">
                    <?php if ($pending): ?>
                    <li><strong><?= count($pending) ?></strong> domain(s) set up here but still pointing at their old nameservers — they are not live yet. Go-Live switches them.</li>
                    <?php endif; ?>
                    <?php if ($unlinked): ?>
                    <li><strong><?= count($unlinked) ?></strong> domain(s) have a zone here that the console has not recorded. Worth knowing that a domain bought <em>at</em> Cloudflare gets a zone automatically, pointing at Cloudflare's own nameservers — so an unrecorded zone usually means "bought here", not "already provisioned". The zone is likely empty until a build fills it in.</li>
                    <?php endif; ?>
                    <?php if ($strays): ?>
                    <li><strong><?= count($strays) ?></strong> domain(s) in this account that the console has never heard of at all.</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php
            // Nameserver spread. One pair carrying everything is a public link between
            // every domain in the account, which undoes spreading them across registrars.
            $nsPairs = infra_ns_pairs($zones);
            if ($nsPairs):
                $biggest = max($nsPairs);
                $leaky   = count($nsPairs) === 1 && $biggest > 1;
            ?>
            <div style="border:1px solid <?= $leaky ? '#fde047' : '#e5e7eb' ?>;background:<?= $leaky ? '#fffbeb' : '#f9fafb' ?>;border-radius:8px;padding:12px 14px;margin-bottom:18px">
                <strong style="color:<?= $leaky ? '#854d0e' : '#374151' ?>">Nameservers these domains hand out</strong>
                <table style="margin-top:8px">
                    <thead><tr>
                        <th style="width:420px">Nameserver pair</th>
                        <th>Domains handing it out</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($nsPairs as $pair => $cnt): ?>
                        <tr>
                            <td><code style="font-size:12px"><?= ih($pair) ?></code></td>
                            <td><strong><?= $cnt ?></strong> domain<?= $cnt === 1 ? '' : 's' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($leaky): ?>
                <div style="color:#854d0e;margin-top:8px;font-size:13px">
                    &#9888; Every domain in this account hands out the same two nameservers. Anyone can look up
                    one domain's nameservers and find all the others. Spreading domains across registrars hides
                    who owns them; this puts it back. Cloudflare gives a pair per account, so more accounts
                    means more pairs &mdash; provisioning already shares new domains out between whatever
                    accounts are listed here.
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="display:flex;align-items:center;gap:12px;margin:0 0 8px">
                <h2 style="font-size:15px;margin:0">Domains in this account (<?= count($zones) ?>)</h2>
                <?php if ($zones): ?>
                <a class="btn sec" style="padding:3px 10px;font-size:12px"
                   href="index.php?view=cloudflare&amp;scan=<?= ih($a['id'] ?? '') ?>">Look inside these zones</a>
                <?php endif; ?>
            </div>
            <?php if (!$zones): ?>
                <div class="ic-empty"><?= $ok ? 'No domains in this Cloudflare account yet.' : 'Cannot list domains until the credentials work.' ?></div>
            <?php else: ?>
                <!-- The list scrolls inside the card, so the headings stick to the top of it —
                     otherwise you scroll past row 12 and no longer know which column is which. -->
                <div style="max-height:520px;overflow:auto">
                <table class="ic-sticky">
                    <thead><tr><th>Domain</th><th>Cloudflare status</th><th>What's in the zone</th><th>Points at</th><th>Since</th><th>In your records?</th></tr></thead>
                    <tbody>
                    <?php foreach ($zones as $z):
                        $nm   = strtolower($z['name'] ?? '');
                        $live = ($z['status'] ?? '') === 'active';
                        $dns  = !empty($z['id']) ? infra_zone_contents_cached($z['id']) : null;
                    ?>
                        <tr>
                            <td><strong><?= ih($z['name'] ?? '?') ?></strong></td>
                            <td style="color:<?= $live ? '#166534' : '#92400e' ?>">
                                <strong><?= $live ? 'nameservers set' : 'waiting on nameservers' ?></strong></td>
                            <td style="font-size:13px">
                                <?php if ($dns === null): ?>
                                    <span style="color:#9ca3af">not looked at yet</span>
                                <?php elseif ($dns['n'] === 0): ?>
                                    <span style="color:#92400e"><strong>empty</strong> &mdash; nothing to serve</span>
                                <?php else: ?>
                                    <span style="color:#166534"><?= (int) $dns['n'] ?> record<?= $dns['n'] === 1 ? '' : 's' ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#6b7280">
                                <?php if ($dns === null || empty($dns['a'])): ?>&mdash;
                                <?php else: foreach (array_slice($dns['a'], 0, 2) as $rec): ?>
                                    <div><code><?= ih($rec['ip']) ?></code><?= $rec['proxied'] ? ' <span style="color:#f59e0b">proxied</span>' : '' ?></div>
                                <?php endforeach; endif; ?>
                            </td>
                            <td style="font-size:12px;color:#6b7280">
                                <?= ih(substr((string) ($z['activated_on'] ?? $z['created_on'] ?? ''), 0, 10) ?: '—') ?></td>
                            <td style="font-size:12px">
                                <?php if (isset($recorded[$nm])): ?><span style="color:#166534">yes</span>
                                <?php elseif (isset($known[$nm])): ?><span style="color:#92400e">not written down</span>
                                <?php else: ?><span style="color:#991b1b">unknown domain</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>

            <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <a class="btn sec" href="index.php?view=cloudflare&amp;edit=<?= ih($a['id'] ?? '') ?>#cf-<?= ih($a['id'] ?? '') ?>">Edit these settings</a>
                <form method="post" action="actions/cf_save.php" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= ih($a['id'] ?? '') ?>">
                    <button class="btn" style="background:#991b1b" type="submit"
                            onclick="return confirm('Remove &quot;<?= ih($a['label'] ?? $a['id']) ?>&quot; from this console?\n\nYour Cloudflare account is NOT touched — the zones stay, DNS keeps working, the websites stay up. It just stops appearing here.')">
                        Remove from console
                    </button>
                </form>
            </div>

            <?php if ($editId === ($a['id'] ?? '')): ?>
            <div id="cf-<?= ih($a['id'] ?? '') ?>" style="margin-top:18px;border-top:1px solid #e5e7eb;padding-top:16px">
                <h2 style="font-size:15px;margin:0 0 10px">Edit this account</h2>
                <?php infra_cf_form($a); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="ic-card" id="add-cf">
        <h2>Add a Cloudflare account</h2>
        <div class="body">
            <div class="ic-note">Cloudflare limits how many domains one account can hold, so a large fleet is normally spread over several. Each one you add here is listed separately above.</div>
            <?php infra_cf_form(null); ?>
        </div>
    </div>
    <?php
    infra_footer(); exit;
}

/* ============================== SERVERS ==============================
   Every origin box in the fleet, on HestiaCP. Plesk was removed on
   2026-08-15 once the panel decision settled: it ran on nothing, its one
   registry entry pointed at a machine that had been rebuilt under Hestia,
   and probing that dead endpoint cost a 12-second timeout on every load of
   this page. The Plesk client (lib/plesk.php) is deliberately left in place
   — lib/provision.php still calls it — so this removes the window, not the
   machinery behind it. */
if ($view === 'servers') {

    require_once __DIR__ . '/lib/hestia_fleet.php';
    infra_header('servers');
    ?>
    <div style="margin-bottom:16px">
        <a class="btn" href="index.php?view=servers&amp;refresh=1">&#8635; Refresh</a>
    </div>

    <!-- How a site gets onto a box. Written out because the order is not
         obvious, two of the steps fail silently, and the consequence of getting
         step 5 early is a domain Google crawls while it is empty. -->
    <details class="ic-card" open>
        <summary style="padding:14px 16px;font-size:15px;font-weight:600;cursor:pointer">
            How a batch goes live
            <span style="color:#9ca3af;font-weight:400;font-size:13px">— five phases, in this order</span>
        </summary>
        <div class="body" style="font-size:13.5px;line-height:1.65">

            <p style="margin:0 0 16px;color:#6b7280">
                Each phase finishes for the <em>whole batch</em> before the next begins, and every phase is
                safe to run twice. The order exists so failures land where they are cheap: phase 2 costs
                seconds, phase 4 costs an hour, and phase 5 is the only one the outside world can see.
            </p>

            <h3 style="font-size:14px;margin:0 0 4px">1. Define batch scope</h3>
            <ul style="margin:0 0 16px 18px;padding:0">
                <li>Decide which domains, which niche, and which box they land on.</li>
                <li><strong>Only owned domains.</strong> A domain that is planned, queued or merely
                    available is not a batch member &mdash; ownership is a receipt, not an intention.</li>
                <li>Pick the box on headroom and spread the work. Stacking one box concentrates the
                    blast radius that running several providers was meant to avoid.</li>
                <li>Nothing here touches a server. This phase produces a list, and a list is free to
                    throw away.</li>
            </ul>

            <h3 style="font-size:14px;margin:0 0 4px">2. Create all host folders &amp; verify</h3>
            <ul style="margin:0 0 16px 18px;padding:0">
                <li>For every domain: the nginx rule (<em>&ldquo;this domain &rarr; this folder&rdquo;</em>), the
                    folder itself, and an FTP login locked to that folder alone.</li>
                <li>All sites are filed under <strong>one account per box</strong>. A Hestia account is a
                    Linux user &mdash; one each would mean 500 home directories and a listing costing 501
                    API calls instead of 2.</li>
                <li><strong>Restart the web server once, at the end</strong> &mdash; not once per site.
                    nginx does not notice new rules on its own, and until it restarts it serves
                    Hestia&rsquo;s default page with a <code>200 OK</code>. Nothing says <em>error</em>:
                    Hestia confirms the folder and uploads land correctly while the wrong page is served.
                    <code>v-rebuild-web-domains</code> does not fix it; only a restart does.</li>
                <li><strong>Then verify, because this is the cheap phase.</strong> Three checks:
                    every vhost is listed (one call for the batch); each FTP login actually connects and
                    writes (<em>exit code 0 is not evidence</em>); and one domain fetched by
                    <code>Host</code> header returns its own folder rather than the default page.</li>
                <li>Seconds of work. Finding a broken box here costs nothing; finding it in phase 4 costs
                    an hour and leaves half a batch uploaded.</li>
            </ul>

            <h3 style="font-size:14px;margin:0 0 4px">3. Generate all sites</h3>
            <ul style="margin:0 0 16px 18px;padding:0">
                <li>Build every site&rsquo;s static HTML locally on the factory. Nothing goes over the wire
                    in this phase.</li>
                <li><strong>A build failure here costs nothing.</strong> That is the entire reason it is
                    separate from uploading &mdash; a batch that dies on site 37 should not leave 36 sites
                    live and 14 missing.</li>
                <li>Read the output before it leaves the building: no unresolved <code>{tokens}</code>,
                    no placeholder phone numbers, no invented statistics.</li>
            </ul>

            <h3 style="font-size:14px;margin:0 0 4px">4. Upload all sites</h3>
            <ul style="margin:0 0 16px 18px;padding:0">
                <li>Each site goes up through its own FTP login into its own folder, so a leaked
                    credential stops at one site.</li>
                <li>The slow phase, and the one worth making re-runnable: a second run should skip what
                    is already there rather than send it again.</li>
                <li><strong>Confirm the folders are no longer empty.</strong> A vhost whose folder still
                    holds only the two placeholder files is a site that was created and never filled &mdash;
                    the failure that is invisible until someone visits.</li>
            </ul>

            <h3 style="font-size:14px;margin:0 0 4px">5. Go live (point DNS)</h3>
            <ul style="margin:0 0 6px 18px;padding:0">
                <li>Only now point DNS at the box &mdash; proxied through Cloudflare, cache everything,
                    SSL Full (strict) against an Origin CA certificate.</li>
                <li><strong>Never let a rank-and-rent domain resolve while it is empty.</strong> DNS first
                    means a window where Google crawls the domain and finds a blank page, and a first
                    impression of &ldquo;empty site&rdquo; is expensive to undo.</li>
                <li>Everything before this point is invisible from outside. The batch can sit finished
                    but unpointed for as long as you like &mdash; which is what makes the earlier phases
                    safe to get wrong.</li>
                <li>Then check each site answers on its real hostname <em>and</em> that the certificate
                    matches. Serving fine behind a bad certificate is a browser warning for every visitor.</li>
            </ul>
        </div>
    </details>
    <?php
    /** The add form and the edit form are the same fields, so they are the same
     *  function. $srv = null renders "add"; an existing server renders "edit"
     *  with its values filled in — except the key pair, which is never printed
     *  back into the page. Blank on an edit keeps the stored one. */
    function infra_hestia_form(?array $srv): void {
        $isEdit = $srv !== null;
        $v = fn(string $k, string $d = '') => ih((string) ($srv[$k] ?? $d));
        ?>
        <form method="post" action="actions/hestia_save.php">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $v('id') ?>"><?php endif; ?>

            <table>
                <tbody>
                <tr>
                    <td style="width:250px"><label><strong>Name</strong><br>
                        <span style="color:#6b7280;font-size:12px">Anything that helps you tell it apart</span></label></td>
                    <td><input name="label" value="<?= $v('label') ?>"
                               placeholder="e.g. Hestia trial box" style="width:100%;max-width:420px" required></td>
                </tr>
                <tr>
                    <td><label><strong>Address you log in to Hestia at</strong><br>
                        <span style="color:#6b7280;font-size:12px">IP or hostname &mdash; no https://</span></label></td>
                    <td><input name="host" value="<?= $v('host') ?>" placeholder="e.g. 5.9.12.34"
                               style="width:100%;max-width:420px" required></td>
                </tr>
                <tr>
                    <td><label><strong>Port</strong><br>
                        <span style="color:#6b7280;font-size:12px">Hestia uses 8083 unless you changed it</span></label></td>
                    <td><input name="port" type="number" value="<?= $v('port', '8083') ?>" style="width:120px"></td>
                </tr>
                <tr>
                    <td><label><strong>Access key</strong><br>
                        <span style="color:#6b7280;font-size:12px">Hestia &rarr; Server Settings &rarr; Configure &rarr; Security &rarr; Access Keys.
                        Leave both key boxes empty if Hestia is not on this machine yet.</span></label></td>
                    <td><input name="access_key" type="password" autocomplete="new-password"
                               placeholder="<?= $isEdit ? 'leave blank to keep the stored pair' : 'paste the access key' ?>"
                               style="width:100%;max-width:420px"></td>
                </tr>
                <tr>
                    <td><label><strong>Secret key</strong><br>
                        <span style="color:#6b7280;font-size:12px">Shown once, when the access key is created</span></label></td>
                    <td><input name="secret_key" type="password" autocomplete="new-password"
                               placeholder="<?= $isEdit ? 'leave blank to keep the stored pair' : 'paste the secret key' ?>"
                               style="width:100%;max-width:420px">
                        <?php if ($isEdit): ?><br><span style="color:#9ca3af;font-size:12px">A key pair is stored. It is never shown here.</span><?php endif; ?></td>
                </tr>
                <tr>
                    <td><label><strong>Account the websites are filed under</strong><br>
                        <span style="color:#6b7280;font-size:12px">One account holds every site on this box. Created on first use.</span></label></td>
                    <td><input name="site_user" value="<?= $v('site_user', 'fleet') ?>" style="width:200px"></td>
                </tr>
                <tr>
                    <td><label><strong>IP address the websites answer on</strong><br>
                        <span style="color:#6b7280;font-size:12px">Cloudflare points at this. Blank = same as above</span></label></td>
                    <td><input name="default_ip" value="<?= $v('default_ip') ?>" placeholder="usually the same address"
                               style="width:100%;max-width:420px"></td>
                </tr>
                <tr>
                    <td><label style="color:#6b7280">Contact email and package
                        <span style="font-size:12px">(used when the account is created)</span></label></td>
                    <td><input name="contact_email" value="<?= $v('contact_email') ?>" placeholder="you@example.com" style="width:260px">
                        <input name="package" value="<?= $v('package', 'default') ?>" placeholder="default" style="width:140px"></td>
                </tr>
                <tr>
                    <td><label style="color:#6b7280">What this machine is
                        <span style="font-size:12px">(optional &mdash; host, plan, size, price, where it is)</span></label></td>
                    <td><input name="notes" value="<?= $v('notes') ?>"
                               placeholder="e.g. Hetzner CX23 · 2 vCPU · 4 GB · 40 GB · Helsinki · €6.49/mo"
                               style="width:100%;max-width:420px"></td>
                </tr>
                </tbody>
            </table>

            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Add this server' ?></button>
                <button class="btn sec" type="submit" name="action" value="test">Test without saving</button>
                <?php if ($isEdit): ?><a class="btn sec" href="index.php?view=servers#hestia">Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php
    }

    $hEditId  = (string) ($_GET['hedit'] ?? '');
    $hServers = infra_hestia_servers();

    // Deliberately on demand: one outbound request per site, so a box with 40 on it
    // should ask when you press the button, not on every page load.
    // Read what is actually in each host area's folder. On demand: one API call per
    // host area, so it is asked for, not paid for on every page load.
    $contentId = (string) ($_GET['content'] ?? '');
    if ($contentId !== '') {
        foreach (infra_hestia_servers() as $srv) {
            if (($srv['id'] ?? '') !== $contentId) continue;
            $r = infra_hestia_content_run($srv);
            infra_set_flash($r['empty'] > 0 ? 'warn' : 'ok',
                $r['checked'] === 0 ? 'No host areas on that server to check.'
                : ($r['with_files'] . ' of ' . $r['checked'] . ' host area(s) contain a site'
                   . ($r['empty'] > 0 ? ' — ' . $r['empty'] . ' still hold only the placeholder, so nothing has been uploaded into them.' : '.')));
        }
        header('Location: index.php?view=servers#hestia'); exit;
    }

    $hCheckId = (string) ($_GET['hcheck'] ?? '');
    if ($hCheckId !== '') {
        $n = 0;
        foreach ($hServers as $srv) {
            if (($srv['id'] ?? '') !== $hCheckId) continue;
            foreach (infra_discover_hestia($srv)['sites'] as $s) {
                if (($s['name'] ?? '') !== '') { infra_site_check_run($s['name']); $n++; }
            }
        }
        infra_set_flash('ok', 'Checked ' . $n . ' website' . ($n === 1 ? '' : 's') . ' on the Hestia box.');
        header('Location: index.php?view=servers#hestia'); exit;
    }

    // One shared reader (infra_hestia_fleet) rather than this page's own loop —
    // it is the same discovery, and the derived numbers stay consistent with the
    // dashboard and the batch page by construction.
    $hRows = [];
    foreach (infra_hestia_fleet() as $b) $hRows[] = ['srv' => $b['server'], 'd' => infra_discover_hestia($b['server']), 'b' => $b];
    ?>

    <!-- id="hestia" is kept: links elsewhere on this page still target it. -->
    <div id="hestia"></div>

    <?php if (empty($hServers)): ?>
        <div class="ic-empty">No HestiaCP server registered yet. Add one below.</div>
    <?php else: ?>

    <?php foreach ($hRows as $r): $srv = $r['srv']; $d = $r['d']; $b = $r['b'];
          $f = ['panel_version' => $b['version'], 'platform' => $b['platform'], 'hostname' => $b['hostname']];
          $unset = $b['pending'];
          $acct  = $b['accounts'];
          // Collapsed once it is healthy — a working box is a one-line fact and
          // eight of them should fit on a screen. Anything wrong opens itself,
          // because a problem nobody clicks on is a problem nobody sees.
          $openIt = !$d['ok'] || $hEditId === ($srv['id'] ?? ''); ?>
    <details class="ic-card srv-card" <?= $openIt ? 'open' : '' ?>>
        <summary style="cursor:pointer">
        <h2 style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="color:#9ca3af;font-weight:400">▸</span>
            <?= ih($srv['label'] ?? $srv['id']) ?>
            <span class="badge" style="background:#dbeafe;color:#1e40af">HestiaCP</span>
            <?= $d['ok'] ? '<span class="badge b-ok">up</span>'
                 : ($unset ? '<span class="badge" style="background:#fef3c7;color:#92400e">not set up yet</span>'
                           : '<span class="badge b-err">cannot reach it</span>') ?>
            <span style="font-weight:400;font-size:13px;color:#6b7280">
                <code><?= ih($srv['host'] ?? '') ?></code>
                <?php if ($d['ok']): ?>
                    &middot; Hestia <?= ih($f['panel_version'] ?: '?') ?>
                    &middot; <?= ih($f['platform'] ?: '?') ?>
                <?php endif; ?>
            </span>
        </h2>

        <?php if ($d['ok']): ?>
        <!-- The four numbers worth seeing without opening anything. Sites first:
             it is the one that answers "is this box doing any work". -->
        <div style="display:flex;gap:26px;flex-wrap:wrap;padding:2px 16px 14px 30px">
            <?php
            // Files first: a host area whose folder is empty is the failure that stays
            // invisible until someone visits, so it reads before the count of hosts.
            // '?' until asked — the check costs one API call per host area.
            $content = infra_hestia_content_cached($srv);
            $tiles = [
                ['Files', $content === null ? '?' : (int) $content['with_files'],
                 $content === null ? '#9ca3af' : ((int) $content['empty'] > 0 ? '#92400e' : '#166534')],
                // DEPLOYED sites, not vhosts: the panel's own hostname vhost is an
                // artefact of installing Hestia, and counting it made every empty
                // box report one website. The full vhost list is still below.
                // "Hosts", not "Websites": this counts the host areas created on the
                // box — the vhost + folder + FTP login that step 3 makes. Whether a
                // site has been uploaded into one is a different question, and calling
                // it Websites implied this number answered it.
                ['Hosts',               $b['deployed'],          '#111827'],
                ['Accounts',            $acct['total'],          '#111827'],
                ['&hellip; with a site', $acct['with_site'],     '#166534'],
                ['&hellip; with none',   $acct['without_site'],  $acct['without_site'] > 0 ? '#92400e' : '#9ca3af'],
            ];
            foreach ($tiles as [$label, $val, $col]): ?>
                <div>
                    <div style="font-size:20px;font-weight:700;color:<?= $col ?>;line-height:1.1"><?= is_int($val) ? $val : ih((string) $val) ?></div>
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em"><?= $label ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$acct['exact']): ?>
            <div style="font-size:11px;color:#9ca3af;max-width:260px;align-self:center">
                Accounts counts what the console can see. <code>v-list-users</code> returns only the
                key's own account, so an unrelated empty account would not appear.
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </summary>
        <div class="body">

            <?php if ($unset): ?>
            <div class="ic-note" style="background:#fffbeb;border-color:#fcd34d;color:#92400e">
                <strong>The machine is recorded here, but there is no Hestia on it yet.</strong>
                Nothing has been contacted — it has no key pair, so there is nothing to authenticate with.
                Three things left, in order:
                <ol style="margin:8px 0 0 18px;padding:0">
                    <li>Install Hestia on it (the command is in "Add a HestiaCP server" at the bottom of this page).</li>
                    <li>On the box: <code>v-add-sys-api</code>, then put <code>187.127.254.206</code> in <code>API_ALLOWED_IP</code>.</li>
                    <li><code>v-add-access-key user</code> with no permissions argument, then paste the pair into
                        "Edit these settings" below.</li>
                </ol>
            </div>
            <?php elseif (!$d['ok']): ?>
            <div class="ic-note" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b">
                <strong>The console could not talk to this server.</strong><br><?= ih($d['error']) ?>
                <?php if (($d['error'] ?? '') !== '' && stripos($d['error'], 'allowed') !== false): ?>
                <br><br>Hestia answers HTTP 200 with the body <code>Error</code> when the API is switched
                off or the caller's IP is not allowed — it looks like nothing is wrong. On the box:
                <code>v-add-sys-api</code>, then add this server's IP to <code>API_ALLOWED_IP</code>.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <table style="margin-bottom:18px">
                <tbody>
                    <tr><td style="width:230px;color:#6b7280">Address you'd log in at</td>
                        <td><code>https://<?= ih($srv['host'] ?? '') ?>:<?= ih((string)($srv['port'] ?? '')) ?></code></td></tr>
                    <tr><td style="color:#6b7280">IP address the sites point at</td>
                        <td><code><?= ih($srv['default_ip'] ?? '—') ?></code></td></tr>
                    <?php if (trim((string) ($srv['notes'] ?? '')) !== ''): ?>
                    <tr><td style="color:#6b7280">What this machine is</td>
                        <td><?= ih($srv['notes']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td style="color:#6b7280">Hestia version</td>
                        <td><?= $unset ? '<span style="color:#92400e">not installed yet</span>' : ih($f['panel_version'] ?: '—') ?></td></tr>
                    <tr><td style="color:#6b7280">Server's own hostname</td>
                        <td><code><?= ih($f['hostname'] ?: '—') ?></code></td></tr>
                    <tr><td style="color:#6b7280">Operating system</td>
                        <td><?= ih($f['platform'] ?: '—') ?></td></tr>
                    <tr><td style="color:#6b7280">Host areas on it</td>
                        <td><strong><?= count($d['sites']) ?></strong></td></tr>
                    <tr><td style="color:#6b7280">Account they're filed under</td>
                        <td><code><?= ih($srv['site_user'] ?? 'fleet') ?></code>
                            <span style="color:#9ca3af">— <?= count($d['users']) ?> account<?= count($d['users']) === 1 ? '' : 's' ?> on the box</span></td></tr>

                    <!-- Hestia has no "list every domain" call — domains hang off
                         accounts, so this is 1 + one call per account. Filing every site
                         under a single account is what keeps it at two. -->
                    <tr><td style="color:#6b7280"><strong>Cost to read this server</strong></td>
                        <td>
                        <?php if ($unset): ?>
                            <span style="color:#9ca3af">not measured — nothing has been read from it</span>
                            <div style="font-size:12px;color:#6b7280">A box with no key pair costs nothing to
                                "read" because no request is sent. Zero here would otherwise look like a
                                very fast server rather than an absent one.</div>
                        <?php else: ?>
                            <strong><?= (int) $d['calls'] ?></strong> API call<?= (int) $d['calls'] === 1 ? '' : 's' ?>,
                            <strong><?= (int) $d['ms'] ?>ms</strong>
                            <div style="font-size:12px;color:#6b7280">
                                measured <?= ih(date('j M H:i', strtotime($d['at'] ?? 'now'))) ?>, then cached for
                                <?= (int) INFRA_HESTIA_TTL ?>s. Hestia has no "list every domain" call — domains hang
                                off accounts, so this is 1 + one call per account. Filing every site under a single
                                account is what keeps that at two instead of one-per-site.
                            </div>
                        <?php endif; ?>
                        </td></tr>
                </tbody>
            </table>

            <div style="display:flex;align-items:center;gap:12px;margin:0 0 8px">
                <h2 style="font-size:15px;margin:0">Websites on this server (<?= count($d['sites']) ?>)</h2>
                <?php if ($d['sites']): ?>
                <a class="btn sec" style="padding:3px 10px;font-size:12px"
                   href="index.php?view=servers&amp;hcheck=<?= ih($srv['id'] ?? '') ?>">Check if they're up</a>
                <a class="btn sec" style="padding:3px 10px;font-size:12px"
                   href="index.php?view=servers&amp;content=<?= ih($srv['id'] ?? '') ?>">Check for files</a>
                <?php endif; ?>
            </div>
            <?php if (!$d['sites']): ?>
                <div class="ic-empty">Nothing on this server yet.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Website</th><th>Is it up?</th><th>Account</th><th>SSL</th><th>Folder its files live in</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['sites'] as $s):
                        $name = (string) ($s['name'] ?? '');
                        $chk  = $name !== '' ? infra_site_check_cached($name) : null;
                        // Green only when it answers AND the certificate matches. Serving
                        // fine behind a bad cert is a browser warning for every visitor and
                        // must not read as healthy.
                        $col  = $chk === null ? '#9ca3af' : ((!empty($chk['up']) && !empty($chk['cert_ok'])) ? '#166534' : (!empty($chk['up']) ? '#92400e' : '#991b1b'));
                    ?>
                        <tr>
                            <td><strong><?= ih($name ?: '?') ?></strong></td>
                            <td style="color:<?= $col ?>">
                                <?php if ($chk === null): ?>
                                    <span style="color:#9ca3af">not checked yet</span>
                                <?php else: ?>
                                    <strong><?= ih(infra_site_verdict($chk)) ?></strong>
                                    <div style="font-size:12px;color:#6b7280">
                                        <?= $chk['code'] ? ih((string) $chk['code']) . ' · ' : '' ?>
                                        <?= $chk['ms'] ? ih((string) $chk['ms']) . 'ms · ' : '' ?>
                                        checked <?= ih(date('j M H:i', strtotime($chk['at'] ?? 'now'))) ?>
                                    </div>
                                    <?php if (!empty($chk['error'])): ?>
                                    <div style="font-size:12px;color:#991b1b"><?= ih(substr($chk['error'], 0, 120)) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:12px"><?= ih($s['user'] ?? '—') ?></code></td>
                            <td><?= strtolower((string) ($s['ssl'] ?? '')) === 'yes'
                                    ? '<span style="color:#166534">yes</span>'
                                    : '<span style="color:#9ca3af">no</span>' ?></td>
                            <td><code style="font-size:12px"><?= ih($s['docroot'] ?? '—') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <a class="btn sec" href="index.php?view=servers&amp;refresh=1#hestia">&#8635; Re-read this server</a>
                <a class="btn sec" href="index.php?view=servers&amp;hedit=<?= ih($srv['id'] ?? '') ?>#hform-<?= ih($srv['id'] ?? '') ?>">Edit these settings</a>
                <form method="post" action="actions/hestia_save.php" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= ih($srv['id'] ?? '') ?>">
                    <button class="btn" style="background:#991b1b" type="submit"
                            onclick="return confirm('Remove &quot;<?= ih($srv['label'] ?? $srv['id']) ?>&quot; from this console?\n\nThe server itself is NOT touched — it keeps running and its websites stay up. It just stops appearing here.')">
                        Remove from console
                    </button>
                </form>
            </div>

            <?php if ($hEditId === ($srv['id'] ?? '')): ?>
            <div id="hform-<?= ih($srv['id'] ?? '') ?>" style="margin-top:18px;border-top:1px solid #e5e7eb;padding-top:16px">
                <h2 style="font-size:15px;margin:0 0 10px">Edit this server</h2>
                <?php infra_hestia_form($srv); ?>
            </div>
            <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="ic-card" id="add-hestia">
        <h2>Add a HestiaCP server</h2>
        <div class="body">
            <div class="ic-note">
                You buy the VPS and install Hestia yourself. Install it without mail, DNS or a
                database — these sites are static HTML and every service you skip is one less
                thing exposed:
                <br><br>
                <code style="display:block;white-space:pre-wrap;font-size:12px;line-height:1.7">bash hst-install.sh --interactive no --hostname panel.example.com --email you@example.com \
  --username user --password 'STRONG_PW' --apache no --phpfpm no --multiphp no --mysql no \
  --postgresql no --exim no --dovecot no --clamav no --spamassassin no --named no \
  --vsftpd yes --iptables yes --fail2ban yes --quota no --api yes</code>
                <br>
                Three things about that command, each of which cost a failed run:
                <ul style="margin:6px 0 0 18px;padding:0">
                    <li><strong>There is no <code>--nginx</code> option.</strong> Nginx is always
                        installed; <code>--apache no</code> is what makes it nginx-only. Passing
                        <code>--nginx yes</code> aborts the whole run with
                        <code>illegal option -- -</code>.</li>
                    <li><strong><code>--username user</code> is required.</strong> Hestia refuses
                        <code>admin</code> as the admin account name, and with
                        <code>--interactive no</code> it does not say so — it loops
                        "Please use a valid username" forever. This is why the account on these
                        boxes is called <code>user</code>.</li>
                    <li><strong>Do not write <code>--force yes</code>.</strong> <code>--force</code>
                        takes no value, so the stray <code>yes</code> becomes a positional argument,
                        <code>getopts</code> stops there, and <em>every flag after it is silently
                        ignored</em> — you get a default install with mail, DNS and a database on it.</li>
                </ul>
                <br>
                <code>--api yes</code> switches the API on during the install, so
                <code>v-add-sys-api</code> is only needed if you left it out. Either way you must add
                this server's IP (<code>187.127.254.206</code>) to <code>API_ALLOWED_IP</code> in
                <code>/usr/local/hestia/conf/hestia.conf</code>. Until you do, every call comes back
                as HTTP 200 with the body <code>Error</code> — which looks like nothing is wrong.
                Firewall port 8083 to that IP only.
            </div>
            <?php infra_hestia_form(null); ?>
        </div>
    </div>
    <?php
    infra_footer(); exit;
}

/* ============================= DASHBOARD ============================= */
// Headline numbers only. The servers themselves live on the Servers tab — one list
// of them, in one place, rather than a second shorter one here that drifts.
// Counts the HESTIA registry, which is the fleet. It used to count the Plesk
// one, and when Plesk came off the Servers tab that left this reading
// "1 server, 0 sites, 1 issue" while eight boxes were up — the wrongest kind of
// number, because it is confident and on the front page.
require_once __DIR__ . '/lib/hestia_fleet.php';
$servers = infra_hestia_servers();
$cfAccts = infra_cf_accounts();
$totalSites = 0; $issues = 0; $pending = 0;
foreach (infra_hestia_fleet() as $b) {
    // A box awaiting its key pair is unfinished, not broken. Counting it as an
    // issue would make every newly-bought server look like a fault.
    if ($b['pending'])   $pending++;
    elseif (!$b['ok'])   $issues++;
    // Deployed sites, so the tile does not count each box's own hostname vhost
    // and report eight sites for an empty fleet.
    $totalSites += $b['deployed'];
}
infra_header('dashboard');
?>
<?php if (empty($servers)): ?><div class="ic-note">No servers registered yet. Add one on the <a href="index.php?view=servers">Servers</a> tab.</div><?php endif; ?>
<?php if ($pending): ?><div class="ic-note"><?= (int) $pending ?> server<?= $pending === 1 ? ' is' : 's are' ?> registered but not set up yet — no Hestia on <?= $pending === 1 ? 'it' : 'them' ?> so far. <a href="index.php?view=servers">Finish <?= $pending === 1 ? 'it' : 'them' ?></a>.</div><?php endif; ?>
<div class="ic-tiles">
  <a class="ic-tile" href="index.php?view=servers" style="text-decoration:none;color:inherit">
    <div class="n"><?= count($servers) ?></div><div class="l">Servers</div></a>
  <a class="ic-tile" href="index.php?view=servers" style="text-decoration:none;color:inherit">
    <div class="n"><?= $totalSites ?></div><div class="l">Sites (live)</div></a>
  <a class="ic-tile" href="index.php?view=cloudflare" style="text-decoration:none;color:inherit">
    <div class="n"><?= count($cfAccts) ?></div><div class="l">CF Accounts</div></a>
  <a class="ic-tile" href="index.php?view=servers" style="text-decoration:none;color:inherit<?= $issues ? ';border-color:#fca5a5' : '' ?>">
    <div class="n"<?= $issues ? ' style="color:#991b1b"' : '' ?>><?= $issues ?></div><div class="l">Issues</div></a>
</div>
<div style="margin-bottom:16px">
  <a class="btn" href="index.php?refresh=1">&#8635; Discover / Refresh</a>
  <a class="btn sec" href="index.php?view=servers">Servers &rarr;</a>
  <a class="btn sec" href="index.php?view=domains">All domains &rarr;</a>
</div>
<?php infra_footer();
