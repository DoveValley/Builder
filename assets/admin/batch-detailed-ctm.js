// batch-detailed.js, part 4/4 — Batch Detailed: CTM phone provisioning (Get/Set
// Routing/Release/Resync), Buy Domains, Area code check, per-row save/delete/
// correct/add, and Correct & Regenerate's background rebuild polling.
//
// Same split as part 1 (see its header) — plain top-level code, shares global
// scope with the other 3 parts. Loads LAST: ends with the page's actual kickoff
// (msLoadRows()/msResumeAreaCodeCheck()/msResumeCtmBulk()/msResumeDomainBuy()),
// which needs every other part already loaded and is why this one must load
// after them, not before.
    // ── "Get CTM Phone Numbers" — isolated capability, see includes/calltrackingmetrics.php.
    window.msCtmToggleOne = function (cb) {
        const domain = cb.dataset.domain;
        if (cb.checked) msCtmSelected[domain] = true; else delete msCtmSelected[domain];
        msCtmUpdateCount();
    };

    window.msCtmToggleAll = function (cb) {
        document.querySelectorAll('#ms-rows-table .ms-ctm-check:not(:disabled)').forEach(el => {
            el.checked = cb.checked;
            if (cb.checked) msCtmSelected[el.dataset.domain] = true; else delete msCtmSelected[el.dataset.domain];
        });
        msCtmUpdateCount();
    };

    // Same checkbox selection drives all six bulk actions below.
    function msCtmUpdateCount() {
        const n = Object.keys(msCtmSelected).length;
        ['ms-ctm-count', 'ms-route-count', 'ms-release-count', 'ms-resync-count', 'ms-areacode-count', 'ms-buy-count', 'ms-ctm-selbar-count']
            .forEach(id => { const el = document.getElementById(id); if (el) el.textContent = n; });
    }

    // ── Quick-select bar above the Target List table. Mirrors Batch Simple's
    // ready/scheduled/not-uploaded shortcuts, but this table drives several
    // different bulk actions (Get Numbers, Set Routing, Release, Resync, Area Code
    // Check, Buy Domains) instead of one, so the filters are the ones that matter
    // for THIS table's own fields (phone/routing/live), not Simple's go-live steps.
    // Additive like Simple's, not a replace — "none" is the only clearing action.
    window.msCtmQuickSelect = function (kind) {
        if (kind === 'none') { msCtmSelected = {}; msRenderRowsTable(); msCtmUpdateCount(); msCtmSelbarMsg(''); return; }
        // Domain-less rows have no checkbox at all (disabled — see msRenderRowPair),
        // so there is nothing to add to msCtmSelected for them. Flash + scroll
        // instead of pretending they were "selected".
        if (kind === 'nodomain') {
            const keys = msRowOrder.filter(key => !(msRows[key] || {}).domain);
            msCtmFlashRows(keys);
            msCtmSelbarMsg(keys.length ? keys.length + (keys.length === 1 ? ' row needs' : ' rows need') + ' a domain — no checkbox until one is added (Edit)' : 'Every row already has a domain.');
            return;
        }
        msRowOrder.forEach(key => {
            const r = msRows[key] || {};
            const f = r.fields || {};
            const domain = r.domain || '';
            if (!domain) return; // disabled checkbox, nothing to select
            const isLive = r.status === 'live';
            let match = false;
            if (kind === 'nonumber') match = !f.phone && !isLive;
            else if (kind === 'noroute') match = !!f.phone && !f.forward_to;
            else if (kind === 'live') match = isLive;
            else if (kind === 'notlive') match = !isLive;
            if (match) msCtmSelected[domain] = true;
        });
        msRenderRowsTable();
        msCtmUpdateCount();
        msCtmSelbarMsg('');
    };

    window.msCtmRangeTick = function (cb) {
        const start = parseInt(document.getElementById('ms-ctm-range-start').value, 10);
        const end = parseInt(document.getElementById('ms-ctm-range-end').value, 10);
        if (!start || !end || start > end) {
            msCtmSelbarMsg('Enter a valid start and end row number first (the # column).');
            cb.checked = false;
            return;
        }
        msRowOrder.forEach((key, i) => {
            const n = i + 1;
            if (n < start || n > end) return;
            const domain = (msRows[key] || {}).domain || '';
            if (!domain) return; // disabled checkbox, nothing to toggle
            if (cb.checked) msCtmSelected[domain] = true; else delete msCtmSelected[domain];
        });
        msRenderRowsTable();
        msCtmUpdateCount();
        msCtmSelbarMsg('');
    };

    function msCtmSelbarMsg(text) {
        const el = document.getElementById('ms-ctm-selbar-msg');
        if (el) el.textContent = text;
    }

    function msCtmFlashRows(keys) {
        if (!keys.length) return;
        const first = document.querySelector('#ms-rows-table tr[data-key="' + CSS.escape(keys[0]) + '"]');
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        keys.forEach(key => {
            const tr = document.querySelector('#ms-rows-table tr[data-key="' + CSS.escape(key) + '"]');
            if (!tr) return;
            tr.style.transition = 'background-color 0.3s';
            tr.style.backgroundColor = '#fef3c7';
            setTimeout(() => { tr.style.backgroundColor = ''; }, 2000);
        });
    }

    // ── Shared launch+poll plumbing for all 4 CTM bulk actions below. Each one now
    // runs as a detached background job (multisite/ctm_bulk.php) instead of one
    // synchronous fetch — same reasoning as "Insert/verify primary area code
    // selected": a double-digit selection costs several real seconds per domain
    // (CTM's own pool-and-retry search), long enough to silently hit Apache's ~30s
    // request timeout mid-batch. The actions stay SEQUENTIAL inside that job (not
    // parallel like the area-code AI lookups) — see ctm_bulk.php's own docblock for
    // why: CTM's search endpoint is not reliably deterministic, so concurrent
    // buys/releases risk two domains colliding on the same number. Only the
    // *visibility* changed here, not the order or the logic.
    const msCtmBulkTimers = {};
    // statusAction defaults to 'ctm_bulk_status' when omitted — only buy_domains
    // needs its own (own directory/lock server-side, see domainbuy_launch() in
    // multisite_api.php; buying domains and CTM phone actions share no resource).
    const msCtmBulkIds = {
        ctm_get_numbers:     { btn: 'ms-ctm-btn',     msg: 'ms-ctm-msg',     out: 'ms-ctm-out',     results: 'ms-ctm-results',     details: 'ms-ctm-details',     clearSelectionAfter: true },
        ctm_set_routing:     { btn: 'ms-route-btn',   msg: 'ms-route-msg',   out: 'ms-route-out',   results: 'ms-route-results',   details: 'ms-route-details',   clearSelectionAfter: false },
        ctm_release_numbers: { btn: 'ms-release-btn', msg: 'ms-release-msg', out: 'ms-release-out', results: 'ms-release-results', details: 'ms-release-details', clearSelectionAfter: true },
        ctm_resync_numbers:  { btn: 'ms-resync-btn',  msg: 'ms-resync-msg',  out: 'ms-resync-out',  results: 'ms-resync-results',  details: 'ms-resync-details',  clearSelectionAfter: false },
        buy_domains:         { btn: 'ms-buy-btn',     msg: 'ms-buy-msg',     out: 'ms-buy-out',     results: 'ms-buy-results',     details: 'ms-buy-details',     clearSelectionAfter: true, launchAction: 'domain_buy_bulk', statusAction: 'domain_buy_bulk_status' },
    };

    function msRenderCtmBulkResults(action, resEl, rows) {
        if (action === 'ctm_get_numbers') {
            resEl.innerHTML = rows.map(r => {
                if (r.ok) {
                    const warn = r.warning ? ' <span style="color:#b45309;">— ' + esc(r.warning) + '</span>' : '';
                    return '<div style="color:#166534;">&#10003; ' + esc(r.domain) + ' → ' + esc(r.phone) + ' (area code ' + esc(r.area_code) + ')' + warn + '</div>';
                }
                return '<div style="color:#991b1b;">&#10007; ' + esc(r.domain) + ' — ' + esc(r.error) + '</div>';
            }).join('');
        } else if (action === 'ctm_set_routing') {
            resEl.innerHTML = rows.map(r => {
                if (!r.ok) return '<div style="color:#991b1b;">&#10007; ' + esc(r.domain) + ' — ' + esc(r.error) + '</div>';
                if (r.pending) return '<div style="color:#b45309;">&#8634; ' + esc(r.domain) + ' → ' + esc(r.destination) + ' (saved — no CTM number yet, applies automatically once it has one)</div>';
                return '<div style="color:#166534;">&#10003; ' + esc(r.domain) + ' → ' + esc(r.destination) + '</div>';
            }).join('');
        } else if (action === 'ctm_release_numbers') {
            resEl.innerHTML = rows.map(r => {
                if (!r.ok) return '<div style="color:#991b1b;">&#10007; ' + esc(r.domain) + ' — ' + esc(r.error) + '</div>';
                return '<div style="color:#166534;">&#10003; ' + esc(r.domain) + ' — released ' + esc(r.released_phone) + '</div>';
            }).join('');
        } else if (action === 'ctm_resync_numbers') {
            resEl.innerHTML = rows.map(r => {
                if (!r.ok) return '<div style="color:#991b1b;">&#10007; ' + esc(r.domain) + ' — ' + esc(r.error) + '</div>';
                const changes = [];
                if (r.relabeled) changes.push('label fixed');
                if (r.area_synced) changes.push('area code → ' + esc(r.area_code));
                if (r.routing === 'synced') changes.push('routing re-applied');
                if (r.routing === 'failed') changes.push('routing FAILED');
                const summary = changes.length ? changes.join(', ') : 'already in sync';
                const warn = r.warning ? ' <span style="color:#b45309;">— ' + esc(r.warning) + '</span>' : '';
                return '<div style="color:#166534;">&#10003; ' + esc(r.domain) + ' — ' + summary + warn + '</div>';
            }).join('');
        } else if (action === 'buy_domains') {
            resEl.innerHTML = rows.map(r => {
                const via = r.registrar ? ' (' + esc(r.registrar) + ')' : '';
                if (!r.ok) return '<div style="color:#991b1b;">&#10007; ' + esc(r.domain) + via + ' — ' + esc(r.error) + '</div>';
                if (r.pending) return '<div style="color:#b45309;">&#8634; ' + esc(r.domain) + via + ' — ' + esc(r.message) + '</div>';
                return '<div style="color:#166534;">&#10003; ' + esc(r.domain) + via + ' — bought</div>';
            }).join('');
        }
    }

    // Shared miss-tracker (assets/admin/ms-poll.js), one per action since each of the
    // 4 bulk actions polls its own output element independently. Found live during a
    // fleet-wide poll audit (2026-11-02): this catch used to be empty — a persistent
    // failure left the button disabled forever with zero feedback and no way to tell
    // whether the job was still running server-side or the browser had just lost it.
    const msCtmBulkMissTrackers = {};
    function msCtmBulkTracker(action) {
        if (!msCtmBulkMissTrackers[action]) {
            msCtmBulkMissTrackers[action] = msPollMissTracker(() => {
                const out = document.getElementById(msCtmBulkIds[action].out);
                if (out) out.textContent += '\n\n✗ Lost contact with the server — still trying… (the job may still be running; reload to check)';
            });
        }
        return msCtmBulkMissTrackers[action];
    }
    function msPollCtmBulk(action, runId) {
        const ids = msCtmBulkIds[action];
        fetch('multisite_api.php?action=' + (ids.statusAction || 'ctm_bulk_status') + '&run_id=' + encodeURIComponent(runId))
            .then(r => r.json())
            .then(d => {
                msCtmBulkTracker(action).reset();
                const out = document.getElementById(ids.out);
                if (d.none) { out.textContent = 'Starting…'; return; }
                out.textContent = d.output || '';
                out.scrollTop = out.scrollHeight;
                if (!d.done) return;
                if (msCtmBulkTimers[action]) clearInterval(msCtmBulkTimers[action]);
                document.getElementById(ids.btn).disabled = false;
                const msgEl = document.getElementById(ids.msg);
                const ok = !d.dead && d.exit === 0;
                msgEl.textContent = d.dead ? 'stopped unexpectedly' : (ok ? 'done' : 'failed (exit ' + d.exit + ')');
                msgEl.style.color = ok ? '#166534' : '#991b1b';
                if (d.report) msRenderCtmBulkResults(action, document.getElementById(ids.results), d.report.results || []);
                if (ids.clearSelectionAfter) msCtmSelected = {};
                msLoadRows();
            })
            .catch(() => msCtmBulkTracker(action).miss());
    }

    function msCtmBulkWatch(action, runId) {
        if (msCtmBulkTimers[action]) clearInterval(msCtmBulkTimers[action]);
        msCtmBulkTimers[action] = setInterval(() => msPollCtmBulk(action, runId), 2500);
        msPollCtmBulk(action, runId);
    }

    // Found live 2026-10-27, same gap as the area-code button below: these are
    // detached background jobs, so they keep running — and their report stays on
    // disk — independent of the browser, but the output panel is plain JS state and
    // used to go blank on navigating away and back even after a successful run.
    // Called once on page load. All 4 actions share ONE job directory/lock
    // (ctmb_launch — only one of the four can run at a time for a batch), so there is
    // only ever one "latest run" to resume; which of the 4 cards it belongs to comes
    // back as 'action' in the status response (see ctmb_launch's sidecar file and the
    // ctm_bulk_status case in multisite_api.php — the report alone can't answer this
    // while the job is still running, since it's only written at the very end).
    // Asks the shared ctm_bulk_status endpoint which of the 4 shared-lock CTM
    // actions a running job really belongs to, then watches it under the right
    // card. Needed anywhere a run_id might belong to a DIFFERENT one of the 4
    // than whichever action is in hand locally — true both on page load (nothing
    // local to go on at all) and when a "busy" error hands back a real run_id
    // that may not be the action just clicked, since all 4 share one lock.
    function msResolveAndWatchCtmBulk() {
        fetch('multisite_api.php?action=ctm_bulk_status')
            .then(r => r.json())
            .then(d => {
                if (d.none || !d.run_id || !d.action) return;
                const action = 'ctm_' + d.action;
                const ids = msCtmBulkIds[action];
                if (!ids) return;
                document.getElementById(ids.out).style.display = 'block';
                if (!d.done) {
                    document.getElementById(ids.btn).disabled = true;
                    document.getElementById(ids.msg).textContent = 'Working… (resumed)';
                }
                msCtmBulkWatch(action, d.run_id);
            })
            .catch(() => {});
    }
    function msResumeCtmBulk() { msResolveAndWatchCtmBulk(); }

    // Same resume reasoning as msResumeCtmBulk above, for buy_domains' own
    // directory/lock (domainbuy_launch) — a separate job family, so it needs its
    // own "anything still running?" check rather than overloading ctm_bulk_status.
    // No prefix mapping needed here: the report's 'action' field is already the
    // literal msCtmBulkIds key ('buy_domains'), not a bare CTM action name.
    function msResumeDomainBuy() {
        fetch('multisite_api.php?action=domain_buy_bulk_status')
            .then(r => r.json())
            .then(d => {
                if (d.none || !d.run_id || !d.action) return;
                const ids = msCtmBulkIds[d.action];
                if (!ids) return;
                document.getElementById(ids.out).style.display = 'block';
                if (!d.done) {
                    document.getElementById(ids.btn).disabled = true;
                    document.getElementById(ids.msg).textContent = 'Working… (resumed)';
                }
                msCtmBulkWatch(d.action, d.run_id);
            })
            .catch(() => {});
    }

    async function msCtmBulkStart(action, extraFields, confirmMsg) {
        const domains = Object.keys(msCtmSelected);
        const ids = msCtmBulkIds[action];
        const btn = document.getElementById(ids.btn);
        const msgEl = document.getElementById(ids.msg);
        const out = document.getElementById(ids.out);
        const resEl = document.getElementById(ids.results);
        if (!domains.length) { msgEl.textContent = 'Select at least one row first.'; return; }
        if (confirmMsg && !confirm(confirmMsg)) return;

        btn.disabled = true;
        msgEl.textContent = 'Working…'; msgEl.style.color = '';
        document.getElementById(ids.details).open = true;   // a run you just started should be visible, not need an extra click to watch
        out.style.display = 'block'; out.textContent = 'Starting…';
        resEl.innerHTML = '';

        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        domains.forEach(d => fd.append('domains[]', d));
        Object.keys(extraFields).forEach(k => fd.append(k, extraFields[k]));

        // launchAction covers the one case (buy_domains) whose real server-side
        // action is named differently from this client-side key — found via a
        // real stuck "Starting…" with no further feedback: the fetch below was
        // silently hitting 'Unknown action' (400) because this used to always
        // fetch '?action=' + action verbatim, and buy_domains's actual case is
        // domain_buy_bulk (the underlying script's name). The error WAS being
        // set on msgEl even then — just not on out, which is why the big output
        // box looked frozen while a small, easy-to-miss message sat elsewhere.
        let d;
        try { d = await (await fetch('multisite_api.php?action=' + (ids.launchAction || action), { method: 'POST', body: fd })).json(); }
        catch (e) { btn.disabled = false; msgEl.textContent = 'Could not reach the server.'; out.textContent = 'Could not reach the server.'; return; }
        if (d.error) {
            msgEl.textContent = d.error;
            out.textContent = d.error;   // never leave the output box stuck on "Starting…" when the message span is easy to miss
            // Carries the live run_id when this action is already running for this
            // batch — resume watching it instead of leaving the button disabled with
            // no feedback, same as the area-code button.
            //
            // ids.statusAction (buy_domains only) means an exclusive lock — this
            // run_id can only ever be its own, so watching it directly under the
            // action just clicked is correct. The 4 CTM actions share ONE lock
            // instead: the run_id handed back here can belong to a DIFFERENT one
            // of the 4 than whichever was just clicked — found live, where
            // clicking Resync while Get Numbers was still running rendered Get
            // Numbers' real report through Resync's renderer as a false "done."
            // Resolve which one it really is via the status endpoint instead of
            // trusting the local guess.
            if (d.run_id) {
                if (ids.statusAction) msCtmBulkWatch(action, d.run_id);
                else msResolveAndWatchCtmBulk();
            }
            else btn.disabled = false;
            return;
        }
        msgEl.textContent = '';
        msCtmBulkWatch(action, d.run_id);
    }

    window.msCtmGetNumbers = function () {
        const domains = Object.keys(msCtmSelected);
        const accountId = document.getElementById('ms-ctm-account').value.trim();
        if (!accountId) { document.getElementById('ms-ctm-msg').textContent = 'Enter a CTM sub-account ID first.'; return; }
        if (!domains.length) { document.getElementById('ms-ctm-msg').textContent = 'Select at least one row first.'; return; }
        msCtmBulkStart('ctm_get_numbers', { account_id: accountId },
            'Buy a real CallTrackingMetrics number for ' + domains.length + ' domain(s) under sub-account ' + accountId + '? This is a real purchase, not a preview.');
    };

    // ── "Set Call Routing" — same isolated capability, see
    // ctm_set_routing_for_domain() in includes/calltrackingmetrics.php. Reuses
    // msCtmSelected rather than its own selection state: one set of checkboxes drives
    // both "buy a number" and "point it somewhere", since they're usually done in that
    // order for the same rows.
    window.msCtmSetRouting = function () {
        const domains = Object.keys(msCtmSelected);
        const accountId = document.getElementById('ms-route-account').value.trim();
        const destination = document.getElementById('ms-route-dest').value.trim();
        if (!accountId) { document.getElementById('ms-route-msg').textContent = 'Enter a CTM sub-account ID first.'; return; }
        if (!destination) { document.getElementById('ms-route-msg').textContent = 'Enter a destination phone number first.'; return; }
        if (!domains.length) { document.getElementById('ms-route-msg').textContent = 'Select at least one row first.'; return; }
        msCtmBulkStart('ctm_set_routing', { account_id: accountId, destination: destination },
            'Point ' + domains.length + ' domain(s) at ' + destination + '?\n\nFor any row with a CTM tracking number already, this replaces whatever it currently routes to, for real, in CTM. A row with no number yet just has the destination saved for when it gets one.');
    };

    // ── "Release CTM Numbers" — the inverse of Get CTM Phone Numbers, see
    // ctm_release_number()/ctm_number_by_phone() in includes/calltrackingmetrics.php.
    // Reuses msCtmSelected same as Set Call Routing above.
    window.msCtmReleaseNumbers = function () {
        const domains = Object.keys(msCtmSelected);
        const accountId = document.getElementById('ms-release-account').value.trim();
        if (!accountId) { document.getElementById('ms-release-msg').textContent = 'Enter a CTM sub-account ID first.'; return; }
        if (!domains.length) { document.getElementById('ms-release-msg').textContent = 'Select at least one row first.'; return; }
        msCtmBulkStart('ctm_release_numbers', { account_id: accountId },
            'Release the CTM tracking number for ' + domains.length + ' domain(s) under sub-account ' + accountId + ' and clear Phone/Area Code here?\n\nThis is a real release back to the pool, not a preview — those numbers stop ringing immediately.');
    };

    // ── "Resync from CTM" — for a number bought by hand directly in CTM, see
    // ctm_number_by_phone()/ctm_build_label()/ctm_set_routing_for_domain() in
    // includes/calltrackingmetrics.php. Reuses msCtmSelected same as Set Call Routing
    // and Release above. Does not reset selection after running — chainable, same
    // reasoning as Set Call Routing (not a one-shot purchase/release).
    window.msCtmResyncNumbers = function () {
        const domains = Object.keys(msCtmSelected);
        const accountId = document.getElementById('ms-resync-account').value.trim();
        if (!accountId) { document.getElementById('ms-resync-msg').textContent = 'Enter a CTM sub-account ID first.'; return; }
        if (!domains.length) { document.getElementById('ms-resync-msg').textContent = 'Select at least one row first.'; return; }
        msCtmBulkStart('ctm_resync_numbers', { account_id: accountId },
            'Sync CTM\'s label, area code, and call routing for ' + domains.length + ' domain(s) under sub-account ' + accountId + ' to match what\'s on file?\n\nReal CTM writes for anything found out of sync — not a preview.');
    };

    // ── "Buy Domains" — reuses msCtmSelected same as the four CTM actions above
    // (same readiness gate: a row needs a domain set either way). 100%
    // infra_domain_buy() underneath (admin/infra/lib/acquire.php, see
    // multisite/domain_buy_bulk.php) — real money, not reversible, so the confirm()
    // text says so plainly, same pattern as msCtmGetNumbers' own real-purchase confirm.
    // Registrar is now OPTIONAL: blank (the default option) means each checked row
    // buys through its OWN params.csv `registrar` column — a pre-planned spread
    // across registrars executes in one click, no manual grouping. Picking an
    // explicit registrar here overrides every checked row to that one, same as the
    // original single-registrar behavior.
    window.msDomainBuy = async function () {
        const domains = Object.keys(msCtmSelected);
        const sel = document.getElementById('ms-buy-registrar');
        const registrar = sel ? sel.value : '';
        const msgEl = document.getElementById('ms-buy-msg');
        if (!domains.length) { msgEl.textContent = 'Select at least one row first.'; return; }

        // Funding pre-flight: resolve which registrar each checked row would
        // actually buy through, and check its REAL balance against a
        // conservative flat estimate before the confirm dialog even shows —
        // a registrar running dry partway through a mixed-registrar run is
        // what this catches (multisite_api.php's buy_funding_check).
        msgEl.textContent = 'Checking registrar funding…';
        let fundingNote = '';
        try {
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            domains.forEach(d => fd.append('domains[]', d));
            fd.append('registrar', registrar);
            const fc = await (await fetch('multisite_api.php?action=buy_funding_check', { method: 'POST', body: fd })).json();
            if (!fc.error && fc.registrars) {
                const short = fc.registrars.filter(r => r.sufficient === false);
                const unknown = fc.registrars.filter(r => r.sufficient === null);
                const lines = [];
                short.forEach(r => lines.push('  ⚠ ' + r.registrar + ': balance ' + r.balance + ' ' + (r.currency || '') + ' looks too low for ' + r.count + ' domain(s) (~' + r.estimated_cost + ' est.)'));
                unknown.forEach(r => lines.push('  ? ' + r.registrar + ': no balance API — ' + r.count + ' domain(s), can\'t verify funds automatically'));
                if (lines.length) fundingNote = '\n\nFunding check (estimated, not exact pricing):\n' + lines.join('\n');
            }
        } catch (e) { /* funding check failing silently just means no warning shown — the real buy's own per-domain checks still apply */ }
        msgEl.textContent = '';

        const confirmMsg = registrar
            ? 'Buy ' + domains.length + ' real domain(s) via ' + sel.options[sel.selectedIndex].text + '? This is a real purchase, not a preview.\n\nEach domain is re-checked for availability right before buying — one already taken or already owned is skipped and reported, not charged for.' + fundingNote
            : 'Buy ' + domains.length + ' real domain(s), each through its OWN registrar column value? This is a real purchase, not a preview.\n\nA row with no registrar assigned is skipped and reported, not guessed at. Each domain is re-checked for availability right before buying — one already taken or already owned is skipped and reported, not charged for.' + fundingNote;
        msCtmBulkStart('buy_domains', { registrar: registrar }, confirmMsg);
    };

    // ── "Insert/verify primary area code selected" — real AI-plus-web-search check,
    // see ctm_verify_area_code_via_ai() in includes/calltrackingmetrics.php. Reuses
    // msCtmSelected same as the other four actions above. Unlike those (synchronous,
    // one request), this is a detached background job — real per-city API+web-search
    // latency, same reasoning as "Research cities" — so it launches+polls instead of
    // a single fetch.
    let msAreaCodeTimer = null;
    window.msAreaCodeCheck = async function () {
        const domains = Object.keys(msCtmSelected);
        const msgEl = document.getElementById('ms-areacode-msg');
        const out = document.getElementById('ms-areacode-out');
        if (!domains.length) { msgEl.textContent = 'Select at least one row first.'; return; }

        document.getElementById('ms-areacode-btn').disabled = true;
        msgEl.textContent = 'Working…';
        document.getElementById('ms-areacode-details').open = true;   // a run you just started should be visible, not need an extra click to watch
        out.style.display = 'block'; out.textContent = 'Starting…';
        document.getElementById('ms-areacode-results').innerHTML = '';

        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        domains.forEach(d => fd.append('domains[]', d));

        let d;
        try { d = await (await fetch('multisite_api.php?action=check_area_codes', { method: 'POST', body: fd })).json(); }
        catch (e) { document.getElementById('ms-areacode-btn').disabled = false; msgEl.textContent = 'Could not reach the server.'; return; }
        if (d.error) {
            msgEl.textContent = d.error;
            // Carries the live run_id when a check is already running for this batch —
            // resume watching it instead of leaving the button disabled with no feedback.
            if (d.run_id) { if (msAreaCodeTimer) clearInterval(msAreaCodeTimer); msAreaCodeTimer = setInterval(() => msPollAreaCode(d.run_id), 2500); msPollAreaCode(d.run_id); }
            else document.getElementById('ms-areacode-btn').disabled = false;
            return;
        }
        msgEl.textContent = '';
        if (msAreaCodeTimer) clearInterval(msAreaCodeTimer);
        msAreaCodeTimer = setInterval(() => msPollAreaCode(d.run_id), 2500);
        msPollAreaCode(d.run_id);
    };

    function msRenderAreaCodeReport(report) {
        const box = document.getElementById('ms-areacode-results');
        const rows = (report && report.results) || [];
        if (!rows.length) { box.innerHTML = ''; return; }
        const ACTION_LABEL = { inserted: 'INSERTED', suggested: 'SUGGESTED (live)', correct: 'correct', wrong: 'WRONG', error: 'ERROR' };
        const ACTION_COLOR = { inserted: '#166534', suggested: '#92400e', correct: '#166534', wrong: '#991b1b', error: '#92400e' };
        const ins  = rows.filter(r => r.action === 'inserted');
        const mism = rows.filter(r => r.action === 'wrong');
        const errs = rows.filter(r => r.action === 'error');
        let html = '<table style="width:100%;font-size:.85rem;border-collapse:collapse;margin-top:8px;"><thead><tr>' +
            '<th style="text-align:left;">Domain</th><th style="text-align:left;">City</th><th style="text-align:left;">On file</th>' +
            '<th style="text-align:left;">Action</th><th style="text-align:left;">Primary code</th><th style="text-align:left;">Evidence</th></tr></thead><tbody>';
        rows.forEach(r => {
            const showCode = r.action === 'inserted' || r.action === 'suggested' || r.action === 'wrong';
            html += '<tr>' +
                '<td>' + esc(r.domain) + '</td>' +
                '<td>' + esc((r.city || '') + (r.ss ? ', ' + r.ss : '')) + '</td>' +
                '<td>' + esc(r.on_file || '(blank)') + '</td>' +
                '<td style="color:' + (ACTION_COLOR[r.action] || '#334155') + ';font-weight:700;">' + (ACTION_LABEL[r.action] || r.action) + '</td>' +
                '<td>' + (showCode ? '<strong>' + esc(r.correct_code) + '</strong>' : '') + '</td>' +
                '<td class="hint">' + esc(r.action === 'error' ? r.error : (r.evidence || '')) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        box.innerHTML = '<p class="hint" style="margin:8px 0 0;"><strong>' + ins.length + ' inserted</strong>, '
            + '<strong>' + mism.length + ' mismatch(es)</strong>'
            + (errs.length ? ', ' + errs.length + ' error(s)' : '') + ' out of ' + rows.length + ' checked.</p>' + html;
    }

    // Shared miss-tracker (assets/admin/ms-poll.js) — same empty-catch gap as
    // msPollCtmBulk above, found in the same audit.
    const msAreaCodeTracker = msPollMissTracker(() => {
        const out = document.getElementById('ms-areacode-out');
        if (out) out.textContent += '\n\n✗ Lost contact with the server — still trying… (the job may still be running; reload to check)';
    });
    function msPollAreaCode(runId) {
        fetch('multisite_api.php?action=check_area_codes_status&run_id=' + encodeURIComponent(runId))
            .then(r => r.json())
            .then(d => {
                msAreaCodeTracker.reset();
                const out = document.getElementById('ms-areacode-out');
                if (d.none) { out.textContent = 'Starting…'; return; }
                out.textContent = d.output || '';
                out.scrollTop = out.scrollHeight;
                if (!d.done) return;
                if (msAreaCodeTimer) clearInterval(msAreaCodeTimer);
                document.getElementById('ms-areacode-btn').disabled = false;
                const msgEl = document.getElementById('ms-areacode-msg');
                const ok = !d.dead && d.exit === 0;
                msgEl.textContent = d.dead ? 'stopped unexpectedly' : (ok ? 'done' : 'failed (exit ' + d.exit + ')');
                msgEl.style.color = ok ? '#166534' : '#991b1b';
                if (d.report) msRenderAreaCodeReport(d.report);
                msCtmSelected = {};
                msLoadRows();   // pick up any area_code this run just inserted
            })
            .catch(() => msAreaCodeTracker.miss());
    }

    // Found live 2026-10-27: this is a detached background job (see msAreaCodeCheck
    // above), so it keeps running — and its report stays on disk — independent of
    // the browser. But the output panel itself is plain JS state, so navigating away
    // and back used to show nothing even though the job had finished successfully in
    // the background. Called once on page load: asks for the latest run with no
    // run_id (ms_job_resolve_run's own "no id given, find the latest" behavior,
    // already used by every other job panel that supports a page reload mid-run),
    // then resumes polling it exactly like a fresh launch would — which naturally
    // also covers the already-finished case, since msPollAreaCode's first tick
    // renders the report and stops right away.
    function msResumeAreaCodeCheck() {
        fetch('multisite_api.php?action=check_area_codes_status')
            .then(r => r.json())
            .then(d => {
                if (d.none || !d.run_id) return;
                document.getElementById('ms-areacode-out').style.display = 'block';
                if (!d.done) {
                    document.getElementById('ms-areacode-btn').disabled = true;
                    document.getElementById('ms-areacode-msg').textContent = 'Working… (resumed)';
                }
                msAreaCodeTimer = setInterval(() => msPollAreaCode(d.run_id), 2500);
                msPollAreaCode(d.run_id);
            })
            .catch(() => {});
    }

    window.msRowEditStart = function (key) { msEditingKey = key; msRenderRowsTable(); };
    window.msRowEditCancel = function () { msEditingKey = null; msRenderRowsTable(); };

    function msRowCollectFields(key) {
        const tr = document.querySelector('#ms-rows-table tr[data-key="' + CSS.escape(key) + '"]');
        const out = {};
        tr.querySelectorAll('.ms-row-input').forEach(inp => { out[inp.dataset.f] = inp.value.trim(); });
        return out;
    }

    window.msRowSave = async function (key) {
        const fields = msRowCollectFields(key);
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('key', key);
        Object.keys(fields).forEach(k => fd.append(k, fields[k]));
        const d = await (await fetch('multisite_api.php?action=row_save', { method: 'POST', body: fd })).json();
        if (d.error) { alert(d.error); return; }
        msEditingKey = null;
        await msLoadRows();
    };

    window.msRowDelete = function (key) {
        const r = msRows[key] || {};
        const label = r.domain || ((r.fields && r.fields.city) ? r.fields.city : key);
        if (!confirm('Remove ' + label + ' from this batch\'s target list?\n\nAny host/FTP account it already has is untouched — this only removes the row.')) return;
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('key', key);
        fetch('multisite_api.php?action=row_delete', { method: 'POST', body: fd })
            .then(r => r.json()).then(d => { if (d.error) { alert(d.error); return; } msLoadRows(); });
    };

    // LIVE rows: typed-domain confirm unlocks editing — lighter than the Infra
    // console's Danger Zone "+ live" phrase (#4), since this only rewrites fields,
    // it touches no infrastructure and triggers no build by itself.
    window.msRowUnlock = function (domain) {
        const typed = prompt('This domain is LIVE. Type it exactly to unlock its data for correction:\n\n' + domain);
        if (typed === null) return;
        if (typed.trim().toLowerCase() !== domain.toLowerCase()) { alert('Did not match — nothing unlocked.'); return; }
        msUnlockedConfirm[domain] = typed.trim();
        msEditingKey = domain;   // a live row's key is always its own domain (see ms_rows_by_key())
        msRenderRowsTable();
    };

    window.msRowSaveCorrect = async function (domain) {
        const fields = msRowCollectFields(domain);
        const fd = new FormData();
        fd.append('csrf_token', csrfToken); fd.append('domain', domain);
        fd.append('confirm', msUnlockedConfirm[domain] || domain);
        // Same field the CTM bulk-action buttons already read — lets row_correct
        // auto-resync CTM's label/routing when the phone actually changes (see its
        // docblock in admin/multisite_api.php). Blank is fine: the row still saves,
        // the resync step just doesn't run.
        const ctmAcct = document.getElementById('ms-ctm-account');
        if (ctmAcct) fd.append('account_id', ctmAcct.value.trim());
        Object.keys(fields).forEach(k => fd.append(k, fields[k]));
        const d = await (await fetch('multisite_api.php?action=row_correct', { method: 'POST', body: fd })).json();
        if (d.error) { alert(d.error); return; }
        if (d.ctm_resync && d.ctm_resync.error) {
            alert('Saved, but could not sync CTM: ' + d.ctm_resync.error);
        } else if (d.ctm_resync && d.ctm_resync.warning) {
            alert('Saved. CTM resync note: ' + d.ctm_resync.warning);
        }
        msEditingKey = null;
        delete msUnlockedConfirm[domain];
        await msLoadRows();
        msRegenerateOne(domain);   // one combined flow: correct, then rebuild + redeploy just this domain
    };

    function msProgressEl(domain) {
        const row = document.querySelector('#ms-rows-table tr[data-domain-progress="' + CSS.escape(domain) + '"]');
        if (row) row.style.display = '';
        return row ? row.querySelector('.ms-row-progress') : null;
    }

    // Launches build_upload.php (the SAME server-side job Batch Simple's "Run selected"
    // uses) instead of two separate client-triggered calls. Found live 2026-10-27: the
    // old two-call version (action=run, then — once the client's own setInterval saw it
    // finish — a second action=upload call) left the site un-pushed for an hour when the
    // tab was closed between the two. The generate half is a real detached server
    // process and kept running fine; nothing was left to notice it finished and fire the
    // upload. One job launched here (ms_launch_job, setsid) runs generate AND upload
    // back to back entirely server-side, so closing the tab mid-way no longer drops the
    // upload — only the live progress display here is lost, not the actual work.
    async function msRegenerateOne(domain) {
        const el = msProgressEl(domain);
        if (el) el.textContent = 'Starting rebuild + upload for ' + domain + '…';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('domains', JSON.stringify([domain]));
        fd.append('do_host', '0');   // already a live, hosted row — nothing for Create host to do
        fd.append('skip_images', '1');   // a data fix has no business re-processing photos (upload itself stays normal)
        let d;
        try { d = await (await fetch('multisite_api.php?action=rollout_build', { method: 'POST', body: fd })).json(); }
        catch (e) { if (el) el.textContent = 'Could not reach the server.'; return; }
        if (d.error) { if (el) el.innerHTML = esc(d.error); return; }
        if (d.queued) {
            // A build was already running elsewhere in this batch — found
            // 2026-10-28 silently dropping clicks like this one three sessions
            // running. Now it waits its turn instead of just vanishing; see
            // msPollQueuedBuild() and ms_build_queue_launch_or_queue().
            if (el) el.textContent = 'Waiting for another build in this batch to finish… (position ' + d.position + ')';
            msPollQueuedBuild(domain);
            return;
        }
        msPollRegenBuild(domain, d.run_id);
    }

    // One row's build/upload is only ever watched by ONE active poller at a time —
    // msResumeQueuedBuilds() (page load) and msRegenerateOne() (a fresh click) can
    // both try to attach one for the same domain; without this a reload right
    // after clicking would end up with two intervals hitting the status endpoint
    // for the same domain.
    const msActiveBuildPolls = new Set();

    function msRenderBuildDone(domain, dead, exit) {
        const el = msProgressEl(domain);
        if (!el) return;
        // dead — killed, or the box restarted, mid-job: no exit code was ever
        // written, which on its own used to read as a clean success.
        const bad = dead || (exit != null && exit !== 0);
        el.innerHTML = dead
            ? '<span style="color:#991b1b;">Rebuild/upload stopped unexpectedly for ' + esc(domain) + ' — killed, or the server restarted. Re-run it.</span>'
            : (bad
                ? '<span style="color:#991b1b;">Finished with failures for ' + esc(domain) + ' — check the log.</span>'
                : '<span style="color:#166534;">&#10003; Regenerated &amp; redeployed ' + esc(domain) + '.</span>');
    }

    function msPollRegenBuild(domain, runId) {
        if (msActiveBuildPolls.has(domain)) return;
        msActiveBuildPolls.add(domain);
        const el = msProgressEl(domain);
        // Shared miss-tracker (assets/admin/ms-poll.js) — the catch here used to be a
        // silent `return`, so a persistent failure left this row's progress text
        // stuck on its last line forever with no sign anything was wrong.
        const tracker = msPollMissTracker(() => {
            if (el) el.textContent = 'Lost contact with the server while rebuilding ' + domain + ' — still trying… (reload to check)';
        });
        const timer = setInterval(async () => {
            let s;
            try { s = await (await fetch('multisite_api.php?action=rollout_build_status&run_id=' + encodeURIComponent(runId))).json(); }
            catch (e) { tracker.miss(); return; }
            tracker.reset();
            if (!s) return;
            if (!s.done) { if (el) el.textContent = (s.detail || s.stage_label || ('Working on ' + domain)) + '…'; return; }
            clearInterval(timer);
            msActiveBuildPolls.delete(domain);
            msRenderBuildDone(domain, !!s.dead, s.exit);
        }, 2500);
    }

    // Watches a QUEUED request (no run_id yet) until it either starts for real
    // (hands off to msPollRegenBuild, unchanged) or finishes while still sitting
    // in the queue's own "done" state (the drain cron can launch AND finish a
    // short job between two polls). Keeps working across a page reload, since it
    // reads from queue.json on the server, not anything held in this tab.
    function msPollQueuedBuild(domain) {
        if (msActiveBuildPolls.has(domain)) return;
        msActiveBuildPolls.add(domain);
        const el = msProgressEl(domain);
        // Shared miss-tracker (assets/admin/ms-poll.js) — same silent-forever catch as
        // msPollRegenBuild above.
        const tracker = msPollMissTracker(() => {
            if (el) el.textContent = 'Lost contact with the server while waiting to rebuild ' + domain + ' — still trying… (reload to check)';
        });
        const timer = setInterval(async () => {
            let s;
            try { s = await (await fetch('multisite_api.php?action=rollout_build_queue_status&domain=' + encodeURIComponent(domain))).json(); }
            catch (e) { tracker.miss(); return; }
            tracker.reset();
            if (!s || s.state === 'none') { clearInterval(timer); msActiveBuildPolls.delete(domain); return; }
            if (s.state === 'queued') { if (el) el.textContent = 'Waiting for another build in this batch to finish… (position ' + s.position + ')'; return; }
            clearInterval(timer);
            msActiveBuildPolls.delete(domain);
            if (s.state === 'running') { msPollRegenBuild(domain, s.run_id); return; }
            msRenderBuildDone(domain, !!s.dead, s.exit);
        }, 3000);
    }

    window.msRowAddToggle = function () {
        const f = document.getElementById('ms-row-add-form');
        f.style.display = f.style.display === 'none' ? 'block' : 'none';
    };
    window.msRowAddSave = async function () {
        const domain = document.getElementById('ms-add-domain').value.trim();
        const msg = document.getElementById('ms-add-msg');
        if (!domain) { msg.textContent = 'Domain is required.'; msg.style.color = '#991b1b'; return; }
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('domain', domain);
        fd.append('business', document.getElementById('ms-add-business').value.trim());
        fd.append('phone', document.getElementById('ms-add-phone').value.trim());
        fd.append('city', document.getElementById('ms-add-city').value.trim());
        fd.append('state', document.getElementById('ms-add-state').value.trim());
        fd.append('SS', document.getElementById('ms-add-ss').value.trim());
        const d = await (await fetch('multisite_api.php?action=row_add', { method: 'POST', body: fd })).json();
        if (d.error) { msg.textContent = d.error; msg.style.color = '#991b1b'; return; }
        msg.textContent = '';
        ['ms-add-domain', 'ms-add-business', 'ms-add-phone', 'ms-add-city', 'ms-add-state', 'ms-add-ss']
            .forEach(id => { document.getElementById(id).value = ''; });
        document.getElementById('ms-row-add-form').style.display = 'none';
        await msLoadRows();
    };

    window.msRowAddNStart = async function () {
        const raw = prompt('How many of the oldest unclaimed domains do you want to review?', '20');
        const count = parseInt(raw, 10);
        if (!raw || !Number.isFinite(count) || count < 1) return;

        const box = document.getElementById('ms-addn-list');
        const msg = document.getElementById('ms-addn-msg');
        box.innerHTML = '';
        msg.textContent = 'Loading…';
        document.getElementById('ms-addn-dialog').showModal();

        const d = await (await fetch('multisite_api.php?action=oldest_unclaimed_domains&count=' + count)).json();
        if (d.error) { msg.textContent = d.error; return; }
        if (!d.domains.length) { msg.textContent = 'No owned, unclaimed domains found for this niche.'; return; }
        msg.textContent = '';

        const now = Date.now();
        box.innerHTML = '<table style="width:100%;font-size:.84rem;"><thead><tr>' +
            '<th style="width:26px;"></th><th>Domain</th><th style="width:110px;">Registrar</th>' +
            '<th style="width:150px;">Owned since</th><th style="width:90px;">Age</th>' +
            '</tr></thead><tbody>' +
            d.domains.map(function (row) {
                const boughtAt = new Date(row.owned_at.replace(' ', 'T') + 'Z');
                const days = Math.max(0, Math.floor((now - boughtAt.getTime()) / 86400000));
                return '<tr><td><input type="checkbox" class="ms-addn-cb" data-domain="' + esc(row.domain) +
                    '" checked onchange="msAddnUpdateCount()"></td><td>' + esc(row.domain) + '</td><td>' +
                    esc(row.registrar || '—') + '</td><td>' +
                    esc(row.owned_at) + '</td><td>' + days + ' day' + (days === 1 ? '' : 's') + '</td></tr>';
            }).join('') + '</tbody></table>';
        msAddnUpdateCount();
    };

    window.msAddnUpdateCount = function () {
        const n = document.querySelectorAll('.ms-addn-cb:checked').length;
        document.getElementById('ms-addn-count').textContent = n + ' selected';
    };

    window.msRowAddNAccept = async function () {
        const domains = Array.from(document.querySelectorAll('.ms-addn-cb:checked')).map(cb => cb.dataset.domain);
        const msg = document.getElementById('ms-addn-msg');
        if (!domains.length) { msg.textContent = 'Nothing checked — check at least one domain, or Cancel.'; return; }

        const btn = document.getElementById('ms-addn-accept');
        btn.disabled = true;
        msg.textContent = 'Adding ' + domains.length + '…';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('domains', domains.join(','));
        const d = await (await fetch('multisite_api.php?action=claim_domains', { method: 'POST', body: fd })).json();
        btn.disabled = false;
        if (d.error) { msg.textContent = d.error; return; }

        if (!d.failed.length) {
            document.getElementById('ms-addn-dialog').close();
        } else {
            msg.innerHTML = '<span style="color:#166534;">Added ' + d.added.length + '.</span> ' +
                '<span style="color:#991b1b;">' + d.failed.length + ' failed: ' +
                d.failed.map(f => f.domain + ' (' + f.reason + ')').join(', ') + '</span>';
        }
        await msLoadRows();
    };

    // Moved here from batch-detailed-generate.js's own page-load kickoff (2026-11-13
    // split): refreshParamsState() calls msLoadRows() internally, which isn't defined
    // until batch-detailed-rows.js loads — two files after generate.js. This is the
    // last-loaded file, so everything every kickoff call below needs already exists.
    refreshParamsState();  // download-current button + saved versions
    msLoadRows();
    msResumeAreaCodeCheck();
    msResumeCtmBulk();
    msResumeDomainBuy();
