<?php
/**
 * Layout variations (multisite structural differentiation, item 2a).
 *
 * A page's blocks render in one natural order (variant 0). For multisite we let the
 * operator generate up to 3 ALTERNATE orderings — hero pinned first, last block pinned
 * last, a couple of swaps in the movable middle — reviewed/edited and saved on the page.
 * At generation each cloned site deterministically gets ONE ordering by domain hash, so
 * sites aren't structurally identical while every layout stays sane.
 *
 * A variant is stored as an ordered list of block IDs (so it survives block edits).
 * Applied by reordering blocks to match; any block whose id isn't listed keeps its place.
 */

/** Assign a stable, unique id to any block that lacks one (deterministic — type + counter). */
function ensure_block_ids(array $blocks): array {
    $used = [];
    foreach ($blocks as $b) { if (!empty($b['id'])) $used[$b['id']] = true; }
    foreach ($blocks as &$b) {
        if (empty($b['id'])) {
            $base = (string)($b['type'] ?? 'block');
            $n = 1; $id = $base . '_' . $n;
            while (isset($used[$id])) { $n++; $id = $base . '_' . $n; }
            $b['id'] = $id; $used[$id] = true;
        }
    }
    unset($b);
    return $blocks;
}

/**
 * Generate up to ($total-1) alternate orderings (each a full list of block ids incl. the
 * pinned first + last). Variant 0 = natural order and is NOT returned. Empty if too few
 * movable blocks. Blocks must already have ids (call ensure_block_ids first).
 */
function layout_generate_variants(array $blocks, int $total = 4, bool $randomize = false): array {
    $ids = [];
    foreach ($blocks as $b) { if (!empty($b['id'])) $ids[] = $b['id']; }
    $n = count($ids);
    if ($n < 4) return [];                          // need hero + >=2 middle + last

    $first  = $ids[0];
    $last   = $ids[$n - 1];
    $middle = array_slice($ids, 1, $n - 2);
    $m      = count($middle);
    if ($m < 2) return [];

    $variants = [];
    $seen = [implode('|', $middle) => true];        // natural middle — never repeat it

    // Each candidate = a primary adjacent swap (i,i+1) plus a second non-overlapping one,
    // i.e. "a couple of swaps." Deterministic order by default; shuffled on Regenerate so
    // the operator can roll a different (still subtle, still valid) set.
    $primaries = range(0, $m - 2);
    if ($randomize) shuffle($primaries);
    foreach ($primaries as $i) {
        if (count($variants) >= $total - 1) break;
        $cand = $middle;
        [$cand[$i], $cand[$i + 1]] = [$cand[$i + 1], $cand[$i]];
        if ($m >= 4) {
            $j = ($i + 2) % $m; $k = ($j + 1) % $m;
            if (!in_array($j, [$i, $i + 1], true) && !in_array($k, [$i, $i + 1], true)) {
                [$cand[$j], $cand[$k]] = [$cand[$k], $cand[$j]];
            }
        }
        $key = implode('|', $cand);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $variants[] = array_merge([$first], $cand, [$last]);
    }
    return $variants;
}

/** Stable variant index in [0,count) for a domain on a given axis (salt). Deterministic — no RNG. */
function ms_variant(string $domain, int $count, string $salt = ''): int {
    if ($count <= 1) return 0;
    return crc32($salt . '|' . strtolower(trim($domain))) % $count;
}

/**
 * Apply a page's saved layout variation for this domain (mutates $container's content_blocks).
 * Picks one ordering deterministically by domain; index 0 = natural (no change). No-op unless
 * layout_enabled + layout_variants are present.
 */
function layout_apply_for_domain(array &$container, string $domain): void {
    if (empty($container['layout_enabled']) || empty($container['layout_variants'])) return;
    $variants = array_values($container['layout_variants']);
    $idx = ms_variant($domain, 1 + count($variants), 'layout');   // 0 = natural
    if ($idx === 0) return;
    $order = $variants[$idx - 1] ?? null;
    if (is_array($order) && $order) {
        $container['content_blocks'] = layout_apply($container['content_blocks'] ?? [], $order);
    }
}

/** Reorder blocks to match a variant's id order; blocks not listed keep their place. */
function layout_apply(array $blocks, array $variantIds): array {
    if (!$variantIds) return $blocks;
    $byId = [];
    foreach ($blocks as $b) { if (!empty($b['id'])) $byId[$b['id']] = $b; }
    $out = []; $taken = [];
    foreach ($variantIds as $id) {
        if (isset($byId[$id])) { $out[] = $byId[$id]; $taken[$id] = true; }
    }
    // Append anything not covered by the variant (added after the variant was saved).
    foreach ($blocks as $b) {
        if (empty($b['id']) || !isset($taken[$b['id']])) $out[] = $b;
    }
    return $out;
}

/** Short human label for a block (type + first heading-ish field) — for review/preview UIs. */
function layout_block_label(array $b): string {
    $type = (string)($b['type'] ?? 'block');
    foreach ($b as $k => $v) {
        if (is_string($v) && $v !== '' && preg_match('/head|title|heading|label|badge/i', $k)) {
            $t = trim(strip_tags($v));
            if ($t !== '') return $type . ' — ' . mb_strimwidth($t, 0, 38, '…');
        }
    }
    return $type;
}

/** Admin panel: the per-page "Layout variations" card (Generate/Regenerate → review → Save). */
function render_layout_variations_editor(string $scope, string $id = '') {
    global $csrfToken;
    ?>
    <div class="card" id="lv-card" data-scope="<?= h($scope) ?>" data-id="<?= h($id) ?>" data-csrf="<?= h($csrfToken) ?>">
        <h3 style="margin-top:0;">Layout variations <span style="font-weight:400;color:#64748b;font-size:.85em;">(MultiSite)</span></h3>
        <p class="hint">Alternate section orders used by <strong>MultiSite</strong> when this site is cloned across many cities — each city deterministically gets one, so the sites aren't structurally identical. On this single site the natural order is always used. <em>Landing-page-only generation support is coming.</em></p>
        <label class="hint" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:12px;">
            <input type="checkbox" id="lv-enabled"> Enable layout variations for this page
        </label>
        <div style="margin-bottom:12px;">
            <button type="button" class="btn" onclick="lvGenerate()">✨ Generate / Regenerate</button>
            <button type="button" class="btn btn-primary" onclick="lvSave()">Save layouts</button>
            <span id="lv-msg" class="hint" style="margin-left:10px;"></span>
        </div>
        <p class="hint" style="margin:0 0 8px;">Reflects the <strong>saved</strong> blocks — save the page first if you just edited them. Regenerate for a different (still subtle) set.</p>
        <div id="lv-display"></div>
    </div>
    <script>
    (function () {
        var card = document.getElementById('lv-card');
        var CSRF = card.getAttribute('data-csrf'), SCOPE = card.getAttribute('data-scope'), PID = card.getAttribute('data-id');
        var natural = null, variants = [];
        function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
        function post(action, extra){
            var fd = new FormData(); fd.append('csrf_token',CSRF); fd.append('action',action); fd.append('scope',SCOPE); fd.append('id',PID);
            if (extra) Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });
            return fetch('layout_api.php',{method:'POST',body:fd}).then(function(r){return r.json();});
        }
        function listHtml(title, items){
            return '<div style="margin-bottom:14px;"><strong style="font-size:0.9rem;">'+title+'</strong>'+
                '<ol style="margin:4px 0 0 20px;font-size:0.85rem;line-height:1.6;color:#334155;">'+
                items.map(function(it){return '<li>'+esc(it.label)+'</li>';}).join('')+'</ol></div>';
        }
        function renderLists(){
            var el = document.getElementById('lv-display');
            if (!variants.length) { el.innerHTML = '<p class="hint">No layouts yet — click Generate to create up to 4.</p>'; return; }
            var html = natural ? listHtml('Layout 1 — natural (this single site always uses this)', natural) : '';
            variants.forEach(function(v,i){ html += listHtml('Layout '+(i+2), v); });
            el.innerHTML = html;
        }
        window.lvGenerate = function(){
            var msg=document.getElementById('lv-msg'); msg.textContent='Generating…';
            post('generate').then(function(d){ msg.textContent=''; if(d.error){alert(d.error);return;} natural=d.natural; variants=d.variants||[]; renderLists(); });
        };
        window.lvSave = function(){
            var msg=document.getElementById('lv-msg'); msg.textContent='Saving…';
            var ids = variants.map(function(v){ return v.map(function(it){ return it.id; }); });
            post('save',{ enabled: document.getElementById('lv-enabled').checked?'1':'', variants: JSON.stringify(ids) })
                .then(function(d){ msg.textContent = d.saved ? ('Saved ✓ ('+d.count+' layouts)') : (d.error||'Error'); });
        };
        post('load').then(function(d){
            if (d.error) return;
            document.getElementById('lv-enabled').checked = !!d.enabled;
            variants = d.variants || [];
            variants.length ? renderLists() : (document.getElementById('lv-display').innerHTML = '<p class="hint">No layouts saved yet — click Generate to create 4.</p>');
        });
    })();
    </script>
    <?php
}
