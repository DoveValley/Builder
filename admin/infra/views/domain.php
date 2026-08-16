<?php
/* ============================= DOMAIN MANAGE (edit/remove) ============================= */
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
