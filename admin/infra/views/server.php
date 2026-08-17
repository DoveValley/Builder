<?php
/* ============================= SERVER DETAIL ============================= */
    $srv = infra_find_server($_GET['id'] ?? '');
    infra_header('dashboard');
    if (!$srv) { echo '<div class="ic-note">Unknown server. <a href="index.php">&larr; back</a></div>'; infra_footer(); exit; }

    // The LAST STORED answer, never the network. This page used to call
    // infra_discover_hestia(), whose 180-second TTL meant every visit after three
    // minutes went and asked the box four questions before printing anything —
    // measured at 2.31s for one box, and it held the session lock while it ran, so
    // the next tab you clicked waited too. Refresh is now a button.
    // ?refresh=1 (the button) is the ONLY thing that goes and looks; ttl 0 forces it.
    $disc  = infra_cache_fresh()
           ? infra_discover_hestia($srv, 0)
           : (infra_hestia_cached($srv) ?? ['ok' => false, 'error' => '', 'info' => null,
                                            'sites' => [], 'users' => [], 'at' => '']);
    $age   = infra_cache_age('hestia:' . ($srv['id'] ?? ''));
    $probe = ['ok' => $disc['ok'], 'error' => $disc['error'] ?? ''];
    $info  = $disc['info'];
    $sites = $disc['sites'];
    $cfIdx = infra_cf_zone_index();
    $reg   = infra_registrar_map();
    $hasCf = count(infra_cf_accounts()) > 0;
    $badge = $probe['ok'] ? '<span class="badge b-ok">reachable</span>' : '<span class="badge b-err">unreachable</span>';
    ?>
    <div style="margin-bottom:14px"><a class="ic-back" style="color:#2563eb" href="index.php">&larr; All servers</a></div>
    <?php infra_freshness_bar([
        'age'         => $age,
        'stale_after' => 900,
        'noun'        => 'this box',
        'href'        => 'index.php?view=server&id=' . urlencode((string) ($srv['id'] ?? '')) . '&refresh=1',
        'button'      => 'Refresh this box',
    ]); ?>
    <div class="ic-card">
      <h2><?= ih($srv['label'] ?? $srv['id']) ?> <?= $badge ?></h2>
      <div class="body"><table>
        <tr><th style="width:200px">Server ID</th><td><code><?= ih($srv['id'] ?? '') ?></code></td></tr>
        <tr><th>Hestia panel</th><td><code><?= ih($srv['host'] ?? '') ?>:<?= ih($srv['port'] ?? 8083) ?></code></td></tr>
        <tr><th>Default IP (CF targets this)</th><td><code><?= ih($srv['default_ip'] ?? $srv['host'] ?? '') ?></code></td></tr>
        <tr><th>Hestia version</th><td><?= ih($info['panel_version'] ?? '—') ?><?= isset($info['hostname']) ? ' · ' . ih($info['hostname']) : '' ?></td></tr>
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
            <thead><tr><th>Domain</th><th>Host</th><th>Cloudflare</th><th>Registrar</th><th>State</th></tr></thead>
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
