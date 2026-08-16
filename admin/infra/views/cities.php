<?php
/* ============================= CITIES / NICHE =============================
 * Pick the cities a niche will target, score them, choose an area code, and
 * point a domain at each one. Everything on this page is a PLAN — re-pointable,
 * re-scorable, deletable. Nothing here spends money.
 */
    require_once __DIR__ . '/../lib/cities.php';
    require_once __DIR__ . '/../lib/keywords.php';
    require_once __DIR__ . '/../lib/serp.php';
    infra_header('cities');

    $niches = infra_niches();
    $niche  = infra_niche_slug($_GET['niche'] ?? '');
    if ($niche === '' || !isset($niches[$niche])) $niche = (string) array_key_first($niches);
    $counts = infra_cn_counts();
    $total  = infra_cities_count();

    // "selected" = the cities already picked for this niche; "pool" = browse all.
    $show   = ($_GET['show'] ?? 'selected') === 'pool' ? 'pool' : 'selected';
    $q      = trim((string) ($_GET['q'] ?? ''));
    $stateF = strtoupper(trim((string) ($_GET['state'] ?? '')));
    $minPop = (int) ($_GET['min_pop'] ?? 0);
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $per    = 100;

    // Round-trip the filters through every form so a save comes back where you were.
    $sortQ = (string) ($_GET['sort'] ?? '');
    $dirQ  = (string) ($_GET['dir'] ?? '');
    $qs = http_build_query(array_filter([
        'show' => $show !== 'selected' ? $show : null, 'q' => $q ?: null,
        'state' => $stateF ?: null, 'min_pop' => $minPop ?: null, 'page' => $page > 1 ? $page : null,
        'sort' => $sortQ ?: null, 'dir' => $dirQ ?: null,
    ]));
    $selfUrl = fn(array $over = []) => 'index.php?view=cities&' . http_build_query(array_filter(array_merge([
        'niche' => $niche, 'show' => $show, 'q' => $q, 'state' => $stateF,
        'min_pop' => $minPop ?: null, 'page' => $page > 1 ? $page : null,
        'sort' => $sortQ ?: null, 'dir' => $dirQ ?: null,
    ], $over), fn($v) => $v !== null && $v !== ''));

    // Domains this niche could use: owned, right niche, not already on another city.
    $taken = infra_cn_domains_taken();
    $avail = [];
    foreach (infra_state_all_domains() as $d => $r) {
        if (($r['niche'] ?? '') !== $niche) continue;
        $avail[$d] = ['owned' => ($r['owned'] ?? '') === 'yes', 'taken' => $taken[$d] ?? ''];
    }
    ksort($avail);
    $freeCount = count(array_filter($avail, fn($a) => $a['owned'] && $a['taken'] === ''));
    ?>

    <!-- niche tabs -->
    <div class="ic-card" style="margin-bottom:14px"><div class="body" style="padding:10px 14px">
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <?php foreach ($niches as $s => $n): $c = $counts[$s] ?? ['selected' => 0, 'linked' => 0]; ?>
          <a href="index.php?view=cities&niche=<?= urlencode($s) ?>"
             class="btn <?= $s === $niche ? '' : 'sec' ?>" style="padding:4px 12px;font-size:13px">
            <?= ih($n['label']) ?>
            <span style="opacity:.7;font-size:11px"><?= (int) $c['selected'] ?>&nbsp;cities · <?= (int) $c['linked'] ?>&nbsp;linked</span>
          </a>
        <?php endforeach; ?>
        <details style="margin-left:auto"><summary style="cursor:pointer;font-size:12px;color:#6b7280">Manage niches</summary>
          <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
            <form method="post" action="actions/cities_save.php" style="display:flex;gap:6px;align-items:flex-end">
              <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
              <input type="hidden" name="action" value="niche_add">
              <label style="font-size:12px">Add a niche<br><input name="new_niche" placeholder="roofing" required style="padding:5px 8px"></label>
              <label style="font-size:12px">Label<br><input name="new_label" placeholder="Roofing" style="padding:5px 8px"></label>
              <button class="btn" type="submit">Add</button>
            </form>
            <form method="post" action="actions/cities_save.php" style="display:flex;gap:6px;align-items:flex-end"
                  onsubmit="return confirm('Remove this niche and every city selection under it? Cities and domains are not touched.')">
              <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
              <input type="hidden" name="action" value="niche_delete">
              <label style="font-size:12px">Remove<br>
                <select name="slug" style="padding:5px 8px">
                  <?php foreach ($niches as $s => $n): ?><option value="<?= ih($s) ?>"><?= ih($n['label']) ?></option><?php endforeach; ?>
                </select></label>
              <button class="btn sec" type="submit">Remove</button>
            </form>
          </div>
        </details>
      </div>
    </div></div>

    <?php if (!$total): ?>
      <div class="ic-card"><div class="body">
        <p style="margin-top:0">The city list has not been loaded yet. It comes from
          <code>admin/infra/data/us_cities.csv</code> — the 10,000 largest US cities by
          2024 Census population, ranked 1 (largest) down, with suggested area codes.</p>
        <form method="post" action="actions/cities_save.php">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="action" value="seed">
          <input type="hidden" name="niche" value="<?= ih($niche) ?>">
          <button class="btn" type="submit">Load the city list</button>
        </form>
      </div></div>
      <?php infra_footer(); exit;
    endif; ?>

    <?php
    $kwOn    = infra_kw_configured();
    $tpl     = $niches[$niche]['template'] ?? '';
    $primary = infra_niche_source($niche);
    $provs   = infra_kw_types();
    $groups  = infra_city_name_groups($tpl);

    /** What the first page looks like: open / tight / crowded, detail on hover. */
    $serpCell = function (array $r): string {
        if (($r['serp_at'] ?? '') === '') return '<span style="color:#d1d5db">&mdash;</span>';
        $d = ['map' => $r['serp_map'] ?? '', 'ads' => $r['serp_ads'] ?? '', 'dirs' => $r['serp_dirs'] ?? '',
              'first' => $r['serp_first'] ?? ''];
        $v = infra_serp_verdict($d);
        $cls = $v === 'open' ? 'b-ok' : ($v === 'tight' ? 'b-warn' : 'b-err');
        $tip = ($d['map'] === 'yes' ? 'Map pack present' : 'No map pack')
             . ' \u{b7} ' . (int) $d['ads'] . ' ads above organic'
             . ' \u{b7} ' . (int) $d['dirs'] . ' directories in the results'
             . ($d['first'] !== '' ? ' \u{b7} first organic at position ' . (int) $d['first'] : '')
             . (($r['serp_top'] ?? '') !== '' ? ' \u{b7} top: ' . $r['serp_top'] : '')
             . ' \u{b7} checked ' . substr((string) $r['serp_at'], 0, 10);
        return '<span class="badge ' . $cls . '" title="' . ih($tip) . '">' . ih($v) . ' ' . (int) $r['serp_score'] . '</span>';
    };

    $sort = (string) ($_GET['sort'] ?? 'rank');
    $dir  = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    if (!isset(infra_cities_sorts()[$sort])) $sort = 'rank';

    /** A sortable column heading. Clicking the active one flips direction. */
    $th = function (string $key, string $label, string $style = '', string $tip = '') use (&$selfUrl, $sort, $dir): string {
        $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        // Population, volume, CPC and score are most useful biggest-first, so
        // that is what the first click on them gives you.
        if ($sort !== $key && in_array($key, ['pop', 'score'], true) === false
            && preg_match('/_(volume|cpc)$/', $key)) $next = 'desc';
        if ($sort !== $key && in_array($key, ['pop', 'score'], true)) $next = 'desc';
        $arrow = $sort === $key ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '';
        return '<th style="' . $style . '"' . ($tip ? ' title="' . ih($tip) . '"' : '') . '>'
             . '<a href="' . ih($selfUrl(['sort' => $key, 'dir' => $next, 'page' => null])) . '"'
             . ' style="color:inherit;text-decoration:none' . ($sort === $key ? ';color:#2563eb' : '') . '">'
             . $label . $arrow . '</a></th>';
    };

    /** Badge for a city whose keyword is shared with a same-named city elsewhere. */
    $sharedBadge = function (array $city) use ($groups): string {
        $s = infra_city_shared($city, $groups);
        if (!$s) return '';
        $names = implode(', ', array_map(fn($c) => $c['city'] . ' ' . $c['ss'], array_slice($s['others'], 0, 4)));
        if (count($s['others']) > 4) $names .= ' and ' . (count($s['others']) - 4) . ' more';
        $tip = $s['is_primary']
            ? 'Shares this keyword with ' . $names . '. As the largest, these numbers are most likely about this one.'
            : 'Shares this keyword with ' . $names . '. The figures probably describe '
              . $s['primary']['city'] . ' ' . $s['primary']['ss'] . ' (population '
              . number_format((int) $s['primary']['population']) . '), not this city.';
        return ' <span class="badge ' . ($s['is_primary'] ? 'b-mut' : 'b-warn') . '" title="' . ih($tip) . '">'
             . ($s['is_primary'] ? 'shared name' : 'shared &mdash; not this one') . '</span>';
    };
    ?>
    <div class="ic-note">
      Pick the cities <strong><?= ih($niches[$niche]['label']) ?></strong> will target, score them, choose an
      area code, then point a domain at each. This is a <strong>plan</strong> — re-point a domain any time;
      nothing here buys anything. <strong><?= $freeCount ?></strong> owned <?= ih($niche) ?> domain<?= $freeCount === 1 ? '' : 's' ?>
      not yet assigned to a city. Phone numbers are typed in for now.
    </div>

    <!-- keyword + fetch -->
    <div class="ic-card" style="margin-bottom:14px"><div class="body" style="padding:10px 14px;display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <form method="post" action="actions/cities_save.php" style="display:flex;gap:6px;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="template">
        <input type="hidden" name="niche" value="<?= ih($niche) ?>">
        <input type="hidden" name="qs" value="<?= ih($qs) ?>">
        <label style="font-size:12px">Keyword looked up for each city<br>
          <input name="template" value="<?= ih($tpl) ?>" style="padding:5px 8px;width:280px" placeholder="appliance repair {city}"></label>
        <button class="btn sec" type="submit">Save keyword</button>
      </form>
      <div style="font-size:12px;color:#6b7280;max-width:300px">
        <code>{city}</code>, <code>{state}</code> and <code>{ss}</code> are replaced per row.
        <?php if ($tpl !== ''): ?>Rank 1 becomes “<strong><?= ih(infra_kw_phrase($tpl, ['city' => 'New York', 'state' => 'New York', 'ss' => 'NY'])) ?></strong>”.<?php endif; ?>
      </div>
      <?php if (count(infra_kw_types()) > 1): ?>
      <form method="post" action="actions/cities_save.php" style="display:flex;gap:6px;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="source">
        <input type="hidden" name="niche" value="<?= ih($niche) ?>">
        <input type="hidden" name="qs" value="<?= ih($qs) ?>">
        <label style="font-size:12px" title="Which provider the Score is computed from. The two do not share a scale, so a niche commits to one.">Score from<br>
          <select name="source" style="padding:5px 8px">
            <?php foreach (infra_kw_types() as $t => $m): ?>
              <option value="<?= ih($t) ?>" <?= $t === $primary ? 'selected' : '' ?>><?= ih($m['label']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <button class="btn sec" type="submit">Apply &amp; re-score</button>
      </form>
      <?php endif; ?>
      <?php if ($kwOn): ?>
        <form method="post" action="actions/cities_save.php" style="margin-left:auto;display:flex;gap:6px;align-items:flex-end">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="action" value="fetch">
          <input type="hidden" name="niche" value="<?= ih($niche) ?>">
          <input type="hidden" name="qs" value="<?= ih($qs) ?>">
          <?php if (count($kwOn) > 1): ?>
            <label style="font-size:12px">Source<br>
              <select name="provider" style="padding:5px 8px">
                <?php foreach ($kwOn as $t => $m): ?><option value="<?= ih($t) ?>"><?= ih($m['label']) ?></option><?php endforeach; ?>
              </select></label>
          <?php else: ?>
            <input type="hidden" name="provider" value="<?= ih((string) array_key_first($kwOn)) ?>">
          <?php endif; ?>
          <label style="font-size:12px">Re-fetch older than<br>
            <select name="stale_days" style="padding:5px 8px">
              <option value="0">never fetched</option>
              <option value="30" selected>30 days</option>
              <option value="90">90 days</option>
              <option value="-1">everything</option>
            </select></label>
          <button class="btn" type="submit">Fetch<?= count($kwOn) > 1 ? '' : ' from ' . ih($kwOn[array_key_first($kwOn)]['label']) ?></button>
        </form>
      <?php endif; ?>
    </div></div>

    <!-- filters + mode -->
    <form method="get" class="ic-card" style="margin-bottom:14px"><div class="body" style="padding:10px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="view" value="cities">
      <input type="hidden" name="niche" value="<?= ih($niche) ?>">
      <label style="font-size:12px">Show<br>
        <select name="show" style="padding:5px 8px">
          <option value="selected" <?= $show === 'selected' ? 'selected' : '' ?>>Selected cities</option>
          <option value="pool"     <?= $show === 'pool' ? 'selected' : '' ?>>All <?= number_format($total) ?> cities</option>
        </select></label>
      <label style="font-size:12px">Search<br><input name="q" value="<?= ih($q) ?>" placeholder="city or state" style="padding:5px 8px"></label>
      <label style="font-size:12px">State<br>
        <select name="state" style="padding:5px 8px"><option value="">any</option>
          <?php foreach (infra_states_list() as $s): ?>
            <option value="<?= ih($s) ?>" <?= $s === $stateF ? 'selected' : '' ?>><?= ih($s) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label style="font-size:12px">Min population<br><input name="min_pop" type="number" min="0" step="1000" value="<?= $minPop ?: '' ?>" placeholder="any" style="padding:5px 8px;width:110px"></label>
      <button class="btn sec" type="submit">Apply</button>
      <?php if ($q || $stateF || $minPop): ?><a class="btn sec" href="<?= ih($selfUrl(['q' => null, 'state' => null, 'min_pop' => null, 'page' => null])) ?>">Clear</a><?php endif; ?>
    </div></form>

    <?php
    /* ---------- SELECTED: the editable plan ---------- */
    if ($show === 'selected'):
        $rows = infra_cn_selected($niche, $sort, $dir);
        if ($q !== '' || $stateF !== '' || $minPop > 0) {
            $rows = array_values(array_filter($rows, function ($r) use ($q, $stateF, $minPop) {
                if ($stateF !== '' && $r['ss'] !== $stateF) return false;
                if ($minPop > 0 && (int) $r['population'] < $minPop) return false;
                if ($q !== '' && stripos($r['city'] . ' ' . $r['state'] . ' ' . $r['ss'], $q) === false) return false;
                return true;
            }));
        }
    ?>
    <form method="post" action="actions/cities_save.php">
      <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
      <input type="hidden" name="niche" value="<?= ih($niche) ?>">
      <input type="hidden" name="qs" value="<?= ih($qs) ?>">
      <div class="ic-card">
        <h2><?= count($rows) ?> selected <?= ih($niches[$niche]['label']) ?> cit<?= count($rows) === 1 ? 'y' : 'ies' ?></h2>
        <div class="body">
        <?php if (!$rows): ?>
          <div class="ic-empty">No cities picked for this niche yet — switch <em>Show</em> to
            <a href="<?= ih($selfUrl(['show' => 'pool'])) ?>">all cities</a> and tick the ones you want.</div>
        <?php else: ?>
          <table><thead>
          <tr>
            <th colspan="5"></th>
            <?php foreach ($provs as $t => $m): ?>
              <th colspan="3" style="text-align:center;border-bottom:2px solid <?= $t === $primary ? '#2563eb' : '#e5e7eb' ?>">
                <?= ih($m['label']) ?><?= $t === $primary ? ' <span style="font-weight:400;font-size:10px;color:#2563eb">scoring</span>' : '' ?>
              </th>
            <?php endforeach; ?>
            <th colspan="4"></th>
          </tr>
          <tr>
            <th style="width:24px"></th>
            <?= $th('rank','#','width:46px') ?><?= $th('city','City') ?><?= $th('ss','St','width:34px') ?>
            <?= $th('pop','Pop','width:70px') ?>
            <?php foreach ($provs as $t => $m): ?>
              <?= $th($t.'_volume','Vol','width:58px','Monthly searches') ?>
              <?= $th($t.'_kd','KD','width:42px',"Keyword difficulty on ".$m['label']."'s own 0-100 scale") ?>
              <?= $th($t.'_cpc','CPC','width:58px','Cost per click, US dollars') ?>
            <?php endforeach; ?>
            <?= $th('score','Score','width:58px') ?><?= $th('serp','SERP','width:96px','How open the first page is: map pack, ads, directories') ?>
            <th style="width:96px">Area code</th><th style="width:118px">Phone</th><th>Domain</th>
          </tr></thead><tbody>
          <?php foreach ($rows as $r): $id = $r['id']; ?>
            <tr>
              <td><input type="checkbox" name="city_id[]" value="<?= ih($id) ?>"></td>
              <td style="color:#9ca3af"><?= (int) $r['rank'] ?></td>
              <td><strong><?= ih($r['city']) ?></strong><?= $sharedBadge($r) ?></td>
              <td><?= ih($r['ss']) ?></td>
              <td style="text-align:right;color:#6b7280"><?= number_format((int) $r['population']) ?></td>
              <?php foreach ($provs as $t => $pm):
                  $mm  = infra_cn_metrics($r, $t);
                  $dim = $t === $primary ? '' : 'color:#9ca3af;';
                  $tip = $mm['at'] !== '' ? ih($pm['label']) . ', fetched ' . ih(substr($mm['at'], 0, 10)) : 'not fetched from ' . ih($pm['label']); ?>
                <td style="text-align:right;<?= $dim ?>" title="<?= $tip ?>"><?= $mm['volume'] !== '' ? number_format((int) $mm['volume']) : '<span style="color:#d1d5db">—</span>' ?></td>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['kd'] !== '' ? ih($mm['kd']) : '<span style="color:#d1d5db">—</span>' ?></td>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['cpc'] !== '' ? '$' . ih($mm['cpc']) : '<span style="color:#d1d5db">—</span>' ?></td>
              <?php endforeach; ?>
              <td><input name="row[<?= ih($id) ?>][score]" value="<?= ih($r['score']) ?>" type="number" min="1" max="10" step="1"
                         style="width:52px;padding:3px 6px<?= ($r['score_src'] ?? '') === 'auto' ? ';background:#f8fafc' : '' ?>"
                         title="<?= ($r['score_src'] ?? '') === 'hand' ? 'Set by hand — a fetch will not overwrite it' : (($r['score_src'] ?? '') === 'auto' ? 'Computed from the metrics' : 'Not scored yet') ?>"></td>
              <td><?= $serpCell($r) ?></td>
              <td>
                <input name="row[<?= ih($id) ?>][area_code]" value="<?= ih($r['area_code']) ?>" list="ac-<?= ih($id) ?>"
                       placeholder="<?= ih(implode('/', array_slice(infra_city_area_codes($r), 0, 2))) ?>" style="width:96px;padding:3px 6px">
                <datalist id="ac-<?= ih($id) ?>">
                  <?php foreach (infra_city_area_codes($r) as $c): ?><option value="<?= ih($c) ?>"><?php endforeach; ?>
                </datalist>
              </td>
              <td><input name="row[<?= ih($id) ?>][phone]" value="<?= ih($r['phone']) ?>" placeholder="—" style="width:128px;padding:3px 6px"></td>
              <td>
                <select name="row[<?= ih($id) ?>][domain]" style="padding:3px 6px;max-width:250px">
                  <option value="">— none —</option>
                  <?php foreach ($avail as $d => $a):
                      $mine = ($d === strtolower($r['domain'] ?? ''));
                      if ($a['taken'] !== '' && !$mine) continue; ?>
                    <option value="<?= ih($d) ?>" <?= $mine ? 'selected' : '' ?>>
                      <?= ih($d) ?><?= $a['owned'] ? '' : ' (not owned yet)' ?></option>
                  <?php endforeach; ?>
                  <?php if (($r['domain'] ?? '') !== '' && !isset($avail[strtolower($r['domain'])])): ?>
                    <option value="<?= ih($r['domain']) ?>" selected><?= ih($r['domain']) ?> (other niche)</option>
                  <?php endif; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
          <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
            <button class="btn" type="submit" name="action" value="save">Save changes</button>
            <button class="btn sec" type="submit" name="action" value="unselect"
                    onclick="return confirm('Remove the ticked cities from this niche? The domain link is released; the Ahrefs figure and score are kept.')">Remove ticked</button>
            <span style="font-size:12px;color:#6b7280">A score you type is recorded as set by hand.</span>
          </div>
        <?php endif; ?>
        </div>
      </div>
    </form>

    <?php else:
    /* ---------- POOL: browse and pick ---------- */
        $browse = infra_cities_browse([
            'q' => $q, 'state' => $stateF, 'min_pop' => $minPop,
            'limit' => $per, 'offset' => ($page - 1) * $per,
            'niche' => $niche, 'sort' => $sort, 'dir' => $dir,
        ]);
        $mine  = infra_cn_all($niche);
        $pages = max(1, (int) ceil($browse['total'] / $per));
    ?>
    <form method="post" action="actions/cities_save.php">
      <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
      <input type="hidden" name="niche" value="<?= ih($niche) ?>">
      <input type="hidden" name="qs" value="<?= ih($qs) ?>">
      <div class="ic-card">
        <?php
        $sortNames = ['rank' => 'population', 'city' => 'name', 'ss' => 'state', 'pop' => 'population', 'score' => 'score'];
        foreach ($provs as $t => $m) {
            $sortNames[$t . '_volume'] = $m['label'] . ' volume';
            $sortNames[$t . '_kd']     = $m['label'] . ' difficulty';
            $sortNames[$t . '_cpc']    = $m['label'] . ' CPC';
        }
        ?>
        <h2><?= number_format($browse['total']) ?> cities <span style="color:#9ca3af;font-weight:400;font-size:13px">&mdash; page <?= $page ?> of <?= $pages ?>, sorted by <?= ih($sortNames[$sort] ?? $sort) ?><?= $dir === 'desc' ? ', highest first' : '' ?></span></h2>
        <div class="body">
          <table><thead>
          <tr>
            <th colspan="5"></th>
            <?php foreach ($provs as $t => $m): ?>
              <th colspan="3" style="text-align:center;border-bottom:2px solid <?= $t === $primary ? '#2563eb' : '#e5e7eb' ?>">
                <?= ih($m['label']) ?><?= $t === $primary ? ' <span style="font-weight:400;font-size:10px;color:#2563eb">scoring</span>' : '' ?>
              </th>
            <?php endforeach; ?>
            <th colspan="3"></th>
          </tr>
          <tr>
            <th style="width:24px"></th>
            <?= $th('rank','#','width:46px') ?><?= $th('city','City') ?><?= $th('ss','St','width:34px') ?>
            <?= $th('pop','Population','width:78px') ?>
            <?php foreach ($provs as $t => $m): ?>
              <?= $th($t.'_volume','Vol','width:58px') ?><?= $th($t.'_kd','KD','width:42px') ?><?= $th($t.'_cpc','CPC','width:58px') ?>
            <?php endforeach; ?>
            <?= $th('score','Score','width:52px') ?><?= $th('serp','SERP','width:96px','How open the first page is: map pack, ads, directories') ?>
            <th>Area codes</th><th style="width:84px">In niche</th>
          </tr></thead><tbody>
          <?php foreach ($browse['rows'] as $r):
              // A row in city_niche means "we know something about this city", NOT
              // that it is picked — fetching metrics creates one. Reading it as
              // picked put a "selected" badge on every researched city and took
              // away its checkbox, so the ones you had just looked up were the ones
              // you could no longer choose.
              $picked = (($mine[$r['id']]['selected'] ?? '') === 'yes'); ?>
            <tr<?= $picked ? ' style="background:#f8fafc"' : '' ?>>
              <td><?php if (!$picked): ?><input type="checkbox" name="city_id[]" value="<?= ih($r['id']) ?>"><?php endif; ?></td>
              <td style="color:#9ca3af"><?= (int) $r['rank'] ?></td>
              <td><strong><?= ih($r['city']) ?></strong> <span style="color:#9ca3af;font-size:12px"><?= ih($r['state']) ?></span><?= $sharedBadge($r) ?></td>
              <td><?= ih($r['ss']) ?></td>
              <td style="text-align:right;color:#6b7280"><?= number_format((int) $r['population']) ?></td>
              <?php $m = $mine[$r['id']] ?? [];
              foreach ($provs as $t => $pm):
                  $mm  = infra_cn_metrics($m, $t);
                  $dim = $t === $primary ? '' : 'color:#9ca3af;'; ?>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['volume'] !== '' ? number_format((int) $mm['volume']) : '<span style="color:#d1d5db">—</span>' ?></td>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['kd'] !== '' ? ih($mm['kd']) : '<span style="color:#d1d5db">—</span>' ?></td>
                <td style="text-align:right;<?= $dim ?>"><?= $mm['cpc'] !== '' ? '$' . ih($mm['cpc']) : '<span style="color:#d1d5db">—</span>' ?></td>
              <?php endforeach; ?>
              <td style="text-align:right"><?= ($m['score'] ?? '') !== '' ? '<strong>' . ih($m['score']) . '</strong>' : '<span style="color:#d1d5db">—</span>' ?></td>
              <td><?= $serpCell($m ?: []) ?></td>
              <td style="font-size:12px">
                <?= ih(implode(' · ', infra_city_area_codes($r))) ?: '<span style="color:#9ca3af">none known</span>' ?>
                <?php if (($r['ac_source'] ?? '') === 'near'): ?><span class="badge b-mut" title="Borrowed from nearby cities in the same state — check before using">nearby</span><?php endif; ?>
              </td>
              <td><?= $picked ? '<span class="badge b-ok">selected</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
          <div style="display:flex;gap:8px;margin-top:12px;align-items:center;flex-wrap:wrap">
            <?php if ($kwOn): ?>
              <!-- The filter, carried so the sweep covers every match rather than
                   the 100 rows that happen to be on screen. -->
              <input type="hidden" name="f_q" value="<?= ih($q) ?>">
              <input type="hidden" name="f_state" value="<?= ih($stateF) ?>">
              <input type="hidden" name="f_min_pop" value="<?= $minPop ?: '' ?>">
              <button class="btn" type="submit" name="action" value="fetch"
                      onclick="this.form.scope.value='filter'"
                      title="Every city matching the filter above, not just this page">
                Fetch all <?= number_format($browse['total']) ?> matching
              </button>
              <input type="hidden" name="scope" value="">
              <button class="btn sec" type="submit" name="action" value="fetch"
                      onclick="this.form.scope.value=''">Fetch ticked only</button>
              <?php if (count($kwOn) > 1): ?>
                <select name="provider" style="padding:5px 8px;font-size:13px">
                  <?php foreach ($kwOn as $t => $m): ?><option value="<?= ih($t) ?>"><?= ih($m['label']) ?></option><?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="hidden" name="provider" value="<?= ih((string) array_key_first($kwOn)) ?>">
              <?php endif; ?>
              <select name="stale_days" style="padding:5px 8px;font-size:13px" title="Which cities count as needing a fetch">
                <option value="0">never fetched</option>
                <option value="30" selected>fetched over 30 days ago</option>
                <option value="-1">everything, again</option>
              </select>
            <?php endif; ?>
            <?php if (isset($kwOn['dataforseo'])): ?>
              <button class="btn sec" type="submit" name="action" value="serp"
                      title="Reads the live first page for each city: map pack, ads above organic, directories holding the slots. About $0.002 a city.">SERP check top 50</button>
              <input type="hidden" name="serp_limit" value="50">
            <?php endif; ?>
            <button class="btn <?= $kwOn ? 'sec' : '' ?>" type="submit" name="action" value="select">Add ticked to <?= ih($niches[$niche]['label']) ?></button>
            <?php if ($page > 1): ?><a class="btn sec" href="<?= ih($selfUrl(['page' => $page - 1])) ?>">&larr; Prev</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="btn sec" href="<?= ih($selfUrl(['page' => $page + 1])) ?>">Next &rarr;</a><?php endif; ?>
            <span style="font-size:12px;color:#6b7280">Fetching does not select a city — look the numbers up first, pick after.
              Ticks are lost when you change page.</span>
          </div>
        </div>
      </div>
    </form>
    <?php endif; ?>

    <?php
    // Name every provider and its state in the summary. Collapsing the panel the
    // moment one provider connects hid the one still waiting to be set up.
    $kwBits = [];
    foreach (infra_kw_types() as $t => $m) {
        $kwBits[] = ih($m['label']) . (isset($kwOn[$t]) ? ' connected' : ' <strong>not connected</strong>');
    }
    ?>
    <details style="margin-top:14px" <?= $kwOn ? '' : 'open' ?>>
      <summary style="cursor:pointer;font-size:12px;color:#6b7280">Keyword providers — <?= implode(' · ', $kwBits) ?> <span style="color:#2563eb">(add or change credentials)</span></summary>
      <div class="ic-card" style="margin-top:8px"><div class="body">
        <?php foreach (infra_kw_types() as $type => $meta): $stored = infra_kw_provider($type); $on = isset($kwOn[$type]); ?>
          <h3 style="margin:18px 0 8px;font-size:14px">
            <?= ih($meta['label']) ?>
            <span class="badge <?= $on ? 'b-ok' : 'b-mut' ?>"><?= $on ? 'connected' : 'not connected' ?></span>
          </h3>
          <form method="post" action="actions/cities_save.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="niche" value="<?= ih($niche) ?>">
            <input type="hidden" name="qs" value="<?= ih($qs) ?>">
            <input type="hidden" name="type" value="<?= ih($type) ?>">
            <?php foreach ($meta['fields'] as $f => $spec):
                $has = trim((string) ($stored[$f] ?? '')) !== ''; ?>
              <label style="font-size:12px"><?= ih($spec['label']) ?><br>
                <input name="f[<?= ih($f) ?>]"
                       type="<?= !empty($spec['secret']) ? 'password' : 'text' ?>"
                       value="<?= !empty($spec['secret']) ? '' : ih($stored[$f] ?? ($spec['default'] ?? '')) ?>"
                       placeholder="<?= !empty($spec['secret']) ? ($has ? 'stored — leave blank to keep' : 'paste key') : ih($spec['default'] ?? '') ?>"
                       style="padding:5px 8px;width:<?= !empty($spec['secret']) ? '260' : '110' ?>px"></label>
            <?php endforeach; ?>
            <button class="btn" type="submit" name="action" value="kw_save">Save</button>
            <button class="btn sec" type="submit" name="action" value="kw_test">Test</button>
          </form>
          <div class="ic-note" style="margin-top:10px"><?= $meta['note'] ?></div>
        <?php endforeach; ?>
        <div class="ic-note" style="margin-top:10px">
          Credentials are stored in <code>admin/infra/config/keywords.json</code> — gitignored, <code>0600</code>,
          never printed back into this page. <strong>Test costs nothing at either provider</strong> and reports
          what is <em>left</em> — Ahrefs units, DataForSEO dollars — rather than just "the key works", because a
          key with no quota fails on the day you need it, not the day you test it.
          <br><br><strong>CPC is stored in dollars whichever provider it came from.</strong> Ahrefs returns cents
          and DataForSEO returns dollars; each adapter converts, so the column never mixes units.
          <br><br><strong>Score formula (<?= ih($provs[$primary]['label']) ?>):</strong> <?= ih(infra_kw_score_formula($primary)) ?>
          A score you type overrides it and is never overwritten by a fetch.
        </div>
      </div></div>
    </details>

    <details style="margin-top:14px"><summary style="cursor:pointer;font-size:12px;color:#6b7280">Where this data comes from</summary>
      <div class="ic-note" style="margin-top:8px">
        Population and rank: <strong>US Census sub-est2024</strong> (rank 1 = largest of the 10,000 loaded).
        Area codes: exact city matches from a public area-code database, otherwise borrowed from
        cities nearby <em>in the same state</em> and flagged <span class="badge b-mut">nearby</span> —
        a suggestion to check, not a fact. Rebuild the file with
        <code>python3 admin/infra/data/build_cities.py</code>, then re-load it here.
        <form method="post" action="actions/cities_save.php" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
          <input type="hidden" name="action" value="seed">
          <input type="hidden" name="niche" value="<?= ih($niche) ?>">
          <button class="btn sec" type="submit">Re-load the city list</button>
          <span style="font-size:12px;color:#6b7280">Refreshes populations and area codes. Your selections are keyed by city, not by rank, so they survive.</span>
        </form>
      </div>
    </details>

    <?php infra_footer(); exit;
