<?php
/* ============================= BUY QUEUE ============================= */
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
