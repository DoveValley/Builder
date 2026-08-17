<?php
/* ============================= DOMAINS ============================= */
    infra_header('domains');
    $allRows = infra_fleet_domains();
    $hasCf   = count(infra_cf_accounts()) > 0;
    $regs           = infra_registrar_names();
    $buyable        = infra_registrar_buyable();
    $checkers       = infra_registrar_checkers();      // who can answer "is it available?"
    $defaultChecker = infra_default_checker();         // the fastest of them

    // Tiles reflect the WHOLE fleet, not the filtered/paged slice. The acquisition
    // buckets come first because with 400 loaded-but-unbought rows the old
    // Live/Staged/Drift trio said nothing useful.
    $tally = ['begin' => 0, 'ready' => 0, 'owned' => 0, 'staged' => 0, 'live' => 0, 'drift' => 0, 'failed' => 0];
    foreach ($allRows as $r) {
        switch ($r['state']) {
            case 'begin':                                     $tally['begin']++;  break;
            case 'ready':                                     $tally['ready']++;  break;
            case 'owned':                                     $tally['owned']++;  break;
            case 'live':                                      $tally['live']++;   break;
            case 'buy-failed': case 'register-failed':
            case 'partial':                                   $tally['failed']++; break;
            default:                                          $tally['staged']++; break;
        }
        if ($r['drift']) $tally['drift']++;
    }

    /* search across the full set ------------------------------------------ */
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $ql = strtolower($q);
        $rows = array_values(array_filter($allRows, function ($r) use ($ql) {
            foreach (['domain', 'registrar', 'state', 'drift', 'buy_registrar', 'buy_at', 'niche', 'avail_note'] as $f) {
                if (strpos(strtolower((string) ($r[$f] ?? '')), $ql) !== false) return true;
            }
            return false;
        }));
    } else {
        $rows = $allRows;
    }

    /* sort across the full set (not just the visible page) ----------------- */
    $sort = (string) ($_GET['sort'] ?? 'domain');
    $dir  = (($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
    $sortable = [
        'domain' => fn($r) => $r['domain'],
        'niche'  => fn($r) => $r['niche'] !== '' ? $r['niche'] : 'zzz',   // unset last
        'ready'  => fn($r) => ['yes' => '0', 'no' => '2'][$r['ready_to_buy']] ?? '1',
        'buyreg' => fn($r) => strtolower((string) $r['buy_registrar']),
        'buy_at' => fn($r) => $r['buy_at'] !== '' ? $r['buy_at'] : '9999-99-99',   // unscheduled last
        'owned'  => fn($r) => $r['owned'] === 'yes' ? '0' : '1',
        'cf'     => fn($r) => $r['cf'] ? ($r['cf']['status'] ?? '') : 'zzz',
        'vps'    => fn($r) => $r['host'] ? ($r['host']['server_label'] ?? '') : 'zzz',
        'state'  => fn($r) => array_search($r['state'], INFRA_STATUSES, true) === false
                                ? '99' : sprintf('%02d', array_search($r['state'], INFRA_STATUSES, true)),
        'drift'  => fn($r) => $r['drift'] ?: 'zzz',
    ];
    if (!isset($sortable[$sort])) $sort = 'domain';
    $key = $sortable[$sort];
    usort($rows, function ($a, $b) use ($key, $dir) {
        $c = [$key($a), $a['domain']] <=> [$key($b), $b['domain']];
        return $dir === 'desc' ? -$c : $c;
    });

    /* paginate ------------------------------------------------------------- */
    $perPage = 100;
    $total   = count($rows);
    $pages   = max(1, (int) ceil($total / $perPage));
    $page    = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    $slice   = array_slice($rows, ($page - 1) * $perPage, $perPage);

    $baseQs = 'view=domains'
        . ($q !== '' ? '&q=' . urlencode($q) : '')
        . '&sort=' . urlencode($sort) . '&dir=' . $dir;
    // column header link that toggles direction on the active column
    $sortLink = function (string $k, string $label) use ($sort, $dir, $q, $page) {
        $nd  = ($sort === $k && $dir === 'asc') ? 'desc' : 'asc';
        $ar  = $sort === $k ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '';
        $url = 'index.php?view=domains' . ($q !== '' ? '&q=' . urlencode($q) : '')
             . '&sort=' . urlencode($k) . '&dir=' . $nd . '&page=' . $page;
        return '<a href="' . ih($url) . '" style="color:inherit;text-decoration:none">' . $label . $ar . '</a>';
    };
    ?>
    <div class="ic-tiles">
      <div class="ic-tile"><div class="n"><?= count($allRows) ?></div><div class="l">Domains</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['begin'] ?></div><div class="l">Begin</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['ready'] ?></div><div class="l">Ready to buy</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['owned'] ?></div><div class="l">Owned</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['staged'] ?></div><div class="l">Staged</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['live'] ?></div><div class="l">Live</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['drift'] + $tally['failed'] ?></div><div class="l">Needs attention</div></div>
    </div>

    <?php if (!$regs): ?>
      <div class="ic-note">No registrar configured yet — add one on the <a href="index.php?view=registrars"><strong>Registers</strong></a> tab before you can check availability or schedule buys.</div>
    <?php endif; ?>

    <!-- ============ LOAD NEW DOMAINS (→ begin state) ============ -->
    <details class="ic-card" <?= empty($allRows) ? 'open' : '' ?>>
      <summary style="padding:14px 16px;font-size:15px;font-weight:600;cursor:pointer;border-bottom:1px solid #f0f0f0">
        &#43; Load new domains <span style="color:#9ca3af;font-weight:400;font-size:13px">— paste a list or upload a CSV; they land in <em>begin</em> state</span>
      </summary>
      <div class="body">
        <form method="post" action="actions/domains_load.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <div style="display:grid;grid-template-columns:1fr 300px;gap:18px">
            <div>
              <label style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">Paste — one domain per line</label>
              <textarea name="domains" rows="7" placeholder="littletonpestpros.com&#10;auroramoldpros.com&#10;castlerockwaterpros.com" style="width:100%;margin-top:6px;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-family:monospace;font-size:13px"></textarea>
            </div>
            <div>
              <label style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">…or upload a CSV</label>
              <input type="file" name="csv" accept=".csv,.txt" style="width:100%;margin-top:6px;padding:8px;border:1px solid #d1d5db;border-radius:8px;background:#fff">
              <div style="color:#6b7280;font-size:12px;margin-top:6px">First column = domain. A <code>niche</code> column is used if present; a header row is detected and skipped.</div>
              <label style="display:block;margin-top:12px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">Niche (applied to all)</label>
              <select name="niche" style="width:100%;margin-top:6px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px">
                <option value="">—</option>
                <?php foreach (INFRA_NICHES as $nz): ?><option value="<?= ih($nz) ?>"><?= ih($nz) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn" type="submit">Load domains</button>
            <label style="font-size:13px;color:#374151"><input type="checkbox" name="check_now" value="1" <?= $checkers ? 'checked' : 'disabled' ?>> also check availability now</label>
            <?php if ($checkers): ?>
              <span style="font-size:13px;color:#6b7280">using</span>
              <select name="check_registrar" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:8px">
                <?php foreach ($checkers as $cn => $c): ?>
                  <option value="<?= ih($cn) ?>" <?= $cn === $defaultChecker ? 'selected' : '' ?>><?= ih($cn) ?> — <?= ih($c['speed']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <div style="color:#6b7280;font-size:12px;margin-top:8px">Loading is additive and safe to repeat — a domain already in the table is left exactly as it is, never reset.</div>
        </form>
      </div>
    </details>

    <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <a class="btn" href="index.php?<?= ih($baseQs) ?>&refresh=1">&#8635; Discover / Refresh</a>
      <form method="get" style="display:inline-flex;gap:6px;margin:0">
        <input type="hidden" name="view" value="domains">
        <input type="hidden" name="sort" value="<?= ih($sort) ?>"><input type="hidden" name="dir" value="<?= ih($dir) ?>">
        <input class="ic-search" type="search" name="q" value="<?= ih($q) ?>" placeholder="Search domain / registrar / state / note…" style="margin:0">
        <button class="btn sec" type="submit">Search</button>
        <?php if ($q !== ''): ?><a class="btn sec" href="index.php?view=domains">Clear</a><?php endif; ?>
      </form>
    </div>

    <div class="ic-card">
      <h2>Domain inventory <span style="color:#9ca3af;font-weight:400;font-size:13px">— <?= $total ?><?= $q !== '' ? ' match' . ($total === 1 ? '' : 'es') : '' ?>, page <?= $page ?>/<?= $pages ?>, sorted by <?= ih($sort) ?> <?= $dir ?></span></h2>
      <div class="body">
        <?php if (empty($slice)): ?>
          <div class="ic-empty"><?= $q !== '' ? 'No domains match “' . ih($q) . '”.' : 'No domains yet — load some above.' ?></div>
        <?php else: ?>
        <form method="post" action="actions/domains_bulk.php" id="domForm">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="back" value="<?= ih($baseQs . '&page=' . $page) ?>">
          <input type="hidden" name="from" value="<?= ih($baseQs . '&page=' . $page) ?>">

          <!-- bulk bar: acts on ticked rows -->
          <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13px">
            <strong id="selCount">0 selected</strong>
            <button class="btn sec" type="submit" name="action" value="check_avail" <?= $checkers ? '' : 'disabled' ?>>Check availability</button>
            <?php if ($checkers): ?>
              <span style="color:#6b7280">using</span>
              <select name="check_with" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px" title="Availability is a public fact — any registrar gives the same answer, so pick the fastest. This does not affect who buys.">
                <?php foreach ($checkers as $cn => $c): ?>
                  <option value="<?= ih($cn) ?>" <?= $cn === $defaultChecker ? 'selected' : '' ?>><?= ih($cn) ?> — <?= ih($c['speed']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <span style="color:#d1d5db">|</span>
            <span>Ready to buy</span>
            <select name="ready_val" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
              <option value="yes">yes</option><option value="no">no</option><option value="">clear</option>
            </select>
            <button class="btn sec" type="submit" name="action" value="set_ready" title="Override the availability check by hand">Set</button>
            <span style="color:#d1d5db">|</span>
            <span>Niche</span>
            <select name="bulk_niche" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
              <?php foreach (INFRA_NICHES as $nz): ?><option value="<?= ih($nz) ?>"><?= ih($nz) ?></option><?php endforeach; ?>
            </select>
            <button class="btn sec" type="submit" name="action" value="set_niche">Set</button>
            <span style="color:#d1d5db">|</span>
            <span>Registrar</span>
            <select name="bulk_registrar" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
              <option value="">—</option>
              <?php foreach ($regs as $rn): ?><option value="<?= ih($rn) ?>"><?= ih($rn) ?><?= in_array($rn, $buyable, true) ? '' : ' (no auto-buy)' ?></option><?php endforeach; ?>
              <?php if (count($buyable) > 1): ?><option value="__rr__">🔀 spread round-robin</option><?php endif; ?>
            </select>
            <button class="btn sec" type="submit" name="action" value="set_registrar">Set</button>
            <span style="color:#d1d5db">|</span>
            <span>Buy date</span>
            <input type="date" name="bulk_buy_at" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
            <button class="btn sec" type="submit" name="action" value="set_buy_at">Set</button>
            <span style="color:#d1d5db">|</span>
            <span>Spread</span>
            <input type="number" name="per_day" value="20" min="1" style="width:60px;padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
            <span>/day from</span>
            <input type="date" name="spread_from" value="<?= ih(infra_today()) ?>" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:8px">
            <button class="btn sec" type="submit" name="action" value="schedule_buys">Schedule</button>
            <span style="color:#d1d5db">|</span>
            <button class="btn sec" type="submit" name="action" value="remove" style="color:#991b1b" onclick="return confirm('Remove the ticked domains from the table? Only untracks them here — no infrastructure is touched.')">Remove</button>
          </div>

          <table>
            <thead><tr>
              <th style="width:26px"><input type="checkbox" id="selAll" title="Select all on this page"></th>
              <th><?= $sortLink('domain', '1. Domain') ?></th>
              <th><?= $sortLink('niche',  '2. Niche') ?></th>
              <th><?= $sortLink('ready',  '3. Ready to buy') ?></th>
              <th><?= $sortLink('buyreg', '4. Register') ?></th>
              <th>5. Buy</th>
              <th><?= $sortLink('buy_at', '6. Buy date') ?></th>
              <th><?= $sortLink('owned',  '7. Own') ?></th>
              <th><?= $sortLink('cf',     '8. Cloudflare') ?></th>
              <th><?= $sortLink('vps',    '9. VPS / host') ?></th>
              <th><?= $sortLink('state',  '10. State') ?></th>
              <th><?= $sortLink('drift',  '11. Drift') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($slice as $r):
              $d   = $r['domain'];
              $vps = $r['host']
                  ? '<a href="index.php?view=server&id=' . ih($r['host']['server_id']) . '">' . ih($r['host']['server_label']) . '</a>'
                  : '<span style="color:#9ca3af">—</span>';
            ?>
              <tr>
                <td><input type="checkbox" class="selBox" name="sel[]" value="<?= ih($d) ?>"></td>
                <td>
                  <?php if (!empty($r['managed'])): ?><a href="index.php?view=domain&d=<?= ih($d) ?>"><strong><?= ih($d) ?></strong></a><?php else: ?><strong><?= ih($d) ?></strong><?php endif; ?>
                </td>
                <td>
                  <select name="niche[<?= ih($d) ?>]" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
                    <option value="">—</option>
                    <?php foreach (INFRA_NICHES as $nz): ?>
                      <option value="<?= ih($nz) ?>" <?= $r['niche'] === $nz ? 'selected' : '' ?>><?= ih($nz) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><?= infra_ready_cell($r) ?></td>
                <?php // Once a domain is owned, the registrar and buy date are history: where
                      // it was bought and when. Leaving them editable would invite a change
                      // that contradicts the purchase and cannot be acted on. ?>
                <?php if (($r['owned'] ?? '') === 'yes'): ?>
                  <td><strong><?= ih($r['registrar'] ?: $r['buy_registrar'] ?: '—') ?></strong></td>
                  <td><span class="badge b-ok">bought</span></td>
                  <td><?= ih($r['buy_at'] ?: '—') ?><br><span style="color:#9ca3af;font-size:11px">bought</span></td>
                <?php else: ?>
                  <td>
                    <select name="reg[<?= ih($d) ?>]" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;max-width:130px">
                      <option value="">—</option>
                      <?php foreach ($regs as $rn): ?>
                        <option value="<?= ih($rn) ?>" <?= $r['buy_registrar'] === $rn ? 'selected' : '' ?>><?= ih($rn) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td style="white-space:nowrap">
                    <?php
                    // Buyable = checked available, a registrar chosen, and that registrar
                    // able to complete a purchase from here. Anything else shows why not,
                    // rather than a button that fails when pressed.
                    $bReg  = (string) $r['buy_registrar'];
                    $bDef  = $bReg !== '' ? infra_registrar_type_def($bReg) : [];
                    $bOk   = $r['ready_to_buy'] === 'yes' && $bReg !== '' && !empty($bDef['buy_wired']);
                    $bCost = $r['avail_price'] !== '' ? '$' . $r['avail_price'] : 'the quoted price';
                    // Every registrar buys a 1-year term. Namecheap gets a warning in the
                    // confirm rather than a longer term: its auto-renew cannot be set over
                    // the API, so this one really does need a dashboard visit.
                    $bWarn = strtolower($bReg) === 'namecheap'
                        ? '\n\nNote: Namecheap cannot set auto-renew over its API, so this expires in 1 year and will not renew itself.'
                        : '';
                    ?>
                    <?php if ($bOk): ?>
                      <button class="btn" style="background:#991b1b;padding:3px 11px;font-size:11px"
                              type="submit" formaction="actions/domain_buy.php"
                              name="domain" value="<?= ih($d) ?>"
                              onclick="return confirm('Buy <?= ih($d) ?>\n\nat <?= ih($bReg) ?> for <?= ih($bCost) ?>, 1 year.<?= $bWarn ?>\n\nThis spends real money and cannot be undone.');">Buy</button>
                    <?php elseif ($r['ready_to_buy'] !== 'yes'): ?>
                      <span style="color:#9ca3af;font-size:11px">not ready</span>
                    <?php elseif ($bReg === ''): ?>
                      <span style="color:#9ca3af;font-size:11px">pick registrar</span>
                    <?php else: ?>
                      <span style="color:#9ca3af;font-size:11px" title="<?= ih($bDef['label'] ?? $bReg) ?> cannot buy from here">no adapter</span>
                    <?php endif; ?>
                  </td>
                  <td><input type="date" name="buy[<?= ih($d) ?>]" value="<?= ih($r['buy_at']) ?>" style="padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px"></td>
                <?php endif; ?>
                <td><?= infra_own_cell($r) ?></td>
                <td><?= infra_cf_cell($r['cf'], $hasCf) ?></td>
                <td><?= $vps ?></td>
                <td><?= infra_state_cell($r['state']) ?></td>
                <td><?= infra_drift_cell($r['drift']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn" type="submit" name="action" value="save_edits">Save registrar &amp; date edits</button>
            <span style="color:#6b7280;font-size:12px">Saves every Register / Buy date field on this page. Ticks are only needed for the bulk actions above.</span>
          </div>

          <?php if ($pages > 1): ?>
          <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
            <?php if ($page > 1): ?><a class="btn sec" href="index.php?<?= ih($baseQs) ?>&page=<?= $page - 1 ?>">&larr; Prev</a><?php endif; ?>
            <span style="color:#6b7280;font-size:13px">Showing <?= ($page - 1) * $perPage + 1 ?>&ndash;<?= min($total, $page * $perPage) ?> of <?= $total ?></span>
            <?php if ($page < $pages): ?><a class="btn sec" href="index.php?<?= ih($baseQs) ?>&page=<?= $page + 1 ?>">Next &rarr;</a><?php endif; ?>
          </div>
          <?php endif; ?>
        </form>
        <script>
        (function () {
          var all = document.getElementById('selAll'),
              boxes = Array.prototype.slice.call(document.querySelectorAll('.selBox')),
              out = document.getElementById('selCount');
          function tally() {
            var n = boxes.filter(function (b) { return b.checked; }).length;
            out.textContent = n + ' selected';
          }
          all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; }); tally();
          });
          boxes.forEach(function (b) { b.addEventListener('change', tally); });
          tally();
        })();
        </script>
        <?php endif; ?>
      </div>
    </div>
    <?php infra_footer(); exit;
