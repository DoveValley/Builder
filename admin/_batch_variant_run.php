<?php
/**
 * Content generation + review + build — steps 4c/4d/4e. Two detached passes (content, build),
 * launched/polled exactly like the existing "Generate sites" run card polls run_campaign.php,
 * plus a per-row content-approval table sitting between them.
 *
 * Expects: $csrfToken.
 */
?>
<div class="card" id="ms-variant-content-card">
    <h3 style="margin-top:0;">4c. Generate content</h3>
    <p class="hint">Researches each city (real sources, kept internal) and writes every
        approved row's copy. Builds nothing yet.</p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
        <label class="hint">Only this domain (optional)<br><input type="text" id="ms-vc-only" placeholder="example.com" style="width:200px;"></label>
        <label class="hint"><input type="checkbox" id="ms-vc-force"> Force (regenerate even if already cached)</label>
        <button type="button" class="btn btn-primary" id="ms-vc-run-btn" onclick="msVariantRunContent()">Generate content</button>
    </div>
    <div id="ms-vc-progress" style="margin-top:16px;"></div>
</div>

<div class="card" id="ms-variant-content-review-card">
    <h3 style="margin-top:0;">4d. Review content</h3>
    <p class="hint">Approve each row's generated copy for build. A row flagged "needs review"
        means a legal page's required disclosures didn't clearly survive rewriting &mdash;
        check it before approving.</p>
    <button type="button" class="btn" onclick="msVariantLoadContentReview()">Refresh</button>
    <div id="ms-variant-content-review-table" style="margin-top:12px;"></div>
</div>

<div class="card" id="ms-variant-build-card">
    <h3 style="margin-top:0;">4e. Build</h3>
    <p class="hint">Renders every content-approved row into finished static pages (its assigned
        architecture, colors, and fonts), processes images, and runs a quick SEO check. Nothing
        goes to a server in this step &mdash; that is step 5.</p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
        <label class="hint">Only this domain (optional)<br><input type="text" id="ms-vb-only" placeholder="example.com" style="width:200px;"></label>
        <button type="button" class="btn btn-primary" id="ms-vb-run-btn" onclick="msVariantRunBuild()">Build</button>
    </div>
    <div id="ms-vb-progress" style="margin-top:16px;"></div>
</div>

<script>
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;
    let vcTimer = null, vbTimer = null;

    function escapeHtml(s) { const d = document.createElement('div'); d.innerText = s == null ? '' : s; return d.innerHTML; }

    function launchPass(action, only, force, progressId, btnId, onDone) {
        const fd = new FormData(); fd.append('csrf_token', csrf);
        if (only) fd.append('only', only);
        if (force) fd.append('force', '1');
        document.getElementById(btnId).disabled = true;
        fetch('variant_api.php?action=' + action, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (d.error) { document.getElementById(progressId).innerHTML = '<p class="hint" style="color:#b91c1c;">' + escapeHtml(d.error) + '</p>'; document.getElementById(btnId).disabled = false; return; }
            poll(d.run_id, progressId, btnId, onDone);
        });
    }

    function poll(runId, progressId, btnId, onDone) {
        const url = 'variant_api.php?action=run_status&run_id=' + encodeURIComponent(runId);
        fetch(url).then(r => r.json()).then(d => {
            renderRun(d, progressId);
            if (d.state === 'running') { setTimeout(() => poll(runId, progressId, btnId, onDone), 2500); }
            else { document.getElementById(btnId).disabled = false; if (onDone) onDone(d); }
        }).catch(() => setTimeout(() => poll(runId, progressId, btnId, onDone), 4000));
    }

    function renderRun(d, progressId) {
        if (!d || d.none) { document.getElementById(progressId).innerHTML = ''; return; }
        let rows = '';
        (d.events || []).forEach(ev => {
            if (ev.event === 'row_start') rows += '<div class="hint">&hellip; ' + escapeHtml(ev.domain) + '</div>';
            if (ev.event === 'row_done') rows += '<div class="hint" style="color:#166534;">&#10003; ' + escapeHtml(ev.domain) + (ev.needs_review ? ' (legal needs review)' : '') + (ev.warnings ? ' (' + ev.warnings + ' SEO warning(s))' : '') + '</div>';
            if (ev.event === 'row_error') rows += '<div class="hint" style="color:#b91c1c;">&times; ' + escapeHtml(ev.domain) + ': ' + escapeHtml((ev.errors || []).join('; ')) + '</div>';
        });
        const state = d.state === 'done' ? 'style="color:#166534;"' : (d.state === 'running' ? '' : 'style="color:#b91c1c;"');
        document.getElementById(progressId).innerHTML =
            '<p class="hint" ' + state + '><strong>' + escapeHtml(d.state || '?') + '</strong>' +
            (d.ok !== undefined ? ' &mdash; ' + d.ok + ' ok, ' + (d.failed || 0) + ' failed' : '') + '</p>' + rows;
    }

    window.msVariantRunContent = function () {
        launchPass('run_content', document.getElementById('ms-vc-only').value.trim(),
            document.getElementById('ms-vc-force').checked, 'ms-vc-progress', 'ms-vc-run-btn',
            () => msVariantLoadContentReview());
    };

    window.msVariantRunBuild = function () {
        launchPass('run_build', document.getElementById('ms-vb-only').value.trim(), false, 'ms-vb-progress', 'ms-vb-run-btn');
    };

    window.msVariantLoadContentReview = function () {
        fetch('variant_api.php?action=content_review_list').then(r => r.json()).then(d => {
            const rows = (d && d.rows) || [];
            if (!rows.length) { document.getElementById('ms-variant-content-review-table').innerHTML = '<p class="hint">No generated content yet.</p>'; return; }
            let html = '<table style="width:100%;border-collapse:collapse;font-size:.85rem;">';
            html += '<thead><tr><th style="text-align:left;padding:6px;">Domain</th><th style="text-align:left;padding:6px;">Hero headline</th><th style="text-align:left;padding:6px;">Legal</th><th style="text-align:left;padding:6px;">FAQs</th><th style="text-align:left;padding:6px;">Guides</th><th></th></tr></thead><tbody>';
            rows.forEach(row => {
                const legalBad = row.legal_status.some(s => s === 'needs_review');
                html += '<tr style="border-top:1px solid #e2e8f0;">' +
                    '<td style="padding:6px;font-weight:600;">' + escapeHtml(row.domain) + '</td>' +
                    '<td style="padding:6px;">' + escapeHtml(row.hero ? row.hero.headline : '') + '</td>' +
                    '<td style="padding:6px;' + (legalBad ? 'color:#b91c1c;' : 'color:#166534;') + '">' + (legalBad ? 'needs review' : 'ok') + '</td>' +
                    '<td style="padding:6px;">' + row.faq_count + '</td>' +
                    '<td style="padding:6px;">' + row.guide_count + '</td>' +
                    '<td style="padding:6px;"><label class="hint"><input type="checkbox" ' + (row.content_approved ? 'checked' : '') +
                        ' onchange="msVariantApproveContent(' + JSON.stringify(row.domain) + ', this.checked)"> Approve for build</label></td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ms-variant-content-review-table').innerHTML = html;
        });
    };

    window.msVariantApproveContent = function (domain, approved) {
        const fd = new FormData(); fd.append('csrf_token', csrf);
        fd.append('domain', domain); fd.append('approved', approved ? '1' : '');
        fetch('variant_api.php?action=content_row_approve', { method: 'POST', body: fd });
    };

    // Resume watching an in-flight run on page load (e.g. after a refresh).
    fetch('variant_api.php?action=run_status').then(r => r.json()).then(d => {
        if (d && d.state === 'running') {
            if (d.pass === 'content') poll(d.run_id, 'ms-vc-progress', 'ms-vc-run-btn', () => msVariantLoadContentReview());
            else poll(d.run_id, 'ms-vb-progress', 'ms-vb-run-btn');
        }
    }).catch(() => {});

    msVariantLoadContentReview();
})();
</script>
