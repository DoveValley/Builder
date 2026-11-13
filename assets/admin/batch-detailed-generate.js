// batch-detailed.js, part 2/4 — Batch Detailed: Run batch (Create host/Build/
// Upload — detached background job + log polling), runs history, per-batch
// rotation/options autosave, Research cities, Title preview.
//
// Same split as part 1 (see its header) — plain top-level code, shares global
// scope with the other 3 parts. Loads after 01-upload.
    // ── Run batch (detached background + polling) ──────────────────────────────
    function renderRun(d) {
        const el = document.getElementById('ms-run-progress');
        const btn = document.getElementById('ms-run-btn');
        if (!d || d.none || !d.state) { el.innerHTML = ''; btn.disabled = false; return; }
        const state = d.state;
        const color = { running: '#2563eb', done: '#166534', failed: '#991b1b', stale: '#92400e' }[state] || '#334155';
        const t = d.totals || {};
        const pct = d.total ? Math.round((d.done / d.total) * 100) : 0;
        let html = '<div><strong style="color:' + color + ';">' + state.toUpperCase() + '</strong> — ' +
            (d.done || 0) + '/' + (d.total || 0) + ' done · ' + (d.ok || 0) + ' ok · ' + (d.failed || 0) + ' failed' +
            (t.files_uploaded ? ' · ' + t.files_uploaded + ' files' : '') +
            (t.cost_usd ? ' · text $' + Number(t.cost_usd).toFixed(4) : '') +
            (t.image_cost_usd ? ' · photos $' + Number(t.image_cost_usd).toFixed(4) : '') +
            (d.params_version ? ' · <span title="target list version used">list ' + esc(d.params_version) + '</span>' : '') + '</div>' +
            // A run that found nothing to process (every row already live, per its
            // live-skip guard) still has a state/total/done to render above, but
            // "DONE — 0/0" alone reads like the click failed rather than like the
            // intended safety behavior it actually is — d.note carries that explanation.
            (d.note ? '<div class="hint" style="margin:2px 0 8px;">' + esc(d.note) + '</div>' : '') +
            '<div style="height:8px;background:#e2e8f0;border-radius:4px;margin:8px 0 12px;overflow:hidden;"><div style="height:100%;width:' + pct + '%;background:' + color + ';transition:width .3s;"></div></div>' +
            // Raw tail of the run's own log — the only thing that shows anything during
            // steps that happen before any domain result exists yet, e.g. the auto-research
            // pass, which can run for a long time in total silence otherwise.
            (d.log_tail ? '<div class="hint" style="margin-bottom:3px;">Live log:</div><pre id="ms-run-log-tail" style="background:#0f172a;color:#cbd5e1;font-size:0.78rem;line-height:1.5;padding:8px 10px;border-radius:6px;max-height:160px;overflow-y:auto;white-space:pre-wrap;margin:0 0 12px;">' + esc(d.log_tail) + '</pre>' : '');
        if (d.results && d.results.length) {
            html += '<div style="max-height:240px;overflow:auto;font-size:0.85rem;line-height:1.7;">' +
                d.results.slice().reverse().map(r => {
                    const mk = r.status === 'running' ? '<span style="color:#2563eb;">⋯</span>'
                             : r.status === 'ok'       ? '<span style="color:#166534;">✓</span>'
                             :                           '<span style="color:#991b1b;">✗</span>';
                    // In-flight rows show the current STEP (short, stable) plus the most
                    // recent progress line (changes every tick) — this is the whole point:
                    // a still-running row used to show nothing at all until it finished.
                    const stepLabel = r.status === 'running' && r.step ? ' [' + esc(r.step) + ']' : '';
                    // "view" only once this row has actually generated output on disk — a
                    // failed row has nothing under batches/{id}/output/ for batch_preview.php to serve.
                    const viewLink = r.status === 'ok' && r.domain
                        ? ' &nbsp;·&nbsp; <a href="batch_preview.php?master=' + encodeURIComponent(bpMasterId) +
                          '&batch=' + encodeURIComponent(bpBatchId) + '&domain=' + encodeURIComponent(r.domain) +
                          '" target="_blank" rel="noopener">view</a>'
                        : '';
                    // Public preview — same output, no login, reachable by Google's own tools.
                    // Slug comes from the server (r.slug, set by the run_status action) rather
                    // than being recomputed here — ms_domain_slug() folds the readable part the
                    // same way but also appends an md5-based suffix so two distinct domains
                    // can't collide onto the same folder, and JS has no built-in MD5 to match it.
                    const slug = r.status === 'ok' && r.domain ? (r.slug || '') : '';
                    const publicLink = slug
                        ? ' &nbsp;·&nbsp; <a href="http://' + bpMasterId + '--' + bpBatchId + '--' + slug +
                          '.preview.q111.xyz/" target="_blank" rel="noopener">public preview</a>'
                        : '';
                    // Real deploy to the fixed test server — see the settings panel above.
                    const deployLink = r.status === 'ok' && r.domain
                        ? ' &nbsp;·&nbsp; <a href="#" onclick="msDeployTestServer(\'' + esc(r.domain) + '\', this);return false;">deploy to test server</a>'
                        : '';
                    return '<div>' + mk + ' ' + esc(r.domain) + ' — ' + esc(r.status) + stepLabel +
                        (r.uploaded != null ? ' (' + r.uploaded + ' up)' : '') +
                        (r.cost > 0 ? ' $' + Number(r.cost).toFixed(4) : '') +
                        (r.image_cost > 0 ? ' + img $' + Number(r.image_cost).toFixed(4) : '') +
                        (r.last ? ' — ' + esc(r.last) : '') + viewLink + publicLink + deployLink + '</div>';
                }).join('') + '</div>';
        }
        el.innerHTML = html;
        const logEl = document.getElementById('ms-run-log-tail');
        if (logEl) logEl.scrollTop = logEl.scrollHeight;
        if (state === 'running') { btn.disabled = true; }
        else {
            btn.disabled = false;
            if (msPollTimer) {
                clearInterval(msPollTimer); msPollTimer = null; loadRuns();
                // The Upload card (step 5) reads "how many are ready to upload" once at
                // page load and otherwise never hears about a Generate-sites run finishing
                // — without this it keeps showing a stale "0 ready" until a manual reload.
                if (typeof window.msRefreshUploadState === 'function') window.msRefreshUploadState();
            }
        }
    }

    // ── Runs history ──────────────────────────────────────────────────────────
    function fmtTime(s) { if (!s) return '—'; const d = new Date(s); return isNaN(d) ? s : d.toLocaleString(); }
    function renderRuns(data) {
        const el = document.getElementById('ms-runs');
        const runs = (data && data.runs) || [];
        if (!runs.length) { el.innerHTML = '<p class="hint">No runs yet.</p>'; return; }
        const stC = { running: '#2563eb', done: '#166534', failed: '#991b1b', stale: '#92400e' };
        el.innerHTML = '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.85rem;">' +
            '<thead><tr><th>Started</th><th>State</th><th>Result</th><th>Cost</th><th></th></tr></thead><tbody>' +
            runs.map(r => {
                const c = stC[r.state] || '#334155';
                const retry = r.failed > 0
                    ? ' <button type="button" class="btn" style="padding:1px 8px;font-size:0.76rem;" title="Re-runs only the domains that failed in this run — a real detached background job, same as Generate sites. Safe to navigate away; check back here for the new run\'s result." onclick="msRetry(\'' + esc(r.run_id) + '\', this)">retry ' + r.failed + ' failed</button>'
                    + ' <span title="Background job — safe to navigate away or close this tab, it keeps running on the server." style="display:inline-block;background:#1e3a8a;color:#fff;font-weight:800;font-size:.62rem;line-height:1;border-radius:3px;padding:1px 4px;cursor:help;vertical-align:middle;">B</span>'
                    : '';
                return '<tr>' +
                    '<td>' + esc(fmtTime(r.started_at)) + '</td>' +
                    '<td><span style="color:' + c + ';font-weight:700;">' + esc(r.state) + '</span></td>' +
                    '<td>' + r.ok + '/' + r.total + ' ok' + (r.failed ? ' · ' + r.failed + ' failed' : '') + '</td>' +
                    '<td>' + (r.cost ? '$' + Number(r.cost).toFixed(4) : '—') + '</td>' +
                    '<td><a href="#" onclick="msView(\'' + esc(r.run_id) + '\');return false;">view</a>' + retry + '</td>' +
                    '</tr>';
            }).join('') + '</tbody></table></div>';
    }
    function loadRuns() { fetch('multisite_api.php?action=list_runs').then(r => r.json()).then(renderRuns).catch(() => {}); }

    window.msView = function (id) {
        pollRun(id);
        document.getElementById('ms-run-progress').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
    window.msRetry = function (id, btn) {
        // Same double-submit guard as msRestore above — checked before confirm() so a
        // second click while the first retry is still in flight can't open a second
        // dialog and fire a second retry_failed for the same run.
        if (btn && btn.disabled) return;
        if (!confirm('Re-run only the failed rows from this run?')) return;
        if (btn) btn.disabled = true;
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('run_id', id);
        fetch('multisite_api.php?action=retry_failed', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { alert(d.error); if (btn) btn.disabled = false; return; }
                if (msPollTimer) clearInterval(msPollTimer);
                msPollTimer = setInterval(() => pollRun(d.run_id), 2500);
                pollRun(d.run_id);
                // Stays disabled until the retry run finishes — renderRun()'s completion
                // branch calls loadRuns(), which re-renders this whole row anyway.
            })
            .catch(() => { if (btn) btn.disabled = false; });
    };

    // Shared miss-tracker (assets/admin/ms-poll.js) — surfaces a message once after
    // a run of failed polls instead of retrying silently forever; the still-alive
    // msPollTimer interval keeps calling pollRun(), so "still trying" is literal.
    const pollRunTracker = msPollMissTracker(() => {
        const el = document.getElementById('ms-run-progress');
        if (el) el.insertAdjacentHTML('beforeend',
            '<div style="color:#991b1b;margin-top:6px;">Lost contact with the server — still trying… (the run itself may still be in progress; reload to check)</div>');
    });
    function pollRun(runId) {
        const url = 'multisite_api.php?action=run_status' + (runId ? '&run_id=' + encodeURIComponent(runId) : '');
        fetch(url).then(r => r.json()).then(d => { pollRunTracker.reset(); renderRun(d); }).catch(() => pollRunTracker.miss());
    }

    // Persist a rotation pin-count the moment it changes (per batch — rotation_settings_save.php),
    // so it survives a reload instead of resetting to the 20/20 default. Independent of Generate sites/msRun();
    // that still sends whatever's in the box at click time regardless of whether this save landed.
    window.msSaveRotation = function (input) {
        const msg = input.closest('div').querySelector('.ms-rot-msg');
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        Array.from(document.querySelectorAll('.ms-rot-opt')).forEach(inp => fd.append(inp.dataset.field, inp.value));
        if (msg) { msg.style.color = '#64748b'; msg.style.opacity = '1'; msg.textContent = 'Saving…'; }
        fetch('rotation_settings_save.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!msg) return;
                if (d.error) {
                    // A real problem — stays put (no fade) until the next save attempt.
                    msg.style.color = '#991b1b'; msg.style.opacity = '1'; msg.textContent = '✗ ' + d.error;
                    return;
                }
                // Brief, unmistakable confirmation, then fade — no button, so this IS the only signal it saved.
                msg.style.color = '#166534'; msg.style.opacity = '1'; msg.textContent = '✓ Saved';
                setTimeout(() => { msg.style.opacity = '0'; }, 1800);
            })
            .catch(() => { if (msg) { msg.style.color = '#991b1b'; msg.style.opacity = '1'; msg.textContent = '✗ save failed'; } });
    };

    // Persist EVERY checkbox's current state the moment any one of them changes (per batch —
    // batch_options_save.php): the 7 parent steps plus every sub-switch beneath them. Sends
    // the whole set every time (not just the one that changed) so the file is always a
    // complete, consistent snapshot. Purely what the panel is pre-checked to on next load —
    // msRun() below still reads the live DOM at click time for what an actual run does.
    window.msSaveBatchOptions = function () {
        const msg = document.getElementById('ms-opts-msg');
        const options = { steps: {}, subs: {} };
        document.querySelectorAll('.ms-step-opt').forEach(p => { options.steps[p.value] = p.checked; });
        document.querySelectorAll('.ms-sub-opt').forEach(cb => { options.subs[cb.value] = cb.checked; });
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('options', JSON.stringify(options));
        if (msg) { msg.style.color = '#64748b'; msg.style.opacity = '1'; msg.textContent = 'Saving…'; }
        fetch('batch_options_save.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!msg) return;
                if (d.error) {
                    msg.style.color = '#991b1b'; msg.style.opacity = '1'; msg.textContent = '✗ ' + d.error;
                    return;
                }
                msg.style.color = '#166534'; msg.style.opacity = '1'; msg.textContent = '✓ Saved';
                setTimeout(() => { msg.style.opacity = '0'; }, 1800);
            })
            .catch(() => { if (msg) { msg.style.color = '#991b1b'; msg.style.opacity = '1'; msg.textContent = '✗ save failed'; } });
    };

    // Short display labels for the pre-flight confirmation only — deliberately NOT the
    // long descriptive text $msTree carries for the panel itself (that's documentation,
    // this needs to be readable in two seconds). Keyed the same as skip-list values, so
    // there's no separate mapping to keep in sync with which checkbox controls what.
    const msConfirmGroups = [
        { key: 'ai',        label: 'AI Content',       subs: ['ai.legal_reword','ai.disclaimer_reword','ai.popup_reword','ai.tagline_reword','ai.blog'] },
        { key: 'visual',    label: 'Colours & Fonts',   subs: ['visual.palette','visual.font','visual.jitter'] },
        { key: 'structure', label: 'Site Structure',    subs: ['structure.home','structure.landing','structure.classvocab','structure.schemashape'] },
        { key: 'images',    label: 'Images',            subs: ['images.stamp_home','images.stamp_landing','images.metadata','images.ai_photos_hero_home','images.ai_photos_hero_landing','images.ai_photos_other_home','images.ai_photos_other_landing'] },
        { key: 'tags',      label: 'Site tags',         subs: [] },
        { key: 'landing',   label: 'Landing pages',     subs: [] },
        { key: 'pagepool',  label: 'Page pool',         subs: [] },
    ];

    // Reads the SAME live checkboxes msRunActual() reads to build the real skip list —
    // one function, used by both the confirmation display and the actual run, so the
    // two can never disagree about what's actually about to happen.
    function msBuildSkipList() {
        const skip = Array.from(document.querySelectorAll('.ms-step-opt'))
            .filter(cb => !cb.checked).map(cb => cb.value);
        Array.from(document.querySelectorAll('.ms-sub-opt')).forEach(cb => {
            if (skip.includes(cb.dataset.parent)) return;
            if (!cb.checked) skip.push(cb.value);
        });
        return skip;
    }

    // A count-aware line for the handful of items where a real number is cheap and
    // exact to know upfront (see ms_batch_pending_summary() in steps.php for why only
    // these two — an approximate number for the rest would be worse than none).
    function msConfirmLine(key, summary) {
        const label = msConfirmLabels[key] || key;
        if (!summary) return label;
        if (key === 'ai.blog' && summary.blog) {
            const need = summary.blog.domains_needing, total = summary.domains_total;
            if (total === 0) return label;
            return 'Blog posts — ' + need + ' of ' + total + ' domain' + (total === 1 ? '' : 's')
                 + ' need new posts (' + (total - need) + ' already at full quota)';
        }
        const aiImgKeyToCat = {
            'images.ai_photos_hero_home': 'hero_home', 'images.ai_photos_hero_landing': 'hero_landing',
            'images.ai_photos_other_home': 'other_home', 'images.ai_photos_other_landing': 'other_landing',
        };
        if (aiImgKeyToCat[key] && summary.images) {
            const scopeLabel = msConfirmLabels[key] || key;
            const s = summary.images[aiImgKeyToCat[key]] || {};
            const pending = s.pending_slots || 0, doms = s.domains_affected || 0;
            const willFail = s.slots_will_fail || 0;
            // Checked once against the MASTER, so this count applies to every domain in
            // the batch identically — a slot that can't resolve on the master can't
            // resolve on any clone of it either (see ms_batch_pending_summary()).
            const failNote = willFail > 0
                ? ' &#9888; ' + willFail + ' configured photo slot' + (willFail === 1 ? '' : 's')
                    + " can't be resolved right now (" + esc((s.fail_samples || []).join(', '))
                    + ') — will fail on every domain, re-confirm in Pic Drop before running'
                : '';
            if (pending === 0) return scopeLabel + ' — nothing pending, all already generated' + failNote;
            return scopeLabel + ' — ~' + pending + ' new photo' + (pending === 1 ? '' : 's')
                 + ' across ' + doms + ' domain' + (doms === 1 ? '' : 's') + ' (rest already cached)' + failNote;
        }
        return label;
    }

    window.msClosePreflight = function () {
        document.getElementById('ms-preflight-overlay').style.display = 'none';
    };

    // Held here rather than embedded into the Confirm button's onclick — JSON.stringify()
    // uses double quotes, which would close an onclick="..." attribute early the moment
    // the skip list had more than one entry. A plain variable read on click sidesteps the
    // quoting problem entirely instead of trying to escape around it.
    let msPendingSkip = [];
    let msPendingForce = false;
    window.msConfirmGenerate = function () {
        msClosePreflight();
        msRunActual(msPendingSkip, msPendingForce);
    };

    function msRenderPreflight(skip, force, summary) {
        msPendingSkip = skip;
        msPendingForce = force;
        const willHappen = [];
        const willNot = [];

        if (force) {
            willHappen.push('&#9888; <strong>Force is ON</strong> — refreshes AI content and rebuilds EVERY domain in this batch, even ones already complete (real API cost, not just gaps)');
        }

        msConfirmGroups.forEach(g => {
            // A group with no sub-items (tags, landing, pagepool) has nothing to filter —
            // its own on/off state IS the whole answer, checked directly rather than via
            // an always-empty onSubs list (which used to read as permanently "off").
            if (g.subs.length === 0) {
                if (skip.includes(g.key)) {
                    willNot.push(g.label + ' — off');
                } else {
                    willHappen.push(g.label);
                }
                return;
            }
            const offSubs = g.subs.filter(s => skip.includes(s));
            const onSubs  = g.subs.filter(s => !skip.includes(s));
            if (skip.includes(g.key) || onSubs.length === 0) {
                willNot.push(g.label + ' — off');
                return;
            }
            if (offSubs.length === 0) {
                // Whole group on — one line per item still (each may carry its own
                // count), just not split out as a "mixed" case visually.
                onSubs.forEach(s => willHappen.push(msConfirmLine(s, summary)));
                return;
            }
            // Mixed within the group: show exactly what's on and what's off, since a
            // single collapsed line couldn't say which half applies.
            onSubs.forEach(s => willHappen.push(msConfirmLine(s, summary)));
            offSubs.forEach(s => willNot.push(msConfirmLabels[s] + ' — off'));
        });

        const only = document.getElementById('ms-run-only').value.trim();
        const limit = document.getElementById('ms-limit').value;

        const box = document.getElementById('ms-preflight-box');
        box.innerHTML =
            '<h3 style="margin-top:0;">Before you generate…</h3>' +
            (summary ? '' : '<p class="hint" style="color:#b45309;">Could not load exact counts — showing settings only.</p>') +
            (only ? '<p class="hint">Limited to: <strong>' + esc(only) + '</strong></p>' : '') +
            (limit && limit !== '0' ? '<p class="hint">Capped at ' + esc(limit) + ' domain(s) this run.</p>' : '') +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:16px 0;">' +
              '<div><div style="font-weight:700;color:#166534;margin-bottom:8px;">&#10003; Will happen</div>' +
                '<ul style="margin:0;padding-left:18px;font-size:0.88rem;line-height:1.6;">' +
                    (willHappen.length ? willHappen.map(l => '<li>' + l + '</li>').join('') : '<li class="hint">Nothing — every step is off</li>') +
                '</ul></div>' +
              '<div><div style="font-weight:700;color:#6b7280;margin-bottom:8px;">&#10007; Will NOT happen</div>' +
                '<ul style="margin:0;padding-left:18px;font-size:0.88rem;line-height:1.6;color:#6b7280;">' +
                    (willNot.length ? willNot.map(l => '<li>' + l + '</li>').join('') : '<li>Nothing — every step is on</li>') +
                '</ul></div>' +
            '</div>' +
            '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">' +
              '<button type="button" class="btn sec" onclick="msClosePreflight()">Cancel</button>' +
              '<button type="button" class="btn btn-primary" onclick="msConfirmGenerate()">Confirm &amp; Generate</button>' +
              ' <span title="Background job — safe to navigate away or close this tab, it keeps running on the server." style="display:inline-block;background:#1e3a8a;color:#fff;font-weight:800;font-size:.68rem;line-height:1;border-radius:3px;padding:2px 5px;cursor:help;vertical-align:middle;">B</span>' +
              ' <span class="hint" style="cursor:pointer;font-size:1.05rem;" onclick="bpShowInfo(this)" data-info="This launches one real detached process on the server for every domain in this run — not something your browser has to stay open to finish. Reopen this page any time to pick the live log and progress bar back up where they left off. The run keeps going exactly the same whether you watch it or not.">ⓘ</span>' +
            '</div>';

        document.getElementById('ms-preflight-overlay').style.display = 'flex';
    }

    window.msRun = function () {
        const skip  = msBuildSkipList();
        const force = document.getElementById('ms-force').checked;
        // Scope the counts to what THIS run will actually touch — otherwise a
        // single-domain test run's dialog shows pending counts for the whole batch,
        // even while the same dialog correctly says "Limited to: ...".
        const only  = document.getElementById('ms-run-only').value.trim();
        const limit = document.getElementById('ms-limit').value;
        const qs = new URLSearchParams({ action: 'preflight_summary', only, limit });
        fetch('multisite_api.php?' + qs.toString())
            .then(r => r.json())
            .then(summary => msRenderPreflight(skip, force, summary.error ? null : summary))
            .catch(() => msRenderPreflight(skip, force, null));
    };

    // The actual launch — unchanged from the old msRun() body, just parameterized on
    // the skip list / force flag the confirmation step already computed and showed,
    // instead of re-reading the DOM a second time (which could theoretically disagree
    // if something re-rendered a checkbox between the two calls).
    window.msRunActual = function (skip, force) {
        const btn = document.getElementById('ms-run-btn');
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('limit', document.getElementById('ms-limit').value);
        // Generating never uploads now — sending is step 5, and the built sites are
        // kept under the batch so that step has something to send.
        fd.append('no_deploy', '1');
        if (skip.length) fd.append('skip', skip.join(','));
        if (skip.includes('ai')) fd.append('no_ai', '1');
        // Rotation pin counts — sent regardless of the structure toggles above; the server
        // side only reads them when its own scope isn't skipped.
        Array.from(document.querySelectorAll('.ms-rot-opt')).forEach(inp => {
            fd.append(inp.dataset.field, inp.value);
        });
        if (force) fd.append('force', '1');
        const only = document.getElementById('ms-run-only').value.trim();
        if (only) fd.append('only', only);
        btn.disabled = true;
        document.getElementById('ms-run-progress').innerHTML = '<span class="hint">Starting…</span>';
        fetch('multisite_api.php?action=run', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { btn.disabled = false; document.getElementById('ms-run-progress').innerHTML = '<span style="color:#991b1b;">' + esc(d.error) + '</span>'; return; }
                if (msPollTimer) clearInterval(msPollTimer);
                msPollTimer = setInterval(() => pollRun(d.run_id), 2500);
                pollRun(d.run_id);
            })
            .catch(() => { btn.disabled = false; });
    };

    // ── Research cities — detached; poll the output file ──────────────────────
    var msResearchTimer = null;
    // Shared miss-tracker (assets/admin/ms-poll.js) — unlike pollRun above, this one
    // gives up and re-enables the controls at the threshold, since a stuck "disabled"
    // state here blocks the user from starting a new research run at all.
    const msResearchTracker = msPollMissTracker(() => {
        if (msResearchTimer) clearInterval(msResearchTimer);
        msResearchSetDisabled(false);
        var out = document.getElementById('ms-research-out');
        if (out) out.textContent += '\n\n✗ Lost contact with the server — the job may still be running; reload to check.';
        msSetState('ms-research-state', 'lost contact', 'bad');
    });
    function msPollResearch(runId) {
        fetch('multisite_api.php?action=research_status&run_id=' + encodeURIComponent(runId))
            .then(r => r.json())
            .then(d => {
                msResearchTracker.reset();
                var out = document.getElementById('ms-research-out');
                if (d.error) { out.textContent = d.error; return; }
                if (d.none)  { out.textContent = 'Starting…'; return; }
                out.textContent = d.output || '';
                out.scrollTop = out.scrollHeight;
                if (d.done) {
                    if (msResearchTimer) clearInterval(msResearchTimer);
                    msResearchSetDisabled(false);
                    // d.dead — the job was killed (or the box restarted) and so never
                    // wrote an exit code at all. Without its own branch this prints the
                    // nonsense "Exited with code null".
                    out.textContent += d.dead
                        ? '\n\n✗ Stopped unexpectedly before finishing — killed, or the server restarted. The log above is the whole record of how far it got.'
                        : (d.exit === 0 ? '\n\n✓ Done.' : '\n\n✗ Exited with code ' + d.exit + '.');
                    msSetState('ms-research-state',
                               d.dead ? 'stopped unexpectedly' : (d.exit === 0 ? 'done' : 'failed (exit ' + d.exit + ')'),
                               (!d.dead && d.exit === 0) ? 'ok' : 'bad');
                }
            })
            .catch(() => msResearchTracker.miss());
    }
    function msResearchSetDisabled(disabled) {
        document.getElementById('ms-research-dry').disabled = disabled;
        document.getElementById('ms-research-force').disabled = disabled;
        document.getElementById('ms-research-btn').disabled = disabled;
    }
    // Shared state badge for the two research buttons. Neither said anything about
    // itself: the detached research job's only feedback was an output box that
    // block-buffers and so can look dead for minutes, and the completeness check
    // returned in a second with nothing to mark that it had.
    function msSetState(id, text, kind) {
        var el = document.getElementById(id);
        if (!el) return;
        var colour = kind === 'ok' ? '#166534' : (kind === 'bad' ? '#991b1b' : '#2563eb');
        el.style.color = colour;
        el.textContent = text;
    }

    // Read-only completeness check. generate.py strips ANSI when stdout is not a TTY
    // (see _c()), so the text arrives plain and the have/total cells are coloured here:
    // green only when every city has the field, red for anything short, so an 8-of-10
    // cannot read as acceptable at a glance.
    window.msResearchReport = function () {
        var out = document.getElementById('ms-rr-out');
        var btn = document.getElementById('ms-rr-btn');
        out.style.display = 'block';
        out.textContent = 'Checking…';
        btn.disabled = true;
        msSetState('ms-rr-state', 'working…', 'busy');
        fetch('multisite_api.php?action=research_report')
            .then(function (r) { return r.json(); })
            .then(function (j) {
                btn.disabled = false;
                if (j.error) {
                    out.textContent = 'Error: ' + j.error;
                    msSetState('ms-rr-state', 'failed', 'bad');
                    return;
                }
                msSetState('ms-rr-state', 'done', 'ok');
                var esc = (j.output || '(no output)')
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                out.innerHTML = esc.replace(/(\d+)\/(\d+)/g, function (m, have, tot) {
                    var c = (have === tot) ? '#4ade80' : '#f87171';
                    return '<span style="color:' + c + ';font-weight:700;">' + m + '</span>';
                });
            })
            .catch(function (e) {
                btn.disabled = false;
                out.textContent = 'Failed: ' + e;
                msSetState('ms-rr-state', 'failed', 'bad');
            });
    };

    window.msResearch = function () {
        var dry   = document.getElementById('ms-research-dry').checked;
        var force = document.getElementById('ms-research-force').checked;
        var out   = document.getElementById('ms-research-out');
        // Force re-researches every matched city regardless of what's already on file — real
        // API cost across the whole master, not just gaps. A dry run previews that cost-free,
        // so only the live (non-dry) combination is worth pausing on.
        if (force && !dry && !confirm('Force will re-research EVERY city on this master, even ones already complete — full API cost, not just gaps. Continue?')) {
            return;
        }
        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        if (dry)   fd.append('dry_run', '1');
        if (force) fd.append('force', '1');
        msResearchSetDisabled(true);
        out.style.display = 'block';
        out.textContent = 'Starting ' + (dry ? 'dry run' : 'research') + (force ? ' (forced)' : '') + '…';
        msSetState('ms-research-state', 'working…', 'busy');
        fetch('multisite_api.php?action=research', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    out.textContent = d.error;
                    // "Research is already running" carries the live run_id — resume
                    // watching it instead of just re-enabling the controls and leaving
                    // the operator to guess whether anything is actually happening.
                    if (d.run_id) {
                        if (msResearchTimer) clearInterval(msResearchTimer);
                        msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
                        msPollResearch(d.run_id);
                    } else {
                        msResearchSetDisabled(false);
                    }
                    return;
                }
                if (msResearchTimer) clearInterval(msResearchTimer);
                msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
                msPollResearch(d.run_id);
            })
            .catch(() => { msResearchSetDisabled(false); });
    };

    // ── Title preview (read-only — resolves the master's real page titles) ────
    function loadTitlePreview() {
        fetch('multisite_api.php?action=preview_titles').then(function (r) { return r.json(); }).then(function (d) {
            var el = document.getElementById('ms-titles-preview');
            if (d.error) { el.innerHTML = '<p class="hint" style="color:#991b1b;">' + esc(d.error) + '</p>'; return; }
            var src = d.is_placeholder ? 'an example city (upload your target list to preview real data)' : d.sample_domain;
            var rows = (d.titles || []).map(function (t) {
                var val = t.has_title
                    ? '<span style="color:#0f172a;font-weight:600;">' + esc(t.resolved) + '</span>'
                    : '<span style="color:#991b1b;">' + esc(t.resolved) + '</span>';
                return '<tr><td style="padding:5px 10px;white-space:nowrap;color:#475569;">' + esc(t.label) + '</td><td style="padding:5px 10px;">' + val + '</td></tr>';
            }).join('');
            el.innerHTML = '<p class="hint">Resolved for <strong>' + esc(src) + '</strong>:</p>' +
                '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.9rem;border-collapse:collapse;">' +
                '<thead><tr><th style="text-align:left;padding:5px 10px;border-bottom:1px solid #cbd5e1;">Page</th>' +
                '<th style="text-align:left;padding:5px 10px;border-bottom:1px solid #cbd5e1;">Title tag on a cloned site</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table></div>';
        }).catch(function () {});
    }
    loadTitlePreview();

    // Load the batch's current stored state.
    fetch('multisite_api.php?action=status').then(r => r.json()).then(d => { if (d && d.stored && d.rows) render(d); }).catch(() => {});
    // Resume any latest/in-progress run.
    fetch('multisite_api.php?action=run_status').then(r => r.json()).then(d => {
        if (d && !d.none && d.state) { renderRun(d); if (d.state === 'running') { if (msPollTimer) clearInterval(msPollTimer); msPollTimer = setInterval(() => pollRun(d.run_id), 2500); } }
    }).catch(() => {});
    loadRuns();            // runs history
    // refreshParamsState() itself (defined in batch-detailed-upload.js, loaded
    // right before this file) is called from the kickoff block at the end of
    // batch-detailed-ctm.js instead of here — it calls msLoadRows(), which isn't
    // defined until batch-detailed-rows.js loads, two files after this one.

    // Domain pickers for Generate's "only this domain", Create host's "only these
    // domains", and Upload's "only these domains" — one fetch feeds all three selects
    // (Create host's and Upload's selects live in the included _batch_hosts.php/
    // _batch_upload.php, separate IIFEs, so this is exposed on window rather than
    // called directly). A domain tagged live gets a marker so it's obvious WHY you'd
    // need to name it — these are exactly the ones the live-skip guard otherwise
    // refuses to touch.
    window.msOnlyPick = function (inputId, selectEl) {
        const domain = selectEl.value;
        if (!domain) return;
        const input = document.getElementById(inputId);
        const current = input.value.split(',').map(s => s.trim()).filter(Boolean);
        if (!current.includes(domain)) current.push(domain);
        input.value = current.join(', ');
        selectEl.value = '';
    };
    fetch('multisite_api.php?action=domains').then(r => r.json()).then(d => {
        const domains = (d && d.domains) || [];
        const optionsHtml = domains.map(x =>
            '<option value="' + esc(x.domain) + '">' + esc(x.domain) + (x.live ? ' (live)' : '') + '</option>'
        ).join('');
        ['ms-run-only-picker', 'ms-hosts-only-picker', 'ms-up-only-picker'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.insertAdjacentHTML('beforeend', optionsHtml);
        });
    }).catch(() => {});
    // Resume any latest/in-progress research job. Unlike 'run' above, this used to
    // have no bootstrap at all: reloading the page (or leaving and coming back)
    // while a paid, possibly long research job was running left both buttons
    // re-enabled and the output box empty, with no way to tell it was still going
    // short of clicking "Research cities" again and reading the "already running"
    // error's buried run_id.
    fetch('multisite_api.php?action=research_status').then(r => r.json()).then(d => {
        if (!d || d.none || d.error) return;
        document.getElementById('ms-research-out').style.display = 'block';
        if (!d.done) {
            msResearchSetDisabled(true);
            if (msResearchTimer) clearInterval(msResearchTimer);
            msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
        }
        msPollResearch(d.run_id);
    }).catch(() => {});

