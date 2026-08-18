<?php
/**
 * Which Cloudflare account holds which box's zones — one folding card per box.
 *
 * Sits at the top of the Cloudflare tab, above the per-account cards, which keep doing
 * what they already do (credentials, zone lists, Test, Edit, Remove). This section owns
 * one question only: where do this box's zones go, and is there room.
 *
 * Folds like Servers and Registers: shut when the box is fine, OPEN when it has no
 * account bound or every account it has is full — a problem nobody clicks on is a
 * problem nobody sees.
 *
 * Renders stored answers. The zone counts come from the last sweep; Discover is a
 * button, not something a page load pays for.
 */
require_once __DIR__ . '/../lib/cf_alloc.php';

$cbCap   = infra_cf_capacity();
$cbFree  = infra_cf_accounts_unbound();
$cbSeen  = infra_cache_get('cf_discovered', PHP_INT_MAX);   // last Discover, if ever
$cbBoxes = infra_hestia_servers();
?>
<style>
.cb-row      { display:flex; gap:22px; flex-wrap:wrap; align-items:center; font-size:13px }
.cb-n        { font-size:19px; font-weight:700; line-height:1.1 }
.cb-l        { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.03em }
.cb-t        { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:10px }
.cb-t th     { text-align:left; padding:6px 8px; border-bottom:1px solid #e5e7eb; font-size:11px;
               text-transform:uppercase; letter-spacing:.03em; color:#6b7280; font-weight:700 }
.cb-t td     { padding:6px 8px; border-bottom:1px solid #f3f4f6 }
.cb-bar      { display:inline-block; width:120px; height:7px; background:#e5e7eb; border-radius:4px; overflow:hidden; vertical-align:middle }
.cb-bar span { display:block; height:100%; background:#166534 }
.cb-f        { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap }
.cb-f label  { display:block; font-size:11px; color:#6b7280; margin-bottom:3px }
.cb-f input, .cb-f select { padding:5px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:13px }
</style>

<div class="ic-card">
  <h2>Where each box's zones go
    <span style="font-weight:400;font-size:13px;color:#6b7280">&mdash; one Cloudflare account belongs to one box</span>
  </h2>
  <div class="body">

    <p class="ic-note" style="margin-bottom:12px">
      Every domain in one Cloudflare account shares <strong>one nameserver pair</strong>, so that pair links it
      to the account's other domains &mdash; while its IP links it to whatever else is on its box. Handing the
      two out independently makes those links overlap and <em>chain</em>, so one discovered site leads to the
      next. Binding each account to a single box makes both links land on the same small group instead.
      <br><br>
      <strong>Cloudflare cannot move a zone between accounts.</strong> Correcting one later means deleting and
      recreating it, which issues a new nameserver pair and needs a registrar change &mdash; so a box is chosen
      before its zone, and zones already created stay where they are.
    </p>

    <div class="cb-row" style="margin-bottom:14px">
      <div><div class="cb-n"><?= (int) $cbCap['boxes'] ?></div><div class="cb-l">Boxes</div></div>
      <div><div class="cb-n"><?= (int) $cbCap['bound'] ?></div><div class="cb-l">Accounts bound</div></div>
      <div><div class="cb-n"><?= (int) $cbCap['used'] ?></div><div class="cb-l">Zones held</div></div>
      <div><div class="cb-n"><?= (int) $cbCap['free'] ?></div><div class="cb-l">Room left</div></div>
      <div><div class="cb-n" style="color:<?= $cbCap['boxes_with_none'] ? '#92400e' : '#9ca3af' ?>"><?= (int) $cbCap['boxes_with_none'] ?></div><div class="cb-l">Boxes with none</div></div>
      <div><div class="cb-n" style="color:<?= $cbCap['boxes_full'] ? '#991b1b' : '#9ca3af' ?>"><?= (int) $cbCap['boxes_full'] ?></div><div class="cb-l">Boxes full</div></div>
      <div style="margin-left:auto">
        <?php // Asks Cloudflare which accounts this credential can see, and STORES the
              // answer. Twenty account ids typed by hand is twenty chances to paste the
              // wrong one, and a wrong id is invisible until a zone lands in the wrong place. ?>
        <form method="post" action="actions/cf_save.php" style="display:inline">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <button class="btn sec" type="submit" name="action" value="discover"
                  title="Ask Cloudflare which accounts these credentials can see">&#8635; Discover accounts</button>
        </form>
      </div>
    </div>

    <?php if (is_array($cbSeen) && !empty($cbSeen['accounts'])):
        $cbNew = array_values(array_filter($cbSeen['accounts'], fn($a) => empty($a['known']))); ?>
      <div class="ic-note" style="margin-bottom:12px;<?= $cbNew ? 'background:#fffbeb;border-color:#fcd34d;color:#92400e' : '' ?>">
        <?php if ($cbNew): ?>
          <strong><?= count($cbNew) ?> Cloudflare account<?= count($cbNew) === 1 ? '' : 's' ?> visible that the console does not have:</strong>
          <?php foreach (array_slice($cbNew, 0, 8) as $a): ?>
            <br><code><?= ih($a['id']) ?></code> <?= ih($a['name']) ?>
          <?php endforeach; ?>
          <?php if (count($cbNew) > 8): ?><br>&hellip; and <?= count($cbNew) - 8 ?> more.<?php endif; ?>
          <br><br>Add each with <strong>Add a Cloudflare account</strong> below, then bind it to a box here.
        <?php else: ?>
          Every Cloudflare account these credentials can see is already in the console.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($cbFree): ?>
      <div class="ic-note" style="margin-bottom:12px">
        <strong><?= count($cbFree) ?> account<?= count($cbFree) === 1 ? '' : 's' ?> bound to no box:</strong>
        <?php foreach ($cbFree as $a): ?>
          <span class="badge b-mut"><?= ih($a['label'] ?? $a['id']) ?> &middot; <?= (int) $a['used'] ?> zone<?= (int) $a['used'] === 1 ? '' : 's' ?></span>
        <?php endforeach; ?>
        <br>They still work; they are just outside the scheme, so nothing routes to them.
      </div>
    <?php endif; ?>

  </div>
</div>

<?php foreach ($cbBoxes as $cbS):
    $sid   = (string) ($cbS['id'] ?? '');
    $bound = infra_cf_accounts_for_server($sid);
    $used  = array_sum(array_column($bound, 'used'));
    $cap   = array_sum(array_column($bound, 'max'));
    $free  = array_sum(array_column($bound, 'free'));
    // Open when something is wrong with it, shut when it is fine. Twenty healthy boxes
    // should fit on a screen; the one with no account should be the one you see.
    $cbOpen = !$bound || $free === 0;
    $prov   = function_exists('infra_host_provider') ? infra_host_provider($cbS) : null; ?>
<details class="ic-card ic-fold" <?= $cbOpen ? 'open' : '' ?>>
  <summary style="cursor:pointer">
    <h2 style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="color:#9ca3af;font-weight:400">&#9656;</span>
      <?= ih($cbS['label'] ?? $sid) ?>
      <?php if (!$bound): ?>
        <span class="badge b-warn">no account bound</span>
      <?php elseif ($free === 0): ?>
        <span class="badge b-err">full</span>
      <?php else: ?>
        <span class="badge b-ok"><?= $free ?> free</span>
      <?php endif; ?>
      <span style="font-weight:400;font-size:13px;color:#6b7280">
        <?php if ($prov): ?>
          <a href="<?= ih($prov['login']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()"
             style="color:#2563eb;text-decoration:none"><?= ih($prov['name']) ?> &#8599;</a> &middot;
        <?php endif; ?>
        <code><?= ih($cbS['host'] ?? '') ?></code>
        <?php if ($bound): ?>
          &middot; <?= count($bound) ?> account<?= count($bound) === 1 ? '' : 's' ?>
          &middot; <?= $used ?>/<?= $cap ?> zones
          <span class="cb-bar"><span style="width:<?= $cap > 0 ? min(100, round($used / $cap * 100)) : 0 ?>%"></span></span>
        <?php endif; ?>
      </span>
    </h2>
  </summary>
  <div class="body">

    <?php if (!$bound): ?>
      <div class="ic-note" style="background:#fffbeb;border-color:#fcd34d;color:#92400e">
        <strong>No Cloudflare account is bound to this box</strong>, so no domain on it can be staged &mdash;
        the CF zone step will refuse rather than put the zone somewhere that breaks the separation.
      </div>
    <?php elseif ($free === 0): ?>
      <div class="ic-note" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b">
        <strong>Every account bound to this box is full.</strong> The next zone for it will be refused.
        Bind another account below &mdash; deliberately, rather than having a batch quietly spill into
        another box's account.
      </div>
    <?php endif; ?>

    <?php if ($bound): ?>
    <table class="cb-t">
      <thead><tr><th style="width:36px">#</th><th>Account</th><th>Cloudflare account id</th><th>Zones</th><th>Room</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bound as $a): ?>
        <tr>
          <td style="color:#9ca3af"><?= (int) ($a['order'] ?? 0) ?></td>
          <td><strong><?= ih($a['label'] ?? $a['id']) ?></strong></td>
          <td><code style="font-size:12px"><?= ih($a['account_id'] ?? '') ?></code></td>
          <td>
            <?php if (!empty($a['unswept'])): ?>
              <span style="color:#9ca3af" title="No sweep has ever counted this account's zones">not counted yet</span>
            <?php else: ?>
              <?= (int) $a['used'] ?> / <?= (int) $a['max'] ?>
            <?php endif; ?>
          </td>
          <td style="color:<?= $a['free'] > 0 ? '#166534' : '#991b1b' ?>"><?= $a['free'] > 0 ? (int) $a['free'] : 'full' ?></td>
          <td>
            <form method="post" action="actions/cf_save.php" class="cb-f">
              <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
              <input type="hidden" name="action" value="bind">
              <input type="hidden" name="id" value="<?= ih($a['id'] ?? '') ?>">
              <input type="hidden" name="server_id" value="<?= ih($sid) ?>">
              <div><label>order</label><input type="number" name="order" value="<?= (int) ($a['order'] ?? 0) ?>" min="0" max="99" style="width:60px"></div>
              <div><label>max zones</label><input type="number" name="max_zones" value="<?= (int) $a['max'] ?>" min="1" max="5000" style="width:80px"></div>
              <button class="btn sec" type="submit">Save</button>
              <button class="btn sec" type="submit" name="server_id" value=""
                      onclick="return confirm('Unbind <?= ih($a['label'] ?? $a['id']) ?> from this box?\n\nZones already in it stay exactly where they are — Cloudflare cannot move a zone between accounts. It just stops receiving new ones.')">Unbind</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($cbFree): ?>
      <form method="post" action="actions/cf_save.php" class="cb-f" style="margin-top:4px">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="bind">
        <input type="hidden" name="server_id" value="<?= ih($sid) ?>">
        <div>
          <label>bind another account to this box</label>
          <select name="id">
            <?php foreach ($cbFree as $a): ?>
              <option value="<?= ih($a['id'] ?? '') ?>"><?= ih($a['label'] ?? $a['id']) ?> (<?= (int) $a['used'] ?> zones)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>order</label><input type="number" name="order" value="<?= count($bound) ?>" min="0" max="99" style="width:60px"></div>
        <div>
          <label title="A footprint policy, not a Cloudflare limit: how many domains may share this account's nameservers">max zones</label>
          <input type="number" name="max_zones" value="<?= INFRA_CF_DEFAULT_MAX ?>" min="1" max="5000" style="width:80px">
        </div>
        <button class="btn" type="submit">Bind</button>
      </form>
      <div style="font-size:11px;color:#9ca3af;margin-top:5px">
        <strong>max zones</strong> is a footprint policy, not a Cloudflare limit &mdash; how many domains you are
        willing to have share this account's nameserver pair. Cloudflare enforces nothing here.
      </div>
    <?php else: ?>
      <div style="font-size:12px;color:#9ca3af">
        Every account the console knows is already bound to a box. Add another below to bind more here.
      </div>
    <?php endif; ?>
  </div>
</details>
<?php endforeach; ?>
