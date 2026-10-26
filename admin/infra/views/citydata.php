<?php
/**
 * infra/views/citydata.php — "City Data" browse/lookup: Census ACS income and
 * housing facts for any city already in `cities`.
 *
 * Deliberately separate from City Research: this is a plain lookup over
 * city_data (lib/census.php), not scoped to any niche or research run. Linked
 * from above the niche-tab row on the Research page, not from the main nav —
 * see that page for why (income/homeownership don't change based on niche).
 */
require_once __DIR__ . '/../lib/census.php';
infra_header('research');

$q     = trim((string) ($_GET['q'] ?? ''));
$state = strtoupper(trim((string) ($_GET['state'] ?? '')));
$coverage = infra_census_coverage();

$rows = [];
if ($q !== '' || $state !== '') {
    $db  = infra_state_db();
    $sql = 'SELECT c.id, c.city, c.state, c.ss, c.population,
                   d.income_median, d.homeownership_pct, d.home_value_median,
                   d.year_built_median, d.housing_units_total, d.updated_at
            FROM cities c LEFT JOIN city_data d ON d.city_id = c.id
            WHERE 1=1';
    $params = [];
    if ($q !== '')     { $sql .= ' AND c.city LIKE ?'; $params[] = '%' . $q . '%'; }
    if ($state !== '') { $sql .= ' AND c.ss = ?';      $params[] = $state; }
    $sql .= ' ORDER BY c.population DESC LIMIT 200';
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="ic-card"><div class="body">
  <h2 style="margin-top:0">City Data</h2>
  <p style="font-size:13px;line-height:1.5;color:#374151">
    Census ACS 5-Year facts per city — median household income, homeownership rate, median home
    value, median year structure built, and total housing units. Independent of niche and of any
    City Research run; a plain lookup over the same <code>cities</code> this console already knows.
  </p>
  <p style="font-size:13px;color:#6b7280">
    Coverage: <strong><?= number_format($coverage['have']) ?></strong> of
    <strong><?= number_format($coverage['total']) ?></strong> cities currently have Census data.
    <?php if ($coverage['have'] === 0): ?>
      None yet — run <code>php admin/infra/cron/census_backfill.php</code> once to populate this table.
    <?php endif; ?>
  </p>

  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:14px 0 0">
    <input type="hidden" name="view" value="citydata">
    <label style="font-size:12px">City name contains<br>
      <input type="text" name="q" value="<?= ih($q) ?>" placeholder="e.g. Allen" style="padding:6px 8px;width:220px"></label>
    <label style="font-size:12px">State<br>
      <input type="text" name="state" value="<?= ih($state) ?>" placeholder="e.g. TX" maxlength="2" style="padding:6px 8px;width:70px"></label>
    <button class="btn" type="submit">Search</button>
  </form>

  <?php if ($q !== '' || $state !== ''): ?>
    <div style="overflow-x:auto;margin-top:14px">
      <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead>
          <tr style="background:#f9fafb;text-align:left">
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">City</th>
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">State</th>
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Population</th>
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Median Income</th>
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Homeownership</th>
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Median Home Value</th>
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Median Year Built</th>
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Housing Units</th>
            <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Census Updated</th>
          </tr>
        </thead>
        <tbody style="color:#374151">
          <?php if (!$rows): ?>
            <tr><td colspan="9" style="padding:10px 8px;color:#9ca3af">No cities matched.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"><?= ih($r['city']) ?></td>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"><?= ih($r['ss']) ?></td>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"><?= number_format((int) $r['population']) ?></td>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">
                <?= $r['income_median'] !== null ? '$' . number_format((int) $r['income_median']) : '<span style="color:#d1d5db">—</span>' ?></td>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">
                <?= $r['homeownership_pct'] !== null ? number_format((float) $r['homeownership_pct'], 1) . '%' : '<span style="color:#d1d5db">—</span>' ?></td>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">
                <?= $r['home_value_median'] !== null ? '$' . number_format((int) $r['home_value_median']) : '<span style="color:#d1d5db">—</span>' ?></td>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">
                <?= $r['year_built_median'] !== null ? (int) $r['year_built_median'] : '<span style="color:#d1d5db">—</span>' ?></td>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">
                <?= $r['housing_units_total'] !== null ? number_format((int) $r['housing_units_total']) : '<span style="color:#d1d5db">—</span>' ?></td>
              <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">
                <?= $r['updated_at'] ? ih(substr($r['updated_at'], 0, 10)) : '<span style="color:#d1d5db">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div></div>

<?php infra_footer(); ?>
