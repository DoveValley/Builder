<?php
/* ============================= REGISTRARS ============================= */
    infra_header('registrars');
    $types = infra_registrar_types();      // this array's order IS the card order
    $saved = infra_load_json(infra_config_path('registrar.json'), [])['registrars'] ?? [];
    // Test results arrive via the session so a redirect can carry them (creds never in a URL).
    $tests = $_SESSION['infra_reg_tests'] ?? [];
    unset($_SESSION['infra_reg_tests']);

    /*
     * ONE pass decides every card's state, and the tiles at the top count the very
     * array the cards are rendered from. Two loops each working out "is this one
     * configured?" is how a summary comes to disagree with what it summarises.
     */
    // How many domains are actually HELD at each registrar. It is the fact that makes
    // the rest of the card mean something — "not used for new buys" reads very
    // differently next to 31 domains than next to none — and it comes from the same
    // fleet state the Domains tab reports, so the two cannot disagree.
    $held = [];
    foreach (infra_state_all_domains() as $d) {
        $rn = strtolower(trim((string) ($d['registrar'] ?? '')));
        if ($rn === '') continue;
        $owned = trim((string) ($d['owned'] ?? '')) !== '' && ($d['owned'] ?? '') !== '0';
        $held[$rn]['total'] = ($held[$rn]['total'] ?? 0) + 1;
        if ($owned) $held[$rn]['owned'] = ($held[$rn]['owned'] ?? 0) + 1;
    }

    $rows = [];
    foreach ($types as $type => $def) {

        // The config key is normally the type name, but a registrar may be keyed under
        // any name with its type declared inside, so resolve rather than assume.
        $savedName = null;
        foreach ($saved as $name => $c) if (strtolower($c['type'] ?? $name) === $type) $savedName = $name;
        $rawRow = $saved[$savedName ?? $type] ?? [];

        // Read through infra_registrar_config(), NOT the raw row. That function is what
        // every buy and NS switch actually authenticates with — including Cloudflare's
        // fallback to the CF account registry — so it is the only honest answer to
        // "what would run if I pressed Buy". Reading the raw row is why this card used
        // to show empty credential boxes while the console was quietly working fine.
        $cfg = infra_registrar_config($savedName ?? $type);

        // Credentials that came from somewhere else rather than being typed in here.
        $typedHere = (bool) array_filter(
            array_diff_key($rawRow, ['type' => 1]),
            fn($v) => trim((string) $v) !== ''
        );

        // What is set, and what is still empty. A field with a default is not missing:
        // the form posts the default, so it arrives filled.
        $secretsSet = 0;
        $missing    = [];
        foreach ($def['fields'] as $fn => $f) {
            $val = trim((string) ($cfg[$fn] ?? ''));
            if (!empty($f['secret']) && $val !== '') $secretsSet++;
            if ($val === '' && empty($f['optional']) && ($f['default'] ?? '') === '') $missing[] = $f['label'];
        }

        // Configured = it holds a credential. Everything here authenticates with a key
        // of some sort, so a row holding only {"type":"cloudflare"} is not configured —
        // it used to badge itself "configured" purely for existing, which read as
        // "ready to buy" on a card with nothing in it.
        $has = $secretsSet > 0;

        // Waiting on API access is a different state from "not set up": there is no
        // credential to enter and nothing for anyone to do about it here.
        $pending = (string) ($def['pending_api'] ?? '');

        $rows[$type] = [
            'def'       => $def,
            'name'      => $savedName ?? $type,
            'cfg'       => $cfg,
            'has'       => $has,
            'row'       => $savedName !== null,   // a row exists in registrar.json
            'borrowed'  => $has && !$typedHere,   // credentials owned by another page
            'missing'   => $missing,
            'pending'   => $pending,
            'test'      => $tests[$type] ?? null,
            // Anything wrong opens itself, exactly as the server cards do — a problem
            // nobody clicks on is a problem nobody sees. A healthy registrar is a
            // one-line fact and eight of them should fit on a screen. A card blocked
            // on someone else's reply stays SHUT: its badge and digest say everything,
            // and holding it open every day until support answers just costs a screen.
            'open'      => $pending === '' ? (!$has || $missing || isset($tests[$type]))
                                           : isset($tests[$type]),
        ];
    }

    $nConfigured = count(array_filter($rows, fn($r) => $r['has']));
    // Attention = something a person can act on here. Waiting on a third party is not
    // that, so a pending registrar is not counted — a number that never moves stops
    // being read.
    $nAttention  = count(array_filter($rows, fn($r) => $r['open'] && !isset($r['test'])));
    $nCheck      = count(array_filter($rows, fn($r) => !empty($r['def']['check'])));
    $nRenew      = count(array_filter($rows, fn($r) => !empty($r['def']['autorenew']['ok'])));
    ?>
    <div class="ic-tiles">
      <div class="ic-tile"><div class="n"><?= count($rows) ?></div><div class="l">Registrars</div></div>
      <div class="ic-tile"><div class="n" style="color:<?= $nConfigured === count($rows) ? '#166534' : '#111827' ?>"><?= $nConfigured ?></div><div class="l">Configured</div></div>
      <div class="ic-tile"><div class="n" style="color:<?= $nAttention ? '#92400e' : '#9ca3af' ?>"><?= $nAttention ?></div><div class="l">Need attention</div></div>
      <div class="ic-tile"><div class="n"><?= $nCheck ?></div><div class="l">Can check availability</div></div>
      <div class="ic-tile"><div class="n"><?= $nRenew ?></div><div class="l">Auto-renew by API</div></div>
    </div>

    <div class="ic-note">Where the console buys domains and switches nameservers. Credentials live in <code>admin/infra/config/registrar.json</code> — gitignored, <code>0600</code>, denied to the web, and never printed back into this page. <strong>Test</strong> is read-only: it verifies the key and reports the balance, which is what decides whether a scheduled buy can actually complete. Cards open themselves when something is wrong; the rest stay shut.</div>

    <div class="ic-refresh">
      <form method="post" action="actions/registrar_save.php" style="display:contents">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <button class="btn" type="submit" name="action" value="test_all">&#8635; Test all <?= $nConfigured ?> configured</button>
      </form>
      <span class="ic-asof">Read-only. Also refreshes the owned-domain list that tells &ldquo;taken&rdquo; from &ldquo;you already own it&rdquo;.</span>
    </div>

    <?php $n = 0; foreach ($rows as $type => $r):
      $n++;                       // 1..8, in the deliberate order set by infra_registrar_types()
      $def = $r['def'];
      $cfg = $r['cfg'];
      $t   = $r['test'];
      $pending = (string) ($def['pending_api'] ?? '');   // no API access yet — not "cannot"
      $unused  = (string) ($def['not_in_use'] ?? '');    // deliberately not bought at
      $owned   = (int) ($held[$type]['owned'] ?? 0);
      $caps = [];
      foreach (['check' => 'availability', 'buy' => 'auto-buy', 'ns' => 'nameservers', 'balance' => 'balance'] as $k => $lbl) {
          $on = !empty($def[$k]);
          // auto-buy is only genuinely usable when the adapter is written here too.
          $partial = ($k === 'buy' && $on && empty($def['buy_wired']));
          if ($pending !== '') {
              // "?" not "✗". Four crosses would say the registrar cannot do these
              // things; what is actually true is that nobody has told us yet.
              $caps[] = '<span class="badge b-mut" title="not known yet — waiting on API access">? ' . $lbl . '</span>';
              continue;
          }
          $caps[] = '<span class="badge ' . ($partial ? 'b-warn' : ($on ? 'b-ok' : 'b-mut')) . '"'
                  . ($partial ? ' title="the registrar API supports it; the purchase adapter is not written here yet"' : '')
                  . '>' . ($partial ? '~ ' : ($on ? '✓ ' : '✗ ')) . $lbl . '</span>';
      }
      // The digest: what this registrar is good for, stated once, so the card does not
      // have to be opened to answer "can it check, can it renew, can I see the money".
      $bulk  = (int) ($def['check_bulk'] ?? 1);
      $facts = [];
      if ($pending !== '') {
          $facts[] = '<b style="color:#92400e">nothing wired yet — waiting on API access</b>';
      } else {
          $facts[] = empty($def['check'])
              ? 'no availability API'
              : ($bulk >= 10 ? 'checks <b>' . $bulk . ' names per request</b>'
                             : ($bulk === 1 ? 'checks <b>one at a time</b>' : 'checks <b>one per 10 seconds</b>'));
          // Three states, not two: works / cannot / nobody has established which.
          $facts[] = !isset($def['autorenew'])       ? 'auto-renew unknown'
                   : (empty($def['autorenew']['ok']) ? '<b style="color:#b91c1c">auto-renew not settable</b>'
                                                     : 'auto-renew by API');
          $facts[] = empty($def['balance']) ? 'no balance endpoint' : 'reports balance';
      }
    ?>
      <details class="ic-card ic-fold" <?= $r['open'] ? 'open' : '' ?>>
        <summary>
          <h2 style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="color:#9ca3af;font-weight:400">▸</span>
            <span style="color:#9ca3af">Register <?= $n ?> -</span> <?= ih($def['label']) ?>
            <?php if ($pending !== ''): ?>
              <span class="badge b-warn">no API yet</span>
              <?php if ($r['row']): ?><span class="badge b-mut">recorded — domains can be assigned</span><?php endif; ?>
            <?php elseif (!$r['has']): ?>
              <span class="badge b-mut">not configured</span>
            <?php elseif ($r['missing']): ?>
              <span class="badge b-warn"><?= count($r['missing']) ?> field<?= count($r['missing']) === 1 ? '' : 's' ?> still empty</span>
            <?php else: ?>
              <span class="badge b-ok">configured</span>
            <?php endif; ?>
            <?php if ($unused !== ''): ?><span class="badge b-mut" title="works, but not where new fleet domains are bought">not used for new buys</span><?php endif; ?>
            <?php if ($r['borrowed']): ?><span class="badge b-mut" title="the credentials come from the Cloudflare account registry">borrowed creds</span><?php endif; ?>
            <?php if ($t): ?>
              <span class="badge <?= $t['ok'] ? 'b-ok' : 'b-err' ?>"><?= $t['ok'] ? '✓ just tested' : '✗ test failed' ?></span>
            <?php endif; ?>
            <span style="margin-left:auto;font-weight:400;display:flex;gap:5px"><?= implode(' ', $caps) ?></span>
          </h2>
          <div class="ic-digest">
            <span><?= $owned ? '<b>' . $owned . '</b> domain' . ($owned === 1 ? '' : 's') . ' held here' : 'no domains held here' ?></span>
            <?php foreach ($facts as $f): ?><span><?= $f ?></span><?php endforeach; ?>
            <?php if ($t && $t['ok'] && $t['balance'] !== null && $t['balance'] !== ''): ?>
              <span>balance <b><?= ih($t['balance']) ?> <?= ih($t['currency']) ?></b></span>
            <?php endif; ?>
          </div>
        </summary>
        <div class="body">

          <?php if ($pending !== ''): ?>
            <div class="ic-note" style="background:#fffbeb;border-color:#fde047;color:#854d0e">
              <strong>⏳ No API access yet.</strong> <?= ih($pending) ?>
            </div>
          <?php endif; ?>

          <?php if ($unused !== ''): ?>
            <div class="ic-note" style="background:#f9fafb;border-color:#e5e7eb;color:#374151">
              <strong>Not used for new purchases.</strong> <?= ih($unused) ?>
              <?php if ($owned): ?>
                <br><br><strong><?= $owned ?> domain<?= $owned === 1 ? ' is' : 's are' ?> already registered here</strong>
                — see them on the <a href="index.php?view=domains">Domains</a> tab.
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($r['has'] && $r['missing']): ?>
            <div class="ic-note" style="background:#fffbeb;border-color:#fde047;color:#854d0e">
              <strong>The key works, but <?= count($r['missing']) ?> field<?= count($r['missing']) === 1 ? ' is' : 's are' ?> empty:</strong>
              <?= ih(implode(', ', $r['missing'])) ?>.
              Availability and nameserver changes do not need them. <strong>A purchase does</strong> — it will
              fail at the contact step rather than at the payment, so this is worth filling in before the first buy here.
            </div>
          <?php endif; ?>

          <?php if ($r['borrowed']): ?>
            <div class="ic-note">
              <strong>These credentials are not stored on this page.</strong> Nothing has been saved here, so the
              console falls back to the <a href="index.php?view=cloudflare">Cloudflare account registry</a> —
              which is what the boxes below are showing. It works as it stands; press Save only to pin different
              values here, and note that doing so ends the fallback: from then on what is typed here is what is used.
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

          <div style="color:#6b7280;font-size:12.5px;margin-bottom:12px"><?= ih($def['note']) ?></div>

          <?php if (!empty($def['autorenew'])):
            $ar  = $def['autorenew'];
            $bad = empty($ar['ok']); ?>
            <div style="border:<?= $bad ? '2px solid #dc2626' : '1px solid #e5e7eb' ?>;background:<?= $bad ? '#fef2f2' : '#f9fafb' ?>;border-radius:8px;padding:11px 13px;margin-bottom:12px">
              <div style="font-weight:700;font-size:13px;color:<?= $bad ? '#b91c1c' : '#15803d' ?>">
                <?= $bad ? '⛔ Auto-renew CANNOT be set through this API' : '✓ Auto-renew works' ?>
              </div>
              <div style="font-size:12.5px;color:<?= $bad ? '#7f1d1d' : '#374151' ?>;margin-top:5px"><?= $ar['note'] ?></div>
            </div>
          <?php endif; ?>

          <?php if (!empty($def['buy']) && empty($def['buy_wired'])): ?>
            <!-- Unreachable while every supported registrar has an adapter. Kept because
                 the next one to be added arrives in exactly this state, and this is the
                 explanation of the "~ auto-buy" badge above. -->
            <div class="ic-note" style="background:#fffbeb;border-color:#fde047;color:#854d0e">
              <strong>Auto-buy: API-capable, adapter not written yet.</strong> You can assign and schedule domains
              to <?= ih($def['label']) ?> now — the plan is valid — but the purchase itself cannot run here until
              its adapter lands. Every other registrar on this page can complete a purchase.
            </div>
          <?php endif; ?>

          <form method="post" action="actions/registrar_save.php">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="type" value="<?= ih($type) ?>">
            <?php if (!$def['fields']): ?>
              <div style="color:#6b7280;font-size:12.5px">
                Nothing to enter — the credential fields are unknown until support says what the API authenticates with.
                Saving here just records <?= ih($def['label']) ?> as a registrar you hold domains at, so it can be picked on a domain.
              </div>
            <?php endif; ?>
            <table>
              <?php foreach ($def['fields'] as $fname => $f):
                $cur      = (string) ($cfg[$fname] ?? ($f['default'] ?? ''));
                $isSecret = !empty($f['secret']);
                $hasVal   = $cur !== '';
              ?>
                <tr>
                  <th style="width:200px">
                    <?= ih($f['label']) ?>
                    <?php if (!empty($f['optional'])): ?><span style="font-weight:400;text-transform:none;letter-spacing:0;color:#9ca3af"> optional</span><?php endif; ?>
                  </th>
                  <td>
                    <?php if ($isSecret): ?>
                      <input name="f[<?= ih($fname) ?>]" type="password" autocomplete="new-password"
                             placeholder="<?= $hasVal ? 'saved — leave blank to keep' : 'not set' ?>"
                             style="width:380px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                      <?php if ($hasVal): ?><span class="badge b-ok"><?= $r['borrowed'] ? 'from Cloudflare' : 'saved' ?> (<?= strlen($cur) ?> chars)</span><?php endif; ?>
                    <?php else: ?>
                      <input name="f[<?= ih($fname) ?>]" value="<?= ih($cur) ?>"
                             style="width:380px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                    <?php endif; ?>
                    <?php if (!empty($f['hint'])): ?>
                      <div style="color:#9ca3af;font-size:11.5px;margin-top:3px"><?= ih($f['hint']) ?></div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
            <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
              <button class="btn" type="submit" name="action" value="save"><?= $def['fields'] ? 'Save credentials' : 'Record this registrar' ?></button>
              <?php if ($def['fields']): ?>
                <button class="btn sec" type="submit" name="action" value="test" <?= $r['has'] ? '' : 'disabled' ?>>Test connection<?= !empty($def['balance']) ? ' &amp; balance' : '' ?></button>
              <?php endif; ?>
              <?php if ($r['row'] && !$r['borrowed']): ?>
                <button class="btn sec" type="submit" name="action" value="delete" style="color:#991b1b;margin-left:auto"
                        onclick="return confirm('Remove <?= ih($def['label']) ?> credentials? Domains assigned to it keep the assignment but cannot be bought until it is reconfigured.');">Remove</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </details>
    <?php endforeach; ?>

    <?php infra_footer(); exit;
