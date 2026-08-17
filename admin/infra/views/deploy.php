<?php
/* ============================= DEPLOY (Phase 2 bridge) ============================= */
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
      <div class="ic-note">This bridges Phase&nbsp;1 (provisioning) → Phase&nbsp;2 (content). The console generated an FTP user for each provisioned domain; export them as a <strong>params CSV</strong> and merge into a master site's params (MultiSite tab) — then <strong>Build&nbsp;+&nbsp;Deploy</strong> uploads the generated content to the provisioned box using these creds. Columns match the multisite params format exactly.
        <br><br><strong>The upload path is the FTP login's own home</strong> (<code>/home/{ftp_user}</code>, FTP:21, passive) — that is the docroot on Hestia, with no <code>/httpdocs</code> or <code>/public_html</code> beneath it. The CSV below writes that path for you. <strong>The trap:</strong> any params row still carrying <code>/httpdocs</code> or <code>/public_html</code> — the Plesk and cPanel conventions — uploads every file successfully into a folder nginx never reads, and reports success doing it.</div>
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
