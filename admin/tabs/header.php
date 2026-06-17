    <div class="tab-content" style="<?= $tab === 'header' ? '' : 'display:none;' ?>">
        <form action="save.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="section" value="header">
            <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Header</button></div>

            <div class="card">
                <h2>Site Variables</h2>
                <p class="hint" style="margin-bottom:16px;">
                    Use these tokens anywhere in your content or SEO fields:
                    <code>{city}</code> <code>{state}</code> <code>{SS}</code> <code>{city_state}</code>
                    <code>{business}</code> <code>{phone}</code> <code>{zip}</code>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group" style="margin:0;">
                        <label>City</label>
                        <input type="text" name="site_vars_city" value="<?= h($siteVars['city'] ?? '') ?>" placeholder="e.g. Katy">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Full state <code>{state}</code></label>
                        <input type="text" name="site_vars_state" value="<?= h($siteVars['state'] ?? '') ?>" placeholder="e.g. Texas">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>State abbreviation <code>{SS}</code></label>
                        <input type="text" name="site_vars_SS" value="<?= h($siteVars['SS'] ?? '') ?>" placeholder="e.g. TX" maxlength="5">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>City slug <code>{city_slug}</code></label>
                        <input type="text" name="site_vars_city_slug" value="<?= h($siteVars['city_slug'] ?? '') ?>" placeholder="e.g. katy-tx">
                        <span class="hint">Used in page URLs. Lowercase, hyphenated.</span>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Zip code <code>{zip}</code></label>
                        <input type="text" name="site_vars_zip" value="<?= h($siteVars['zip'] ?? '') ?>" placeholder="e.g. 77449" maxlength="10">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Business name <code>{business}</code></label>
                        <input type="text" name="site_vars_business" value="<?= h($siteVars['business'] ?? '') ?>" placeholder="e.g. Katy Pest Pros">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Phone <code>{phone}</code></label>
                        <input type="tel" name="site_vars_phone" value="<?= h($siteVars['phone'] ?? '') ?>" placeholder="e.g. (281) 555-1234">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Tracking / dial number <code>{tel}</code></label>
                        <input type="text" name="site_vars_tel" value="<?= h($siteVars['tel'] ?? '') ?>" placeholder="e.g. +12812150160">
                        <span class="hint">E.164 format used in <code>tel:</code> links. Can differ from the display phone.</span>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Website <code>{website}</code></label>
                        <input type="text" name="site_vars_website" value="<?= h($siteVars['website'] ?? '') ?>" placeholder="e.g. katypestpros.com">
                    </div>
                </div>
                <p class="hint" style="margin-top:12px;">
                    <strong>{city_state}</strong> is auto-built from City + State abbreviation (e.g. "Katy, TX").
                </p>
            </div>

            <div class="card">
                <h2>Logo (top left)</h2>

                <div class="form-group">
                    <div class="current-image">
                        <?php if (!empty($header['logo'])): ?>
                            <img src="../<?= h($header['logo']) ?>" alt="Current logo">
                        <?php else: ?>
                            <span class="none">No logo uploaded yet.</span>
                        <?php endif; ?>
                    </div>

                    <label for="logo">Upload new logo</label>
                    <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
                    <span class="hint">Recommended: a transparent PNG, around 200px wide.</span>

                    <?php if (!empty($header['logo'])): ?>
                        <label style="margin-top:10px; font-weight:400;">
                            <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                        </label>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="logo_max_height">Logo height: <strong id="logo_height_val"><?= h($header['logo_max_height'] ?? '56') ?>px</strong></label>
                    <input type="range" id="logo_max_height" name="logo_max_height"
                           min="32" max="120" step="4"
                           value="<?= h($header['logo_max_height'] ?? '56') ?>"
                           oninput="document.getElementById('logo_height_val').textContent = this.value + 'px'"
                           style="width:100%;accent-color:var(--color-accent, #2563eb);">
                    <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#888;margin-top:2px;">
                        <span>32px (small)</span><span>76px (medium)</span><span>120px (large)</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Menu Items</h2>
                <p class="hint" style="margin-bottom:14px;">Add top-level items. Each item can optionally have a dropdown sub-menu.</p>
                <div id="menu-items">
                    <?php
                    $menu = $header['menu'] ?: [['label'=>'','url'=>'','children'=>[]]];
                    foreach ($menu as $mi => $item):
                        $children = $item['children'] ?? [];
                    ?>
                    <div class="menu-item-card" data-menu-index="<?= $mi ?>">
                        <div class="menu-item-top repeat-row">
                            <input type="text" name="menu_label[]" placeholder="Label (e.g. Home)" value="<?= h($item['label'] ?? '') ?>">
                            <input type="text" name="menu_url[]" placeholder="Link (e.g. / or #about)" value="<?= h($item['url'] ?? '') ?>">
                            <button type="button" class="btn btn-secondary btn-small" onclick="toggleDropdown(this)" style="white-space:nowrap;">
                                + Sub-menu (<?= count($children) ?>)
                            </button>
                            <button type="button" class="remove-row" onclick="removeMenuItem(this)">&times;</button>
                        </div>
                        <div class="menu-dropdown-editor <?= empty($children) ? 'is-hidden' : '' ?>">
                            <p class="hint" style="margin:6px 0 8px 0;">Sub-menu links — shown in a dropdown under this item.</p>
                            <div class="dropdown-links">
                                <?php foreach ($children as $ci => $child): ?>
                                <div class="repeat-row dropdown-link-row">
                                    <input type="text" name="menu_child_label[<?= $mi ?>][]" placeholder="Sub-link label" value="<?= h($child['label'] ?? '') ?>">
                                    <input type="text" name="menu_child_url[<?= $mi ?>][]" placeholder="Sub-link URL" value="<?= h($child['url'] ?? '') ?>">
                                    <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-secondary btn-small" onclick="addDropdownLink(this, <?= $mi ?>)" style="margin-top:6px;">+ Add sub-link</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary btn-small" style="margin-top:10px;" onclick="addMenuRow()">+ Add menu item</button>
            </div>

            <div class="card">
                <h2>Top Announcement Bar (optional)</h2>
                <p class="hint" style="margin-bottom:14px;">A slim bar above the header — great for "24/7 Support Line - Call Now (555) 123-4567". Leave blank to hide.</p>
                <div class="form-group">
                    <label for="topbar_text">Bar text</label>
                    <input type="text" id="topbar_text" name="topbar_text"
                           value="<?= h($header['topbar_text'] ?? '') ?>"
                           placeholder="e.g. 24/7 Support Line - Call Now (281) 215-0160">
                </div>
                <div class="form-group">
                    <label for="topbar_link">Bar link (optional)</label>
                    <input type="text" id="topbar_link" name="topbar_link"
                           value="<?= h($header['topbar_link'] ?? '') ?>"
                           placeholder="e.g. tel:+12812150160">
                    <span class="hint">If filled in, the whole bar becomes a clickable link.</span>
                </div>
            </div>

            <div class="card">
                <h2>Phone Number &amp; Location</h2>
                <div class="form-group">
                    <label for="phone">Phone number</label>
                    <input type="tel" id="phone" name="phone" value="<?= h($header['phone'] ?? '') ?>" placeholder="+1 (555) 123-4567">
                </div>
                <div class="form-group">
                    <label for="city">City / Location</label>
                    <input type="text" id="city" name="city" value="<?= h($header['city'] ?? '') ?>" placeholder="e.g. Katy, TX">
                    <span class="hint">Shown with a globe icon in the header info row.</span>
                </div>
            </div>

            <div class="card">
                <h2>Nav Bar Style</h2>
                <p class="hint" style="margin-bottom:14px;">The colored bar that contains the menu and phone button.</p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;">
                        <label for="nav_bg">Nav bar background color</label>
                        <input type="color" id="nav_bg" name="nav_bg" value="<?= h($header['nav_bg'] ?? '#fd783b') ?>">
                    </div>
                    <div class="form-group" style="flex:1 1 160px;">
                        <label for="nav_text">Nav bar text color</label>
                        <input type="color" id="nav_text" name="nav_text" value="<?= h($header['nav_text'] ?? '#ffffff') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="phone_btn_style">Phone button style</label>
                    <select id="phone_btn_style" name="phone_btn_style">
                        <option value="outline" <?= ($header['phone_btn_style'] ?? 'outline') === 'outline' ? 'selected' : '' ?>>Outline (border only)</option>
                        <option value="filled"  <?= ($header['phone_btn_style'] ?? 'outline') === 'filled'  ? 'selected' : '' ?>>Filled (solid background)</option>
                    </select>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:1 1 180px;">
                        <label for="phone_label">Label above phone button <span class="hint">(leave blank to hide)</span></label>
                        <input type="text" id="phone_label" name="phone_label" value="<?= h($header['phone_label'] ?? 'Helpline:') ?>" placeholder="e.g. Helpline: or Call us:">
                    </div>
                    <div class="form-group" style="flex:0 0 auto;padding-bottom:4px;">
                        <label>
                            <input type="checkbox" name="show_sponsored" value="1" <?= !empty($header['show_sponsored']) ? 'checked' : '' ?>>
                            Show "Sponsored" badge
                        </label>
                        <span class="hint">Uncheck for non-lead-gen sites.</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="sticky" value="1" <?= !empty($header['sticky']) ? 'checked' : '' ?>>
                        Sticky header (stays at top of page when scrolling)
                    </label>
                </div>
            </div>

            <div class="card">
                <h2>Nav CTA Button <span class="hint">(optional)</span></h2>
                <p class="hint" style="margin-bottom:14px;">A secondary button in the nav bar — e.g. "Enroll Now", "Get a Quote", "Book Online". Appears alongside the phone button. Leave blank to hide.</p>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;">
                        <label for="cta_text">Button text</label>
                        <input type="text" id="cta_text" name="cta_text" value="<?= h($header['cta_text'] ?? '') ?>" placeholder="e.g. Enroll Now">
                    </div>
                    <div class="form-group" style="flex:2 1 220px;">
                        <label for="cta_url">Button URL</label>
                        <input type="text" id="cta_url" name="cta_url" value="<?= h($header['cta_url'] ?? '') ?>" placeholder="e.g. /enroll or https://...">
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Header Info Items</h2>
                <p class="hint" style="margin-bottom:14px;">Small icon + text items shown in the top row beside the logo (e.g. "Proudly American", "Call for Great Service!"). Leave text blank to hide an item.</p>
                <?php
                $infoItems = $header['info_items'] ?? [['icon'=>'','text'=>''],['icon'=>'','text'=>''],['icon'=>'','text'=>'']];
                foreach ($infoItems as $ii => $infoItem):
                ?>
                <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;">
                    <div class="form-group" style="flex:0 0 80px;margin:0;">
                        <label>Icon/emoji</label>
                        <input type="text" name="info_icon[]" value="<?= h($infoItem['icon'] ?? '') ?>" placeholder="🇺🇸" style="font-size:1.2rem;">
                    </div>
                    <div class="form-group" style="flex:1;margin:0;">
                        <label>Text</label>
                        <input type="text" name="info_text[]" value="<?= h($infoItem['text'] ?? '') ?>" placeholder="e.g. Proudly American">
                    </div>
                </div>
                <?php endforeach; ?>
                <button type="button" class="btn btn-secondary btn-small" onclick="addInfoItem()">+ Add info item</button>
                <div id="extra-info-items"></div>
            </div>

            <div class="card">
                <h2>Social Media Links</h2>
                <p class="hint" style="margin-bottom:14px;">Add links to your social profiles. Shown in the header top row when set. Leave blank to hide.</p>
                <?php
                $socials = [
                    'facebook'  => 'Facebook',
                    'instagram' => 'Instagram',
                    'twitter'   => 'X / Twitter',
                    'youtube'   => 'YouTube',
                    'linkedin'  => 'LinkedIn',
                    'tiktok'    => 'TikTok',
                    'yelp'      => 'Yelp',
                ];
                foreach ($socials as $key => $label):
                ?>
                <div class="form-group">
                    <label for="social_<?= $key ?>"><?= h($label) ?></label>
                    <input type="url" id="social_<?= $key ?>" name="social_<?= $key ?>"
                           value="<?= h($header['socials'][$key] ?? '') ?>"
                           placeholder="https://<?= h($key) ?>.com/yourpage">
                </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn">Save Header</button>
        </form>
    </div>
