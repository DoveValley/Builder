<?php
/**
 * Variant assignment review card — step 4a/4b. Propose auto-assigns every dimension for every
 * row; the table shows what got picked; Reroll swaps one row's one dimension; Approve unlocks
 * the content pass. Modeled directly on the Validation card's propose->table->confirm shape.
 *
 * Expects: $csrfToken.
 */
?>
<div class="card" id="ms-variant-review-card">
    <h3 style="margin-top:0;">4a&ndash;4b. Variant assignment</h3>
    <p class="hint">Auto-picks an architecture, type, color, title pattern, research prompt, and
        voice for every row. Review what got picked below, reroll anything you don't like, then
        approve to unlock content generation.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
        <button type="button" class="btn" id="ms-variant-propose-btn" onclick="msVariantPropose()">Propose variants</button>
        <button type="button" class="btn btn-primary" id="ms-variant-approve-btn" onclick="msVariantApprove()" disabled>Approve plan</button>
    </div>
    <div id="ms-variant-review-table"></div>
</div>

<script>
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;
    const DIM_LABELS = { architecture: 'Architecture', type: 'Type', color: 'Color', titles: 'Titles', research_prompt: 'Research', voice: 'Voice' };

    window.msVariantPropose = function () {
        const fd = new FormData(); fd.append('csrf_token', csrf);
        fetch('variant_api.php?action=variant_propose', { method: 'POST', body: fd })
            .then(r => r.json()).then(renderVariantPlan).catch(() => {});
    };

    window.msVariantApprove = function () {
        const fd = new FormData(); fd.append('csrf_token', csrf);
        fetch('variant_api.php?action=variant_approve', { method: 'POST', body: fd })
            .then(r => r.json()).then(d => { renderVariantPlan(d); if (window.msVariantOnApprove) window.msVariantOnApprove(); })
            .catch(() => {});
    };

    window.msVariantReroll = function (domain, dimension) {
        const fd = new FormData(); fd.append('csrf_token', csrf);
        fd.append('domain', domain); fd.append('dimension', dimension);
        fetch('variant_api.php?action=variant_reroll', { method: 'POST', body: fd })
            .then(r => r.json()).then(renderVariantPlan).catch(() => {});
    };

    function renderVariantPlan(d) {
        if (!d || d.error) { document.getElementById('ms-variant-review-table').innerHTML =
            '<p class="hint" style="color:#b91c1c;">' + (d && d.error ? d.error : 'Failed to load plan.') + '</p>'; return; }
        window.msLastVariantPlan = d;
        document.getElementById('ms-variant-approve-btn').disabled = !d.rows || !d.rows.length || d.approved;
        if (!d.rows || !d.rows.length) {
            document.getElementById('ms-variant-review-table').innerHTML = '<p class="hint">No rows yet &mdash; upload a target list first (step 1), then Propose.</p>';
            return;
        }
        let html = '<table style="width:100%;border-collapse:collapse;font-size:.85rem;">';
        html += '<thead><tr><th style="text-align:left;padding:6px;">Domain</th>';
        d.dimensions.forEach(dim => html += '<th style="text-align:left;padding:6px;">' + (DIM_LABELS[dim] || dim) + '</th>');
        html += '<th></th></tr></thead><tbody>';
        d.rows.forEach(row => {
            html += '<tr style="border-top:1px solid #e2e8f0;"><td style="padding:6px;font-weight:600;">' + escapeHtml(row.domain) + '</td>';
            d.dimensions.forEach(dim => {
                const pick = row.picks[dim];
                const title = pick ? escapeHtml(pick.description || '') : 'no options available';
                html += '<td style="padding:6px;" title="' + title + '">' + (pick ? escapeHtml(pick.name) : '&mdash;') +
                    (d.approved ? '' : ' <a href="#" style="font-size:.78rem;" onclick="msVariantReroll(' +
                        JSON.stringify(row.domain) + ',' + JSON.stringify(dim) + ');return false;">reroll</a>') + '</td>';
            });
            html += '<td style="padding:6px;">' + (row.approved ? '<span style="color:#166534;">approved</span>' : '') + '</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('ms-variant-review-table').innerHTML = html;
    }

    function escapeHtml(s) { const d = document.createElement('div'); d.innerText = s == null ? '' : s; return d.innerHTML; }

    fetch('variant_api.php?action=variant_plan_status').then(r => r.json()).then(renderVariantPlan).catch(() => {});
})();
</script>
