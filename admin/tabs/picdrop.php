    <div class="tab-content" style="<?= $tab === 'picdrop' ? '' : 'display:none;' ?>">
        <?php tab_header('Pic Drop', 'Every picture on the site, page by page. Drop a new image onto any slot and it is optimised, resized to fit that slot, filed in the media library and written straight into the page.', 'tab-picdrop'); ?>

        <?php if ($tab !== 'picdrop'): ?>
            <?php /* Enumerating slots means parsing every landing page file — 153 of them on
                     appliance-site. index.php renders all tabs into one document, so doing that
                     work unconditionally would tax every admin page view, on every tab. */ ?>
            <div class="card"><p class="hint">Open the Pic Drop tab to load the page list.</p></div>
        <?php else: ?>
            <?php
            require_once BASE_DIR . '/includes/picdrop.php';

            $pdGroups = picdrop_groups();

            // How many slots share each image, so the propagate control can say what it will do
            // before you commit to it. Keyed by value + leaf field, matching what the API does.
            $pdShared = [];
            $pdTotal  = 0;
            foreach ($pdGroups as $g) {
                foreach ($g['slots'] as $s) {
                    $pdTotal++;
                    if ($s['value'] !== '' && !$s['token']) {
                        $pdShared[$s['value'] . '|' . picdrop_leaf($s['field'])] ??= 0;
                        $pdShared[$s['value'] . '|' . picdrop_leaf($s['field'])]++;
                    }
                }
            }
            // Landing templates holding the same picture. Counted separately because a
            // template is not a page — it is what pages get regenerated FROM, so it is
            // worth naming in the propagate label rather than folding into the page count.
            $pdTpl = [];
            if (defined('TEMPLATES_FILE') && is_file(TEMPLATES_FILE)) {
                foreach ((array) json_decode((string) @file_get_contents(TEMPLATES_FILE), true) as $t) {
                    if (!is_array($t)) continue;
                    foreach (($t['content_blocks'] ?? []) as $block) {
                        if (!is_array($block)) continue;
                        foreach (picdrop_block_paths($block) as $path) {
                            $v = (string) picdrop_get($block, $path);
                            if ($v === '') continue;
                            $k = $v . '|' . picdrop_leaf($path);
                            $pdTpl[$k] = ($pdTpl[$k] ?? 0) + 1;
                        }
                    }
                }
            }

            $pdMissing = 0;
            foreach ($pdGroups as $g) {
                // A token slot has no file by design, and an empty slot is not broken —
                // only a real path that does not resolve counts as missing.
                foreach ($g['slots'] as $s) { if (!$s['exists'] && !$s['token'] && $s['value'] !== '') $pdMissing++; }
            }
            $pdOcr = is_executable('/usr/bin/tesseract');
            ?>

            <div class="card">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <input id="pd-filter" type="text" placeholder="Filter pages by name or slug&hellip;"
                           style="flex:1;min-width:200px;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;">
                    <button type="button" class="btn btn-secondary btn-small" onclick="pdToggleAll(true)">Expand all</button>
                    <button type="button" class="btn btn-secondary btn-small" onclick="pdToggleAll(false)">Collapse all</button>
                    <span id="pd-shown" style="font-size:.85rem;color:#6b7280;white-space:nowrap;">
                        <?= count($pdGroups) ?> pages &middot; <?= $pdTotal ?> pictures
                    </span>
                </div>

                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:12px;">
                    <label class="hint" style="display:flex;align-items:center;gap:6px;<?= $pdOcr ? '' : 'opacity:.5;' ?>">
                        <input type="checkbox" id="pd-screen" <?= $pdOcr ? '' : 'disabled' ?>>
                        Screen each drop for burned-in text
                        <?php if (!$pdOcr): ?><em>(tesseract not installed)</em><?php else: ?><em>(slower &mdash; runs OCR)</em><?php endif; ?>
                    </label>
                    <span style="flex:1;"></span>
                    <span id="pd-dirty" style="display:none;font-size:.8rem;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:4px;padding:4px 9px;">
                        Changed &mdash; the built site still shows the old pictures
                    </span>
                    <button type="button" id="pd-build-btn" class="btn btn-small" onclick="pdRebuild()">Rebuild static site</button>
                </div>
                <div id="pd-build-log" style="margin-top:8px;font-size:.8rem;color:#475569;min-height:1.2em;"></div>

                <?php if ($pdMissing > 0): ?>
                    <p class="hint" style="margin-top:10px;color:#b91c1c;">
                        <?= $pdMissing ?> slot<?= $pdMissing === 1 ? '' : 's' ?> point at a file that is not on disk &mdash; shown in red below.
                    </p>
                <?php endif; ?>
            </div>

            <?php foreach ($pdGroups as $gi => $g): ?>
                <?php $gid = 'pdg' . $gi; ?>
                <div class="card pd-group" data-search="<?= h(strtolower($g['title'] . ' ' . $g['sub'])) ?>" style="padding:0;overflow:hidden;">
                    <button type="button" class="pd-head" onclick="pdToggle('<?= $gid ?>')"
                            style="width:100%;display:flex;align-items:center;gap:10px;padding:12px 16px;background:none;border:0;cursor:pointer;text-align:left;">
                        <span id="<?= $gid ?>_caret" style="color:#6b7280;font-size:.8rem;width:12px;">&#9654;</span>
                        <span style="font-weight:600;color:#1e3a5f;"><?= h($g['title']) ?></span>
                        <span class="hint" style="flex:1;"><?= h($g['sub']) ?></span>
                        <span class="hint" style="white-space:nowrap;"><?= count($g['slots']) ?> picture<?= count($g['slots']) === 1 ? '' : 's' ?></span>
                    </button>

                    <div id="<?= $gid ?>" class="pd-body" style="display:none;border-top:1px solid #e5e7eb;padding:8px 16px 16px;">
                        <?php if (!$g['slots']): ?>
                            <p class="hint" style="margin:10px 0 0;">No pictures on this page.</p>
                        <?php else: ?>
                            <?php foreach ($g['slots'] as $s): ?>
                                <?php
                                $sid     = 'pds' . md5($s['key']);
                                $shareKey= $s['value'] . '|' . picdrop_leaf($s['field']);
                                $shared  = $pdShared[$shareKey] ?? 1;
                                $tplHits = $pdTpl[$shareKey] ?? 0;
                                ?>
                                <div class="pd-slot" style="display:flex;gap:14px;padding:12px 0;border-bottom:1px solid #f1f5f9;">
                                    <div id="<?= $sid ?>_prev" style="flex-shrink:0;width:132px;">
                                        <?php if ($s['token']): ?>
                                            <div style="width:132px;height:88px;border-radius:5px;background:#f8fafc;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#94a3b8;">&#127961;</div>
                                        <?php elseif ($s['value'] !== ''): ?>
                                            <img data-src="/<?= h($s['value']) ?>" data-full="/<?= h($s['value']) ?>"
                                                 data-name="<?= h(basename($s['value'])) ?>" alt="" title="Click to view full size"
                                                 style="width:132px;height:88px;object-fit:cover;border-radius:5px;background:#f1f5f9;display:block;cursor:zoom-in;"
                                                 onerror="this.style.display='none'">
                                        <?php else: ?>
                                            <div style="width:132px;height:88px;border-radius:5px;background:#f8fafc;border:1px dashed #cbd5e1;"></div>
                                        <?php endif; ?>
                                    </div>

                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:.85rem;font-weight:600;color:#374151;">
                                            <?= h($s['block_label']) ?> &middot; <?= h($s['label']) ?>
                                        </div>
                                        <div id="<?= $sid ?>_meta" class="hint" style="margin-top:2px;">
                                            <?php if ($s['token']): ?>
                                                filled per city from <code><?= h($s['value']) ?></code> &mdash; not replaceable here
                                            <?php elseif (!$s['exists'] && $s['value'] !== ''): ?>
                                                <span style="color:#b91c1c;">missing file &mdash; <?= h($s['value']) ?></span>
                                            <?php elseif ($s['value'] === ''): ?>
                                                empty slot &mdash; a dropped image keeps its own size
                                            <?php else: ?>
                                                <?= (int) $s['w'] ?>&times;<?= (int) $s['h'] ?> &middot; <?= round($s['bytes'] / 1024) ?> KB
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($s['value'] !== '' && !$s['token']): ?>
                                            <div id="<?= $sid ?>_acts" style="margin-top:5px;display:flex;gap:10px;font-size:.76rem;">
                                                <a href="#" data-view="/<?= h($s['value']) ?>" data-name="<?= h(basename($s['value'])) ?>"
                                                   style="color:#2563eb;text-decoration:none;">&#128269; View full size</a>
                                                <?php /* A plain same-origin link with `download`; the file is already served
                                                         from this host, so no endpoint is needed to hand it back. */ ?>
                                                <a href="/<?= h($s['value']) ?>" download="<?= h(basename($s['value'])) ?>"
                                                   style="color:#2563eb;text-decoration:none;">&#11015; Download</a>
                                                <a href="#" data-adjust="<?= h($s['key']) ?>" data-sid="<?= $sid ?>"
                                                   style="color:#2563eb;text-decoration:none;">&#9635; Adjust view</a>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($s['alt_field'] !== null): ?>
                                            <input type="text" value="<?= h($s['alt']) ?>" placeholder="Alt text&hellip;"
                                                   data-key="<?= h($s['key']) ?>" onchange="pdSaveAlt(this)"
                                                   style="margin-top:7px;width:100%;max-width:460px;padding:6px 9px;border:1px solid #d1d5db;border-radius:5px;font-size:.82rem;">
                                        <?php else: ?>
                                            <div class="hint" style="margin-top:7px;">Decorative background &mdash; no alt text.</div>
                                        <?php endif; ?>

                                        <?php if ($shared > 1 || $tplHits > 0): ?>
                                            <label class="hint" style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                                                <input type="checkbox" class="pd-prop" checked>
                                                Also replace
                                                <?php if ($shared > 1): ?>
                                                    on the other <?= $shared - 1 ?> page<?= $shared - 1 === 1 ? '' : 's' ?> using this same picture here<?= $tplHits ? ',' : '' ?>
                                                <?php endif; ?>
                                                <?php if ($tplHits): ?>
                                                    <?= $shared > 1 ? 'and in' : 'in' ?> <?= $tplHits ?> landing template<?= $tplHits === 1 ? '' : 's' ?> (or a regen puts the old one back)
                                                <?php endif; ?>
                                            </label>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($s['token']): ?>
                                        <div style="flex-shrink:0;width:210px;border:1px solid #e5e7eb;border-radius:7px;padding:14px 10px;text-align:center;font-size:.78rem;color:#94a3b8;background:#f8fafc;">
                                            &#128274; Set per city<br><span style="font-size:.72rem;">City Image plugin</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="pd-drop" data-key="<?= h($s['key']) ?>" data-sid="<?= $sid ?>"
                                             style="flex-shrink:0;width:210px;border:2px dashed #d1d5db;border-radius:7px;padding:14px 10px;text-align:center;cursor:pointer;font-size:.8rem;color:#6b7280;transition:border-color .15s,background .15s;">
                                            Drop an image here<br><span style="font-size:.75rem;">or click to choose</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <input type="file" id="pd-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">

            <div id="pd-adj" style="display:none;position:fixed;inset:0;z-index:9100;background:rgba(15,23,42,.9);align-items:center;justify-content:center;padding:24px;">
              <div style="background:#fff;border-radius:10px;padding:18px;max-width:min(92vw,760px);width:100%;">
                <div style="font-weight:700;color:#1e3a5f;margin-bottom:2px;">Adjust view</div>
                <div id="pd-adj-sub" class="hint" style="margin-bottom:12px;">&nbsp;</div>

                <?php /* The frame IS the slot: what you see inside these edges is exactly
                         what the page will show, because the browser computes the same
                         source rectangle the server then cuts. */ ?>
                <div id="pd-adj-frame"
                     style="position:relative;overflow:hidden;background:#0f172a;border-radius:6px;margin:0 auto;cursor:grab;user-select:none;touch-action:none;">
                  <img id="pd-adj-img" src="" alt="" draggable="false"
                       style="position:absolute;left:0;top:0;transform-origin:0 0;max-width:none;pointer-events:none;">
                </div>

                <div style="display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;">
                  <span class="hint" style="white-space:nowrap;">Zoom</span>
                  <input id="pd-adj-zoom" type="range" min="100" max="400" value="100" style="flex:1;min-width:160px;">
                  <span id="pd-adj-zval" class="hint" style="width:52px;">1.00×</span>
                  <button type="button" class="btn btn-secondary btn-small" id="pd-adj-reset">Reset</button>
                </div>
                <div id="pd-adj-note" class="hint" style="margin-top:8px;min-height:1.2em;"></div>

                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
                  <button type="button" class="btn btn-secondary btn-small" id="pd-adj-cancel">Cancel</button>
                  <button type="button" class="btn btn-small" id="pd-adj-apply">Apply</button>
                </div>
              </div>
            </div>

            <div id="pd-lightbox" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,23,42,.88);align-items:center;justify-content:center;padding:32px;">
                <div style="max-width:100%;max-height:100%;display:flex;flex-direction:column;gap:10px;align-items:center;">
                    <img id="pd-lb-img" src="" alt="" style="max-width:100%;max-height:calc(100vh - 132px);object-fit:contain;border-radius:6px;background:#fff;">
                    <div style="display:flex;align-items:center;gap:14px;color:#e2e8f0;font-size:.82rem;flex-wrap:wrap;justify-content:center;">
                        <span id="pd-lb-name" style="font-weight:600;"></span>
                        <span id="pd-lb-dims"></span>
                        <a id="pd-lb-dl" href="" download="" style="color:#93c5fd;text-decoration:none;">&#11015; Download</a>
                        <button type="button" id="pd-lb-close" style="background:none;border:1px solid #64748b;color:#e2e8f0;border-radius:5px;padding:3px 10px;cursor:pointer;font-size:.78rem;">Close (Esc)</button>
                    </div>
                </div>
            </div>

            <script>
            (function () {
                var pending = null;   // the drop zone waiting on the hidden file input

                window.pdToggle = function (id) {
                    var body  = document.getElementById(id);
                    var caret = document.getElementById(id + '_caret');
                    var open  = body.style.display === 'none';
                    body.style.display = open ? '' : 'none';
                    caret.innerHTML    = open ? '&#9660;' : '&#9654;';
                    // Thumbnails load on first expand, not on page load. 460 images up front
                    // would be several MB of requests for a page you may only scroll past.
                    if (open) {
                        body.querySelectorAll('img[data-src]').forEach(function (img) {
                            img.src = img.getAttribute('data-src');
                            img.removeAttribute('data-src');
                        });
                    }
                };

                window.pdToggleAll = function (open) {
                    document.querySelectorAll('.pd-group').forEach(function (g) {
                        if (g.style.display === 'none') return;
                        var body = g.querySelector('.pd-body');
                        if (!body) return;
                        var isOpen = body.style.display !== 'none';
                        if (isOpen !== open) pdToggle(body.id);
                    });
                };

                document.getElementById('pd-filter').addEventListener('input', function () {
                    var q = this.value.trim().toLowerCase();
                    var shown = 0;
                    document.querySelectorAll('.pd-group').forEach(function (g) {
                        var hit = !q || g.getAttribute('data-search').indexOf(q) !== -1;
                        g.style.display = hit ? '' : 'none';
                        if (hit) shown++;
                    });
                    document.getElementById('pd-shown').textContent =
                        shown + (q ? ' of <?= count($pdGroups) ?>' : '') + ' pages';
                });

                function markDirty() {
                    document.getElementById('pd-dirty').style.display = '';
                }

                // ── Full-size viewer ──────────────────────────────────────────────
                var lb     = document.getElementById('pd-lightbox');
                var lbImg  = document.getElementById('pd-lb-img');
                var lbName = document.getElementById('pd-lb-name');
                var lbDims = document.getElementById('pd-lb-dims');
                var lbDl   = document.getElementById('pd-lb-dl');

                function pdOpen(url, name) {
                    lbName.textContent = name || '';
                    lbDims.textContent = '';
                    lbDl.href = url;
                    lbDl.setAttribute('download', name || '');
                    // Read the natural size off the loaded image rather than trusting the
                    // stored dimensions — after a replace they can differ until reload.
                    lbImg.onload = function () {
                        lbDims.textContent = lbImg.naturalWidth + ' × ' + lbImg.naturalHeight;
                    };
                    lbImg.src = url;
                    lb.style.display = 'flex';
                }

                function pdClose() {
                    lb.style.display = 'none';
                    lbImg.src = '';        // stop a large image decoding in the background
                }

                // Delegated, so thumbnails swapped in after an upload work without rebinding.
                document.addEventListener('click', function (e) {
                    var adjLink = e.target.closest ? e.target.closest('a[data-adjust]') : null;
                    if (adjLink) {
                        e.preventDefault();
                        adjOpen(adjLink.getAttribute('data-adjust'), adjLink.getAttribute('data-sid'));
                        return;
                    }
                    var link = e.target.closest ? e.target.closest('a[data-view]') : null;
                    if (link) {
                        e.preventDefault();
                        pdOpen(link.getAttribute('data-view'), link.getAttribute('data-name'));
                        return;
                    }
                    var img = e.target.closest ? e.target.closest('img[data-full]') : null;
                    if (img) {
                        pdOpen(img.getAttribute('data-full'), img.getAttribute('data-name'));
                    }
                });

                lb.addEventListener('click', function (e) {
                    // Backdrop only — a click on the image itself should not dismiss it.
                    if (e.target === lb) pdClose();
                });
                document.getElementById('pd-lb-close').addEventListener('click', pdClose);
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && lb.style.display !== 'none') pdClose();
                });

                function setMeta(sid, text, colour) {
                    var el = document.getElementById(sid + '_meta');
                    if (el) { el.innerHTML = ''; el.textContent = text; el.style.color = colour || ''; }
                }

                function upload(zone, file) {
                    var sid = zone.getAttribute('data-sid');
                    var slot = zone.closest('.pd-slot');
                    var prop = slot ? slot.querySelector('.pd-prop') : null;

                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'place');
                    fd.append('key', zone.getAttribute('data-key'));
                    fd.append('file', file);
                    if (prop && prop.checked) fd.append('propagate', '1');
                    if (document.getElementById('pd-screen').checked) fd.append('screen', '1');

                    zone.style.borderColor = '#2563eb';
                    setMeta(sid, 'Uploading…', '#2563eb');

                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'The server did not return a valid response.' }; })
                        .then(function (d) {
                            zone.style.borderColor = '#d1d5db';
                            if (!d || !d.success) {
                                setMeta(sid, (d && d.error) || 'Upload failed.', '#b91c1c');
                                return;
                            }
                            // Cache-bust the thumbnail only. data-full stays clean so the
                            // viewer and the download link keep a tidy filename.
                            var prev = document.getElementById(sid + '_prev');
                            prev.innerHTML = '';
                            var img = document.createElement('img');
                            img.src = '/' + d.url + '?t=' + Date.now();
                            img.setAttribute('data-full', '/' + d.url);
                            img.setAttribute('data-name', d.filename);
                            img.alt = '';
                            img.title = 'Click to view full size';
                            img.style.cssText = 'width:132px;height:88px;object-fit:cover;border-radius:5px;background:#f1f5f9;display:block;cursor:zoom-in;';
                            prev.appendChild(img);

                            // The row may not have had View/Download links yet (empty slot).
                            var acts = document.getElementById(sid + '_acts');
                            if (!acts) {
                                acts = document.createElement('div');
                                acts.id = sid + '_acts';
                                acts.style.cssText = 'margin-top:5px;display:flex;gap:10px;font-size:.76rem;';
                                var meta = document.getElementById(sid + '_meta');
                                meta.parentNode.insertBefore(acts, meta.nextSibling);
                            }
                            acts.innerHTML =
                                '<a href="#" data-view="/' + d.url + '" data-name="' + d.filename + '" style="color:#2563eb;text-decoration:none;">&#128269; View full size</a>' +
                                '<a href="/' + d.url + '" download="' + d.filename + '" style="color:#2563eb;text-decoration:none;">&#11015; Download</a>';

                            var msg = d.width + '×' + d.height + ' · ' + d.note;
                            if (d.propagated) msg += ' · also applied to ' + d.propagated + ' other page' + (d.propagated === 1 ? '' : 's');
                            if (d.templates)  msg += ' · ' + d.templates + ' landing template' + (d.templates === 1 ? '' : 's') + ' updated so a regen keeps it';
                            if (d.og_updated) msg += ' · social image followed on ' + d.og_updated;
                            if (d.screened)   msg += ' · ' + d.screened;
                            if (d.errors && d.errors.length) msg += ' · ' + d.errors.length + ' write error(s): ' + d.errors[0];
                            setMeta(sid, msg, d.errors && d.errors.length ? '#b45309' : '#15803d');
                            markDirty();
                        });
                }

                // ── Adjust view: zoom + pan ───────────────────────────────────────
                // The rectangle worked out here is the SAME one img_source_rect() cuts
                // on the server, from the same zoom/fx/fy. Keep the two in step or the
                // preview stops predicting the result.
                var adj = {
                    key: null, sid: null, zoom: 1, fx: 0.5, fy: 0.5,
                    ow: 0, oh: 0, tw: 0, th: 0, frameW: 0, frameH: 0, drag: null
                };
                var adjBox   = document.getElementById('pd-adj');
                var adjFrame = document.getElementById('pd-adj-frame');
                var adjImg   = document.getElementById('pd-adj-img');
                var adjZoom  = document.getElementById('pd-adj-zoom');
                var adjZval  = document.getElementById('pd-adj-zval');
                var adjNote  = document.getElementById('pd-adj-note');
                var adjSub   = document.getElementById('pd-adj-sub');

                function adjRect() {
                    var target = adj.tw / adj.th, sw0, sh0;
                    if (adj.ow / adj.oh > target) { sh0 = adj.oh; sw0 = Math.round(adj.oh * target); }
                    else                          { sw0 = adj.ow; sh0 = Math.round(adj.ow / target); }
                    var sw = Math.max(1, Math.round(sw0 / adj.zoom));
                    var sh = Math.max(1, Math.round(sh0 / adj.zoom));
                    return {
                        sx: Math.round(adj.fx * (adj.ow - sw)),
                        sy: Math.round(adj.fy * (adj.oh - sh)),
                        sw: sw, sh: sh
                    };
                }

                function adjPaint() {
                    var r = adjRect();
                    var k = adj.frameW / r.sw;          // frame pixels per source pixel
                    adjImg.style.width  = (adj.ow * k) + 'px';
                    adjImg.style.height = (adj.oh * k) + 'px';
                    adjImg.style.left   = (-r.sx * k) + 'px';
                    adjImg.style.top    = (-r.sy * k) + 'px';
                    adjZval.textContent = adj.zoom.toFixed(2) + '×';
                    adjZoom.value = Math.round(adj.zoom * 100);
                }

                function adjOpen(key, sid) {
                    adj.key = key; adj.sid = sid;
                    adjNote.textContent = 'Loading…';
                    adjNote.style.color = '';
                    adjBox.style.display = 'flex';

                    fetch('picdrop_api.php?action=info&key=' + encodeURIComponent(key))
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'could not reach the server' }; })
                        .then(function (d) {
                            if (!d || !d.success) {
                                adjNote.textContent = (d && d.error) || 'Could not load this picture.';
                                adjNote.style.color = '#b91c1c';
                                return;
                            }
                            adj.ow = d.src_w; adj.oh = d.src_h;
                            adj.tw = d.slot_w; adj.th = d.slot_h;
                            adj.zoom = d.zoom || 1; adj.fx = d.fx; adj.fy = d.fy;

                            // Frame drawn at the slot's own shape so nothing is implied
                            // about the crop that is not true.
                            var maxW = Math.min(660, window.innerWidth - 90);
                            var maxH = window.innerHeight - 320;
                            adj.frameW = maxW;
                            adj.frameH = Math.round(maxW * adj.th / adj.tw);
                            if (adj.frameH > maxH) { adj.frameH = maxH; adj.frameW = Math.round(maxH * adj.tw / adj.th); }
                            adjFrame.style.width  = adj.frameW + 'px';
                            adjFrame.style.height = adj.frameH + 'px';

                            adjSub.textContent = 'Slot ' + adj.tw + '×' + adj.th
                                + ' · source ' + adj.ow + '×' + adj.oh
                                + (d.has_original ? ' (full original kept)' : ' (no original — you can zoom in, not out)');
                            adjNote.textContent = 'Drag to move · scroll or use the slider to zoom';

                            adjImg.onload = adjPaint;
                            adjImg.src = 'picdrop_api.php?action=source&key=' + encodeURIComponent(key) + '&t=' + Date.now();
                        });
                }

                function adjClose() { adjBox.style.display = 'none'; adjImg.src = ''; adj.key = null; }

                adjFrame.addEventListener('pointerdown', function (e) {
                    if (!adj.ow) return;
                    adj.drag = { x: e.clientX, y: e.clientY };
                    adjFrame.style.cursor = 'grabbing';
                    adjFrame.setPointerCapture(e.pointerId);
                });
                adjFrame.addEventListener('pointermove', function (e) {
                    if (!adj.drag) return;
                    var r = adjRect();
                    var k = adj.frameW / r.sw;
                    // Dragging right reveals what is to the LEFT, so the offset moves the
                    // opposite way. Divided by the slack, since fx/fy are fractions of it.
                    var slackX = adj.ow - r.sw, slackY = adj.oh - r.sh;
                    if (slackX > 0) adj.fx = Math.max(0, Math.min(1, adj.fx - (e.clientX - adj.drag.x) / (k * slackX)));
                    if (slackY > 0) adj.fy = Math.max(0, Math.min(1, adj.fy - (e.clientY - adj.drag.y) / (k * slackY)));
                    adj.drag = { x: e.clientX, y: e.clientY };
                    adjPaint();
                });
                ['pointerup', 'pointercancel'].forEach(function (ev) {
                    adjFrame.addEventListener(ev, function () { adj.drag = null; adjFrame.style.cursor = 'grab'; });
                });
                adjFrame.addEventListener('wheel', function (e) {
                    if (!adj.ow) return;
                    e.preventDefault();
                    adj.zoom = Math.max(1, Math.min(4, adj.zoom * (e.deltaY < 0 ? 1.08 : 1 / 1.08)));
                    adjPaint();
                }, { passive: false });
                adjZoom.addEventListener('input', function () {
                    adj.zoom = Math.max(1, Math.min(4, this.value / 100));
                    adjPaint();
                });
                document.getElementById('pd-adj-reset').addEventListener('click', function () {
                    adj.zoom = 1; adj.fx = 0.5; adj.fy = 0.5; adjPaint();
                });
                document.getElementById('pd-adj-cancel').addEventListener('click', adjClose);
                adjBox.addEventListener('click', function (e) { if (e.target === adjBox) adjClose(); });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && adjBox.style.display !== 'none') adjClose();
                });

                document.getElementById('pd-adj-apply').addEventListener('click', function () {
                    if (!adj.key) return;
                    var btn = this;
                    btn.disabled = true;
                    adjNote.textContent = 'Cutting…';
                    adjNote.style.color = '';

                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'adjust');
                    fd.append('key', adj.key);
                    fd.append('zoom', adj.zoom);
                    fd.append('fx', adj.fx);
                    fd.append('fy', adj.fy);

                    var sid = adj.sid;
                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'The server did not return a valid response.' }; })
                        .then(function (d) {
                            btn.disabled = false;
                            if (!d || !d.success) {
                                adjNote.textContent = (d && d.error) || 'Could not apply.';
                                adjNote.style.color = '#b91c1c';
                                return;
                            }
                            var prev = document.getElementById(sid + '_prev');
                            if (prev) {
                                prev.innerHTML = '';
                                var img = document.createElement('img');
                                img.src = '/' + d.url + '?t=' + Date.now();
                                img.setAttribute('data-full', '/' + d.url);
                                img.setAttribute('data-name', d.filename);
                                img.alt = ''; img.title = 'Click to view full size';
                                img.style.cssText = 'width:132px;height:88px;object-fit:cover;border-radius:5px;background:#f1f5f9;display:block;cursor:zoom-in;';
                                prev.appendChild(img);
                            }
                            var acts = document.getElementById(sid + '_acts');
                            if (acts) {
                                acts.innerHTML =
                                    '<a href="#" data-view="/' + d.url + '" data-name="' + d.filename + '" style="color:#2563eb;text-decoration:none;">&#128269; View full size</a>' +
                                    '<a href="/' + d.url + '" download="' + d.filename + '" style="color:#2563eb;text-decoration:none;">&#11015; Download</a>' +
                                    '<a href="#" data-adjust="' + adj.key + '" data-sid="' + sid + '" style="color:#2563eb;text-decoration:none;">&#9635; Adjust view</a>';
                            }
                            setMeta(sid, d.width + '×' + d.height + ' · ' + d.note, '#15803d');
                            markDirty();
                            adjClose();
                        });
                });

                window.pdSaveAlt = function (input) {
                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'alt');
                    fd.append('key', input.getAttribute('data-key'));
                    fd.append('alt', input.value);
                    input.style.borderColor = '#2563eb';
                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'network' }; })
                        .then(function (d) {
                            input.style.borderColor = (d && d.success) ? '#16a34a' : '#b91c1c';
                            if (d && d.success) markDirty();
                            setTimeout(function () { input.style.borderColor = '#d1d5db'; }, 1500);
                        });
                };

                document.querySelectorAll('.pd-drop').forEach(function (zone) {
                    ['dragenter', 'dragover'].forEach(function (ev) {
                        zone.addEventListener(ev, function (e) {
                            e.preventDefault(); e.stopPropagation();
                            zone.style.borderColor = '#2563eb'; zone.style.background = '#eff6ff';
                        });
                    });
                    ['dragleave', 'drop'].forEach(function (ev) {
                        zone.addEventListener(ev, function (e) {
                            e.preventDefault(); e.stopPropagation();
                            zone.style.borderColor = '#d1d5db'; zone.style.background = '';
                        });
                    });
                    zone.addEventListener('drop', function (e) {
                        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                        if (f) upload(zone, f);
                    });
                    zone.addEventListener('click', function () {
                        pending = zone;
                        var inp = document.getElementById('pd-file');
                        inp.value = '';
                        inp.click();
                    });
                });

                document.getElementById('pd-file').addEventListener('change', function () {
                    if (pending && this.files && this.files[0]) upload(pending, this.files[0]);
                    pending = null;
                });

                // Paste a screenshot straight onto whichever slot was clicked last.
                document.addEventListener('paste', function (e) {
                    if (!pending || !e.clipboardData) return;
                    var items = e.clipboardData.items || [];
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].type.indexOf('image/') === 0) {
                            var f = items[i].getAsFile();
                            if (f) { upload(pending, f); e.preventDefault(); }
                            return;
                        }
                    }
                });

                window.pdRebuild = function () {
                    var btn = document.getElementById('pd-build-btn');
                    var log = document.getElementById('pd-build-log');
                    btn.disabled = true;
                    log.style.color = '#475569';
                    log.textContent = 'Rebuilding…';

                    var es = new EventSource('generate_static.php?token=' + encodeURIComponent(CSRF_TOKEN));
                    es.onmessage = function (ev) {
                        var d;
                        try { d = JSON.parse(ev.data); } catch (x) { return; }
                        if (d.type === 'progress' && d.total > 0) {
                            log.textContent = 'Rebuilding… ' + d.done + ' of ' + d.total;
                        } else if (d.type === 'done') {
                            es.close();
                            btn.disabled = false;
                            log.style.color = '#15803d';
                            log.textContent = 'Rebuilt. The static site now matches these pictures.';
                            document.getElementById('pd-dirty').style.display = 'none';
                        } else if (d.type === 'fatal' || d.type === 'error') {
                            es.close();
                            btn.disabled = false;
                            log.style.color = '#b91c1c';
                            log.textContent = 'Rebuild failed: ' + (d.msg || 'unknown error');
                        }
                    };
                    es.onerror = function () {
                        es.close();
                        btn.disabled = false;
                        // A clean end also fires onerror after the stream closes, so only
                        // report a problem if we never saw a terminal message.
                        if (log.textContent.indexOf('Rebuilding') === 0) {
                            log.style.color = '#b91c1c';
                            log.textContent = 'Connection to the build stream was lost.';
                        }
                    };
                };
            })();
            </script>
        <?php endif; ?>
    </div>
