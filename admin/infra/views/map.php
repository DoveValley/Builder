<?php

    require_once __DIR__ . '/../lib/hestia_fleet.php';
    // Who each box is rented from, derived from its notes — see lib/hosts.php.
    require_once __DIR__ . '/../lib/hosts.php';
    // IP -> real-world location, one shared module — see lib/geoip.php.
    require_once __DIR__ . '/../lib/geoip.php';
    // This tab's own editable fields, in their own file — NEVER hestia.json. See
    // lib/map_meta.php for why that split exists.
    require_once __DIR__ . '/../lib/map_meta.php';
    infra_header('map');

    $fleet    = infra_hestia_fleet_cached();
    $points   = [];
    $unplaced = [];
    $oldest   = null; // oldest geoip answer on screen, so the page can say "as of"

    // Fields a dot's popup can edit, stored together under one namespaced object on
    // the server record — see actions/map_meta_save.php. Adding a second editable
    // item later means one more key read here plus one more control in the popup
    // form in the <script> block below; nothing about this shape needs to change.
    $mapMetaDefaults = ['label_dir' => 'upper-right'];

    foreach ($fleet as $b) {
        $srv  = $b['server'];
        $prov = infra_host_provider($srv);
        $ip   = (string) ($srv['default_ip'] ?? $srv['host'] ?? '');
        $meta = infra_map_meta_get($b['id']);
        $status = $b['pending'] ? 'pending' : ($b['never'] ? 'unchecked' : ($b['ok'] ? 'up' : 'down'));
        $info = [
            'id'       => $b['id'],
            'label'    => $b['label'],
            'host'     => $b['host'],
            'status'   => $status,
            'error'    => $b['error'],
            'version'  => $b['version'],
            'platform' => $b['platform'],
            'deployed' => $b['deployed'],
            'accounts' => is_array($b['accounts']) ? ($b['accounts']['total'] ?? null) : null,
            'provider' => $prov['name'] ?? null,
            'login'    => $prov['login'] ?? null,
            'editHref' => 'index.php?view=servers&hedit=' . rawurlencode($b['id']),
            'labelDir' => (string) ($meta['label_dir'] ?? $mapMetaDefaults['label_dir']),
        ];

        $geo = $ip !== '' ? infra_geoip_cached($ip) : null;
        $age = $ip !== '' ? infra_geoip_age($ip) : null;
        if ($age !== null && ($oldest === null || $age > $oldest)) $oldest = $age;

        if ($geo && $geo['ok'] && $geo['lat'] !== null) {
            $place = trim($geo['city'] . ($geo['region'] && $geo['region'] !== $geo['city'] ? ', ' . $geo['region'] : '') . ', ' . $geo['country'], ', ');
            $info['lat'] = $geo['lat']; $info['lng'] = $geo['lon']; $info['place'] = $place; $info['exact'] = true;
            $points[] = $info;
        } else {
            $info['place'] = $geo ? ('lookup failed: ' . $geo['error']) : ($ip === '' ? 'no IP on file' : 'not looked up yet');
            $unplaced[] = $info;
        }
    }
    ?>
<style>
/* Scoped to this view only — the rest of the console stays plain PHP/vanilla. */
.map-wrap{position:relative;border-radius:12px;overflow:hidden;border:1px solid #1f2937;
  box-shadow:0 10px 30px -10px rgba(0,0,0,.35)}
#fleet-map{height:620px;width:100%;background:#0b1220}
.map-legend{display:flex;gap:16px;align-items:center;flex-wrap:wrap;font-size:12.5px;
  color:#374151;margin:10px 2px 16px}
.map-legend .dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;
  vertical-align:middle}
.fleet-pin{display:block;border-radius:50%;box-shadow:0 0 0 2px #fff,0 2px 6px rgba(0,0,0,.45)}
.fleet-pin.up{background:#22c55e}
.fleet-pin.down{background:#ef4444}
.fleet-pin.pending,.fleet-pin.unchecked{background:#9ca3af}
.fleet-pin.up::after{content:'';position:absolute;inset:0;border-radius:50%;background:#22c55e;
  opacity:.55;animation:fleet-ping 2.2s cubic-bezier(0,0,0.2,1) infinite}
@keyframes fleet-ping{0%{transform:scale(1);opacity:.55}75%,100%{transform:scale(2.4);opacity:0}}
/* left/top/transform are set inline per-marker (direction-dependent) — see pin() below */
.fleet-tag{position:absolute;font:700 11px -apple-system,Segoe UI,Roboto,Arial,sans-serif;
  color:#fff;background:rgba(17,24,39,.82);padding:1px 6px;border-radius:4px;white-space:nowrap;
  line-height:14px;pointer-events:none}
.fleet-pop{font:13px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;min-width:230px}
.fleet-pop h3{margin:0 0 4px;font-size:14.5px}
.fleet-pop .place{color:#6b7280;font-size:12px;margin-bottom:8px}
.fleet-pop table{border-collapse:collapse;width:100%;font-size:12.5px}
.fleet-pop td{padding:2px 0;vertical-align:top}
.fleet-pop td:first-child{color:#6b7280;padding-right:8px;white-space:nowrap}
.fleet-pop a.open{display:inline-block;margin-top:8px;font-size:12.5px;font-weight:600;
  color:#2563eb;text-decoration:none}
.fleet-pop-form{display:flex;align-items:center;gap:6px;margin-top:10px;padding-top:8px;
  border-top:1px solid #e5e7eb}
.fleet-pop-form label{font-size:11.5px;color:#6b7280;white-space:nowrap}
.fleet-pop-form select{flex:1;font-size:12px;padding:3px 4px;border-radius:5px;border:1px solid #d1d5db}
.fleet-pop-form button{padding:3px 10px;font-size:12px}
.leaflet-popup-content-wrapper{border-radius:10px}
.unplaced-row{display:flex;gap:10px;align-items:center;padding:8px 12px;border-bottom:1px solid #f3f4f6;
  font-size:13px}
.unplaced-row:last-child{border-bottom:none}
</style>

<div class="ic-card">
    <div class="body">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
            <p style="margin:0 0 4px;color:#6b7280;font-size:13px;flex:1;min-width:260px">
                Every box in <code>hestia.json</code>, placed by looking up its real IP — not by
                guessing from notes text. Click a dot for that box's live status, version and a
                link into Servers. Switch map styles with the layers control (top right) — pan
                and scroll-zoom like any map.
            </p>
            <form method="post" action="actions/geoip_refresh.php" style="flex-shrink:0">
                <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                <button class="btn sec" type="submit">&#8635; Refresh locations</button>
                <?php if ($oldest !== null): ?>
                    <div style="font-size:11px;color:#9ca3af;margin-top:4px;text-align:right">as of <?= infra_ago($oldest) ?></div>
                <?php endif; ?>
            </form>
        </div>
        <div class="map-legend">
            <span><span class="dot" style="background:#22c55e"></span>up</span>
            <span><span class="dot" style="background:#ef4444"></span>down</span>
            <span><span class="dot" style="background:#9ca3af"></span>pending / not checked yet</span>
            <span style="color:#9ca3af">— a ring on a dot is only a visual pulse, not a value</span>
        </div>
        <div class="map-wrap"><div id="fleet-map"></div></div>
    </div>
</div>

<?php if ($unplaced): ?>
<div class="ic-card" style="margin-top:16px">
    <div class="body">
        <h3 style="margin:0 0 4px;font-size:14px">Not shown on the map</h3>
        <p style="margin:0 0 10px;color:#6b7280;font-size:12.5px">
            Either this box has never been looked up (press "Refresh locations" above) or the
            lookup itself failed for the reason shown — a wrong dot would be worse than no dot,
            so these are listed instead of guessed at.
        </p>
        <?php foreach ($unplaced as $u): ?>
            <div class="unplaced-row">
                <strong><?= ih($u['label']) ?></strong>
                <span style="color:#9ca3af"><?= ih($u['host']) ?></span>
                <span class="badge <?= $u['status'] === 'up' ? 'b-ok' : '' ?>"
                      style="<?= $u['status'] !== 'up' ? 'background:#f3f4f6;color:#6b7280' : '' ?>">
                      <?= ih($u['status']) ?></span>
                <span style="color:#9ca3af;font-size:12px"><?= ih($u['place']) ?></span>
                <a class="open" href="<?= ih($u['editHref']) ?>" style="margin-left:auto;font-size:12.5px">Open in Servers tab &rarr;</a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script id="fleet-points" type="application/json"><?= json_encode(
    $points, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?></script>
<script>
(function () {
    var points  = JSON.parse(document.getElementById('fleet-points').textContent);
    var CSRF    = <?= json_encode(infra_csrf()) ?>;
    var OPEN_ID = <?= json_encode((string) ($_GET['open'] ?? '')) ?>;

    // Two boxes on the same IP's geolocation (same building, or just the same
    // city-level answer from the lookup) land on the EXACT same coordinate —
    // with no separation, the top marker's icon fully covers the one(s) under it
    // and they become unclickable, not just visually stacked. Nudge each member
    // of a shared-coordinate group a few dozen meters apart in a small circle so
    // every dot stays independently clickable. This only moves the rendered dot;
    // p.place (the text shown in its popup) is untouched, so it still reports the
    // real looked-up location, not the nudged one.
    (function spreadCoincidentPoints() {
        var groups = {};
        points.forEach(function (p) {
            var key = p.lat.toFixed(4) + ',' + p.lng.toFixed(4);
            (groups[key] = groups[key] || []).push(p);
        });
        Object.keys(groups).forEach(function (key) {
            var g = groups[key];
            if (g.length < 2) return;
            var R = 0.0005; // ~50m — separated enough to click apart once zoomed to street level
            g.forEach(function (p, i) {
                var angle = (2 * Math.PI * i) / g.length;
                p.lat += R * Math.cos(angle);
                p.lng += R * Math.sin(angle) / Math.cos(p.lat * Math.PI / 180);
            });
        });
    })();

    var map = L.map('fleet-map', { worldCopyJump: true }).setView([30, 10], 2);

    // "Multiple versions of the map" — four base styles, switched from the layers
    // control in the top-right corner. CARTO's basemap tiles now require a paid API
    // key (its free anonymous tier was retired) so this uses Esri's keyless REST
    // tile services instead, plus plain OpenStreetMap — all free, no key, no quota
    // that would ever block an internal 20-dot admin page.
    var dark = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri', maxZoom: 16
    }).addTo(map);
    var light = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri', maxZoom: 16
    });
    var streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 19, subdomains: 'abc'
    });
    var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri', maxZoom: 19
    });
    L.control.layers({
        'Dark (default)': dark,
        'Light': light,
        'Streets': streets,
        'Satellite': satellite
    }, null, { position: 'topright' }).addTo(map);

    // A leader line off every dot to a small "Box N" tag, so a box is identifiable
    // without having to click it first. The dot itself always sits at the exact
    // center of the icon box — iconAnchor points there, never at the tag or line —
    // so changing the direction only moves the tag, never the real coordinate.
    // DIRS also drives the <select> in each popup; one list, two uses.
    var CX = 70, CY = 35, R = 26, D = 18; // D: diagonal line length (shorter than R so NE/SE/etc. don't overshoot the icon box)
    var DIRS = [
        { v: 'up',          t: 'Up',          end: [CX,     CY - R], tf: 'translate(-50%,-100%)' },
        { v: 'down',        t: 'Down',        end: [CX,     CY + R], tf: 'translate(-50%,0%)' },
        { v: 'left',        t: 'Left',        end: [CX - R, CY],     tf: 'translate(-100%,-50%)' },
        { v: 'right',       t: 'Right',       end: [CX + R, CY],     tf: 'translate(0%,-50%)' },
        { v: 'upper-left',  t: 'Upper left',  end: [CX - D, CY - D], tf: 'translate(-100%,-100%)' },
        { v: 'upper-right', t: 'Upper right', end: [CX + D, CY - D], tf: 'translate(0%,-100%)' },
        { v: 'lower-left',  t: 'Lower left',  end: [CX - D, CY + D], tf: 'translate(-100%,0%)' },
        { v: 'lower-right', t: 'Lower right', end: [CX + D, CY + D], tf: 'translate(0%,0%)' }
    ];
    var DIR_BY_V = {};
    DIRS.forEach(function (d) { DIR_BY_V[d.v] = d; });

    function pin(status, tagText, dir) {
        var g    = DIR_BY_V[dir] || DIR_BY_V['upper-right'];
        var gap  = 6, ex = g.end[0], ey = g.end[1];
        // Label sits a few px further out than the line's end, in the same direction.
        var lx = ex + (ex > CX ? gap : (ex < CX ? -gap : 0));
        var ly = ey + (ey > CY ? gap : (ey < CY ? -gap : 0));
        var html = '<div style="position:relative;width:' + (CX * 2) + 'px;height:' + (CY * 2) + 'px">'
            + '<span class="fleet-pin ' + status + '" style="position:absolute;left:' + (CX - 7) + 'px;top:' + (CY - 7) + 'px;width:14px;height:14px"></span>'
            + '<svg width="' + (CX * 2) + '" height="' + (CY * 2) + '" style="position:absolute;left:0;top:0;pointer-events:none">'
            + '<line x1="' + CX + '" y1="' + CY + '" x2="' + ex + '" y2="' + ey + '" stroke="rgba(255,255,255,.85)" stroke-width="1.5"/></svg>'
            + '<div class="fleet-tag" style="left:' + lx + 'px;top:' + ly + 'px;transform:' + g.tf + '">' + tagText + '</div>'
            + '</div>';
        return L.divIcon({
            className: '', html: html,
            iconSize: [CX * 2, CY * 2], iconAnchor: [CX, CY], popupAnchor: [0, -14]
        });
    }

    function row(k, v) { return v ? ('<tr><td>' + k + '</td><td>' + v + '</td></tr>') : ''; }

    function dirOptions(current) {
        return DIRS.map(function (d) {
            return '<option value="' + d.v + '"' + (d.v === current ? ' selected' : '') + '>' + d.t + '</option>';
        }).join('');
    }

    var bounds = [], markerById = {};
    points.forEach(function (p) {
        bounds.push([p.lat, p.lng]);
        var num = (p.label.match(/\d+/) || [])[0];
        var tag = num ? ('Box ' + num) : p.label;
        var marker = L.marker([p.lat, p.lng], { icon: pin(p.status, tag, p.labelDir) }).addTo(map);
        markerById[p.id] = marker;
        var statusLabel = { up: 'up', down: 'down', pending: 'not set up yet', unchecked: 'not checked yet' }[p.status] || p.status;
        var html = '<div class="fleet-pop">'
            + '<h3>' + p.label + '</h3>'
            + '<div class="place">' + p.place + (p.exact ? '' : ' <em>(approximate)</em>') + '</div>'
            + '<table>'
            + row('Status', statusLabel)
            + row('Host', p.host)
            + row('Provider', p.provider ? (p.login ? '<a href="' + p.login + '" target="_blank" rel="noopener">' + p.provider + '</a>' : p.provider) : '')
            + row('Hestia', p.version ? (p.version + (p.platform ? ' / ' + p.platform : '')) : '')
            + row('Sites', (p.deployed !== null && p.deployed !== undefined) ? p.deployed : '')
            + row('Accounts', p.accounts)
            + row('Error', p.error)
            + '</table>'
            // Map-only fields live in one object on the server record (map_meta) and
            // save through one small, dedicated endpoint — see actions/map_meta_save.php.
            // A second field later is one more row here plus one more line in that file.
            + '<form method="post" action="actions/map_meta_save.php" class="fleet-pop-form">'
            + '<input type="hidden" name="csrf" value="' + CSRF + '">'
            + '<input type="hidden" name="id" value="' + p.id + '">'
            + '<label>Label direction</label>'
            + '<select name="dir">' + dirOptions(p.labelDir) + '</select>'
            + '<button type="submit" class="btn sec">Save</button>'
            + '</form>'
            + '<a class="open" href="' + p.editHref + '">Open in Servers tab &rarr;</a>'
            + '</div>';
        marker.bindPopup(html);
    });

    // After saving a direction, actions/map_meta_save.php redirects back here with
    // ?open=<id> — reopen that box's popup, centered, instead of resetting to the
    // whole-fleet view: that would throw away exactly the zoom level someone just
    // set up to tell three crowded dots apart.
    if (OPEN_ID && markerById[OPEN_ID]) {
        var m = markerById[OPEN_ID];
        map.setView(m.getLatLng(), Math.max(map.getZoom(), 8));
        m.openPopup();
    } else if (bounds.length) {
        map.fitBounds(bounds, { padding: [30, 30], maxZoom: 5 });
    }
})();
</script>
<?php infra_footer();
