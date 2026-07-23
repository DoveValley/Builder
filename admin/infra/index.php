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
    $map = ['live' => 'b-ok', 'staged' => 'b-warn', 'unknown' => 'b-mut'];
    return '<span class="badge ' . ($map[$state] ?? 'b-mut') . '">' . ih($state) . '</span>';
}
function infra_drift_cell(?string $drift): string
{
    if (!$drift) return '<span style="color:#9ca3af">—</span>';
    return '<span class="badge b-err">' . ih($drift) . '</span>';
}

/* ============================= DOMAINS ============================= */
if ($view === 'domains') {
    infra_header('domains');
    $rows  = infra_fleet_domains();
    $hasCf = count(infra_cf_accounts()) > 0;
    $live = $staged = $drift = 0;
    foreach ($rows as $r) {
        if ($r['state'] === 'live') $live++;
        elseif ($r['state'] === 'staged') $staged++;
        if ($r['drift']) $drift++;
    }
    ?>
    <div class="ic-tiles">
      <div class="ic-tile"><div class="n"><?= count($rows) ?></div><div class="l">Domains</div></div>
      <div class="ic-tile"><div class="n"><?= $live ?></div><div class="l">Live</div></div>
      <div class="ic-tile"><div class="n"><?= $staged ?></div><div class="l">Staged</div></div>
      <div class="ic-tile"><div class="n"><?= $drift ?></div><div class="l">Drift</div></div>
    </div>
    <?php if (!$hasCf): ?>
      <div class="ic-note">No Cloudflare account configured yet — the Cloudflare column shows <em>no CF account</em>. Add one to <code>admin/infra/config/cloudflare.json</code> and refresh to see real zone/NS state.</div>
    <?php endif; ?>
    <div style="margin-bottom:12px"><a class="btn" href="index.php?view=domains">&#8635; Discover / Refresh</a></div>
    <div class="ic-card">
      <h2>Domain inventory</h2>
      <div class="body">
        <?php if (empty($rows)): ?>
          <div class="ic-empty">No domains found across Plesk / Cloudflare / registrar map.</div>
        <?php else: ?>
          <input class="ic-search" type="search" placeholder="Filter domains…" data-target="tbl-dom">
          <table id="tbl-dom">
            <thead><tr><th>Domain</th><th>Registrar</th><th>Cloudflare</th><th>VPS / Plesk</th><th>State</th><th>Drift</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
              $reg = $r['registrar'] !== '' ? ih($r['registrar']) : '<span class="badge b-mut">unknown</span>';
              $vps = $r['plesk']
                  ? '<a href="index.php?view=server&id=' . ih($r['plesk']['server_id']) . '">' . ih($r['plesk']['server_label']) . '</a>'
                  : '<span class="badge b-warn">not on Plesk</span>';
            ?>
              <tr>
                <td><strong><?= ih($r['domain']) ?></strong><?php if (!empty($r['managed'])): ?> <span class="badge b-ok" title="provisioned/tracked by this console">managed</span><?php endif; ?></td>
                <td><?= $reg ?></td>
                <td><?= infra_cf_cell($r['cf'], $hasCf) ?></td>
                <td><?= $vps ?></td>
                <td><?= infra_state_cell($r['state']) ?></td>
                <td><?= infra_drift_cell($r['drift']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <?php infra_search_js(); infra_footer(); exit;
}

/* ============================= SERVER DETAIL ============================= */
if ($view === 'server') {
    $srv = infra_find_server($_GET['id'] ?? '');
    infra_header('dashboard');
    if (!$srv) { echo '<div class="ic-note">Unknown server. <a href="index.php">&larr; back</a></div>'; infra_footer(); exit; }

    $probe = plesk_probe($srv);
    $info  = $probe['ok'] ? plesk_server_info($srv) : null;
    $sites = $probe['ok'] ? plesk_list_sites($srv) : [];
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
                <?php foreach ($servers as $s): ?><option value="<?= ih($s['id'] ?? '') ?>"><?= ih(($s['label'] ?? $s['id']) . ' — ' . ($s['host'] ?? '')) ?></option><?php endforeach; ?>
              </select></td></tr>
            <tr><th>Cloudflare account</th><td>
              <?php if ($accts): ?><select name="cf_account_id" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                <?php foreach ($accts as $a): ?><option value="<?= ih($a['id'] ?? '') ?>"><?= ih($a['label'] ?? $a['id']) ?></option><?php endforeach; ?>
              </select><?php else: ?><span class="badge b-mut">no CF account</span><?php endif; ?></td></tr>
            <tr><th>Registrar</th><td>
              <?php if ($regs): ?>
                <select name="registrar" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                  <?php foreach ($regs as $rn): ?><option value="<?= ih($rn) ?>"><?= ih($rn) ?></option><?php endforeach; ?>
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

    <div class="ic-note">Go-live = switching the domain's <strong>nameservers at the registrar</strong> to the Cloudflare pair. Cloudflare then flips the zone to <em>active</em> and the console detects it. No registrar API is wired yet, so releases surface the NS to set manually; use <strong>Refresh</strong> to poll Cloudflare and mark domains live.</div>

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
    $probe = plesk_probe($srv);
    $sites = $probe['ok'] ? plesk_list_sites($srv) : [];
    if (!$probe['ok']) $issues++;
    $totalSites += count($sites);
    $rows[] = ['srv' => $srv, 'probe' => $probe, 'sites' => count($sites),
               'info' => $probe['ok'] ? plesk_server_info($srv) : null];
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
  <a class="btn" href="index.php">&#8635; Discover / Refresh</a>
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
