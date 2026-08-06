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
    $due   = ($r['buy_at'] ?? '') !== '' && $r['buy_at'] <= gmdate('Y-m-d');
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
    $statuses = ['staged', 'queued', 'releasing', 'awaiting-ns', 'live', 'partial', 'register-failed'];
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
          <p style="margin-top:0">Bought <?= $rec['owned_at'] ? 'on <strong>' . ih($rec['owned_at']) . '</strong> UTC' : '' ?><?= $rec['registrar'] ? ' at <strong>' . ih($rec['registrar']) . '</strong>' : '' ?>. Nothing further to do here — it is ready to provision.</p>
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
                  : 'Its purchase adapter is not written yet (only NameSilo can buy today) — buy it by hand for now and record it below.' ?>
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
          <tr><th>Niche</th><td><input name="niche" value="<?= ih($rec['niche']) ?>" style="width:200px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px"></td></tr>
          <tr><th>Status</th><td>
            <select name="status" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
              <?php foreach ($statuses as $st): ?><option <?= $rec['status'] === $st ? 'selected' : '' ?>><?= ih($st) ?></option><?php endforeach; ?>
            </select></td></tr>
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
              <label style="display:block;margin-top:12px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">Niche (optional, applied to all)</label>
              <input name="niche" placeholder="pest" style="width:100%;margin-top:6px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
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
            <input type="date" name="spread_from" value="<?= ih(gmdate('Y-m-d')) ?>" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
            <button class="btn sec" type="submit" name="action" value="schedule_buys">Schedule</button>
            <span style="color:#d1d5db">|</span>
            <button class="btn sec" type="submit" name="action" value="remove" style="color:#991b1b" onclick="return confirm('Remove the ticked domains from the table? Only untracks them here — no infrastructure is touched.')">Remove</button>
          </div>

          <table>
            <thead><tr>
              <th style="width:26px"><input type="checkbox" id="selAll" title="Select all on this page"></th>
              <th><?= $sortLink('domain', '1. Domain') ?></th>
              <th><?= $sortLink('ready',  '2. Ready to buy') ?></th>
              <th><?= $sortLink('buyreg', '3. Register') ?></th>
              <th><?= $sortLink('buy_at', '4. Buy date') ?></th>
              <th><?= $sortLink('owned',  '5. Own') ?></th>
              <th><?= $sortLink('cf',     '6. Cloudflare') ?></th>
              <th><?= $sortLink('vps',    '7. VPS / Plesk') ?></th>
              <th><?= $sortLink('state',  '8. State') ?></th>
              <th><?= $sortLink('drift',  '9. Drift') ?></th>
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
                  <?php if ($r['niche'] !== ''): ?><br><span style="color:#9ca3af;font-size:11px"><?= ih($r['niche']) ?></span><?php endif; ?>
                </td>
                <td><?= infra_ready_cell($r) ?></td>
                <td>
                  <select name="reg[<?= ih($d) ?>]" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;max-width:130px">
                    <option value="">—</option>
                    <?php foreach ($regs as $rn): ?>
                      <option value="<?= ih($rn) ?>" <?= $r['buy_registrar'] === $rn ? 'selected' : '' ?>><?= ih($rn) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input type="date" name="buy[<?= ih($d) ?>]" value="<?= ih($r['buy_at']) ?>" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px"></td>
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

    $disc  = infra_discover_server($srv);
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
    foreach (infra_servers() as $s) $servers[$s['id'] ?? ''] = $s;
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
    $servers = infra_servers();
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
                <div style="color:#6b7280;font-size:12px;margin-top:4px">Auto-buy wired for NameSilo. Leave unchecked if the domain is already registered — the selected registrar is still recorded for the go-live NS switch.</div>
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
              <label style="display:block;margin-bottom:6px"><input type="checkbox" name="do_plesk" checked> Create Plesk site + FTP user</label>
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
    $servers = infra_servers();
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
              <label style="margin-right:16px"><input type="checkbox" name="do_plesk" checked> Plesk site</label>
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
    $cStaged = $cQueued = $cLive = 0;
    foreach ($all as $dom => $r) {
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
    $today = gmdate('Y-m-d');
    ?>
    <div class="ic-tiles">
      <div class="ic-tile"><div class="n"><?= $cStaged ?></div><div class="l">Staged</div></div>
      <div class="ic-tile"><div class="n"><?= $cQueued ?></div><div class="l">Queued</div></div>
      <div class="ic-tile"><div class="n"><?= $cLive ?></div><div class="l">Live</div></div>
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
      <?php if (!$queue): ?><div class="ic-empty">Nothing staged/queued. Provision domains first.</div>
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

/* ============================= BUY QUEUE ============================= */
if ($view === 'buyqueue') {
    infra_header('buyqueue');
    $today = gmdate('Y-m-d');
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
      Dates are UTC. Set them on the <a href="index.php?view=domains">Domains</a> tab
      (tick rows &rarr; <em>Spread N/day from</em>).
    </div>

    <div class="ic-card">
      <h2>Due today <span style="color:#9ca3af;font-weight:400;font-size:13px">&mdash; <?= gmdate('D j M Y') ?> &middot; about $<?= number_format($spend, 2) ?> if all of it went through</span></h2>
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
                    <input type="hidden" name="years" value="<?= strtolower($r['buy_registrar']) === 'namecheap' ? 3 : 1 ?>">
                    <input type="hidden" name="auto_renew" value="1">
                    <button class="btn" style="background:#991b1b;padding:4px 12px;font-size:12px" type="submit"
                      onclick="return confirm('Buy <?= ih($dom) ?> at <?= ih($r['buy_registrar']) ?> for <?= ih($price) ?>?\n\nThis spends real money and cannot be undone.');">Buy</button>
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
            Namecheap buys are placed for <strong>3 years</strong>, since its auto-renew cannot be set over the API.
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
              <td><strong><?= ih($date) ?></strong><br><span style="color:#9ca3af;font-size:11px"><?= ih(gmdate('D', strtotime($date))) ?></span></td>
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
if (in_array($view, ['plesk', 'cloudflare'], true)) {
    infra_header($view);
    $titles = ['plesk' => 'Plesk', 'cloudflare' => 'Cloudflare', 'golive' => 'Go-Live'];
    echo '<div class="ic-card"><h2>' . ih($titles[$view]) . '</h2><div class="ic-empty">CRUD for '
       . ih($titles[$view]) . ' comes in the next build step.</div></div>';
    infra_footer(); exit;
}

/* ============================= DASHBOARD ============================= */
$servers = infra_servers();
$cfAccts = infra_cf_accounts();
$totalSites = 0; $issues = 0; $rows = [];
foreach ($servers as $srv) {
    $disc = infra_discover_server($srv);
    if (!$disc['ok']) $issues++;
    $totalSites += count($disc['sites']);
    $rows[] = ['srv' => $srv, 'probe' => ['ok' => $disc['ok'], 'error' => $disc['error']],
               'sites' => count($disc['sites']), 'info' => $disc['info']];
}
infra_header('dashboard');
?>
<?php if (empty($servers)): ?><div class="ic-note">No servers registered. Add one to <code>admin/infra/config/servers.json</code>.</div><?php endif; ?>
<div class="ic-tiles">
  <div class="ic-tile"><div class="n"><?= count($servers) ?></div><div class="l">Servers</div></div>
  <div class="ic-tile"><div class="n"><?= $totalSites ?></div><div class="l">Sites (live)</div></div>
  <div class="ic-tile"><div class="n"><?= count($cfAccts) ?></div><div class="l">CF Accounts</div></div>
  <div class="ic-tile"><div class="n"><?= $issues ?></div><div class="l">Issues</div></div>
</div>
<div style="margin-bottom:16px">
  <a class="btn" href="index.php?refresh=1">&#8635; Discover / Refresh</a>
  <a class="btn sec" href="index.php?view=domains">View all domains &rarr;</a>
</div>
<div class="ic-card">
  <h2>Servers</h2>
  <div class="body">
    <?php if (empty($rows)): ?><div class="ic-empty">No servers to show.</div><?php else: ?>
      <table>
        <thead><tr><th>Server</th><th>Host</th><th>Plesk</th><th>Sites</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $srv = $r['srv'];
          $st = $r['probe']['ok'] ? '<span class="badge b-ok">reachable</span>' : '<span class="badge b-err">unreachable</span>'; ?>
          <tr>
            <td><strong><?= ih($srv['label'] ?? $srv['id']) ?></strong></td>
            <td><code><?= ih($srv['host'] ?? '') ?></code></td>
            <td><?= ih($r['info']['panel_version'] ?? '—') ?></td>
            <td><?= $r['sites'] ?></td>
            <td><?= $st ?></td>
            <td style="text-align:right"><a class="btn sec" href="index.php?view=server&id=<?= ih($srv['id'] ?? '') ?>">Open &rarr;</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php infra_footer();
