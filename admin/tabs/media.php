    <div class="tab-content" style="<?= $tab === 'media' ? '' : 'display:none;' ?>">
        <div class="card">
            <h2>Media Library</h2>
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
    </div>
