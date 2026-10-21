    <div class="tab-content" style="<?= $tab === 'picdrop' ? '' : 'display:none;' ?>">
        <?php tab_header('Pic Drop', 'Every picture on the site, page by page. Each slot shows its Real photo on the left and its AI photo on the right — whichever one has the blue border and checkmark is the one actually live on the page. To add or replace the Real photo, use the "Drop an image here" box next to the thumbnails: drag a file onto it, or click it to choose one from your computer. It is optimised, resized to fit that slot, filed in the media library, and written straight into the page.', 'tab-picdrop'); ?>

        <div class="callout tip" style="margin-bottom:16px;">
            <p style="margin:0 0 6px;"><strong>Writing a good AI-generate prompt.</strong> The default text pre-filled below already asks for these — worth keeping if you rewrite it:</p>
            <ul style="margin:0 0 6px 18px;padding:0;">
                <li><strong>Name the actual equipment or vehicle</strong> that belongs in the shot — a wet-vac, dehumidifier, extraction wand, work van, whatever it really is. Left unnamed, the model won't invent it.</li>
                <li><strong>Ask for a wide or medium-wide shot, explicitly.</strong> Left unstated, results tend toward a tight portrait crop that leaves the equipment and surroundings out of frame.</li>
                <li><strong>Keep the lighting / expression / no-text-or-logo lines</strong> from the default — they stop dark or "distressed"-looking scenes and any burned-in text.</li>
            </ul>
            <p style="margin:0 0 6px;"><strong>"Use current photo as reference" uses whichever side is currently active — not always Real.</strong> Each slot can hold a Real photo and an AI photo side by side; whichever one shows the blue border and the &#10003; checkmark (Real or AI) is what's actually live on the page right now, and that is exactly what gets sent as the reference — not necessarily the original Real photo. Switch the active side first (the "use Real" / "use AI" link under the other thumbnail) if you want to generate from a different starting photo.</p>
            <p style="margin:0;font-size:.85rem;color:#475569;">The result always gets cropped to fit this slot's exact box afterward, so a subject or piece of equipment composed edge-to-edge is exactly what that crop cuts into — the default prompt now says so explicitly ("leave margin around the subject... do not compose it edge-to-edge") when <strong>"Use current photo as reference"</strong> is checked. See the <a href="/admin/docs.php#tab-picdrop" target="_blank">Pic Drop docs</a> for more.</p>
        </div>

        <?php if ($tab !== 'picdrop'): ?>
            <?php /* Enumerating slots means parsing every landing page file — 153 of them on
                     appliance-site. index.php renders all tabs into one document, so doing that
                     work unconditionally would tax every admin page view, on every tab. */ ?>
            <div class="card"><p class="hint">Open the Pic Drop tab to load the page list.</p></div>
        <?php else: ?>
            <?php
            require_once BASE_DIR . '/includes/picdrop.php';

            $pdGroups         = picdrop_groups();
            $pdTemplateGroups = picdrop_template_groups();

            // What actually gets a row: site-wide/home/core pages as before, but landing
            // pages are replaced by their TEMPLATES — one row per template, not one per
            // already-built city, which stops scaling past a handful of cities. $pdGroups
            // itself is left untouched (still a full scan, still used below for the
            // cross-value checks the non-template rows rely on).
            $pdRenderGroups = array_merge(
                array_values(array_filter($pdGroups, fn($g) => $g['scope'] !== 'landing')),
                $pdTemplateGroups
            );

            // How many slots share each image, so the propagate control can say what it will do
            // before you commit to it. Keyed by value + leaf field, matching what the API does.
            $pdShared = [];
            foreach ($pdGroups as $g) {
                foreach ($g['slots'] as $s) {
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

            $pdTotal = 0;
            foreach ($pdRenderGroups as $g) { $pdTotal += count($g['slots']); }

            // Scanned from $pdGroups, NOT $pdRenderGroups — the render list swaps every
            // already-built landing page for its one template row (so the page doesn't
            // grow a row per city), but a broken file reference is a data-integrity
            // problem on that specific built page, not something a template row would
            // ever surface. $pdGroups already walked every one of those page files for
            // the $pdShared count above; this just reads the same scan. Split into
            // "shown below" vs. "total" so the banner can say which is which, rather
            // than pointing at red text that may not exist among the rendered rows.
            $pdMissingShown = 0;
            foreach ($pdRenderGroups as $g) {
                foreach ($g['slots'] as $s) { if (!$s['exists'] && !$s['token'] && $s['value'] !== '') $pdMissingShown++; }
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
                        <?= count($pdRenderGroups) ?> groups &middot; <?= $pdTotal ?> pictures
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
                        <?= $pdMissing ?> slot<?= $pdMissing === 1 ? '' : 's' ?> point at a file that is not on disk
                        <?php if ($pdMissingShown === $pdMissing): ?>
                            &mdash; shown in red below.
                        <?php elseif ($pdMissingShown > 0): ?>
                            &mdash; <?= $pdMissingShown ?> shown in red below, the rest on already-built
                            landing pages (not listed individually here &mdash; expand the page in question
                            directly, or check <code>data/pages/</code>).
                        <?php else: ?>
                            &mdash; all on already-built landing pages, not listed individually here
                            (this page only lists one row per template). Check <code>data/pages/</code> for
                            the specific city page.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php $pdShownTplHeader = false; ?>
            <?php foreach ($pdRenderGroups as $gi => $g): ?>
                <?php if ($g['scope'] === 'template' && !$pdShownTplHeader): $pdShownTplHeader = true; ?>
                    <h2 style="font-size:1rem;color:#1e3a5f;margin:24px 0 8px;">Landing page templates</h2>
                    <p class="hint" style="margin:0 0 12px;">One row per template &mdash; not per city. Setting a photo here and checking
                        "also replace elsewhere" pushes it onto every page already built from this template.</p>
                <?php endif; ?>
                <?php $gid = 'pdg' . $gi; ?>
                <div class="card pd-group" data-search="<?= h(strtolower($g['title'] . ' ' . $g['sub'])) ?>" style="padding:0;overflow:hidden;">
                    <button type="button" class="pd-head" onclick="pdToggle('<?= $gid ?>')"
                            style="width:100%;display:flex;align-items:center;gap:10px;padding:12px 16px;background:none;border:0;cursor:pointer;text-align:left;">
                        <span id="<?= $gid ?>_caret" style="color:#6b7280;font-size:.8rem;width:12px;">&#9660;</span>
                        <span style="font-weight:600;color:#1e3a5f;"><?= h($g['title']) ?></span>
                        <span class="hint" style="flex:1;"><?= h($g['sub']) ?></span>
                        <span class="hint" style="white-space:nowrap;"><?= count($g['slots']) ?> picture<?= count($g['slots']) === 1 ? '' : 's' ?></span>
                    </button>

                    <?php // Open by default on page load — Scott comes in to work through
                          // every slot, not to browse titles, so "collapsed" was just an
                          // extra click on every single group, every single visit. ?>
                    <div id="<?= $gid ?>" class="pd-body" style="border-top:1px solid #e5e7eb;padding:8px 16px 16px;">
                        <?php if (!$g['slots']): ?>
                            <p class="hint" style="margin:10px 0 0;">No pictures on this page.</p>
                        <?php else: ?>
                            <?php foreach ($g['slots'] as $s): ?>
                                <?php
                                $sid = 'pds' . md5($s['key']);
                                // A template row never offers "also replace elsewhere" at all — one Pic
                                // Drop action on a template touches exactly that one field, nothing else
                                // (see docs/pic-drop-image-management.md's "Also replace elsewhere"
                                // section for why: the byte-identical matchers this checkbox used to
                                // drive for a page row would, for a template row, just as easily match
                                // and overwrite an unrelated template that happens to share the same
                                // starting photo — which is exactly what happened).
                                if ($g['scope'] === 'template') {
                                    $shared = $tplHits = 0;
                                    $propShow = false;
                                } else {
                                    $shareKey = $s['value'] . '|' . picdrop_leaf($s['field']);
                                    $shared   = $pdShared[$shareKey] ?? 1;
                                    $tplHits  = $pdTpl[$shareKey] ?? 0;
                                    $propShow = ($shared > 1 || $tplHits > 0);
                                }
                                ?>
                                <div class="pd-slot" style="display:flex;gap:14px;padding:12px 0;border-bottom:1px solid #f1f5f9;">
                                    <div id="<?= $sid ?>_prev" style="flex-shrink:0;width:132px;">
                                        <?php
                                        $pair = $s['pair'] ?? ['real' => null, 'ai' => null, 'active' => 'real'];
                                        // A slot untouched by this feature still has its one real photo sitting in
                                        // the block field itself — show it in the Real space rather than telling you
                                        // "no real photo" about a slot that plainly has one. Guarded against also
                                        // being the AI path so the two spaces never show the same file after an
                                        // AI photo has been used on a slot pairs.json never saw a real drop for.
                                        $realPath = $pair['real'] ?: (($s['value'] !== '' && $s['value'] !== $pair['ai']) ? $s['value'] : null);
                                        $aiPath   = $pair['ai'];
                                        $active   = $pair['active'];
                                        // A cell renderer, not a function, so it can close over $sid/h() without a
                                        // parameter list — this block is the only caller and always will be.
                                        $cell = function (?string $path, string $labelText, bool $isActive, string $which) use ($sid, $s) {
                                            echo '<div style="display:inline-block;width:63px;vertical-align:top;">';
                                            if ($path === null) {
                                                echo '<div style="width:63px;height:63px;border-radius:5px;background:#f8fafc;border:1px dashed #cbd5e1;"></div>';
                                                echo '<div style="font-size:.62rem;text-align:center;margin-top:2px;color:#cbd5e1;">no ' . $labelText . '</div>';
                                            } else {
                                                $activeStyle = $isActive ? 'border:2px solid #2563eb;' : 'border:2px solid transparent;opacity:.75;';
                                                echo '<img src="/' . h($path) . '" data-full="/' . h($path) . '" data-name="' . h(basename($path)) . '" data-sid="' . $sid . '" data-key="' . h($s['key']) . '" data-which="' . $which . '" alt="" title="Click to view full size"'
                                                   . ' style="width:63px;height:63px;object-fit:cover;border-radius:5px;background:#f1f5f9;display:block;cursor:zoom-in;' . $activeStyle . '"'
                                                   . ' onerror="this.style.display=\'none\'">';
                                                echo '<div style="font-size:.62rem;text-align:center;margin-top:2px;color:' . ($isActive ? '#2563eb;font-weight:600;' : '#94a3b8;') . '">';
                                                if ($isActive) { echo $labelText . ' &#10003;'; }
                                                else { echo '<a href="#" class="pd-set-active" data-sid="' . $sid . '" data-key="' . h($s['key']) . '" data-which="' . $which . '" style="color:#6b7280;text-decoration:underline;">use ' . $labelText . '</a>'; }
                                                echo '</div>';
                                            }
                                            echo '</div>';
                                        };
                                        ?>
                                        <?php if ($s['token']): ?>
                                            <div style="width:132px;height:88px;border-radius:5px;background:#f8fafc;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#94a3b8;">&#127961;</div>
                                        <?php else: ?>
                                            <?php $cell($realPath, 'Real', $active === 'real' && $realPath !== null, 'real'); ?>
                                            <?php $cell($aiPath, 'AI', $active === 'ai' && $aiPath !== null, 'ai'); ?>
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
                                        <?php if (!$s['token']): ?>
                                            <div style="margin-top:2px;font-size:.72rem;color:#94a3b8;font-style:italic;">
                                                <?= h(picdrop_crop_hint($s['block_type'], picdrop_leaf($s['field']))) ?>
                                            </div>
                                        <?php endif; ?>

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
                                                   id="<?= $sid ?>_alt" data-sid="<?= $sid ?>"
                                                   data-key="<?= h($s['key']) ?>" onchange="pdSaveAlt(this)"
                                                   style="margin-top:7px;width:100%;max-width:460px;padding:6px 9px;border:1px solid #d1d5db;border-radius:5px;font-size:.82rem;">
                                            <div id="<?= $sid ?>_altnote" class="hint" style="display:none;margin-top:3px;color:#b45309;">
                                                Photo changed &mdash; check this alt text still describes what&rsquo;s in it.
                                            </div>
                                        <?php else: ?>
                                            <div class="hint" style="margin-top:7px;">Decorative background &mdash; no alt text.</div>
                                        <?php endif; ?>

                                        <?php // $propShow is always false for a template row — see above.
                                        if ($propShow): ?>
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
                                        <?php
                                        // The alt text (when there is one) is a real description of what's
                                        // actually supposed to be in the shot — often written by the AI content
                                        // generator itself, e.g. "Water damage restoration technician using a
                                        // wet-vac beside a marked service van in {city}, {SS}". The block/field
                                        // label ("Feature Split — Feature split") is just a UI name for the slot
                                        // and says nothing about the subject, which is exactly why a generic
                                        // default led the model to invent unrelated equipment instead of the
                                        // vehicle that belonged in frame.
                                        $subjectFallback = $s['block_label'] . ' — ' . $s['label'];
                                        $subjectDesc = trim($s['alt']) !== '' ? $s['alt'] : $subjectFallback;
                                        // Alt text carries {city}/{SS}-style shortcodes meant for the page
                                        // render, not an image prompt. Only a TRAILING run of them is safe to
                                        // strip — e.g. "...beside a marked service van in {city}, {SS}" — since
                                        // that's genuinely just a location suffix bolted onto a real
                                        // description. Peeled one clause at a time (so "in {city}, {SS}" — two
                                        // clauses — fully clears) rather than matching the whole tail in one
                                        // shot.
                                        do {
                                            $before = $subjectDesc;
                                            $subjectDesc = trim((string) preg_replace('/\s*,?\s*(\bin\s+)?\{[a-z_]+\}\s*$/i', '', $subjectDesc));
                                        } while ($subjectDesc !== $before && $subjectDesc !== '');
                                        // A token that's NOT at the end isn't decoration — it's the sentence's
                                        // actual subject (e.g. "performing {service} in a {city_state} home"),
                                        // and deleting it mid-sentence used to leave a mangled fragment like
                                        // "technician performing in a home": grammatical, but with the one real
                                        // piece of information (what service) silently gone, which read to the
                                        // model as vaguer and less anchored than the real alt text. A string
                                        // that's nothing but tokens ("{service} in {city}, {SS}") strips all the
                                        // way to empty via the loop above. Either way, falling back to the
                                        // generic label beats handing the model literal curly-brace text or a
                                        // sentence missing its own subject.
                                        if ($subjectDesc === '' || preg_match('/\{[a-z_]+\}/i', $subjectDesc)) {
                                            $subjectDesc = $subjectFallback;
                                        }
                                        // Six full prompts, one per mode — not one default with clauses bolted
                                        // on for the others, since each mode's subject/intent is different
                                        // enough that a shared sentence fought itself (a "no technician" clause
                                        // appended to a sentence whose subject IS a technician, etc). All six
                                        // are written as edits of the current photo (see the reference checkbox
                                        // below, always on), so each opens by anchoring to "the same scene." A
                                        // seventh mode, "Custom", has no canned text of its own at all — it
                                        // clears the box for a hand-typed instruction instead (see
                                        // pdSetPromptMode()'s 'custom' case below).
                                        $aiPromptTech = 'Photorealistic photo for: ' . $subjectDesc . '. Keep the same scene and equipment. Make sure a technician is in the shot, genuinely working — not posed, not looking at the camera, focused on the tool or equipment. Their head, hands, and the equipment must all be visible in frame. Wide or medium-wide shot, with margin around the subject since this will be cropped to fit its slot. Bright, natural daylight. No visible text or logos.';
                                        $aiPromptNoTech = 'Similar picture, for: ' . $subjectDesc . ' — keep the same scene and equipment, with only a very subtle change in camera angle. Remove the technician entirely — just the equipment and surroundings. The equipment must be fully visible in frame, not cropped off. Wide or medium-wide shot, with margin around the equipment since this will be cropped to fit its slot. Bright, natural daylight. No visible text or logos.';
                                        // No subject description here either, same reasoning as "vary" below —
                                        // this mode REMOVES the equipment, so naming it (the way $aiPromptNoTech
                                        // safely can, since that mode KEEPS it) would ask the model to remove and
                                        // keep the same thing in one prompt. See picdrop_bare_scene_prompt().
                                        $aiPromptBare = picdrop_bare_scene_prompt();
                                        $aiPromptNewPerson = 'Same picture, for: ' . $subjectDesc . ' — keep the exact same scene, composition, and equipment. Replace whoever\'s in the shot with a different-looking person in the same role. The person and the equipment must both be fully visible in frame, not cropped off. Wide or medium-wide shot, with margin around the subject since this will be cropped to fit its slot. Bright, natural daylight. No visible text or logos.';
                                        // No subject description here, deliberately — unlike the others,
                                        // this mode isn't adding, removing, or changing anything, so naming the
                                        // equipment/subject just gives the model something to act on instead of
                                        // leave alone. Found by testing: restating "keep the equipment exactly"
                                        // alongside the subject's own equipment-naming text was adding MORE
                                        // equipment to the scene, not preserving what was already there.
                                        // The same applied to the generic nouns themselves - see
                                        // picdrop_vary_prompt() for why the text now names nothing at all.
                                        $aiPromptVary = picdrop_vary_prompt();
                                        // "Vary" plus one explicit change — its own prompt, not vary's text
                                        // with a clause appended, since vary's own wording ("everything stays
                                        // exactly as it is, nothing new is added") would directly contradict
                                        // an instruction to recolor the background tacked on after it. See
                                        // picdrop_vary_bg_prompt().
                                        $aiPromptVaryBg = picdrop_vary_bg_prompt();
                                        ?>
                                        <div style="flex-shrink:0;width:210px;">
                                            <div class="pd-drop" data-key="<?= h($s['key']) ?>" data-sid="<?= $sid ?>"
                                                 style="border:2px dashed #d1d5db;border-radius:7px;padding:14px 10px;text-align:center;cursor:pointer;font-size:.8rem;color:#6b7280;transition:border-color .15s,background .15s;">
                                                Drop an image here<br><span style="font-size:.75rem;">or click to choose</span>
                                            </div>
                                            <div style="display:flex;gap:12px;justify-content:center;margin-top:6px;font-size:.75rem;">
                                                <a href="#" class="pd-lib-open" data-key="<?= h($s['key']) ?>" data-sid="<?= $sid ?>" style="color:#2563eb;text-decoration:none;">&#128193; Library</a>
                                                <a href="#" class="pd-ai-open" data-sid="<?= $sid ?>" style="color:#2563eb;text-decoration:none;">&#10024; AI generate</a>
                                            </div>
                                            <div id="<?= $sid ?>_ai" style="display:none;margin-top:8px;">
                                                <textarea id="<?= $sid ?>_ai_prompt" rows="4"
                                                          data-prompt-tech="<?= h($aiPromptTech) ?>"
                                                          data-prompt-notech="<?= h($aiPromptNoTech) ?>"
                                                          data-prompt-bare="<?= h($aiPromptBare) ?>"
                                                          data-prompt-newperson="<?= h($aiPromptNewPerson) ?>"
                                                          data-prompt-vary="<?= h($aiPromptVary) ?>"
                                                          data-prompt-varybg="<?= h($aiPromptVaryBg) ?>"
                                                          placeholder="Describe the one change you want, e.g. &quot;make the wall purple instead of white&quot;"
                                                          style="width:100%;font-size:.74rem;padding:6px 7px;border:1px solid #d1d5db;border-radius:5px;box-sizing:border-box;resize:vertical;"><?= h($aiPromptTech) ?></textarea>
                                                <?php
                                                // Rendered unconditionally (hidden via style, not skipped via
                                                // if/endif) so dropping/generating a photo into a slot that was
                                                // EMPTY at page load can reveal this block live — applyResult()
                                                // in the JS below un-hides it on any successful photo change.
                                                // Before this, a same-session first-ever photo on a slot left the
                                                // Real thumbnail updated but this whole block permanently absent
                                                // (PHP had already decided not to emit it at the initial render),
                                                // which silently made every later "Generate" on that slot skip
                                                // the reference photo entirely — not a disabled control, nothing
                                                // in the DOM for the user to even notice was missing.
                                                $pdHasRef = ($s['value'] !== '' && $s['exists']);
                                                ?>
                                                <div id="<?= $sid ?>_ai_ref" style="<?= $pdHasRef ? '' : 'display:none;' ?>">
                                                <?php /* Always on, not optional, whenever there's a photo to
                                                         reference — all seven modes below are edits of the existing
                                                         photo, not from-scratch generations, so there's no mode
                                                         where skipping the reference makes sense. Left visible
                                                         (checked + disabled) rather than removed so it's still
                                                         obvious what Generate is about to do. */ ?>
                                                <label class="hint" style="display:flex;align-items:center;gap:5px;margin-top:5px;font-size:.7rem;">
                                                    <input type="checkbox" checked disabled>
                                                    Using current photo as reference
                                                </label>
                                                <?php /* A real radio group, not checkboxes faking exclusivity —
                                                         exactly one of the seven is always selected, "Add/ensure
                                                         technician" pre-selected, so there's no "nothing picked"
                                                         state to special-case. */ ?>
                                                <?php
                                                // One fixed-width number column (monospace digits are already
                                                // equal-width, but "1." through "7." still need a shared min-width
                                                // so every row's text starts at the same x position) followed by
                                                // the label text in its OWN span glued directly next to it — two
                                                // siblings inside one flex row used to let the number vertically
                                                // CENTER against the whole label whenever the text wrapped to a
                                                // second line (the three longer labels, at this column's width),
                                                // which read as the number floating apart from "the first line"
                                                // instead of sitting directly beside it. align-items:flex-start
                                                // plus the number/text pair sharing one line-box fixes both.
                                                $pdModes = [
                                                    ['tech', '1.', 'Add/ensure technician', true],
                                                    ['notech', '2.', 'No technician', false],
                                                    ['bare', '3.', 'No technician, no equipment', false],
                                                    ['newperson', '4.', 'Different person', false],
                                                    ['vary', '5.', 'Subtle variation', false],
                                                    ['varybg', '6.', 'Subtle variation, change background color', false],
                                                    ['custom', '7.', 'Custom instruction', false],
                                                ];
                                                foreach ($pdModes as [$modeVal, $modeNum, $modeLabel, $modeChecked]):
                                                ?>
                                                <label class="hint" style="display:flex;align-items:flex-start;gap:5px;margin-top:<?= $modeChecked ? '6' : '3' ?>px;font-size:.7rem;">
                                                    <input type="radio" name="<?= $sid ?>_ai_mode" value="<?= $modeVal ?>" <?= $modeChecked ? 'checked ' : '' ?>onchange="pdSetPromptMode('<?= $sid ?>','<?= $modeVal ?>')" style="margin-top:1px;">
                                                    <span><span style="font-family:monospace;color:#94a3b8;display:inline-block;min-width:13px;"><?= $modeNum ?></span><?= h($modeLabel) ?></span>
                                                </label>
                                                <?php endforeach; ?>
                                                </div>
                                                <div id="<?= $sid ?>_ai_btnrow" style="display:flex;justify-content:space-between;align-items:center;margin-top:5px;gap:6px;">
                                                    <span class="hint" style="font-size:.7rem;">est. ~$0.04/try</span>
                                                    <button type="button" class="btn btn-secondary" style="font-size:.72rem;padding:4px 12px;"
                                                            onclick="pdGenerate('<?= h($s['key']) ?>','<?= $sid ?>')">Generate</button>
                                                </div>
                                                <div id="<?= $sid ?>_ai_status" class="hint" style="font-size:.72rem;margin-top:5px;"></div>
                                                <div id="<?= $sid ?>_ai_preview" style="display:none;margin-top:8px;"></div>
                                            </div>
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

                <?php /* Reshape: picks a DIFFERENT target box than whatever the slot's
                         current photo happens to be. The normal "self-configuring" target
                         (picdrop_slot_target()) only ever reflects the shape already on
                         file — for a slot's first-ever photo there is nothing else to
                         derive a shape from, so a too-wide photo just gets saved untouched,
                         with no crop to "reveal" and nowhere in the UI to ask for one. This
                         is that missing manual escape hatch: pick a shape, the preview
                         switches to it immediately (zoomed out to show the whole picture,
                         centered), fine-tune with the zoom/drag controls below same as
                         always, Apply sends the chosen shape instead of the slot's own. */ ?>
                <div style="display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;">
                  <span class="hint" style="white-space:nowrap;">Shape</span>
                  <select id="pd-adj-ratio" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:.82rem;">
                    <option value="">Current shape</option>
                    <option value="4:3">4:3</option>
                    <option value="3:2">3:2</option>
                    <option value="16:9">16:9</option>
                    <option value="1:1">Square</option>
                    <option value="custom">Custom…</option>
                  </select>
                  <span id="pd-adj-ratio-custom" style="display:none;align-items:center;gap:4px;">
                    <input id="pd-adj-ratio-w" type="number" min="1" value="4" style="width:52px;padding:5px 6px;border:1px solid #d1d5db;border-radius:5px;font-size:.82rem;">
                    <span class="hint">:</span>
                    <input id="pd-adj-ratio-h" type="number" min="1" value="3" style="width:52px;padding:5px 6px;border:1px solid #d1d5db;border-radius:5px;font-size:.82rem;">
                  </span>
                </div>

                <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
                  <span class="hint" style="white-space:nowrap;">Zoom</span>
                  <input id="pd-adj-zoom" type="range" min="100" max="400" value="100" style="flex:1;min-width:160px;">
                  <span id="pd-adj-zval" class="hint" style="width:52px;">1.00×</span>
                  <?php /* Only offered when the source is a different shape from the slot —
                           otherwise "show the whole picture" and "fill the slot" are the
                           same thing and the button would do nothing. */ ?>
                  <button type="button" class="btn btn-secondary btn-small" id="pd-adj-fit" style="display:none;">Fit whole picture</button>
                  <button type="button" class="btn btn-secondary btn-small" id="pd-adj-reset">Fill slot</button>
                </div>

                <div id="pd-adj-fillwrap" style="display:none;align-items:center;gap:8px;margin-top:8px;">
                  <span class="hint">Empty space</span>
                  <select id="pd-adj-fill" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:.82rem;">
                    <option value="white">White</option>
                    <option value="black">Black</option>
                    <option value="none">Transparent</option>
                  </select>
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
                        <button type="button" id="pd-lb-del" style="display:none;background:none;border:1px solid #f87171;color:#fca5a5;border-radius:5px;padding:3px 10px;cursor:pointer;font-size:.78rem;">&#128465; Delete</button>
                        <button type="button" id="pd-lb-close" style="background:none;border:1px solid #64748b;color:#e2e8f0;border-radius:5px;padding:3px 10px;cursor:pointer;font-size:.78rem;">Close (Esc)</button>
                    </div>
                </div>
            </div>

            <div id="pd-lib" style="display:none;position:fixed;inset:0;z-index:9050;background:rgba(15,23,42,.85);align-items:center;justify-content:center;padding:24px;">
                <div style="background:#fff;border-radius:10px;padding:18px;max-width:min(92vw,760px);width:100%;max-height:85vh;display:flex;flex-direction:column;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <div style="font-weight:700;color:#1e3a5f;">Choose from the media library</div>
                        <button type="button" id="pd-lib-close" class="btn btn-secondary" style="font-size:.78rem;padding:4px 12px;">Close</button>
                    </div>
                    <div id="pd-lib-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;overflow-y:auto;"></div>
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
                        shown + (q ? ' of <?= count($pdRenderGroups) ?>' : '') + ' groups';
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

                // ctx = {sid, key, which} when opened from a slot's Real/AI thumbnail —
                // the only case where Delete makes sense. Library thumbnails pass none.
                var lbCtx = null;
                var lbDel = document.getElementById('pd-lb-del');
                function pdOpen(url, name, ctx) {
                    lbCtx = ctx || null;
                    lbDel.style.display = lbCtx ? '' : 'none';
                    lbDel.disabled = false;
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
                        var ctx = img.getAttribute('data-which')
                            ? { sid: img.getAttribute('data-sid'), key: img.getAttribute('data-key'), which: img.getAttribute('data-which') }
                            : null;
                        pdOpen(img.getAttribute('data-full'), img.getAttribute('data-name'), ctx);
                    }
                });

                lb.addEventListener('click', function (e) {
                    // Backdrop only — a click on the image itself should not dismiss it.
                    if (e.target === lb) pdClose();
                });
                document.getElementById('pd-lb-close').addEventListener('click', pdClose);

                lbDel.addEventListener('click', function () {
                    if (!lbCtx) return;
                    var c = lbCtx, label = c.which === 'ai' ? 'AI' : 'Real';
                    if (!confirm('Delete the ' + label + ' photo from this slot?\n\nIf it is the live photo, the slot switches to the other one '
                        + '(or is left empty if this is its only photo). '
                        + 'The file itself is only removed if no other page or template uses it.')) return;
                    lbDel.disabled = true;
                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'remove_side');
                    fd.append('key', c.key);
                    fd.append('which', c.which);
                    setMeta(c.sid, 'Deleting…', '#2563eb');
                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'The server did not return a valid response.' }; })
                        .then(function (d) {
                            if (!d || !d.success) {
                                lbDel.disabled = false;
                                setMeta(c.sid, (d && d.error) || 'Could not delete.', '#b91c1c');
                                alert((d && d.error) || 'Could not delete.');
                                return;
                            }
                            pdClose();
                            if (d.removed_only) {
                                renderPrev(c.sid, c.key, d.pair);
                                if (d.emptied) {
                                    var acts = document.getElementById(c.sid + '_acts');
                                    if (acts) acts.parentNode.removeChild(acts);
                                }
                                setMeta(c.sid, d.note, '#15803d');
                                markDirty();
                            } else {
                                applyResult(c.sid, c.key, d);
                            }
                        });
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && lb.style.display !== 'none') pdClose();
                });

                function setMeta(sid, text, colour) {
                    var el = document.getElementById(sid + '_meta');
                    if (el) { el.innerHTML = ''; el.textContent = text; el.style.color = colour || ''; }
                }

                // Shared by upload / AI-generate / library-pick — whichever source produced the
                // final image, the slot updates the same way: new thumbnail, view/download
                // links, and a result line. Keeping this in one place is what let the AI and
                // library paths ride the same result-handling as an upload, instead of each
                // reimplementing "how a slot looks once it's filled."
                // One thumbnail cell — mirrors the PHP $cell() renderer in the tab's
                // markup exactly, so a live update after a click looks identical to
                // what a page reload would have rendered.
                function pairCell(sid, key, path, labelText, isActive, which) {
                    if (!path) {
                        return '<div style="display:inline-block;width:63px;vertical-align:top;">'
                            + '<div style="width:63px;height:63px;border-radius:5px;background:#f8fafc;border:1px dashed #cbd5e1;"></div>'
                            + '<div style="font-size:.62rem;text-align:center;margin-top:2px;color:#cbd5e1;">no ' + labelText + '</div></div>';
                    }
                    var activeStyle = isActive ? 'border:2px solid #2563eb;' : 'border:2px solid transparent;opacity:.75;';
                    var img = '<img src="/' + path + '?t=' + Date.now() + '" data-full="/' + path + '" data-name="' + path.split('/').pop() + '" data-sid="' + sid + '" data-key="' + key + '" data-which="' + which + '" alt="" title="Click to view full size"'
                        + ' style="width:63px;height:63px;object-fit:cover;border-radius:5px;background:#f1f5f9;display:block;cursor:zoom-in;' + activeStyle + '"'
                        + ' onerror="this.style.display=\'none\'">';
                    var label = isActive
                        ? labelText + ' &#10003;'
                        : '<a href="#" class="pd-set-active" data-sid="' + sid + '" data-key="' + key + '" data-which="' + which + '" style="color:#6b7280;text-decoration:underline;">use ' + labelText + '</a>';
                    return '<div style="display:inline-block;width:63px;vertical-align:top;">' + img
                        + '<div style="font-size:.62rem;text-align:center;margin-top:2px;color:' + (isActive ? '#2563eb;font-weight:600;' : '#94a3b8;') + '">' + label + '</div></div>';
                }

                // Redraws the left-hand Real/AI spaces for a slot from a pair record.
                // Both spaces are always drawn — an empty one just shows its "no Real"/
                // "no AI" placeholder — so the two-space layout never appears or
                // disappears depending on how many sides happen to be filled.
                function renderPrev(sid, key, pair) {
                    pair = pair || { real: null, ai: null, active: 'real' };
                    var prev = document.getElementById(sid + '_prev');
                    prev.innerHTML =
                        pairCell(sid, key, pair.real, 'Real', pair.active === 'real' && !!pair.real, 'real') +
                        pairCell(sid, key, pair.ai, 'AI', pair.active === 'ai' && !!pair.ai, 'ai');
                    return true;
                }

                function applyResult(sid, key, d, extraMsg) {
                    if (!d || !d.success) return false;

                    renderPrev(sid, key, d.pair);

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
                        '<a href="/' + d.url + '" download="' + d.filename + '" style="color:#2563eb;text-decoration:none;">&#11015; Download</a>' +
                        '<a href="#" data-adjust="' + key + '" data-sid="' + sid + '" style="color:#2563eb;text-decoration:none;">&#9635; Adjust view</a>';

                    var msg = d.width + '×' + d.height + ' · ' + d.note + (extraMsg ? ' · ' + extraMsg : '');
                    if (d.propagated) msg += ' · also applied to ' + d.propagated + ' other page' + (d.propagated === 1 ? '' : 's');
                    if (d.templates)  msg += ' · ' + d.templates + ' landing template' + (d.templates === 1 ? '' : 's') + ' updated so a regen keeps it';
                    if (d.og_updated) msg += ' · social image followed on ' + d.og_updated;
                    if (d.screened)   msg += ' · ' + d.screened;
                    if (d.errors && d.errors.length) msg += ' · ' + d.errors.length + ' write error(s): ' + d.errors[0];
                    setMeta(sid, msg, d.errors && d.errors.length ? '#b45309' : '#15803d');
                    markDirty();
                    pdFlagAlt(sid);

                    // A slot that had no photo at page load renders its AI-generate panel
                    // with the reference checkbox + 7 mode radios hidden (see the PHP above) —
                    // reveal it now that a photo genuinely exists, so the very next Generate on
                    // this slot uses it as a reference instead of silently generating blind.
                    var refBlock = document.getElementById(sid + '_ai_ref');
                    if (refBlock) refBlock.style.display = '';

                    return true;
                }

                // Changing a photo never touches its alt text (the API writes the image
                // field only), so an alt written for the old photo silently stays. Every
                // photo change — upload, AI generate, library pick, Real/AI switch — lands
                // in applyResult(), so flagging here covers them all. Cleared on a
                // successful alt save; a page reload also clears it, deliberately — it
                // is a nudge at the moment of change, not a stored review state.
                function pdFlagAlt(sid) {
                    var input = document.getElementById(sid + '_alt');
                    var note  = document.getElementById(sid + '_altnote');
                    if (!input || !note) return;    // decorative background: no alt
                    input.style.borderColor = '#f59e0b';
                    input.style.background  = '#fffbeb';
                    note.style.display = '';
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
                            if (!applyResult(sid, zone.getAttribute('data-key'), d)) {
                                setMeta(sid, (d && d.error) || 'Upload failed.', '#b91c1c');
                            }
                        });
                }

                // ── AI generate ────────────────────────────────────────────────────
                document.addEventListener('click', function (e) {
                    var openLink = e.target.closest ? e.target.closest('a.pd-ai-open') : null;
                    if (!openLink) return;
                    e.preventDefault();
                    var sid = openLink.getAttribute('data-sid');
                    var panel = document.getElementById(sid + '_ai');
                    panel.style.display = panel.style.display === 'none' ? '' : 'none';
                });

                // ── Switch which side (real/AI) is live, no new file involved ───────
                document.addEventListener('click', function (e) {
                    var link = e.target.closest ? e.target.closest('a.pd-set-active') : null;
                    if (!link) return;
                    e.preventDefault();
                    var sid = link.getAttribute('data-sid');
                    var key = link.getAttribute('data-key');
                    var which = link.getAttribute('data-which');

                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'set_active');
                    fd.append('key', key);
                    fd.append('which', which);

                    setMeta(sid, 'Switching…', '#2563eb');
                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'The server did not return a valid response.' }; })
                        .then(function (d) {
                            if (!applyResult(sid, key, d)) {
                                setMeta(sid, (d && d.error) || 'Could not switch.', '#b91c1c');
                            }
                        });
                });

                // Swaps the prompt box to match whichever of the seven radio options is
                // selected — real radio inputs (one <input name> group), so the browser
                // itself guarantees exactly one is ever selected, "Add/ensure technician"
                // pre-checked. Always overwrites the textarea on selection (rather than
                // trying to detect a hand-edited prompt) since the box is a quick-start
                // draft reviewed before Generate is ever clicked, not committed text.
                // "Custom" is the one exception to "overwrite with canned text" — it has
                // none, so it clears the box instead (the placeholder attribute explains
                // what to type) rather than leaving another mode's irrelevant boilerplate
                // ("keep the same scene and equipment...") sitting there to be edited.
                window.pdSetPromptMode = function (sid, which) {
                    var box = document.getElementById(sid + '_ai_prompt');
                    if (which === 'custom') { box.value = ''; box.focus(); return; }
                    box.value = which === 'notech' ? box.dataset.promptNotech
                        : which === 'bare' ? box.dataset.promptBare
                        : which === 'newperson' ? box.dataset.promptNewperson
                        : which === 'vary' ? box.dataset.promptVary
                        : which === 'varybg' ? box.dataset.promptVarybg
                        : box.dataset.promptTech;
                };

                // Generating never touches the live page — it produces a candidate you
                // review here first. Nothing is "placed" until you click Use this photo,
                // so a bad result costs a re-roll, not a page that silently changed.
                window.pdGenerate = function (key, sid) {
                    var promptBox = document.getElementById(sid + '_ai_prompt');
                    var status    = document.getElementById(sid + '_ai_status');
                    var preview   = document.getElementById(sid + '_ai_preview');
                    var btn       = document.getElementById(sid + '_ai_btnrow').querySelector('button');
                    var prompt    = promptBox.value.trim();
                    if (!prompt) { status.textContent = 'Enter a prompt first.'; status.style.color = '#b91c1c'; return; }

                    // The mode radio group only renders at all when this slot has an
                    // existing photo to reference — its presence IS the signal that a
                    // reference is available, so there's no separate checkbox to read.
                    var modeInput = document.querySelector('input[name="' + sid + '_ai_mode"]:checked');
                    var mode      = modeInput ? modeInput.value : 'tech';

                    btn.disabled = true;
                    preview.style.display = 'none';
                    preview.innerHTML = '';
                    status.textContent = 'Generating… (usually 10-60s, real cost)';
                    status.style.color = '#2563eb';

                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'generate');
                    fd.append('key', key);
                    fd.append('prompt', prompt);
                    if (modeInput) fd.append('use_reference', '1');
                    // Tells the server which of the seven reference-edit instructions to
                    // append behind the scenes — the fixed "keep close to reference" suffix
                    // needs to say something different depending on which mode is selected,
                    // not the same thing every time.
                    fd.append('mode', mode);

                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'The server did not return a valid response.' }; })
                        .then(function (d) {
                            btn.disabled = false;
                            if (!d || !d.success) {
                                status.textContent = (d && d.error) || 'Generation failed.';
                                status.style.color = '#b91c1c';
                                return;
                            }
                            var costMsg = typeof d.cost === 'number' ? ('cost ~$' + d.cost.toFixed(3)) : '';
                            status.textContent = 'Here\'s what came back' + (costMsg ? ' (' + costMsg + ')' : '') + ' — use it, or tweak the prompt and try again.';
                            status.style.color = '#374151';

                            var img = document.createElement('img');
                            img.src = '/' + d.url + '?t=' + Date.now();
                            // Same data-full/data-name attributes every other thumbnail uses —
                            // the page's one delegated click listener (document.addEventListener
                            // on img[data-full]) opens the lightbox for any image carrying them,
                            // no separate handler needed here. No data-which, so the lightbox's
                            // Delete button stays hidden — this candidate isn't placed anywhere
                            // yet, "Discard" below is its undo.
                            img.setAttribute('data-full', '/' + d.url);
                            img.setAttribute('data-name', d.filename);
                            img.title = 'Click to view full size';
                            img.style.cssText = 'width:100%;max-height:220px;object-fit:cover;border-radius:6px;border:1px solid #d1d5db;display:block;cursor:zoom-in;';
                            var row = document.createElement('div');
                            row.style.cssText = 'display:flex;gap:8px;margin-top:6px;';
                            var useBtn = document.createElement('button');
                            useBtn.type = 'button'; useBtn.className = 'btn btn-primary';
                            useBtn.style.cssText = 'font-size:.72rem;padding:4px 12px;flex:1;';
                            useBtn.textContent = '✓ Use this photo';
                            useBtn.onclick = function () { pdUseGenerated(key, sid, d.filename); };
                            var discardBtn = document.createElement('button');
                            discardBtn.type = 'button'; discardBtn.className = 'btn btn-secondary';
                            discardBtn.style.cssText = 'font-size:.72rem;padding:4px 12px;';
                            discardBtn.textContent = 'Discard';
                            discardBtn.onclick = function () {
                                preview.style.display = 'none';
                                preview.innerHTML = '';
                                status.textContent = 'Discarded — edit the prompt and try again whenever you like.';
                                status.style.color = '#6b7280';
                            };
                            row.appendChild(useBtn); row.appendChild(discardBtn);
                            preview.appendChild(img); preview.appendChild(row);
                            preview.style.display = '';
                        });
                };

                // The candidate is already a real file in the media library (generate()
                // saved it there) — using it is exactly the same call as picking one from
                // the library, just skipping the picker grid.
                window.pdUseGenerated = function (key, sid, filename) {
                    var status  = document.getElementById(sid + '_ai_status');
                    var preview = document.getElementById(sid + '_ai_preview');
                    var slot    = document.getElementById(sid + '_ai').closest('.pd-slot');
                    var prop    = slot ? slot.querySelector('.pd-prop') : null;

                    status.textContent = 'Placing…';
                    status.style.color = '#2563eb';

                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'place_media');
                    fd.append('key', key);
                    fd.append('media', filename);
                    fd.append('as', 'ai');
                    if (prop && prop.checked) fd.append('propagate', '1');

                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'The server did not return a valid response.' }; })
                        .then(function (d) {
                            if (!applyResult(sid, key, d)) {
                                status.textContent = (d && d.error) || 'Could not place that image.';
                                status.style.color = '#b91c1c';
                                return;
                            }
                            status.textContent = 'Placed — see the picture on the left.';
                            status.style.color = '#15803d';
                            preview.style.display = 'none';
                            preview.innerHTML = '';
                        });
                };

                // ── Pick from the existing media library ────────────────────────────
                var libModal = document.getElementById('pd-lib');
                var libGrid  = document.getElementById('pd-lib-grid');
                var libKey = null, libSid = null;

                document.addEventListener('click', function (e) {
                    var openLink = e.target.closest ? e.target.closest('a.pd-lib-open') : null;
                    if (!openLink) return;
                    e.preventDefault();
                    libKey = openLink.getAttribute('data-key');
                    libSid = openLink.getAttribute('data-sid');
                    libGrid.innerHTML = '<p class="hint">Loading…</p>';
                    libModal.style.display = 'flex';
                    fetch('media_api.php?action=list')
                        .then(function (r) { return r.json(); })
                        .catch(function () { return []; })
                        .then(function (items) {
                            if (!Array.isArray(items) || !items.length) {
                                libGrid.innerHTML = '<p class="hint">No images in the media library yet.</p>';
                                return;
                            }
                            libGrid.innerHTML = '';
                            items.forEach(function (it) {
                                var cell = document.createElement('div');
                                cell.style.cssText = 'cursor:pointer;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;';
                                cell.innerHTML = '<img src="/' + it.url + '" style="width:100%;height:90px;object-fit:cover;display:block;">'
                                    + '<div style="font-size:.68rem;color:#6b7280;padding:3px 5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + it.filename + '</div>';
                                cell.addEventListener('click', function () { pdPlaceMedia(it.filename); });
                                libGrid.appendChild(cell);
                            });
                        });
                });

                function pdPlaceMedia(filename) {
                    var key = libKey, sid = libSid;
                    libModal.style.display = 'none';
                    setMeta(sid, 'Placing…', '#2563eb');

                    var slot = document.getElementById(sid + '_meta').closest('.pd-slot');
                    var prop = slot ? slot.querySelector('.pd-prop') : null;

                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'place_media');
                    fd.append('key', key);
                    fd.append('media', filename);
                    fd.append('as', 'real');
                    if (prop && prop.checked) fd.append('propagate', '1');

                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'The server did not return a valid response.' }; })
                        .then(function (d) {
                            if (!applyResult(sid, key, d)) {
                                setMeta(sid, (d && d.error) || 'Could not place that image.', '#b91c1c');
                            }
                        });
                }

                document.getElementById('pd-lib-close').addEventListener('click', function () { libModal.style.display = 'none'; });
                libModal.addEventListener('click', function (e) { if (e.target === libModal) libModal.style.display = 'none'; });

                // ── Adjust view: zoom + pan ───────────────────────────────────────
                // The rectangle worked out here is the SAME one img_source_rect() cuts
                // on the server, from the same zoom/fx/fy. Keep the two in step or the
                // preview stops predicting the result.
                var adj = {
                    key: null, sid: null, zoom: 1, zoomMin: 1, fill: 'white', fx: 0.5, fy: 0.5,
                    ow: 0, oh: 0, tw: 0, th: 0, origTw: 0, origTh: 0, frameW: 0, frameH: 0, drag: null
                };
                var adjBox   = document.getElementById('pd-adj');
                var adjFrame = document.getElementById('pd-adj-frame');
                var adjImg   = document.getElementById('pd-adj-img');
                var adjZoom  = document.getElementById('pd-adj-zoom');
                var adjZval  = document.getElementById('pd-adj-zval');
                var adjNote  = document.getElementById('pd-adj-note');
                var adjRatioSel    = document.getElementById('pd-adj-ratio');
                var adjRatioCustom = document.getElementById('pd-adj-ratio-custom');
                var adjRatioW      = document.getElementById('pd-adj-ratio-w');
                var adjRatioH      = document.getElementById('pd-adj-ratio-h');
                var adjSub   = document.getElementById('pd-adj-sub');

                // Mirrors img_place_geometry() exactly. One formula covers both cases:
                // the offset goes negative when the picture overflows the slot (pan)
                // and positive when it sits inside it (pad).
                function adjGeom() {
                    var scale = Math.max(adj.tw / adj.ow, adj.th / adj.oh) * adj.zoom;
                    var nw = Math.max(1, Math.round(adj.ow * scale));
                    var nh = Math.max(1, Math.round(adj.oh * scale));
                    return {
                        nw: nw, nh: nh,
                        px: Math.round(adj.fx * (adj.tw - nw)),
                        py: Math.round(adj.fy * (adj.th - nh))
                    };
                }

                // How much of the SOURCE photo (in its own pixel units) is currently
                // visible through the slot at this zoom — same scale as adjGeom(), just
                // solved for source pixels instead of slot pixels. The drag handler below
                // needs this to convert an on-screen mouse delta into a fraction of the
                // pannable range.
                function adjRect() {
                    var scale = Math.max(adj.tw / adj.ow, adj.th / adj.oh) * adj.zoom;
                    return { sw: adj.tw / scale, sh: adj.th / scale };
                }

                function adjPaint() {
                    var g = adjGeom();
                    var k = adj.frameW / adj.tw;        // frame pixels per slot pixel
                    adjImg.style.width  = (g.nw * k) + 'px';
                    adjImg.style.height = (g.nh * k) + 'px';
                    adjImg.style.left   = (g.px * k) + 'px';
                    adjImg.style.top    = (g.py * k) + 'px';
                    adjFrame.style.background =
                        adj.fill === 'black' ? '#000' :
                        adj.fill === 'none'  ? 'repeating-conic-gradient(#e5e7eb 0 25%, #fff 0 50%) 0 0/16px 16px' : '#fff';
                    adjZval.textContent = adj.zoom.toFixed(2) + '×';
                    adjZoom.value = Math.round(adj.zoom * 100);
                    // Only meaningful once part of the slot is empty.
                    document.getElementById('pd-adj-fillwrap').style.display =
                        (g.nw < adj.tw || g.nh < adj.th) ? '' : 'none';
                }

                // Mirrors img_zoom_min() (includes/media_lib.php) exactly — needed here so
                // picking a Shape can recompute how far the zoom slider is allowed to go
                // without a server round-trip.
                function adjZoomMinJs(ow, oh, tw, th) {
                    if (ow < 1 || oh < 1 || tw < 1 || th < 1) return 1;
                    var cover = Math.max(tw / ow, th / oh);
                    var contain = Math.min(tw / ow, th / oh);
                    return cover > 0 ? contain / cover : 1;
                }

                // Switches the preview to a NEW target box — used both to apply a chosen
                // Shape and to restore "Current shape" (the slot's own normal target).
                // Always re-centers and zooms out to show the whole picture in the new
                // shape, same as clicking "Fit whole picture" — picking a shape IS asking
                // to see the whole thing in it, not a half-cropped guess.
                function adjSetTarget(tw, th) {
                    adj.tw = tw; adj.th = th;
                    adj.zoomMin = adjZoomMinJs(adj.ow, adj.oh, tw, th);
                    adjZoom.min = Math.round(adj.zoomMin * 100);
                    adj.zoom = adj.zoomMin; adj.fx = 0.5; adj.fy = 0.5;
                    document.getElementById('pd-adj-fit').style.display = adj.zoomMin < 0.999 ? '' : 'none';

                    var maxW = Math.min(660, window.innerWidth - 90);
                    var maxH = window.innerHeight - 320;
                    adj.frameW = maxW;
                    adj.frameH = Math.round(maxW * th / tw);
                    if (adj.frameH > maxH) { adj.frameH = maxH; adj.frameW = Math.round(maxH * tw / th); }
                    adjFrame.style.width  = adj.frameW + 'px';
                    adjFrame.style.height = adj.frameH + 'px';

                    adjSub.textContent = 'Shape ' + tw + '×' + th + ' · source ' + adj.ow + '×' + adj.oh;
                    adjPaint();
                }

                function adjRatioParts() {
                    var v = adjRatioSel.value;
                    if (v === '') return null;                          // current shape — no override
                    if (v === 'custom') {
                        var rw = Math.max(1, parseInt(adjRatioW.value, 10) || 4);
                        var rh = Math.max(1, parseInt(adjRatioH.value, 10) || 3);
                        return [rw, rh];
                    }
                    var parts = v.split(':');
                    return [parseInt(parts[0], 10), parseInt(parts[1], 10)];
                }

                function adjMatchRatioOption(tw, th) {
                    if (!tw || !th) return '';
                    var ratio = tw / th;
                    var best = '', bestDiff = 0.01; // 1% tolerance for rounding
                    for (var i = 0; i < adjRatioSel.options.length; i++) {
                        var v = adjRatioSel.options[i].value;
                        if (v === '' || v === 'custom') continue;
                        var parts = v.split(':');
                        var optRatio = parseInt(parts[0], 10) / parseInt(parts[1], 10);
                        var diff = Math.abs(ratio - optRatio) / optRatio;
                        if (diff < bestDiff) { bestDiff = diff; best = v; }
                    }
                    return best;
                }

                function adjApplyRatioChoice() {
                    var parts = adjRatioParts();
                    adjRatioCustom.style.display = adjRatioSel.value === 'custom' ? 'inline-flex' : 'none';
                    if (!adj.ow) return;    // picked before the photo finished loading
                    if (!parts) { adjSetTarget(adj.origTw, adj.origTh); return; }
                    // Width capped the same way the media library caps any saved image
                    // (MAX_WIDTH, includes/media_lib.php) — picking a shape should never
                    // produce a needlessly huge file.
                    var tw = Math.min(adj.ow, 1800);
                    var th = Math.max(1, Math.round(tw * parts[1] / parts[0]));
                    adjSetTarget(tw, th);
                }
                adjRatioSel.addEventListener('change', adjApplyRatioChoice);
                adjRatioW.addEventListener('input', function () { if (adjRatioSel.value === 'custom') adjApplyRatioChoice(); });
                adjRatioH.addEventListener('input', function () { if (adjRatioSel.value === 'custom') adjApplyRatioChoice(); });

                function adjOpen(key, sid) {
                    adj.key = key; adj.sid = sid;
                    adjNote.textContent = 'Loading…';
                    adjNote.style.color = '';
                    adjBox.style.display = 'flex';
                    // A Shape choice from the last photo opened must never carry over —
                    // reset here, then re-select below once this slot's real shape is
                    // known, so a slot that already matches a listed ratio shows it.
                    adjRatioSel.value = '';
                    adjRatioCustom.style.display = 'none';

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
                            adj.origTw = d.slot_w; adj.origTh = d.slot_h;
                            adjRatioSel.value = adjMatchRatioOption(d.slot_w, d.slot_h);
                            adj.zoom = d.zoom || 1; adj.fx = d.fx; adj.fy = d.fy;
                            adj.zoomMin = d.zoom_min || 1;
                            adj.fill = d.fill || 'white';
                            document.getElementById('pd-adj-fill').value = adj.fill;
                            // Floor the slider at 'whole picture visible' rather than 1.
                            adjZoom.min = Math.round(adj.zoomMin * 100);
                            document.getElementById('pd-adj-fit').style.display = adj.zoomMin < 0.999 ? '' : 'none';

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
                                + (adj.zoomMin < 0.999
                                    ? ' · zoom out to ' + adj.zoomMin.toFixed(2) + '× to show the whole picture'
                                    : ' · already the slot\u2019s shape, nothing more to reveal');
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
                    adj.zoom = Math.max(adj.zoomMin, Math.min(4, adj.zoom * (e.deltaY < 0 ? 1.08 : 1 / 1.08)));
                    adjPaint();
                }, { passive: false });
                adjZoom.addEventListener('input', function () {
                    // Same guard as the pointerdown/wheel handlers — without it, a drag
                    // on the still-default slider before the "info" fetch resolves divides
                    // by adj.ow/oh while they're still 0 (adjGeom()'s scale math), painting
                    // NaN/Infinity sizes until the image load repaints over it.
                    if (!adj.ow) return;
                    adj.zoom = Math.max(adj.zoomMin, Math.min(4, this.value / 100));
                    adjPaint();
                });
                document.getElementById('pd-adj-reset').addEventListener('click', function () {
                    adj.zoom = 1; adj.fx = 0.5; adj.fy = 0.5; adjPaint();   // back to cover
                });
                document.getElementById('pd-adj-fit').addEventListener('click', function () {
                    adj.zoom = adj.zoomMin; adj.fx = 0.5; adj.fy = 0.5; adjPaint();
                });
                document.getElementById('pd-adj-fill').addEventListener('change', function () {
                    adj.fill = this.value; adjPaint();
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
                    fd.append('fill', adj.fill);
                    // Only sent when a Shape other than "Current shape" is active — the
                    // server falls back to its own normal target when these are absent,
                    // so picking nothing behaves exactly as it always has.
                    if (adj.tw !== adj.origTw || adj.th !== adj.origTh) {
                        fd.append('target_w', adj.tw);
                        fd.append('target_h', adj.th);
                    }

                    var sid = adj.sid;
                    // Same "also replace elsewhere" checkbox every other write action
                    // reads off the slot's own row — a crop used to never propagate,
                    // so a photo shared across pages only ever got cropped on the one
                    // page you happened to open Adjust view from.
                    var metaEl  = document.getElementById(sid + '_meta');
                    var slotRoot = metaEl ? metaEl.closest('.pd-slot') : null;
                    var prop = slotRoot ? slotRoot.querySelector('.pd-prop') : null;
                    if (prop && prop.checked) fd.append('propagate', '1');

                    fetch('picdrop_api.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { error: 'The server did not return a valid response.' }; })
                        .then(function (d) {
                            btn.disabled = false;
                            // Shared by every other action (upload/generate/set_active) —
                            // redraws the Real/AI pair from what the server actually
                            // saved, and now also reports propagate/og-follow counts, none
                            // of which a one-off single-image redraw here used to show.
                            if (!applyResult(sid, adj.key, d)) {
                                adjNote.textContent = (d && d.error) || 'Could not apply.';
                                adjNote.style.color = '#b91c1c';
                                return;
                            }
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
                            if (d && d.success) {
                                markDirty();
                                input.style.background = '';
                                var note = document.getElementById(input.getAttribute('data-sid') + '_altnote');
                                if (note) note.style.display = 'none';
                            }
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
