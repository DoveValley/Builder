    <div class="tab-content" style="<?= $tab === 'footer' ? '' : 'display:none;' ?>">
        <form action="save.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="section" value="footer">
            <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Footer</button></div>

            <div class="card">
                <h2>Footer Logo &amp; Phone</h2>
                <div class="form-group">
                    <div class="current-image">
                        <?php if (!empty($footer['logo'])): ?>
                            <img src="../<?= h($footer['logo']) ?>" alt="Footer logo">
                        <?php else: ?>
                            <span class="none">No logo uploaded yet.</span>
                        <?php endif; ?>
                    </div>
                    <label>Logo</label>
                    <input type="file" name="footer_logo" accept="image/png,image/jpeg,image/gif,image/webp">
                    <?php if (!empty($footer['logo'])): ?>
                        <label style="margin-top:8px;font-weight:400;">
                            <input type="checkbox" name="remove_footer_logo" value="1"> Remove logo
                        </label>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="logo_in_copyright_bar" value="1"
                               <?= !empty($footer['logo_in_copyright_bar']) ? 'checked' : '' ?>>
                        Also show logo in copyright bar
                    </label>
                </div>
                <div class="form-group">
                    <label>Phone number (used in Contact column + sticky bar)</label>
                    <input type="tel" name="footer_phone" value="<?= h($footer['phone'] ?? '') ?>" placeholder="+1 (555) 123-4567">
                </div>
            </div>

            <div class="card">
                <h2>Social Media Links</h2>
                <p class="hint" style="margin-bottom:18px;">Leave blank to hide. Links appear in the footer bottom bar.</p>
                <?php
                $socials = $footer['socials'] ?? [];
                $socialFields = ['facebook'=>'Facebook','instagram'=>'Instagram','linkedin'=>'LinkedIn','youtube'=>'YouTube','twitter'=>'X / Twitter'];
                ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <?php foreach ($socialFields as $key => $label): ?>
                    <div class="form-group">
                        <label><?= $label ?></label>
                        <input type="url" name="social_<?= $key ?>" value="<?= h($socials[$key] ?? '') ?>" placeholder="https://<?= $key ?>.com/yourpage">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2>Footer Columns</h2>
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:18px;">
                    <div class="form-group" style="margin:0;flex:0 0 auto;">
                        <label>Number of columns</label>
                        <select name="footer_col_count" style="width:auto;padding:8px 12px;">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($footer['col_count'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="hint" style="margin:0;">Column type: <strong>Text</strong> = heading + paragraph &nbsp;|&nbsp; <strong>Links</strong> = heading + link list &nbsp;|&nbsp; <strong>Contact</strong> = phone + city + optional extras.</p>
                </div>

                <div id="footer-columns">
                    <?php foreach ($footer['columns'] as $ci => $column):
                        $colType = $column['type'] ?? 'links';
                    ?>
                        <div class="column-card" data-col-index="<?= (int) $ci ?>" data-next-link-index="<?= $columnNextLinkIndex[$ci] ?? 0 ?>">
                            <div class="column-card-header" style="gap:8px;">
                                <input type="text" name="footer_columns[<?= (int) $ci ?>][title]"
                                       value="<?= h($column['title'] ?? '') ?>"
                                       placeholder="Column heading (e.g. Quick Links)">
                                <select name="footer_columns[<?= (int) $ci ?>][type]"
                                        onchange="switchColType(this)"
                                        style="flex:0 0 140px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:0.88rem;">
                                    <option value="links"   <?= $colType === 'links'   ? 'selected' : '' ?>>Links column</option>
                                    <option value="text"    <?= $colType === 'text'    ? 'selected' : '' ?>>Text column</option>
                                    <option value="contact" <?= $colType === 'contact' ? 'selected' : '' ?>>Contact column</option>
                                </select>
                                <button type="button" class="icon-btn remove-row" onclick="removeColumn(this)">Remove</button>
                            </div>

                            <!-- LINKS type -->
                            <div class="col-type-panel col-type-links <?= $colType !== 'links' ? 'is-hidden' : '' ?>">
                                <div class="column-links">
                                    <?php foreach (($column['links'] ?? []) as $li => $link): ?>
                                        <div class="repeat-row">
                                            <input type="text" name="footer_columns[<?= (int) $ci ?>][links][<?= (int) $li ?>][label]" value="<?= h($link['label'] ?? '') ?>" placeholder="Link text">
                                            <input type="text" name="footer_columns[<?= (int) $ci ?>][links][<?= (int) $li ?>][url]"   value="<?= h($link['url']   ?? '') ?>" placeholder="URL (e.g. /about)">
                                            <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-secondary btn-small" onclick="addLink(this)">+ Add link</button>
                            </div>

                            <!-- TEXT type -->
                            <div class="col-type-panel col-type-text <?= $colType !== 'text' ? 'is-hidden' : '' ?>">
                                <div class="form-group" style="margin-top:10px;">
                                    <textarea name="footer_columns[<?= (int) $ci ?>][text]" rows="5" class="rich-editor"
                                              placeholder="About text, description..."><?= h($column['text'] ?? '') ?></textarea>
                                    <span class="hint">Paragraph text shown in this column. Leave a blank line between paragraphs.</span>
                                </div>
                            </div>

                            <!-- CONTACT type -->
                            <div class="col-type-panel col-type-contact <?= $colType !== 'contact' ? 'is-hidden' : '' ?>">
                                <p class="hint" style="margin:8px 0;">Phone and city are pulled from the footer Phone / Header City fields automatically. Add extra items below.</p>
                                <div class="column-links">
                                    <?php foreach (($column['contact_extras'] ?? []) as $li => $extra): ?>
                                        <div class="repeat-row">
                                            <input type="text" name="footer_columns[<?= (int) $ci ?>][contact_extras][<?= $li ?>][icon]"  value="<?= h($extra['icon']  ?? '') ?>" placeholder="Icon/emoji" style="flex:0 0 70px;">
                                            <input type="text" name="footer_columns[<?= (int) $ci ?>][contact_extras][<?= $li ?>][label]" value="<?= h($extra['label'] ?? '') ?>" placeholder="Label text">
                                            <input type="text" name="footer_columns[<?= (int) $ci ?>][contact_extras][<?= $li ?>][url]"   value="<?= h($extra['url']   ?? '') ?>" placeholder="Link (optional)">
                                            <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-secondary btn-small" onclick="addContactExtra(this)">+ Add item</button>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn btn-secondary btn-small" onclick="addColumn()">+ Add column</button>
            </div>

            <div class="card">
                <h2>Disclaimer Text</h2>
                <div class="form-group">
                    <textarea name="disclaimer" rows="4" class="rich-editor" placeholder="Legal disclaimer text — leave blank to hide."><?= h($footer['disclaimer'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card">
                <h2>Sticky Bottom Bar</h2>
                <div class="form-group">
                    <label>Bar text</label>
                    <input type="text" name="sticky_bar_text"
                           value="<?= h($footer['sticky_bar_text'] ?? '24/7 Support Line - Call Now') ?>"
                           placeholder="e.g. 24/7 Support Line - Call Now">
                </div>
                <div class="form-group">
                    <label>Info tooltip (optional — shown on ℹ️ icon)</label>
                    <input type="text" name="sticky_bar_info"
                           value="<?= h($footer['sticky_bar_info'] ?? '') ?>"
                           placeholder="e.g. Calls answered by advertising partners">
                </div>
            </div>

            <div class="card">
                <h2>Bottom Bar</h2>

                <div class="form-group">
                    <label for="copyright">Copyright text</label>
                    <input type="text" id="copyright" name="copyright" value="<?= h($footer['copyright'] ?? '') ?>">
                    <span class="hint">Shown on the left of the bottom bar, e.g. "© 2026 My Company. All rights reserved."</span>
                </div>

                <div class="form-group">
                    <label>Bottom links</label>
                    <span class="hint" style="margin-bottom:8px;">Shown on the right of the bottom bar, e.g. Privacy Policy | Terms of Service | Sitemap.</span>
                    <div class="repeat-items" id="bottom-links" style="margin-top:10px;">
                        <?php
                        $bottomLinks = $footer['bottom_links'] ?: [['label' => '', 'url' => '']];
                        foreach ($bottomLinks as $link):
                        ?>
                            <div class="repeat-row">
                                <input type="text" name="bottom_link_label[]" placeholder="Label (e.g. Privacy Policy)" value="<?= h($link['label'] ?? '') ?>">
                                <input type="text" name="bottom_link_url[]" placeholder="URL" value="<?= h($link['url'] ?? '') ?>">
                                <button type="button" class="remove-row" onclick="removeRow(this, 'bottom-links')">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addBottomLinkRow()">+ Add bottom link</button>
                </div>
            </div>

            <button type="submit" class="btn">Save Footer</button>
        </form>
    </div>
