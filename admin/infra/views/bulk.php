<?php
/* ============================= BULK PROVISION ============================= */
    infra_header('bulk');
    $servers = infra_hestia_servers();
    $accts   = infra_cf_accounts();
    $regs    = infra_registrar_names();
    ?>
    <div class="ic-card">
      <h2>Bulk Provision — Phase 1 at scale</h2>
      <div class="body">
        <div class="ic-note">Paste one domain per line. Each gets a host created on its Hestia server + is fully staged in Cloudflare (DNS→VPS IP proxied, SSL, HSTS) and saved to fleet state. Idempotent (existing sites/zones are skipped/updated), staged only — no nameservers switched. Progress streams live below.</div>
        <form id="bulkForm">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <textarea name="domains" rows="8" placeholder="dallaspestpros.com&#10;katypestpros.com&#10;austinpestpros.com" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-family:monospace;font-size:13px"></textarea>
          <table style="margin-top:10px">
            <tr><th style="width:180px">Hestia server</th><td>
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
      if (!confirm(buying ? ('⚠ This BUYS ' + doms + ' domain(s) (real money) then provisions them. Proceed?') : ('Provision ' + doms + ' domain(s)? Creates hosts + Cloudflare zones (staged).'))) return;
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
