<?php
/**
 * Gen-Mod tab — settings for "Vary block order per city", the structural
 * differentiation step on the Landing City Page Gen tab. That tab's checkbox
 * is the per-run ON/OFF switch; this tab is where the underlying behavior is
 * configured, same split as Gen-Image (style locked here, generation
 * triggered from Landing City Page Gen with the toggle it already has).
 * $tab, $csrfToken, ACTIVE_SITE_ID, ACTIVE_SITE_DIR available from index.php.
 * ms_layout_variation_defaults()/ms_layout_variation_settings() come from
 * includes/layout_variations.php, already loaded by functions.php.
 */
require_once __DIR__ . '/../../includes/multisite/image_overlay.php'; // ms_image_settings_locate/read/write

$gmLoc      = ms_image_settings_locate(ACTIVE_SITE_DIR, 'layout_variation.json');
$gmSettings = ms_layout_variation_settings(ms_image_settings_read(ACTIVE_SITE_DIR, 'layout_variation.json'));

// Same shared "where is this coming from" line style as Gen-Image, so scope is
// never ambiguous between this site's own setting and the shared fallback.
$gmScopeNote = function (array $loc) {
    if ($loc['scope'] === 'master') {
        return '<span style="color:#065f46;">Saved for <strong>this site only</strong></span> &mdash; <code>'
             . htmlspecialchars(ACTIVE_SITE_ID, ENT_QUOTES) . '/multisite/' . htmlspecialchars(basename($loc['master']), ENT_QUOTES)
             . '</code>. No other master is affected.';
    }
    if ($loc['scope'] === 'global') {
        return '<span style="color:#b45309;">Currently inheriting the shared fallback</span> <code>multisite/'
             . htmlspecialchars(basename($loc['global']), ENT_QUOTES)
             . '</code>, which every master without its own file uses. Saving below writes a copy for '
             . '<strong>this site only</strong> and stops it inheriting.';
    }
    return '<span style="color:#94a3b8;">Nothing saved for this site and no shared fallback</span> &mdash; generation uses the '
         . 'built-in default (4). Saving writes <code>' . htmlspecialchars(ACTIVE_SITE_ID, ENT_QUOTES) . '/multisite/'
         . htmlspecialchars(basename($loc['master']), ENT_QUOTES) . '</code>, for this site only.';
};

$gh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<div class="tab-content" style="<?= $tab === 'genmod' ? '' : 'display:none;' ?>">
<?php tab_header('Gen-Mod', 'Settings for the structural differentiation Landing City Page Gen applies when "Vary block order per city" is checked.', 'tab-genmod'); ?>

<div class="card">
    <h2>&#128260; Vary block order per city</h2>
    <p class="hint">This is the same feature as the <strong>"Vary block order per city"</strong> checkbox on the
        <a href="index.php?tab=citypages">Landing City Page Gen</a> tab &mdash; that checkbox is the per-run
        on/off switch (checked at generate time, not saved), and this tab is where its behavior is configured.</p>

    <h3 style="margin:16px 0 8px;font-size:.95rem;color:#334155;">What it does</h3>
    <ul class="hint" style="margin:0 0 14px;padding-left:20px;line-height:1.65;">
        <li>Every city page built from the same template normally renders its content blocks in the exact same
            order &mdash; with dozens of cities sharing one template, that reads as obviously templated content.</li>
        <li>When the checkbox is on, each city page instead gets one of several <strong>alternate section
            orderings</strong> instead of the template's natural order.</li>
        <li>The <strong>hero block always stays first</strong> and the <strong>closing block always stays
            last</strong> &mdash; only the movable blocks in between get reordered, so a page never opens or ends
            somewhere structurally odd.</li>
        <li>Each city <strong>deterministically</strong> lands on the same ordering every time (a hash of the
            city's own slug) &mdash; regenerating doesn't reshuffle a page that hasn't otherwise changed, but
            different cities on the same template land on different orderings from each other.</li>
        <li>A template needs at least <strong>4 blocks total</strong> (hero + 2 movable middle + closing) to have
            anything to vary &mdash; templates with fewer blocks always use the natural order regardless of this
            setting.</li>
    </ul>
    <p class="hint" style="margin:0 0 14px;"><?= $gmScopeNote($gmLoc) ?></p>

    <h3 style="margin:16px 0 8px;font-size:.95rem;color:#334155;">Parameters</h3>
    <div class="form-group" style="max-width:320px;">
        <label for="gm-variantcount">Number of orderings to rotate among</label>
        <input type="number" id="gm-variantcount" min="2" max="8" step="1" value="<?= (int)$gmSettings['variant_count'] ?>" style="width:100px;">
        <span class="hint">Includes the natural order &mdash; e.g. 4 means the natural order plus up to 3
            alternates. Higher gives more visual variety across a large city list; a template with few movable
            blocks may not have enough genuinely-different arrangements to fill a high number and will simply use
            fewer than asked.</span>
    </div>

    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
        <button type="button" id="gm-savebtn" class="btn btn-primary">&#128274; Save</button>
        <div id="gm-msg" class="hint" style="margin-top:8px;"></div>
    </div>
</div>

<script>
(function () {
    var csrf = <?= json_encode($csrfToken) ?>;
    var input = document.getElementById('gm-variantcount'), msg = document.getElementById('gm-msg');
    document.getElementById('gm-savebtn').addEventListener('click', function () {
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('variant_count', input.value);
        msg.textContent = 'Saving…';
        fetch('layout_variation_save.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); })
            .then(function (d) {
                msg.textContent = d.error ? ('✗ ' + d.error) : '✓ Saved — Landing City Page Gen now uses this.';
                msg.style.color = d.error ? '#991b1b' : '#065f46';
            })
            .catch(function () { msg.textContent = '✗ save failed'; msg.style.color = '#991b1b'; });
    });
})();
</script>
</div>
