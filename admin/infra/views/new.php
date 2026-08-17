<?php
/* ============================= NEW SITE (CRUD) ============================= */
    infra_header('new');
    $servers = infra_hestia_servers();
    $accts   = infra_cf_accounts();
    $regs    = infra_registrar_names();
    ?>
    <div class="ic-card">
      <h2>New Site — Phase 1 provisioning</h2>
      <div class="body">
        <div class="ic-note">Creates the infrastructure only (the host on the server + the Cloudflare zone). No content is uploaded and no nameservers are switched — the site stays <strong>staged</strong> until go-live. Safe to run; re-running skips anything that already exists.</div>
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
                <div style="color:#6b7280;font-size:12px;margin-top:4px">Auto-buy is wired for every registrar the console supports, and goes through the same guards as the Buy button — availability is re-checked immediately before paying. Leave unchecked if the domain is already registered; the selected registrar is still recorded for the go-live NS switch.</div>
              <?php else: ?><span class="badge b-mut">no registrar configured</span><?php endif; ?>
            </td></tr>
            <tr><th>Hestia server</th><td>
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
