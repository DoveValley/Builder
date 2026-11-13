// batch-detailed.js, part 3/4 — Batch Detailed: the Target List table's shared
// rendering engine (msRows, msRenderRowPair, msLoadRows — everything else in
// this split reads/renders through these) plus Box/CF Acct/Registrar autofill
// and the box/registrar distribution check.
//
// Same split as part 1 (see its header) — plain top-level code, shares global
// scope with the other 3 parts. Loads after 01-upload/02-generate; parts 2 and
// 4 both call into this file's msRows/msLoadRows/msRenderRowPair.
    // ── Per-row target list editor ──────────────────────────────────────────────
    // Fields this quick editor touches — matches MS_ROW_EDITABLE_COLS server-side.
    // Keeping the table's own columns to a readable subset; every other CSV column
    // (analytics_id, landing_cities, etc.) still round-trips untouched through save.
    let msRows = {};          // key -> {domain, fields, status, pool} — key is the domain
                              // when the row has one, else a synthetic per-line key
                              // (see ms_rows_by_key() server-side) so a city-only row
                              // with no domain yet still gets a stable identity.
    let msRowOrder = [];      // keys in the order params.csv stores them (the intended go-live sequence) — never sorted
    let msEditingKey = null;
    let msUnlockedConfirm = {};   // domain -> the confirm string that unlocked a LIVE row
    let msCtmSelected = {};       // domain -> true, for the "Get CTM Phone Numbers" toolbar

    function msStatusBadge(status) {
        if (status === 'live') return '<span style="background:#991b1b;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">LIVE</span>';
        if (status) return '<span style="background:#e2e8f0;color:#334155;padding:2px 8px;border-radius:10px;font-size:11px;">' + esc(status) + '</span>';
        return '<span class="hint">not started</span>';
    }

    function msRenderRowsTable() {
        const tbody = document.querySelector('#ms-rows-table tbody');
        const keys = msRowOrder;
        if (!keys.length) { tbody.innerHTML = '<tr><td colspan="16" class="hint">No rows yet — upload a CSV above or add one below.</td></tr>'; return; }
        tbody.innerHTML = keys.map((key, i) => msRenderRowPair(key, i + 1)).join('');
    }

    // Page-pool staleness notice — only rendered when there's actually something to act
    // on (locked AND stale), so an in-sync or never-built row stays clean. See
    // project_page_pool_manifest_lock in memory for why this exists: a domain's page
    // selection locks in on first build and silently ignores later pool-config changes,
    // with nothing anywhere saying so, until now.
    function msPoolNotice(pool, domain) {
        if (!pool || !pool.locked || !pool.stale) return '';
        const d = domain.replace(/'/g, "\\'");
        return '<div style="margin-top:4px;">' +
            '<span class="hint" style="color:#92400e;background:#fef3c7;border-radius:4px;padding:1px 6px;" ' +
            'title="This domain\'s page selection locked in on an earlier build and has not picked up later page-pool changes (new pages, counts, pinned/rotate tiers).">' +
            'Pages: ' + pool.locked_count + ' locked &rarr; ' + pool.current_count + ' current</span> ' +
            '<button type="button" class="btn" style="padding:1px 8px;font-size:.78rem;" onclick="msPoolReset(\'' + d + '\')">Reset pages</button>' +
            '</div>';
    }

    window.msPoolReset = function (domain) {
        if (!confirm('Reset ' + domain + '\'s locked page selection?\n\nThe next Generate run for this domain will recompute its page list from current pool config. This does not rebuild or upload anything by itself.')) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('domain', domain);
        fetch('multisite_api.php?action=pool_reset', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { if (d.error) { alert(d.error); return; } msLoadRows(); })
            .catch(() => alert('Reset failed — lost contact with the server.'));
    };

    // Recomputes box/cf_account for EVERY row from its current position — strict
    // positional math (includes/multisite/box_assignment.php), via multisite_api.php's
    // box_autofill action. Overwrites any manual edits to those two columns, so this
    // confirms before firing.
    window.msBoxAutofill = function () {
        const msgEl = document.getElementById('ms-box-autofill-msg');
        const overflow = document.getElementById('ms-box-overflow').value.trim();
        const overflowMsg = overflow === ''
            ? 'blank, since only 4 accounts exist per box — decide those by hand later'
            : 'account .' + overflow + ' (reused for all of them)';
        if (!confirm('Recompute Box and CF Acct for EVERY row in this batch, based on its current position in the list?\n\nBox cycles 1-20 repeating; CF Acct is the matching Cloudflare account (0-3) for that round. Rows past position 80 will get ' + overflowMsg + '.\n\nThis overwrites any manual edits already made to those two columns.')) return;
        msgEl.textContent = 'Working…';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        if (overflow !== '') fd.append('overflow_cf_account', overflow);
        fetch('multisite_api.php?action=box_autofill', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { msgEl.textContent = ''; alert(d.error); return; }
                msgEl.textContent = 'Set ' + d.rows + ' row(s).';
                msLoadRows();
            })
            .catch(() => { msgEl.textContent = ''; alert('Auto-fill failed — lost contact with the server.'); });
    };

    // Recomputes registrar for EVERY row from (box, round, this batch's own
    // sequence number) — box-aware assignment (includes/multisite/
    // registrar_schedule.php, multisite_api.php's registrar_autofill action).
    // Needs Box/CF Acct already filled in first. No seed to type in — uses this
    // batch's own already-assigned sequence number, so re-running this on any
    // future batch reproduces the same good spread automatically.
    window.msRegistrarAutofill = function () {
        const msgEl = document.getElementById('ms-box-autofill-msg');
        if (!confirm('Recompute Registrar for EVERY row in this batch, from its (box, round) position and this batch\'s own sequence number?\n\nThis overwrites any manual registrar edits already made. Run Auto-fill Box / CF Acct first if you haven\'t — rows with no box assigned are skipped.')) return;
        msgEl.textContent = 'Working…';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fetch('multisite_api.php?action=registrar_autofill', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { msgEl.textContent = ''; alert(d.error); return; }
                msgEl.textContent = 'Set ' + d.rows + ' row(s)' + (d.skipped_no_box ? ', skipped ' + d.skipped_no_box + ' with no box' : '') + ' (seq ' + d.seq + ').';
                msLoadRows();
            })
            .catch(() => { msgEl.textContent = ''; alert('Auto-fill failed — lost contact with the server.'); });
    };

    // Swaps full row content between a hotspot's over-represented rows and a
    // safe donor elsewhere (multisite_api.php's box_fix_state_clustering,
    // ms_suggest_state_swaps() in includes/multisite/distribution_check.php) —
    // box/CF Acct/registrar are untouched, since those belong to the position,
    // not the row's identity. Run Check Distribution first to see what exists;
    // this runs its own fresh check internally either way.
    window.msFixStateClustering = function () {
        const msgEl = document.getElementById('ms-box-autofill-msg');
        if (!confirm('Fix state clustering by swapping full row content (domain, business, city, state, etc.) between over-represented rows and a safe donor row elsewhere?\n\nBox, CF Acct and Registrar are NOT touched — they stay with the position. This only makes sense before domains are bought and before any CTM numbers/FTP credentials are assigned to these rows.')) return;
        msgEl.textContent = 'Working…';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fetch('multisite_api.php?action=box_fix_state_clustering', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { msgEl.textContent = ''; alert(d.error); return; }
                if (d.swaps === 0 && d.before === 0) { msgEl.textContent = 'No state hotspots found — nothing to fix.'; return; }
                if (d.unresolved) { msgEl.textContent = d.before + ' hotspot(s) found, but no safe donor row exists for any of them.'; return; }
                msgEl.textContent = 'Made ' + d.swaps + ' swap(s) — hotspots ' + d.before + ' → ' + d.after + '.';
                msLoadRows();
            })
            .catch(() => { msgEl.textContent = ''; alert('Fix failed — lost contact with the server.'); });
    };

    // Saves the per-batch "Strict positional box mode" toggle (box_assignment_save.php).
    // Does not itself touch any row — Create Host reads this at the moment it runs.
    window.msSaveStrictBoxMode = function (checked) {
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('strict_mode', checked ? '1' : '');
        fetch('box_assignment_save.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { if (d.error) alert(d.error); })
            .catch(() => alert('Could not save — lost contact with the server.'));
    };

    // Read-only report: box/account totals (sanity) + registrar/state hotspots —
    // a box carrying notably more of one registrar or state than an even spread
    // would predict (multisite_api.php's box_distribution_check,
    // includes/multisite/distribution_check.php). Meant to run right after
    // Auto-fill and BEFORE Buy Domains, while row order can still be adjusted —
    // once domains are bought, registrar can't change.
    window.msBoxDistributionCheck = function () {
        const out = document.getElementById('ms-box-check-out');
        out.style.display = 'block';
        out.innerHTML = '<span class="hint">Checking…</span>';
        fetch('multisite_api.php?action=box_distribution_check')
            .then(r => r.json())
            .then(d => {
                if (d.error) { out.innerHTML = '<span class="hint" style="color:#991b1b;">' + esc(d.error) + '</span>'; return; }
                out.innerHTML = msRenderBoxDistributionReport(d);
            })
            .catch(() => { out.innerHTML = '<span class="hint" style="color:#991b1b;">Check failed — lost contact with the server.</span>'; });
    };

    function msRenderBoxDistributionReport(d) {
        const boxCounts = Object.values(d.box_counts || {});
        const boxesUsed = Object.keys(d.box_counts || {}).length;
        const boxEven = boxCounts.length && boxCounts.every(c => c === boxCounts[0]);
        const cfCounts = Object.values(d.cf_counts || {});
        const cfEven = cfCounts.length && cfCounts.every(c => c === cfCounts[0]);

        let html = '<div class="hint" style="margin-bottom:8px;">' +
            '<strong>Box totals:</strong> ' + boxesUsed + ' box(es) used' +
            (boxEven ? ', all equal (' + boxCounts[0] + ' each) &#10003;' : ' — UNEVEN, check for manual edits') +
            ' &nbsp;&middot;&nbsp; <strong>CF Acct totals:</strong> ' + Object.keys(d.cf_counts || {}).length + ' combo(s) used' +
            (cfEven ? ', all equal (' + cfCounts[0] + ' each) &#10003;' : ' — UNEVEN, check for manual edits') +
            '</div>';

        const hotspotTable = (title, hints, rows) => {
            if (!rows.length) return '<div class="hint" style="margin-bottom:8px;color:#166534;"><strong>' + title + ':</strong> clean — no concentration above threshold.</div>';
            let t = '<div style="margin-bottom:10px;"><strong style="font-size:13px;color:#92400e;">' + title + ' — ' + rows.length + ' hotspot(s)</strong>' +
                '<div class="hint" style="margin:2px 0 6px;">' + hints + '</div>' +
                '<table style="font-size:12.5px;border-collapse:collapse;"><thead><tr style="text-align:left;color:#6b7280;">' +
                '<th style="padding:3px 10px 3px 0;">Box</th><th style="padding:3px 10px 3px 0;">Value</th>' +
                '<th style="padding:3px 10px 3px 0;">Actual</th><th style="padding:3px 10px 3px 0;">Expected</th><th style="padding:3px 0;">Ratio</th></tr></thead><tbody>';
            rows.forEach(h => {
                t += '<tr style="border-top:1px solid #f3f4f6;">' +
                    '<td style="padding:3px 10px 3px 0;">' + h.box + '</td>' +
                    '<td style="padding:3px 10px 3px 0;">' + esc(h.value) + '</td>' +
                    '<td style="padding:3px 10px 3px 0;">' + h.actual + '</td>' +
                    '<td style="padding:3px 10px 3px 0;">' + h.expected + '</td>' +
                    '<td style="padding:3px 0;font-weight:700;color:#92400e;">' + h.ratio + 'x</td></tr>';
            });
            t += '</tbody></table></div>';
            return t;
        };

        html += hotspotTable('Registrar concentration', 'A box with one registrar at &ge;1.5&times; its even-spread share, on at least 3 domains.', d.registrar_hotspots || []);
        html += hotspotTable('State concentration', 'A box with one state at &ge;1.5&times; its even-spread share, on at least 3 domains.', d.state_hotspots || []);

        const missing = d.registrar_missing || [];
        if (!missing.length) {
            html += '<div class="hint" style="margin-bottom:8px;color:#166534;"><strong>Registrar absence:</strong> clean — every box sees every registrar this batch actually uses.</div>';
        } else {
            html += '<div style="margin-bottom:10px;"><strong style="font-size:13px;color:#991b1b;">Registrar absence — ' + missing.length + ' box/registrar pair(s) never occur together</strong>' +
                '<div class="hint" style="margin:2px 0 6px;">A box with ZERO domains from a registrar this batch otherwise uses plenty of — the sharper version of the concentration check above (every box permanently missing half the registrar pool is the real pattern, not just "a bit uneven").</div>' +
                '<table style="font-size:12.5px;border-collapse:collapse;"><thead><tr style="text-align:left;color:#6b7280;">' +
                '<th style="padding:3px 10px 3px 0;">Box</th><th style="padding:3px 10px 3px 0;">Missing registrar</th><th style="padding:3px 0;">Batch total elsewhere</th></tr></thead><tbody>';
            missing.forEach(m => {
                html += '<tr style="border-top:1px solid #f3f4f6;">' +
                    '<td style="padding:3px 10px 3px 0;">' + m.box + '</td>' +
                    '<td style="padding:3px 10px 3px 0;">' + esc(m.value) + '</td>' +
                    '<td style="padding:3px 0;">' + m.batch_total + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        return html;
    }

    // Compact bought/not-bought mark — a single glyph, not a full badge, since this
    // is a narrow column by design. A row can be fully built/hosted/uploaded and
    // still show "not yet" here: that pipeline never checks registration, only the
    // actual nameserver switch at go-live does.
    function msBoughtCell(owned) {
        return owned
            ? '<span style="color:#166534;font-weight:700;" title="Registered at a registrar">&#10003;</span>'
            : '<span style="color:#991b1b;font-weight:700;" title="Not registered yet — buy before go-live, or it will stick at &quot;Nameservers: manual step&quot;">&#10007;</span>';
    }

    // forward_to set + forward_rpn_id blank = staged, never pushed to CTM yet (no
    // tracking number existed when it was set) — distinct from actually applied, so
    // this never implies a call is really routing somewhere when it is not yet.
    function msRoutesToCell(f) {
        if (!f.forward_to) return '<span style="color:#991b1b;font-weight:600;">not set</span>';
        if (!f.forward_rpn_id) return '<span style="color:#b45309;">' + esc(f.forward_to) + '</span> <span class="hint">(staged — no CTM number yet)</span>';
        return esc(f.forward_to);
    }

    function msRenderRowPair(key, rowNum) {
        const r = msRows[key] || {};
        const f = r.fields || {};
        const domain = r.domain || '';
        const hasDomain = domain !== '';
        const isLive = r.status === 'live';
        const editing = msEditingKey === key;
        const keyEsc = key.replace(/'/g, "\\'");
        const numCell = '<td class="hint">' + rowNum + '</td>';
        // A row with no domain yet gets a plain text input here instead of the usual
        // display-only cell — the one place a row's FIRST domain gets typed in (see
        // ms_rows_by_key()/row_save's domain-less-only branch server-side). Once a
        // domain is set it's permanent here; editing an existing one is a separate,
        // bigger operation (row_correct, for live rows only — there's no path to
        // change a non-live row's already-set domain from this table on purpose,
        // same as before this change).
        const domainCell = isLive
            ? '<a href="https://' + esc(domain) + '" target="_blank" rel="noopener">' + esc(domain) + '</a>'
            : (editing && !hasDomain
                ? '<input class="ms-row-input" data-f="domain" placeholder="domain.com" style="width:160px;">'
                : esc(domain));
        // NOT disabled for LIVE rows: this checkbox now drives two actions, not one.
        // Get CTM Phone Numbers still refuses a live row (checked server-side, in
        // multisite_api.php's ctm_get_numbers case) since changing the tracking
        // number itself needs a rebuild — but Set Call Routing is explicitly safe on
        // a live domain (it only ever changes CTM's own forwarding config), and a
        // disabled checkbox here was blocking that for nearly every real row in the
        // fleet. A row with no domain yet has nothing for any CTM action to act on,
        // so its checkbox is just disabled rather than omitted — keeps the column
        // aligned without implying the row is selectable.
        const checkCell = hasDomain
            ? '<td><input type="checkbox" class="ms-ctm-check" data-domain="' + esc(domain) + '" style="width:20px;height:20px;cursor:pointer"' +
              (msCtmSelected[domain] ? ' checked' : '') +
              (isLive ? ' title="LIVE — Get CTM Phone Numbers and Release CTM Numbers will both refuse this row; Set Call Routing works on it fine"' : '') +
              ' onclick="msCtmToggleOne(this)"></td>'
            : '<td><input type="checkbox" disabled title="Add a domain first — CTM actions need one" style="width:20px;height:20px;"></td>';
        let cells;
        if (editing) {
            cells = numCell + checkCell + '<td>' + msStatusBadge(r.status) + '</td>' +
                '<td>' + domainCell + '</td>' +
                '<td><select class="ms-row-input" data-f="registrar" style="width:110px;">' +
                    '<option value=""' + (!f.registrar ? ' selected' : '') + '>—</option>' +
                    msBuyRegistrars.map(reg => '<option value="' + esc(reg) + '"' + (f.registrar === reg ? ' selected' : '') + '>' + esc(msBuyRegistrarLabels[reg] || reg) + '</option>').join('') +
                '</select></td>' +
                '<td style="text-align:center;">' + msBoughtCell(r.owned) + '</td>' +
                '<td><select class="ms-row-input" data-f="box" style="width:48px;">' +
                    '<option value=""' + (!f.box ? ' selected' : '') + '>—</option>' +
                    Array.from({length: 20}, (_, n) => n + 1).map(n => '<option value="' + n + '"' + (String(f.box) === String(n) ? ' selected' : '') + '>' + n + '</option>').join('') +
                '</select></td>' +
                '<td><select class="ms-row-input" data-f="cf_account" style="width:54px;">' +
                    '<option value=""' + (f.cf_account === '' || f.cf_account === undefined ? ' selected' : '') + '>—</option>' +
                    [0, 1, 2, 3].map(n => '<option value="' + n + '"' + (String(f.cf_account) === String(n) ? ' selected' : '') + '>.' + n + '</option>').join('') +
                '</select></td>' +
                '<td><input class="ms-row-input" data-f="business" value="' + esc(f.business || '') + '" style="width:140px;"></td>' +
                '<td><input class="ms-row-input" data-f="area_code" maxlength="3" value="' + esc(f.area_code || '') + '" style="width:60px;"></td>' +
                '<td><input class="ms-row-input" data-f="phone" value="' + esc(f.phone || '') + '" style="width:110px;"></td>' +
                '<td>' + msRoutesToCell(f) + '</td>' +
                '<td><input class="ms-row-input" data-f="city" value="' + esc(f.city || '') + '" style="width:110px;"></td>' +
                '<td><input class="ms-row-input" data-f="state" value="' + esc(f.state || '') + '" style="width:100px;" placeholder="Florida"></td>' +
                '<td><input class="ms-row-input" data-f="SS" maxlength="2" value="' + esc(f.SS || '') + '" style="width:40px;"></td>' +
                '<td><button type="button" class="btn btn-primary" onclick="' + (isLive ? 'msRowSaveCorrect' : 'msRowSave') + "('" + (isLive ? domain.replace(/'/g, "\\'") : keyEsc) + "')" + '">' + (isLive ? 'Save &amp; Regenerate' : 'Save') + '</button> ' +
                '<button type="button" class="btn" onclick="msRowEditCancel()">Cancel</button>' + msPoolNotice(r.pool, domain) + '</td>';
        } else {
            cells = numCell + checkCell + '<td>' + msStatusBadge(r.status) + '</td>' +
                '<td>' + domainCell + '</td>' +
                '<td>' + esc(msBuyRegistrarLabels[f.registrar] || f.registrar || '') + '</td>' +
                '<td style="text-align:center;">' + msBoughtCell(r.owned) + '</td>' +
                '<td>' + esc(f.box || '') + '</td>' +
                '<td>' + (f.cf_account !== undefined && f.cf_account !== '' ? '.' + esc(f.cf_account) : '') + '</td>' +
                '<td>' + esc(f.business || '') + '</td>' +
                '<td>' + esc(f.area_code || '') + '</td>' +
                '<td>' + esc(f.phone || '') + '</td>' +
                '<td>' + msRoutesToCell(f) + '</td>' +
                '<td>' + esc(f.city || '') + '</td>' +
                '<td>' + esc(f.state || '') + '</td>' +
                '<td>' + esc(f.SS || '') + '</td>' +
                '<td>' + (isLive
                    ? '<button type="button" class="btn" onclick="msRowUnlock(\'' + domain.replace(/'/g, "\\'") + '\')">Correct &amp; Regenerate</button> ' +
                      '<span title="The resulting Save and Regenerate is a background job — safe to navigate away or close this tab once you click it, it keeps running on the server." style="display:inline-block;background:#1e3a8a;color:#fff;font-weight:800;font-size:.68rem;line-height:1;border-radius:3px;padding:2px 5px;cursor:help;vertical-align:middle;">B</span> ' +
                      '<span class="hint" style="cursor:pointer;font-size:1.05rem;" onclick="bpShowInfo(this)" data-info="No photo PROCESSING happens — the cosmetic hero-stamp/crop-jitter pass is skipped outright, since it is deterministic per domain and this button has no reason to spend time re-doing it. Upload itself is completely normal: it only ever sends a file whose content actually changed, so your existing live photos are never overwritten by this button, but a page that genuinely needs a photo it never had before (new content, not yet live) still gets it — this is NOT a blanket photo block, just real incremental sync like every other file.\n\nYou can safely leave this screen or close the tab once you click Save and Regenerate — this runs as one real background job on the server, not something your browser has to stay open to finish.\n\nSaves the edited fields on this row, then automatically rebuilds just this one domain (Clone, Identity, Landing pages, Differentiate, Visual identity, AI content, Build) and uploads it live once that finishes. Every step is always full strength, with no caching shortcuts beyond the Images skip described above.\n\nIf another build is already running in this batch, this is queued, not lost — it waits its turn and launches automatically once the batch frees up, even if you close this tab or the page reloads in the meantime.">ⓘ</span>'
                    : '<button type="button" class="btn" onclick="msRowEditStart(\'' + keyEsc + '\')">Edit</button> ' +
                      '<button type="button" class="btn" style="color:#991b1b;" onclick="msRowDelete(\'' + keyEsc + '\')">Delete</button>') +
                msPoolNotice(r.pool, domain) +
                '</td>';
        }
        return '<tr data-key="' + esc(key) + '" data-domain="' + esc(domain) + '">' + cells + '</tr>' +
            '<tr data-domain-progress="' + esc(domain) + '" style="display:none;"><td colspan="16"><div class="ms-row-progress hint"></div></td></tr>';
    }

    async function msLoadRows() {
        try {
            const d = await (await fetch('multisite_api.php?action=row_list')).json();
            msRows = {};
            msRowOrder = [];
            (d.rows || []).forEach(r => { msRows[r.key] = { domain: r.domain, fields: r.fields, status: r.status, owned: r.owned, pool: r.pool }; msRowOrder.push(r.key); });
        } catch (e) { /* leave whatever was already shown */ }
        // Drop selections for domains that no longer exist (deleted, or never matched).
        Object.keys(msCtmSelected).forEach(d => { if (!msRows[d]) delete msCtmSelected[d]; });
        msRenderRowsTable();
        msCtmUpdateCount();
        msResumeQueuedBuilds();
    }

    // Reattaches the right poller for any domain with a build still queued/running/
    // just-finished in THIS batch — one request for the whole table, so a page
    // reload (or any other msLoadRows() refresh) picks back up exactly where a
    // Correct & Regenerate left off, even if it was queued behind something else
    // and the page was closed the whole time it waited.
    async function msResumeQueuedBuilds() {
        let d;
        try { d = await (await fetch('multisite_api.php?action=rollout_build_queue_list')).json(); }
        catch (e) { return; }
        let queuedSeen = 0;
        (d.entries || []).forEach(entry => {
            if (entry.status === 'queued') queuedSeen++;
            (entry.domains || []).forEach(domain => {
                if (!msRows[domain]) return; // not a row in this batch's current table
                if (entry.status === 'queued') {
                    const el = msProgressEl(domain);
                    if (el) el.textContent = 'Waiting for another build in this batch to finish… (position ' + queuedSeen + ')';
                    msPollQueuedBuild(domain);
                } else if (entry.status === 'running' && entry.run_id) {
                    const el = msProgressEl(domain);
                    if (el) el.textContent = 'Resuming — working on ' + domain + '…';
                    msPollRegenBuild(domain, entry.run_id);
                } else if (entry.status === 'done') {
                    msRenderBuildDone(domain, !!entry.dead, entry.exit);
                }
            });
        });
    }

