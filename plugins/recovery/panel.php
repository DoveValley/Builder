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

// Flatten keywords per page type for the client-side download buttons (below).
$kwExport = [];
foreach ($types as $key => $meta) {
    $kw = $keywords[$key] ?? [];
    $kwExport[] = [
        'label'     => $meta['label'] ?? $key,
        'primary'   => $kw['primary'] ?? '',
        'secondary' => array_values($kw['secondary'] ?? []),
    ];
}
$kwNiche = $data['site_vars']['business'] ?? 'Recovery';
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
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button type="button" class="btn" style="background:#0f766e;" onclick="recKwDownloadMap()"
        title="Download the keyword map (per page type: primary + secondaries) as a text file">&#11015; Download Keyword Map</button>
      <button type="button" class="btn" style="background:#0369a1;" onclick="recKwDownloadPriSec()"
        title="Download a plain list of all primary + secondary keywords, one per line, for pasting into keyword tools">&#11015; Download Pri/Sec Keywords</button>
    </div>
    <p class="hint" style="margin-top:10px;">Source: <code>data/recovery/keywords.json</code></p>
  </div>

  <script>
    var RECOVERY_KW    = <?= json_encode($kwExport, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var RECOVERY_NICHE = <?= json_encode($kwNiche, JSON_UNESCAPED_UNICODE) ?>;
    function recKwSlug(s){ return (s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
    function recKwSave(txt, name){
        var blob=new Blob([txt],{type:'text/plain;charset=utf-8'});
        var a=document.createElement('a');
        a.href=URL.createObjectURL(blob); a.download=name;
        document.body.appendChild(a); a.click();
        setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); },100);
    }
    // Per-page-type keyword map (primary + secondaries). Reference only — the
    // matrix generates pages, so tokens {company}/{state}/{city} stay unresolved.
    function recKwDownloadMap(){
        var bar=new Array(73).join('='), rule=new Array(73).join('-');
        var txt=bar+'\n'+(RECOVERY_NICHE||'RECOVERY').toUpperCase()+' — MATRIX KEYWORD MAP (by page type)\n';
        txt+='Reference only — the matrix generates pages, not these keywords.\n';
        txt+='Tokens {company} / {state} / {city} fill in per page.\n'+bar+'\n';
        for(var i=0;i<RECOVERY_KW.length;i++){
            var t=RECOVERY_KW[i];
            txt+='\n'+(i+1)+'.  '+(t.label||'').toUpperCase()+'\n'+rule+'\n';
            txt+='    primary:    '+(t.primary||'—')+'\n';
            txt+='    secondary:  '+((t.secondary&&t.secondary.length)?t.secondary.join('; '):'—')+'\n';
        }
        recKwSave(txt, (recKwSlug(RECOVERY_NICHE)||'recovery')+'-keyword-map.txt');
    }
    // Flat primary/secondary list, one keyword per line, deduped case-insensitively.
    function recKwDownloadPriSec(){
        var prims=[], secs=[], seen={};
        for(var i=0;i<RECOVERY_KW.length;i++){
            var t=RECOVERY_KW[i];
            if(t.primary){ var pk='p:'+t.primary.toLowerCase(); if(!seen[pk]){ seen[pk]=1; prims.push(t.primary); } }
            var sl=t.secondary||[];
            for(var j=0;j<sl.length;j++){
                var s=(sl[j]||'').trim(); if(!s) continue;
                var sk='s:'+s.toLowerCase(); if(seen[sk]) continue; seen[sk]=1; secs.push(s);
            }
        }
        var bar=new Array(73).join('='), rule=new Array(73).join('-');
        var txt=bar+'\n'+(RECOVERY_NICHE||'RECOVERY').toUpperCase()+' — PRIMARY / SECONDARY KEYWORDS\n'+bar+'\n';
        txt+='\nPRIMARY KEYWORDS ('+prims.length+')\n'+rule+'\n'+(prims.length?prims.join('\n')+'\n':'  (none)\n');
        txt+='\nSECONDARY KEYWORDS ('+secs.length+', deduped)\n'+rule+'\n'+(secs.length?secs.join('\n')+'\n':'  (none)\n');
        recKwSave(txt, (recKwSlug(RECOVERY_NICHE)||'recovery')+'-pri-sec-keywords.txt');
    }
  </script>

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
    <form id="rd-deploy-form" method="post" action="plugin_save.php"
          onsubmit="return this.action_build_only ? confirm('Build the gated static site now?') : true;">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="plugin_id"  value="recovery">
      <label style="font-weight:normal;display:block;margin-bottom:12px;">
        <input type="checkbox" name="force_all" id="rd-force" value="1"> Force full re-upload (ignore manifest)
      </label>
      <button type="submit" name="action" value="build_only" class="btn btn-secondary">Build only</button>
      <button type="button" id="rd-deploy-btn" class="btn" style="margin-left:8px;" onclick="rdStartDeploy()">
        &#128640; Build &amp; Deploy
      </button>
    </form>

    <!-- live progress meter -->
    <style>
      #rd-progress .rd-step { flex:1; text-align:center; font-size:.74rem; font-weight:600; padding:6px 4px; border-radius:6px;
        background:#f1f5f9; color:#94a3b8; border:1px solid #e2e8f0; transition:all .2s; }
      #rd-progress .rd-step.active { background:#0e7490; color:#fff; border-color:#0e7490; }
      #rd-progress .rd-step.done   { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
      #rd-progress .rd-step.error  { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
      #rd-log .l-warn  { color:#fbbf24; }
      #rd-log .l-error, #rd-log .l-fatal { color:#f87171; }
      #rd-log .l-done  { color:#4ade80; font-weight:600; }
      #rd-log .l-log   { color:#cbd5e1; }
    </style>
    <div id="rd-progress" style="display:none;margin-top:18px;max-width:720px;">
      <!-- phase steps -->
      <div style="display:flex;gap:8px;margin-bottom:12px;">
        <div class="rd-step" data-step="build">1 &middot; Build</div>
        <div class="rd-step" data-step="upload">2 &middot; Upload</div>
        <div class="rd-step" data-step="finish">Done</div>
      </div>
      <!-- bar -->
      <div style="background:#e2e8f0;border-radius:8px;height:22px;overflow:hidden;position:relative;">
        <div id="rd-bar" style="height:100%;width:0;background:#0e7490;transition:width .3s ease;border-radius:8px;"></div>
        <span id="rd-bar-pct" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#0f172a;"></span>
      </div>
      <!-- stat row -->
      <div style="display:flex;flex-wrap:wrap;gap:6px 18px;margin-top:8px;font-size:.78rem;color:#475569;">
        <span id="rd-stat-count"></span>
        <span id="rd-stat-elapsed"></span>
        <span id="rd-stat-eta"></span>
        <span id="rd-stat-rate"></span>
      </div>
      <div id="rd-progress-text" style="margin-top:6px;font-size:.85rem;color:#334155;font-weight:600;">Starting…</div>
      <div id="rd-stall" style="display:none;margin-top:6px;font-size:.8rem;color:#b45309;"></div>
      <!-- live log tail -->
      <div id="rd-log" style="margin-top:10px;background:#0f172a;border-radius:6px;padding:10px 12px;
        font-family:Menlo,Consolas,monospace;font-size:.74rem;line-height:1.5;max-height:190px;overflow-y:auto;display:none;"></div>
      <!-- persistent issues -->
      <div id="rd-issues" style="display:none;margin-top:10px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;
        border-radius:6px;font-size:.78rem;color:#991b1b;"></div>
    </div>

    <script>
    (function () {
      var polling = false, POLL_URL = '../plugins/recovery/deploy_status.php';
      var uploadStartTs = 0;          // server ts when the upload phase began (for rate/ETA)
      var lastTs = 0, lastTsChange = 0; // for stall detection (uses client delta only)
      var logAtBottom = true;
      var expectStarted = 0;          // only trust a status file whose run started >= this

      window.rdStartDeploy = function () {
        if (!confirm('Build the gated static site and deploy over FTP now? First deploy can take a few minutes.')) return;
        var form = document.getElementById('rd-deploy-form');
        var btn  = document.getElementById('rd-deploy-btn');
        btn.disabled = true;
        document.getElementById('rd-progress').style.display = 'block';
        uploadStartTs = 0; lastTs = 0; lastTsChange = Date.now(); expectStarted = 0;
        setSteps('build'); setBar(0, true); setText('Starting…');
        document.getElementById('rd-issues').style.display = 'none';
        document.getElementById('rd-log').style.display = 'none';
        document.getElementById('rd-log').innerHTML = '';
        var fd = new FormData();
        fd.append('csrf_token', form.querySelector('[name=csrf_token]').value);
        fd.append('plugin_id', 'recovery');
        fd.append('action', 'build_deploy_bg');
        if (document.getElementById('rd-force').checked) fd.append('force_all', '1');
        fetch('plugin_save.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected server response — deploy may not have started.' }; }); })
          .then(function (res) {
            if (!res || res.success === false) { setText((res && res.message) || 'Could not start the deploy.'); btn.disabled = false; return; }
            expectStarted = res.started || 0;   // reject any status file older than this run
            if (res.launched === false) setText('Started, but the background process was not detected — watching for it…');
            setTimeout(rdPoll, 700);
          })
          .catch(function () { setText('Could not start the deploy.'); btn.disabled = false; });
      };

      function setText(t) { document.getElementById('rd-progress-text').textContent = t; }
      function setBar(pct, indeterminate) {
        var bar = document.getElementById('rd-bar');
        bar.style.width = (indeterminate ? 100 : Math.max(0, Math.min(100, pct))) + '%';
        bar.style.opacity = indeterminate ? '0.4' : '1';
        bar.style.backgroundImage = indeterminate
          ? 'repeating-linear-gradient(45deg,rgba(255,255,255,.25) 0 10px,transparent 10px 20px)' : 'none';
        document.getElementById('rd-bar-pct').textContent = indeterminate ? '' : (Math.round(pct) + '%');
      }
      function setSteps(active) {
        var order = ['build', 'upload', 'finish'], ai = order.indexOf(active);
        document.querySelectorAll('#rd-progress .rd-step').forEach(function (el) {
          var i = order.indexOf(el.dataset.step);
          el.className = 'rd-step' + (active === 'error' ? (el.dataset.step === 'finish' ? ' error' : ' done')
            : (i < ai ? ' done' : (i === ai ? ' active' : '')));
        });
      }
      function fmtDur(s) {
        s = Math.max(0, Math.round(s));
        var m = Math.floor(s / 60); return m + ':' + ('0' + (s % 60)).slice(-2);
      }
      function esc(t) { return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

      function renderLog(log) {
        var box = document.getElementById('rd-log');
        if (!log || !log.length) return;
        box.style.display = 'block';
        logAtBottom = (box.scrollTop + box.clientHeight >= box.scrollHeight - 8);
        box.innerHTML = log.map(function (e) {
          return '<div class="l-' + (e.t || 'log') + '">' + esc(e.m) + '</div>';
        }).join('');
        if (logAtBottom) box.scrollTop = box.scrollHeight; // stick to bottom unless user scrolled up
      }
      function renderIssues(issues) {
        var box = document.getElementById('rd-issues');
        if (!issues || !issues.length) { box.style.display = 'none'; return; }
        box.style.display = 'block';
        box.innerHTML = '<strong>&#9888; ' + issues.length + ' issue' + (issues.length !== 1 ? 's' : '') + ':</strong>'
          + issues.slice(-12).map(function (e) { return '<div style="margin-top:3px;font-family:monospace;">' + esc(e.m) + '</div>'; }).join('');
      }

      function rdPoll() {
        if (polling) return; polling = true;
        fetch(POLL_URL, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (s) {
            polling = false;

            // Ignore a stale/previous status file — only trust the run we just started.
            // (Prevents an old completed deploy from flashing "Done" instantly.)
            if (expectStarted && (!s.started || s.started < expectStarted)) {
              setSteps('build'); setBar(0, true);
              setText('Starting…');
              setTimeout(rdPoll, 800);
              return;
            }

            var phase = s.phase || 'idle';
            var elapsed = (s.ts && s.started) ? (s.ts - s.started) : 0;

            // stall detection — has the server timestamp advanced recently?
            if (s.ts !== lastTs) { lastTs = s.ts; lastTsChange = Date.now(); }
            var stall = document.getElementById('rd-stall');
            if (s.running && (Date.now() - lastTsChange) > 30000) {
              stall.style.display = 'block';
              stall.textContent = '⚠ No updates for ' + Math.round((Date.now() - lastTsChange) / 1000)
                + 's — the background process may have stalled. Check sites/recovery-site/deploy_cli.log.';
            } else { stall.style.display = 'none'; }

            renderLog(s.log);
            renderIssues(s.issues);

            document.getElementById('rd-stat-elapsed').textContent = 'Elapsed ' + fmtDur(elapsed);

            if (phase === 'build') {
              setSteps('build');
              if (s.build_total > 0) {
                var bp = Math.round((s.build_done / s.build_total) * 100);
                setBar(bp, false);
                document.getElementById('rd-stat-count').textContent = 'Build ' + s.build_done + ' / ' + s.build_total + ' steps';
              } else { setBar(0, true); document.getElementById('rd-stat-count').textContent = ''; }
              document.getElementById('rd-stat-eta').textContent = '';
              document.getElementById('rd-stat-rate').textContent = '';
              setText(s.msg || 'Building…');
            } else if (phase === 'upload') {
              setSteps('upload');
              if (!uploadStartTs) uploadStartTs = s.ts || 0;
              if (s.up_total > 0) {
                var up = Math.round((s.up_done / s.up_total) * 100);
                setBar(up, false);
                document.getElementById('rd-stat-count').textContent = 'Upload ' + s.up_done + ' / ' + s.up_total + ' files';
                var upElapsed = (s.ts && uploadStartTs) ? (s.ts - uploadStartTs) : 0;
                if (upElapsed > 1 && s.up_done > 0) {
                  var rate = s.up_done / upElapsed;
                  document.getElementById('rd-stat-rate').textContent = rate.toFixed(1) + ' files/s';
                  document.getElementById('rd-stat-eta').textContent = 'ETA ' + fmtDur((s.up_total - s.up_done) / rate);
                }
              } else { setBar(0, true); document.getElementById('rd-stat-count').textContent = 'Connecting to FTP…'; }
              setText(s.msg || 'Uploading…');
            }

            if (s.running === false) {
              var ok = phase === 'done';
              setSteps(ok ? 'finish' : 'error');
              setBar(100, false);
              var bar = document.getElementById('rd-bar');
              bar.style.background = ok ? '#059669' : '#dc2626';
              document.getElementById('rd-bar-pct').textContent = ok ? '✓ Done' : 'Failed';
              document.getElementById('rd-stat-eta').textContent = '';
              document.getElementById('rd-stat-rate').textContent = '';
              document.getElementById('rd-stat-count').textContent =
                (s.pages != null ? s.pages + ' pages' : '') + (s.uploaded != null ? ' · ' + s.uploaded + ' uploaded' : '')
                + (s.failed ? ' · ' + s.failed + ' failed' : '');
              stall.style.display = 'none';
              setText(s.msg || (ok ? 'Done.' : 'Failed.'));
              document.getElementById('rd-deploy-btn').disabled = false;
              return; // stop polling
            }
            setTimeout(rdPoll, 1200);
          })
          .catch(function () { polling = false; setTimeout(rdPoll, 2500); });
      }

      // If a deploy is already running when the panel loads, resume the meter.
      fetch(POLL_URL, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (s) {
          if (s && s.running) {
            document.getElementById('rd-progress').style.display = 'block';
            document.getElementById('rd-deploy-btn').disabled = true;
            expectStarted = s.started || 0;   // trust the run already in flight
            lastTs = s.ts || 0; lastTsChange = Date.now();
            rdPoll();
          }
        })
        .catch(function () {});
    })();
    </script>
  </div>

</div>
