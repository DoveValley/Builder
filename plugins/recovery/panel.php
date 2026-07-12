<?php
/**
 * Recovery Insurance plugin — admin panel.
 *
 * Mounted by admin/tabs/plugins.php at ?tab=plugins&plugin=recovery. All admin
 * scope vars ($data, $csrfToken) are available. This panel is a CONTROL CENTER:
 * it maps the 6 URL types to templates, manages the matrix (carriers/states/
 * cities), and controls phasing. Block + AI authoring itself happens in the
 * reused Templates tab — this panel deep-links there. No factory files touched.
 */

require_once __DIR__ . '/data.php';

$cfg        = recovery_config();
$carriers   = recovery_carriers();
$states     = recovery_states();
$cities     = recovery_cities();
$templates  = recovery_all_templates();
$types      = recovery_types();

// First rows → build example preview URLs.
$exState   = $states[0]['slug']   ?? '';
$exCity    = '';
foreach ($cities as $c) { if (($c['state'] ?? '') === $exState) { $exCity = $c['slug']; break; } }
$exCarrier = $carriers[0]['slug'] ?? '';
$exampleUrl = function (string $type) use ($exState, $exCity, $exCarrier): string {
    switch ($type) {
        case 'hub':              return '/insurance/';
        case 'company_national': return $exCarrier ? "/insurance/$exCarrier" : '';
        case 'state':            return $exState ? "/$exState" : '';
        case 'city':             return ($exState && $exCity) ? "/$exState/$exCity" : '';
        case 'state_company':    return ($exState && $exCarrier) ? "/$exState/$exCarrier" : '';
        case 'city_company':     return ($exState && $exCity && $exCarrier) ? "/$exState/$exCity/$exCarrier" : '';
    }
    return '';
};

// Publishable page count (mirrors build.php enumerator, honoring the phase gate).
$nCarrier = count($carriers);
$nState   = count($states);
$nCity    = count($cities);
$publishCC = !empty($cfg['phasing']['publish_city_company']);
$total = 1 + $nCarrier + $nState + ($nState * $nCarrier) + $nCity + ($publishCC ? $nCity * $nCarrier : 0);
?>

<div class="admin-section">

  <!-- ── Overview ─────────────────────────────────────────────────────────── -->
  <div class="card" style="margin-bottom:16px;">
    <h2>&#128657; Recovery Insurance directory</h2>
    <p class="hint">
      This plugin owns the recovery-insurance <strong>matrix</strong> (states × cities × carriers) and its
      nested URLs. It reuses the shared block library, Templates tab, and AI — it does <strong>not</strong>
      modify the factory. Active only for this site.
    </p>
    <p class="hint" style="margin-top:8px;">
      Full docs: <a href="docs.php?doc=recovery" target="_blank" rel="noopener"><strong>Docs → Recovery Site &nearr;</strong></a>
      &nbsp;·&nbsp; <strong><?= (int) $total ?></strong> pages will publish with the current matrix + phasing.
    </p>
  </div>

  <!-- ── 1. Page templates (map each type → a template) ───────────────────── -->
  <div class="card" style="margin-bottom:16px;">
    <h2>1 · Page templates</h2>
    <p class="hint" style="margin-bottom:12px;">
      Pick which <strong>template</strong> renders each URL type. Author a template's blocks + AI in the
      <a href="index.php?tab=templates"><strong>Templates tab</strong></a>; here you just choose it. Entity
      tokens (<code>{city}</code>, <code>{SS}</code>, <code>{city_state}</code>, <code>{company}</code>) fill in per page.
    </p>
    <form method="post" action="plugin_save.php">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <input type="hidden" name="action"     value="map">
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #ddd;">
            <th style="padding:6px 8px;">Type</th>
            <th style="padding:6px 8px;">URL</th>
            <th style="padding:6px 8px;">Template</th>
            <th style="padding:6px 8px;">Author / preview</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($types as $key => $meta):
            $sel = $cfg['templates'][$key] ?? '';
            $ex  = $exampleUrl($key); ?>
          <tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:8px;"><strong><?= h($meta['label']) ?></strong></td>
            <td style="padding:8px;"><code><?= h($meta['url']) ?></code></td>
            <td style="padding:8px;">
              <select name="tpl_<?= h($key) ?>" style="min-width:200px;">
                <option value="">— none (placeholder) —</option>
                <?php foreach ($templates as $t):
                    $tid = $t['id'] ?? ''; if ($tid === '') continue; ?>
                  <option value="<?= h($tid) ?>" <?= $tid === $sel ? 'selected' : '' ?>>
                    <?= h($tid) ?><?= !empty($t['page_type']) ? ' · ' . h($t['page_type']) : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td style="padding:8px;">
              <?php if ($sel !== ''): ?>
                <a href="index.php?tab=templates&template=<?= h($sel) ?>">Edit blocks + AI &nearr;</a>
              <?php else: ?>
                <span class="hint">map a template first</span>
              <?php endif; ?>
              <?php if ($ex !== ''): ?>
                &nbsp;·&nbsp; <a href="<?= h($ex) ?>" target="_blank" rel="noopener">Preview &nearr;</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn" style="margin-top:12px;">Save template mapping</button>
      <?php if (empty($templates)): ?>
        <p class="hint" style="margin-top:10px;color:#b45309;">
          No templates exist yet. Create them in the <a href="index.php?tab=templates">Templates tab</a> first.
        </p>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── 2. Matrix: carriers ──────────────────────────────────────────────── -->
  <div class="card" style="margin-bottom:16px;">
    <h2>2 · Carriers <span class="hint">(<?= $nCarrier ?>)</span></h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:10px;">
      <?php foreach ($carriers as $c): ?>
        <tr style="border-bottom:1px solid #f0f0f0;">
          <td style="padding:6px 8px;"><strong><?= h($c['name'] ?? '') ?></strong></td>
          <td style="padding:6px 8px;"><code><?= h($c['slug'] ?? '') ?></code></td>
          <td style="padding:6px 8px;text-align:right;">
            <form method="post" action="plugin_save.php" style="margin:0;display:inline;" onsubmit="return confirm('Remove this carrier?');">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="plugin_id"  value="recovery">
              <input type="hidden" name="action"     value="carrier_delete">
              <input type="hidden" name="slug"       value="<?= h($c['slug'] ?? '') ?>">
              <button type="submit" class="btn btn-secondary" style="padding:2px 10px;">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="plugin_save.php" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <input type="hidden" name="action"     value="carrier_add">
      <div class="form-group" style="margin:0;"><label>Carrier name</label><input type="text" name="name" placeholder="e.g. Aetna" required></div>
      <div class="form-group" style="margin:0;"><label>Slug (optional)</label><input type="text" name="slug" placeholder="auto from name"></div>
      <button type="submit" class="btn">+ Add carrier</button>
    </form>
  </div>

  <!-- ── 3. Matrix: states ────────────────────────────────────────────────── -->
  <div class="card" style="margin-bottom:16px;">
    <h2>3 · States <span class="hint">(<?= $nState ?>)</span></h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:10px;">
      <?php foreach ($states as $s): ?>
        <tr style="border-bottom:1px solid #f0f0f0;">
          <td style="padding:6px 8px;"><strong><?= h($s['name'] ?? '') ?></strong> <span class="hint"><?= h($s['ss'] ?? '') ?></span></td>
          <td style="padding:6px 8px;"><code><?= h($s['slug'] ?? '') ?></code></td>
          <td style="padding:6px 8px;text-align:right;">
            <form method="post" action="plugin_save.php" style="margin:0;display:inline;" onsubmit="return confirm('Remove this state?');">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="plugin_id"  value="recovery">
              <input type="hidden" name="action"     value="state_delete">
              <input type="hidden" name="slug"       value="<?= h($s['slug'] ?? '') ?>">
              <button type="submit" class="btn btn-secondary" style="padding:2px 10px;">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="plugin_save.php" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <input type="hidden" name="action"     value="state_add">
      <div class="form-group" style="margin:0;"><label>State name</label><input type="text" name="name" placeholder="e.g. Texas" required></div>
      <div class="form-group" style="margin:0;"><label>Abbrev</label><input type="text" name="ss" maxlength="2" style="width:70px;" placeholder="TX" required></div>
      <div class="form-group" style="margin:0;"><label>Slug (optional)</label><input type="text" name="slug" placeholder="auto"></div>
      <button type="submit" class="btn">+ Add state</button>
    </form>
  </div>

  <!-- ── 4. Matrix: cities ────────────────────────────────────────────────── -->
  <div class="card" style="margin-bottom:16px;">
    <h2>4 · Cities <span class="hint">(<?= $nCity ?>)</span></h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:10px;">
      <?php foreach ($cities as $c): ?>
        <tr style="border-bottom:1px solid #f0f0f0;">
          <td style="padding:6px 8px;"><strong><?= h($c['name'] ?? '') ?></strong></td>
          <td style="padding:6px 8px;"><code><?= h($c['state'] ?? '') ?>/<?= h($c['slug'] ?? '') ?></code></td>
          <td style="padding:6px 8px;"><span class="hint"><?= !empty($c['population']) ? 'pop ' . number_format($c['population']) : '' ?></span></td>
          <td style="padding:6px 8px;text-align:right;">
            <form method="post" action="plugin_save.php" style="margin:0;display:inline;" onsubmit="return confirm('Remove this city?');">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="plugin_id"  value="recovery">
              <input type="hidden" name="action"     value="city_delete">
              <input type="hidden" name="slug"       value="<?= h($c['slug'] ?? '') ?>">
              <input type="hidden" name="state"      value="<?= h($c['state'] ?? '') ?>">
              <button type="submit" class="btn btn-secondary" style="padding:2px 10px;">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="plugin_save.php" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <input type="hidden" name="action"     value="city_add">
      <div class="form-group" style="margin:0;"><label>City name</label><input type="text" name="name" placeholder="e.g. Houston" required></div>
      <div class="form-group" style="margin:0;"><label>State</label>
        <select name="state" required>
          <option value="">—</option>
          <?php foreach ($states as $s): ?>
            <option value="<?= h($s['slug'] ?? '') ?>"><?= h($s['name'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;"><label>Population (optional)</label><input type="number" name="population" min="0" style="width:120px;" placeholder="for gating"></div>
      <button type="submit" class="btn">+ Add city</button>
    </form>
  </div>

  <!-- ── 5. Phasing / publish ─────────────────────────────────────────────── -->
  <div class="card">
    <h2>5 · Phasing &amp; publish</h2>
    <p class="hint" style="margin-bottom:12px;">
      Controls how much of the matrix goes live. The city × company level is the biggest (and thinnest) —
      gate it while proving indexing on a small footprint (YMYL niche).
    </p>
    <form method="post" action="plugin_save.php">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <input type="hidden" name="action"     value="phasing">
      <div class="form-group">
        <label style="font-weight:normal;">
          <input type="checkbox" name="publish_city_company" value="1" <?= $publishCC ? 'checked' : '' ?>>
          Publish the <strong>city × company</strong> pages (<?= $nCity ?> cities × <?= $nCarrier ?> carriers = <?= $nCity * $nCarrier ?> pages)
        </label>
      </div>
      <div class="form-group">
        <label>Minimum city population for city × company (0 = no gate)</label>
        <input type="number" name="min_city_population" min="0" value="<?= (int) ($cfg['phasing']['min_city_population'] ?? 0) ?>" style="width:160px;">
      </div>
      <button type="submit" class="btn">Save phasing</button>
    </form>
  </div>

</div>
