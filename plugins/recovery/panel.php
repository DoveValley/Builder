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
require_once __DIR__ . '/build.php';   // recovery_enumerate_urls() for the page-count hint

$cfg        = recovery_config();
$carriers   = recovery_carriers();
$states     = recovery_states();
$cities     = recovery_cities();
$templates  = recovery_all_templates();
$types      = recovery_types();
$keywords   = recovery_keywords();
$deployFile = (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR !== '') ? ACTIVE_SITE_DIR . '/deploy.json' : '';
$deploy     = ($deployFile && is_file($deployFile)) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];

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

  <!-- ── Target keywords (reference only) ─────────────────────────────────── -->
  <div class="card" style="margin-bottom:16px;">
    <h2>&#128273; Target keywords <span class="hint">(reference only)</span></h2>
    <p class="hint" style="margin-bottom:12px;">
      Target phrases for each page type — use these when authoring the templates below (primary → title/H1/meta;
      secondaries → H2s, FAQ, body). <strong>Reference only: these do not generate pages — the matrix does.</strong>
      Tokens <code>{company}</code>/<code>{state}</code>/<code>{city}</code> fill in per page.
    </p>
    <table style="width:100%;border-collapse:collapse;">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #ddd;">
          <th style="padding:6px 8px;">Type</th>
          <th style="padding:6px 8px;">Primary keyword</th>
          <th style="padding:6px 8px;">Secondary keywords</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($types as $key => $meta):
          $kw = $keywords[$key] ?? []; ?>
        <tr style="border-bottom:1px solid #f0f0f0;">
          <td style="padding:8px;"><strong><?= h($meta['label']) ?></strong></td>
          <td style="padding:8px;"><code><?= h($kw['primary'] ?? '—') ?></code></td>
          <td style="padding:8px;" class="hint"><?= h(implode(' · ', $kw['secondary'] ?? [])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint" style="margin-top:10px;">Source: <code>data/recovery/keywords.json</code></p>
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

  <!-- ── 4b. Per-page AI content (intersection enrichment) ────────────────── -->
  <?php $cov = recovery_intersection_coverage();
        $covPct = $cov['need'] > 0 ? round(100 * $cov['have'] / $cov['need']) : 0;
        $aiRunning = trim((string) @shell_exec('pgrep -f enrich_intersections_cli.php 2>/dev/null')) !== ''; ?>
  <div class="card">
    <h2>4b · Per-page AI content <span class="hint">(unique intersection copy)</span></h2>
    <p class="hint" style="margin-bottom:12px;">
      Each <strong>city × carrier</strong> and <strong>state × carrier</strong> page gets its own AI-written
      intro, second section, features, FAQ, and map note — so pages aren't stitched from shared carrier/city
      fragments (which reads as thin/duplicate content). <strong>Run this after adding cities or carriers.</strong>
    </p>
    <p style="margin:0 0 12px;">
      <strong><?= (int) $cov['have'] ?></strong> of <strong><?= (int) $cov['need'] ?></strong>
      intersection pages have unique AI (<?= $covPct ?>%)
      <?php if ($cov['missing'] > 0): ?>
        <span style="color:#b45309;font-weight:600;">· <?= (int) $cov['missing'] ?> missing</span>
      <?php else: ?>
        <span style="color:#15803d;font-weight:600;">· all covered &#10003;</span>
      <?php endif; ?>
      <?php if ($aiRunning): ?><br><span style="color:#1d4ed8;">&#9203; A generation run is in progress — refresh to watch it climb.</span><?php endif; ?>
    </p>
    <?php if ($cov['missing'] > 0 && !$aiRunning): ?>
    <form method="post" action="plugin_save.php"
          onsubmit="return confirm('Generate unique AI for the missing intersection pages in the background? This calls the AI API.');">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <input type="hidden" name="action"     value="enrich_intersections">
      <label style="font-weight:normal;margin-right:12px;">State
        <select name="state">
          <option value="">All states</option>
          <?php foreach ($states as $s): ?>
            <option value="<?= h($s['slug']) ?>"><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="font-weight:normal;margin-right:12px;">
        <input type="checkbox" name="refresh" value="1"> Regenerate existing (overwrite)
      </label>
      <button type="submit" class="btn">Generate missing intersection AI</button>
    </form>
    <?php elseif (!$aiRunning): ?>
    <form method="post" action="plugin_save.php"
          onsubmit="return confirm('Regenerate ALL intersection AI (overwrite existing)? This calls the AI API.');">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <input type="hidden" name="action"     value="enrich_intersections">
      <input type="hidden" name="refresh"    value="1">
      <button type="submit" class="btn btn-secondary">Regenerate all (overwrite)</button>
    </form>
    <?php endif; ?>
    <p class="hint" style="margin-top:10px;">
      Runs in the background (~6 parallel requests). Idempotent — only missing pages are generated unless you
      tick overwrite. Progress log: <code>/tmp/enrich_intersections.log</code>.
    </p>
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

  <!-- ── 6. Deploy (FTP) ──────────────────────────────────────────────────── -->
  <div class="card" style="margin-top:16px;">
    <h2>6 · Deploy (FTP)</h2>
    <p class="hint" style="margin-bottom:12px;">
      The gated static build is written to <code>sites/<?= h(ACTIVE_SITE_ID) ?>/output/</code>. These credentials
      save to <code>sites/<?= h(ACTIVE_SITE_ID) ?>/deploy.json</code> — gitignored and blocked from web access.
      Only new/changed files are uploaded on deploy.
    </p>
    <form method="post" action="plugin_save.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <input type="hidden" name="action"     value="deploy_save">

      <div class="form-group">
        <label>Canonical domain</label>
        <input type="url" name="canonical_domain" value="<?= h($deploy['canonical_domain'] ?? 'https://r.q111.xyz') ?>" placeholder="https://r.q111.xyz">
      </div>
      <div class="form-group">
        <label>FTP host</label>
        <input type="text" name="ftp_host" value="<?= h($deploy['ftp_host'] ?? '') ?>" placeholder="ftp.yourhost.com" autocomplete="off">
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <div class="form-group" style="flex:1;min-width:180px;">
          <label>FTP username</label>
          <input type="text" name="ftp_user" value="<?= h($deploy['ftp_user'] ?? '') ?>" autocomplete="off">
        </div>
        <div class="form-group" style="width:110px;">
          <label>Port</label>
          <input type="number" name="ftp_port" value="<?= (int)($deploy['ftp_port'] ?? 21) ?>" min="1" max="65535">
        </div>
      </div>
      <div class="form-group">
        <label>FTP password</label>
        <input type="password" name="ftp_pass" value="" autocomplete="new-password"
               placeholder="<?= !empty($deploy['ftp_pass']) ? '(saved — leave blank to keep)' : 'FTP password' ?>">
      </div>
      <div class="form-group">
        <label>Remote path</label>
        <input type="text" name="ftp_path" value="<?= h($deploy['ftp_path'] ?? '/public_html') ?>" placeholder="/public_html">
      </div>
      <div class="form-group">
        <label style="font-weight:normal;">
          <input type="checkbox" name="ftp_passive" value="1" <?= (!array_key_exists('ftp_passive', $deploy) || !empty($deploy['ftp_passive'])) ? 'checked' : '' ?>>
          Passive mode (recommended)
        </label>
      </div>
      <button type="submit" class="btn">Save deploy settings</button>
    </form>

    <hr style="margin:18px 0;border:none;border-top:1px solid #eee;">
    <p class="hint" style="margin-bottom:10px;">
      Builds the gated set (~<?= (int) count(recovery_enumerate_urls()) ?> pages) to
      <code>output/</code>, then FTP-uploads only new/changed files. First deploy can take a few minutes.
    </p>
    <form method="post" action="plugin_save.php"
          onsubmit="return this.action_build_deploy ? confirm('Build the gated static site and deploy over FTP now?') : true;">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <label style="font-weight:normal;display:block;margin-bottom:12px;">
        <input type="checkbox" name="force_all" value="1"> Force full re-upload (ignore manifest)
      </label>
      <button type="submit" name="action" value="build_only" class="btn btn-secondary">Build only</button>
      <button type="submit" name="action" value="build_deploy" class="btn" style="margin-left:8px;"
              onclick="return confirm('Build the gated static site and deploy over FTP now? First deploy can take a few minutes.');">
        &#128640; Build &amp; Deploy
      </button>
    </form>
  </div>

</div>
