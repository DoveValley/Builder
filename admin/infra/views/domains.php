<?php
/* ============================= DOMAINS ============================= */
    infra_header('domains');

    /* Infrastructure hostnames are NOT domains, and counting them as such made the
       headline wrong. box1…box20.q111.xyz, t01…t28.q111.xyz and p.q111.xyz are server
       and test subdomains of ONE domain — 49 rows that inflated "904 DOMAINS" by 49.
       The test is the label count, not a hardcoded q111.xyz: every real fleet name is
       a root domain by standing rule, so anything with a third label is a subdomain. */
    $isInfra = fn(string $d): bool => substr_count($d, '.') >= 2;

    // D.Buy is acquisition-only: begin → ready → owned (plus a failed buy attempt,
    // which still needs a registrar/retry decision made here). Once Bulk provisions a
    // domain — staged/queued/releasing/live/partial/register-failed — it belongs to
    // that pipeline instead, so it drops off this list rather than showing the same
    // row in both tabs with half its columns blank. A domain seen only via a
    // registrar's own list (never loaded here) is kept too — 'unknown' is the
    // earliest stage there is, not a later one. Infra hostnames always pass through.
    $allRows = array_values(array_filter(infra_fleet_domains(), function ($r) use ($isInfra) {
        return $isInfra($r['domain'])
            || in_array($r['state'], INFRA_ACQUISITION_STATUSES, true)
            || $r['state'] === 'unknown';
    }));

    $regs           = infra_registrar_names();
    $buyable        = infra_registrar_buyable();
    $checkers       = infra_registrar_checkers();      // who can answer "is it available?"
    $defaultChecker = infra_default_checker();         // the fastest of them

    // Tiles reflect the WHOLE fleet, not the filtered/paged slice. The acquisition
    // buckets come first because with 400 loaded-but-unbought rows the old
    // Live/Staged/Drift trio said nothing useful.
    // 'regs' splits the Owned figure by which registrar actually holds the domain.
    // One number for 695 domains across seven accounts says nothing about where the
    // renewal bills land; the split is the part worth reading at a glance.
    $blankTally = ['begin' => 0, 'ready' => 0, 'owned' => 0, 'staged' => 0, 'live' => 0, 'drift' => 0, 'failed' => 0, 'all' => 0, 'regs' => []];
    $tally      = $blankTally;
    $infraTally = $blankTally;
    // Same pass, same switch, split by niche as well as totalled — so a niche row
    // and the all-fleet row above it can never disagree about the same domain.
    $nicheTally = [];
    $classify = function (array $r, array &$t): void {
        switch ($r['state']) {
            case 'begin':                                     $t['begin']++;  break;
            case 'ready':                                     $t['ready']++;  break;
            case 'owned':
                $t['owned']++;
                $reg = strtolower(trim((string) ($r['registrar'] ?? ''))) ?: '?';
                $t['regs'][$reg] = ($t['regs'][$reg] ?? 0) + 1;
                break;
            case 'live':                                      $t['live']++;   break;
            case 'buy-failed': case 'register-failed':
            case 'partial':                                   $t['failed']++; break;
            default:                                          $t['staged']++; break;
        }
        if ($r['drift']) $t['drift']++;
        $t['all']++;
    };
    /* Every registrar behind an Owned figure, biggest first. Not a top-3 with a
       "+N more": the whole point is seeing where the renewal bills land, and the four
       it hid were four whole accounts. */
    $ownedRegs = function (array $t): string {
        $r = $t['regs'];
        if (!$r) return '';
        arsort($r);
        $parts = [];
        foreach ($r as $name => $n) $parts[] = ih($name === '?' ? 'unknown' : $name) . ' ' . $n;
        return implode(' · ', $parts);
    };

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

    /* Tally the SEARCHED set, not the whole fleet. Tiles that ignored the search
       described a different population from the table underneath them, so filtering
       to one niche or registrar left the numbers above saying something else entirely.
       Counted after the filter and before paging: the tiles describe the whole result,
       not just the visible page. */
    foreach ($rows as $r) {
        if ($isInfra((string) $r['domain'])) { $classify($r, $infraTally); continue; }
        $classify($r, $tally);
        $n = trim((string) ($r['niche'] ?? '')) ?: '';
        if (!isset($nicheTally[$n])) $nicheTally[$n] = $blankTally;
        $classify($r, $nicheTally[$n]);
    }
    // Biggest niche first; the unset bucket always last, since it is a gap to close
    // rather than a niche to compare against.
    uksort($nicheTally, function ($a, $b) use ($nicheTally) {
        if ($a === '') return 1;
        if ($b === '') return -1;
        return $nicheTally[$b]['all'] <=> $nicheTally[$a]['all'];
    });

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
    <div style="text-align:right;margin-bottom:8px;">
      <a class="btn sec" href="index.php?view=buyqueue">&#128197; Schedule view (D.Own) &rarr;</a>
    </div>
    <div class="ic-tiles">
      <div class="ic-tile">
        <div class="n"><?= $tally['all'] ?></div><div class="l">Domains</div>
        <?php if ($infraTally['all']): ?>
          <?php /* Never a silent exclusion — say what was set aside and why. */ ?>
          <div style="font-size:.66rem;color:#6b7280;line-height:1.35;margin-top:3px;">
            + <?= $infraTally['all'] ?> infra hostnames
          </div>
        <?php endif; ?>
      </div>
      <div class="ic-tile"><div class="n"><?= $tally['begin'] ?></div><div class="l">Begin</div></div>
      <div class="ic-tile"><div class="n"><?= $tally['ready'] ?></div><div class="l">Ready to buy</div></div>
      <div class="ic-tile">
        <div class="n"><?= $tally['owned'] ?></div><div class="l">Owned</div>
        <?php if ($rAll = $ownedRegs($tally)): ?>
          <div style="font-size:.66rem;color:#6b7280;line-height:1.35;margin-top:3px;"><?= $rAll ?></div>
        <?php endif; ?>
      </div>
      <div class="ic-tile"><div class="n"><?= $tally['failed'] ?></div><div class="l">Needs attention</div></div>
    </div>

    <?php /* One row per niche, same five columns as the fleet row above it. */ ?>
    <?php foreach ($nicheTally as $nName => $t): ?>
    <div class="ic-tiles" style="margin-top:6px;">
      <div class="ic-tile" style="background:#f8fafc;">
        <div class="n" style="font-size:1.05rem;line-height:1.9;"><?= $nName === '' ? '<span style="color:#9ca3af">no niche</span>' : ih(ucfirst($nName)) ?></div>
        <div class="l"><?= $t['all'] ?> domains</div>
      </div>
      <div class="ic-tile"><div class="n"><?= $t['begin'] ?></div><div class="l">Begin</div></div>
      <div class="ic-tile"><div class="n"><?= $t['ready'] ?></div><div class="l">Ready to buy</div></div>
      <div class="ic-tile">
        <div class="n"><?= $t['owned'] ?></div><div class="l">Owned</div>
        <?php if ($rN = $ownedRegs($t)): ?>
          <div style="font-size:.66rem;color:#6b7280;line-height:1.35;margin-top:3px;"><?= $rN ?></div>
        <?php endif; ?>
      </div>
      <div class="ic-tile"><div class="n"><?= $t['failed'] ?></div><div class="l">Needs attention</div></div>
    </div>
    <?php endforeach; ?>

    <?php /* Infra hostnames get their own row rather than vanishing from the page —
             they still need provisioning and drift tracking, they just are not domains. */ ?>
    <?php if ($infraTally['all']): $t = $infraTally; ?>
    <div class="ic-tiles" style="margin-top:6px;opacity:.75;">
      <div class="ic-tile" style="background:#f8fafc;">
        <div class="n" style="font-size:1.05rem;line-height:1.9;color:#6b7280;">Infra</div>
        <div class="l"><?= $t['all'] ?> hostnames &mdash; not counted above</div>
      </div>
      <div class="ic-tile"><div class="n"><?= $t['begin'] ?></div><div class="l">Begin</div></div>
      <div class="ic-tile"><div class="n"><?= $t['ready'] ?></div><div class="l">Ready to buy</div></div>
      <div class="ic-tile"><div class="n"><?= $t['owned'] ?></div><div class="l">Owned</div></div>
      <div class="ic-tile"><div class="n"><?= $t['staged'] ?></div><div class="l">Staged</div></div>
      <div class="ic-tile"><div class="n"><?= $t['live'] ?></div><div class="l">Live</div></div>
      <div class="ic-tile"><div class="n"><?= $t['drift'] + $t['failed'] ?></div><div class="l">Needs attention</div></div>
    </div>
    <?php endif; ?>

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

    <!-- The old "Discover / Refresh" link lived here. It did the same job as the bar
         at the top of the page, but inline: a plain ?refresh=1 that swept twenty
         boxes and printed nothing for a measured 61 seconds. Two buttons for one
         job, and the worse one was the prominent one. -->
    <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
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
            <?php /* Taken names do not belong in a buy queue, but deleting them loses the
                     fact that they were checked — so D.Finder can propose them again next
                     week. This hands them back with that verdict attached. */ ?>
            <button class="btn sec" type="submit" name="action" value="to_dfinder"
                    title="Adds each as a D.Finder candidate marked Not available, then removes it here"
                    onclick="return confirm('Move the ticked TAKEN domains to D.Finder as “Not available” and remove them from this table?\n\nOnly rows that were checked, came back taken and were never bought are moved — anything else is skipped and reported.')">&rarr; D.Finder (not available)</button>
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
            </tr></thead>
            <tbody>
            <?php foreach ($slice as $r):
              $d = $r['domain'];
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
