<?php
/* ============================= GO-LIVE (Phase 3) ============================= */
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
