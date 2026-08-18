<?php
/**
 * The DNS / Health tab — is what we already own still correct?
 *
 * Deliberately NOT the go-live grid. That one asks "is this batch progressing" — a
 * pipeline that ends, whose rows retire once they are live. This asks "is the estate
 * still right", forever, over every owned domain. Keeping them apart is what stops the
 * grid growing twenty columns.
 *
 * Renders stored answers only. The three buttons are the only things that go and look.
 */
require_once __DIR__ . '/../lib/health.php';
infra_header('health');

$hRenew = infra_health_renewal_summary();
$hRows  = infra_health_rows();
$hRead  = infra_health_readers();

// The registrar login links live with the registrar definitions — the same ones the
// Registers tab uses — because half of what this page reports ends in "so fix it in
// their dashboard".
$hTypes = infra_registrar_types();

$hTotals = ['total' => 0, 'yes' => 0, 'no' => 0, 'unknown' => 0];
foreach ($hRenew as $r) foreach (['total', 'yes', 'no', 'unknown'] as $k) $hTotals[$k] += $r[$k];

/** The soonest expiry across everything, and how many are inside 60 days. */
$hSoon = ''; $hUrgent = 0; $hNoExpiry = 0;
foreach ($hRows as $row) {
    $e = (string) ($row['rec']['expires_at'] ?? '');
    if ($e === '') { $hNoExpiry++; continue; }
    if ($hSoon === '' || $e < $hSoon) $hSoon = $e;
    if (strtotime($e) < time() + 60 * 86400) $hUrgent++;
}

$hNsCounts = ['ok' => 0, 'drift' => 0, 'elsewhere' => 0, 'parked' => 0, 'none' => 0, 'unknown' => 0];
$hZoCounts = ['ok' => 0, 'warn' => 0, 'empty' => 0, 'none' => 0, 'unknown' => 0];
foreach ($hRows as $row) {
    $hNsCounts[$row['ns']['state']] = ($hNsCounts[$row['ns']['state']] ?? 0) + 1;
    $hZoCounts[$row['zone_v']['state']] = ($hZoCounts[$row['zone_v']['state']] ?? 0) + 1;
}

/** A form button that runs one health sweep. */
function h_btn(string $action, string $arg, string $label, string $title, string $cls = 'btn sec'): string
{
    return '<form method="post" action="actions/health.php" style="display:inline">'
         . '<input type="hidden" name="csrf" value="' . ih(infra_csrf()) . '">'
         . '<input type="hidden" name="action" value="' . ih($action) . '">'
         . ($arg !== '' ? '<input type="hidden" name="arg" value="' . ih($arg) . '">' : '')
         . '<button class="' . $cls . '" type="submit" title="' . ih($title) . '">' . $label . '</button></form>';
}
?>
<style>
.h-tab      { width:100%; border-collapse:collapse; font-size:13px }
.h-tab th   { text-align:left; padding:7px 9px; border-bottom:1px solid #e5e7eb; font-size:11px;
              text-transform:uppercase; letter-spacing:.03em; color:#6b7280; font-weight:700 }
.h-tab td   { padding:7px 9px; border-bottom:1px solid #f3f4f6; vertical-align:top }
.h-num      { text-align:right; width:70px; font-variant-numeric:tabular-nums }
.h-on       { color:#166534; font-weight:700 }
.h-off      { color:#991b1b; font-weight:700 }
.h-unk      { color:#92400e; font-weight:700 }
.h-mut      { color:#cbd5e1 }
.h-sub      { font-size:11px; color:#9ca3af }
.h-pill     { display:inline-block; font-size:11px; padding:2px 8px; border-radius:999px; margin-right:6px }
</style>

<div class="ic-card">
  <h2>Renewal &mdash; will these domains still be ours next year?</h2>
  <div class="body">

    <div class="ic-note" style="margin-bottom:12px">
      <?php // The number that matters, said once, in the words of the risk rather than
            // the words of the database. ?>
      <strong><?= $hTotals['total'] ?></strong> owned &middot;
      <span class="h-on"><?= $hTotals['yes'] ?></span> set to renew &middot;
      <span class="h-off"><?= $hTotals['no'] ?></span> <strong>will NOT renew</strong>
      <?php if ($hTotals['unknown']): ?>&middot; <span class="h-unk"><?= $hTotals['unknown'] ?></span> never asked<?php endif; ?>
      <?php if ($hSoon !== ''): ?>&middot; soonest expiry <strong><?= ih($hSoon) ?></strong><?php endif; ?>
      <?php if ($hUrgent): ?>&middot; <span class="h-off"><?= $hUrgent ?> inside 60 days</span><?php endif; ?>
    </div>

    <p class="ic-note" style="background:#fff;border-color:#e5e7eb;color:#6b7280;margin-bottom:12px">
      <code>auto_renew</code> used to be written once, at purchase, by reading the buy message &mdash;
      so a domain whose registrar quietly disagreed said whatever it said on the day it was bought,
      forever. <strong>Read</strong> asks the registrar itself. Six of them answer for the whole
      account in one request; NameSilo needs one per domain, which is why its button says so.
    </p>

    <table class="h-tab">
      <thead>
        <tr>
          <th>Registrar</th>
          <th class="h-num">Owned</th>
          <th class="h-num">Renews</th>
          <th class="h-num">Will not</th>
          <th class="h-num">Not asked</th>
          <th>Soonest expiry</th>
          <th>Read it back</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($hRenew as $reg => $r):
          $def   = $hTypes[$reg] ?? [];
          $login = (string) ($def['login'] ?? ''); ?>
        <tr>
          <td>
            <strong><?= ih($def['label'] ?? $reg) ?></strong>
            <?php if ($login !== ''): ?>
              <a href="<?= ih($login) ?>" target="_blank" rel="noopener" class="h-sub"
                 style="color:#2563eb;text-decoration:none">dashboard &#8599;</a>
            <?php endif; ?>
          </td>
          <td class="h-num"><?= $r['total'] ?></td>
          <td class="h-num <?= $r['yes'] ? 'h-on' : 'h-mut' ?>"><?= $r['yes'] ?: '—' ?></td>
          <td class="h-num <?= $r['no'] ? 'h-off' : 'h-mut' ?>"><?= $r['no'] ?: '—' ?></td>
          <td class="h-num <?= $r['unknown'] ? 'h-unk' : 'h-mut' ?>"><?= $r['unknown'] ?: '—' ?></td>
          <td><?= $r['soonest'] !== '' ? ih($r['soonest']) : '<span class="h-mut">—</span>' ?></td>
          <td>
            <?php if ($r['reader'] === 'account'): ?>
              <?= h_btn('read_registrar', $reg, '&#8635; Read', 'One request for the whole account') ?>
            <?php elseif ($r['reader'] === 'domain'): ?>
              <?= h_btn('read_registrar', $reg, '&#8635; Read (' . $r['total'] . ' calls)', 'One request per domain — this registrar has no account-wide listing that carries auto-renew') ?>
            <?php else: ?>
              <span class="h-sub">no API &mdash; check it in their dashboard</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php
    // The actual list of at-risk domains. A count tells you there is a problem; this
    // tells you which ones, and where to go and fix each.
    $hOff = array_values(array_filter($hRows, fn($r) => strtolower((string) ($r['rec']['auto_renew'] ?? '')) === 'no'));
    usort($hOff, fn($a, $b) => [(string) $a['rec']['expires_at'], $a['domain']] <=> [(string) $b['rec']['expires_at'], $b['domain']]);
    ?>
    <?php if ($hOff): ?>
    <h2 style="font-size:15px;margin:18px 0 8px">
      <?= count($hOff) ?> domain<?= count($hOff) === 1 ? '' : 's' ?> set NOT to renew
    </h2>
    <p class="h-sub" style="margin:0 0 8px">
      Soonest first. <strong>Namecheap's API cannot switch this on</strong> &mdash; its
      <code>setAutoRenew</code> answers success and changes nothing &mdash; so those need a dashboard
      visit or a longer term bought up front. The others can be set over their APIs, which is not
      wired here yet: this page reports, it does not yet fix.
    </p>
    <table class="h-tab">
      <thead><tr><th>Domain</th><th>Registrar</th><th>Expires</th><th>Niche</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($hOff, 0, 60) as $r):
          $reg   = strtolower((string) ($r['rec']['registrar'] ?? ''));
          $login = (string) ($hTypes[$reg]['login'] ?? ''); ?>
        <tr>
          <td><strong><?= ih($r['domain']) ?></strong></td>
          <td>
            <?php if ($login !== ''): ?>
              <a href="<?= ih($login) ?>" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none"><?= ih($hTypes[$reg]['label'] ?? $reg ?: '—') ?> &#8599;</a>
            <?php else: ?><?= ih($reg ?: '—') ?><?php endif; ?>
          </td>
          <td><?= ih((string) ($r['rec']['expires_at'] ?? '')) ?: '<span class="h-mut">—</span>' ?></td>
          <td class="h-sub"><?= ih((string) ($r['rec']['niche'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (count($hOff) > 60): ?>
        <tr><td colspan="4" class="h-sub">&hellip; and <?= count($hOff) - 60 ?> more.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="ic-card">
  <h2>Nameservers &mdash; where does the world think these domains live?</h2>
  <div class="body">
    <p class="ic-note" style="margin-bottom:12px">
      Read from <strong>public DNS</strong>, not from a registrar API: it needs no key, it works for
      registrars that have no API at all, and it is the same answer a visitor's resolver gets &mdash;
      which is the only one that decides where traffic actually goes.
      <?= h_btn('sweep_ns', '', 'Look up all ' . count($hRows), 'One DNS query per domain') ?>
    </p>
    <div style="margin-bottom:10px">
      <span class="h-pill" style="background:#dcfce7;color:#166534"><?= (int) $hNsCounts['ok'] ?> on our nameservers</span>
      <span class="h-pill" style="background:#fee2e2;color:#991b1b"><?= (int) $hNsCounts['elsewhere'] ?> pointing elsewhere</span>
      <span class="h-pill" style="background:#fef3c7;color:#92400e"><?= (int) $hNsCounts['drift'] ?> partly switched</span>
      <span class="h-pill" style="background:#eff6ff;color:#1e40af" title="Resolving, but at whatever nameservers the registrar gave them — nothing of ours to compare with yet"><?= (int) $hNsCounts['parked'] ?> parked at the registrar</span>
      <span class="h-pill" style="background:#f1f5f9;color:#64748b"><?= (int) $hNsCounts['none'] ?> not resolving</span>
      <span class="h-pill" style="background:#f1f5f9;color:#94a3b8"><?= (int) $hNsCounts['unknown'] ?> never looked up</span>
    </div>
    <?php
    $hNsBad = array_values(array_filter($hRows, fn($r) => in_array($r['ns']['state'], ['elsewhere', 'drift'], true)));
    ?>
    <?php if ($hNsBad): ?>
    <table class="h-tab">
      <thead><tr><th>Domain</th><th>What public DNS says</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($hNsBad, 0, 40) as $r): ?>
        <tr><td><strong><?= ih($r['domain']) ?></strong></td><td class="h-sub"><?= ih($r['ns']['note']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="ic-empty">Nothing is pointing somewhere unexpected.</div>
    <?php endif; ?>
  </div>
</div>

<div class="ic-card">
  <h2>Cloudflare zones &mdash; are they set up the way the architecture assumes?</h2>
  <div class="body">
    <p class="ic-note" style="margin-bottom:12px">
      A proxied A record on the domain's own box, SSL on Full, HSTS on. Two requests per zone:
      its records, and its settings &mdash; <code>/settings</code> returns every one of them at once.
      <?= h_btn('sweep_zones', '', 'Inspect every zone', 'Two calls per zone') ?>
    </p>
    <div style="margin-bottom:10px">
      <span class="h-pill" style="background:#dcfce7;color:#166534"><?= (int) $hZoCounts['ok'] ?> conform</span>
      <span class="h-pill" style="background:#fef3c7;color:#92400e"><?= (int) $hZoCounts['warn'] ?> need attention</span>
      <span class="h-pill" style="background:#fee2e2;color:#991b1b" title="The zone was created but nothing was ever put in it"><?= (int) $hZoCounts['empty'] ?> zone exists but is empty</span>
      <span class="h-pill" style="background:#f1f5f9;color:#64748b"><?= (int) $hZoCounts['none'] ?> no zone at all</span>
      <span class="h-pill" style="background:#f1f5f9;color:#94a3b8"><?= (int) $hZoCounts['unknown'] ?> never inspected</span>
    </div>
    <?php // Empty zones first: they are the half-done ones, and half-done is fixable.
          $hZoBad = array_values(array_filter($hRows, fn($r) => in_array($r['zone_v']['state'], ['empty', 'warn'], true)));
          usort($hZoBad, fn($a, $b) => [$a['zone_v']['state'], $a['domain']] <=> [$b['zone_v']['state'], $b['domain']]); ?>
    <?php if ($hZoBad): ?>
    <table class="h-tab">
      <thead><tr><th>Domain</th><th>What is wrong</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($hZoBad, 0, 40) as $r): ?>
        <tr><td><strong><?= ih($r['domain']) ?></strong></td><td class="h-sub"><?= ih($r['zone_v']['note']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="ic-empty">No zone has been inspected and found wanting.</div>
    <?php endif; ?>
  </div>
</div>
<?php infra_footer(); exit;
