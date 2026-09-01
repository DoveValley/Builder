    <div class="tab-content" style="<?= $tab === 'media' ? '' : 'display:none;' ?>">
        <?php tab_header('Media Library', 'All uploaded images for this site. Check here before uploading — a relevant image may already exist. Use the Library button in any image field to pick from here.', 'tab-media'); ?>
        <div class="card">
            <p class="hint" style="margin-bottom:16px;">All images available to use in your blocks. Drag &amp; drop or click to upload. Click an image to copy its URL.</p>

            <div id="media-dropzone" style="border:2px dashed #d1d5db;border-radius:8px;padding:28px;text-align:center;cursor:pointer;margin-bottom:20px;transition:border-color .2s,background .2s;">
                <input id="media-file-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="display:none;">
                <div style="font-size:2rem;margin-bottom:8px;">📁</div>
                <div style="font-weight:600;color:#374151;">Drop images here or click to upload</div>
                <div class="hint" style="margin-top:4px;">JPG, PNG, GIF, WebP — auto-optimized to WebP on save</div>
            </div>

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                <input id="media-search" type="text" placeholder="Search by filename or alt text…" style="flex:1;min-width:180px;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <span id="media-count" style="font-size:.85rem;color:#6b7280;white-space:nowrap;"></span>
                <button id="dupe-btn" class="btn btn-secondary btn-small" onclick="findDuplicates()" style="white-space:nowrap;">Find Duplicates</button>
            </div>

            <div id="dupe-panel" style="display:none;"></div>

            <?php
            $varFile  = BASE_DIR . '/data/variation.json';
            $varData  = file_exists($varFile) ? (json_decode(file_get_contents($varFile), true) ?? []) : [];
            $varSeed  = (int) ($varData['seed']       ?? 0);
            $varDate  = $varData['applied_at']  ?? '';
            $varCount = (int) ($varData['count'] ?? 0);
            ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:.85rem;font-weight:600;color:#374151;">Site Variation Seed</span>
                    <span style="font-size:.78rem;color:#6b7280;">Makes every image unique per city deployment</span>
                    <input id="var-seed" type="number" min="1" max="9999" placeholder="1–9999"
                        value="<?= $varSeed ?: '' ?>"
                        style="width:90px;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:.85rem;">
                    <button id="var-apply-btn" class="btn btn-small" onclick="applyVariation()" style="white-space:nowrap;">Apply to All Images</button>
                    <?php if ($varSeed): ?>
                    <span style="font-size:.75rem;color:#6b7280;">
                        Seed <strong><?= $varSeed ?></strong> applied <?= h($varDate) ?> &mdash; <?= $varCount ?> images varied
                    </span>
                    <?php endif; ?>
                </div>
                <div id="var-result" style="margin-top:6px;font-size:.8rem;min-height:1.2em;"></div>
            </div>

            <div id="media-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;"></div>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Image Name Cleanup</h2>
            <div class="hint" style="margin-bottom:14px;background:#f0f9ff;border-left:3px solid #2563eb;padding:10px 14px;line-height:1.55;">
                <strong>What this does:</strong> looks at every image actually used on this site and checks two things — is the filename honest, descriptive, and good for SEO (not a raw stock-photo ID or a computer-generated mess), and does the photo genuinely match the page it's on. It also finds images referenced nowhere at all, so they can be removed.<br><br>
                <strong>Costs real money to run</strong> — each image is sent to Claude for a real look, roughly a cent or two per image (a site with 150 images is on the order of $1-2, not free). Orphan detection itself is free; only the per-photo review costs anything.<br><br>
                <strong>Nothing changes automatically.</strong> A scan only proposes — every rename and every deletion is a checkbox you tick yourself, then click Apply. Nothing on the live site is touched until you approve it.
            </div>

            <button id="imgclean-scan-btn" class="btn" onclick="imgcleanScan()">Scan for Cleanup Opportunities</button>
            <span id="imgclean-status" style="font-size:.85rem;color:#6b7280;margin-left:10px;"></span>

            <div id="imgclean-progress-wrap" style="display:none;margin-top:10px;max-width:420px;">
                <div style="height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
                    <div id="imgclean-progress-bar" style="height:100%;width:0%;background:var(--color-accent,#fd783b);border-radius:4px;transition:width .3s ease;"></div>
                </div>
                <div id="imgclean-progress-label" style="font-size:.78rem;color:#6b7280;margin-top:4px;"></div>
            </div>

            <div id="imgclean-results" style="display:none;margin-top:18px;"></div>
        </div>
    </div>
