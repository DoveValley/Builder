<?php
/**
 * Master health check — the one genuinely master-level panel from the old MultiSite tab.
 *
 * Scans this site for content that won't localize when it is cloned: its own
 * city/state/zip typed as literal text (should be {city}/{state}/{SS}) and links back
 * to its own domain. Worth running before any batch — a master with leaks clones 50
 * sites that all name the master's city.
 *
 * The same check also runs automatically when you pick a master in the "+ New Batch"
 * box on the Site Factory panel.
 *
 * Expects: $csrfToken (unused — the lint action is a read-only GET, but kept for parity)
 */
?>
<div class="card" id="mc-card">
    <h3 style="margin-top:0;">Check this master for authoring leaks</h3>
    <p class="hint">Scans for content that <strong>won't localize on a clone</strong> &mdash; this site's own city/state/zip typed as literal text (should be <code>{city}</code>/<code>{state}</code>/<code>{SS}</code> shortcodes) and links pointing at its own domain. Run it before using this site as a batch master. Some hits are intentional (e.g. a governing-law state) &mdash; review each.</p>
    <button type="button" class="btn" id="mc-btn" onclick="mcRun()">Check master</button>
    <span id="mc-msg" class="hint" style="margin-left:10px;"></span>
    <div id="mc-results" style="margin-top:14px;"></div>
</div>

<script>
window.mcRun = function () {
    var btn = document.getElementById('mc-btn');
    var msg = document.getElementById('mc-msg');
    var box = document.getElementById('mc-results');
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    btn.disabled = true; msg.textContent = 'Scanning…'; box.innerHTML = '';
    fetch('multisite_api.php?action=lint_master')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.error) { msg.textContent = '✗ ' + d.error; return; }
            var f = d.findings || [];
            if (!f.length) { msg.textContent = ''; box.innerHTML = '<p style="color:#065f46;font-weight:600;margin:0;">✓ No authoring leaks — this master localizes cleanly.</p>'; return; }
            msg.textContent = '';
            var rows = f.map(function (x) {
                var fix = x.type === 'geo'
                    ? ('literal <strong>' + esc(x.lit) + '</strong> → <code>' + esc(x.fix) + '</code>')
                    : ('master-domain URL → ' + esc(x.fix));
                return '<tr><td style="padding:4px 8px;font-family:monospace;font-size:.8rem;">' + esc(x.file) + '<br><span style="color:#64748b;">' + esc(x.path) + '</span></td>'
                     + '<td style="padding:4px 8px;">' + fix + '</td>'
                     + '<td style="padding:4px 8px;color:#475569;">&ldquo;' + esc(x.excerpt) + '&rdquo;</td></tr>';
            }).join('');
            box.innerHTML = '<p style="margin:0 0 8px;color:#92400e;font-weight:600;">' + f.length + ' finding(s) — review each (some may be intentional):</p>'
                + '<table style="width:100%;border-collapse:collapse;font-size:.86rem;"><thead><tr style="text-align:left;border-bottom:1px solid #e2e8f0;">'
                + '<th style="padding:4px 8px;">Where</th><th style="padding:4px 8px;">Issue</th><th style="padding:4px 8px;">Context</th></tr></thead><tbody>'
                + rows + '</tbody></table>';
        })
        .catch(function () { btn.disabled = false; msg.textContent = '✗ scan failed'; });
};
</script>
