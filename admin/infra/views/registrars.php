<?php
/* ============================= REGISTRARS ============================= */
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
