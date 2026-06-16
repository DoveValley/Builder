<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Require login
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Ensure CSRF token exists (in case session was started before login.php generated one)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$data    = load_data();
$theme    = $data['theme'];
$header   = $data['header'];
$siteVars = $data['site_vars'];
$blocks   = $data['content_blocks'];
$footer   = $data['footer'];
$seo      = $data['seo'];
$pages   = $data['pages'];
$posts   = $data['posts'];
$blogSettings = $data['blog_settings'];

// Active tab
$tab = $_GET['tab'] ?? 'header';
if (!in_array($tab, ['header', 'theme', 'content', 'pages', 'footer', 'popups', 'media', 'seo', 'blog'], true)) {
    $tab = 'header';
}

// If on the Landing Pages tab, are we viewing the list or editing one page?
$editingPageId = null;
$editingPage   = null;
if ($tab === 'pages' && !empty($_GET['page']) && isset($pages[$_GET['page']])) {
    $editingPageId = $_GET['page'];
    $editingPage   = $pages[$editingPageId];
}

// If on the Blog tab, are we viewing the list or editing one post?
$editingPostId = null;
$editingPost   = null;
if ($tab === 'blog' && !empty($_GET['post']) && isset($posts[$_GET['post']])) {
    $editingPostId = $_GET['post'];
    $editingPost   = $posts[$editingPostId];
}

// Flash message (format: "success:..." or "error:...")
$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error'], true)) {
            $alert = ['type' => $type, 'text' => $text];
        }
    }
}

// Work out the next free numeric index for footer columns / links,
// so JS can add new ones without colliding with existing keys.
$nextColumnIndex = 0;
$columnNextLinkIndex = [];
foreach ($footer['columns'] as $ci => $column) {
    $ci = (int) $ci;
    if ($ci >= $nextColumnIndex) {
        $nextColumnIndex = $ci + 1;
    }
    $nextLink = 0;
    foreach (($column['links'] ?? []) as $li => $link) {
        $li = (int) $li;
        if ($li >= $nextLink) {
            $nextLink = $li + 1;
        }
    }
    $columnNextLinkIndex[$ci] = $nextLink;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?= h(SITE_TITLE) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script src="https://cdn.tiny.cloud/1/qeuo7izgoglstixfe9merx5vdkfu7nfuvl1nhyc98p6qej0p/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
    tinymce.init({
        selector: '.rich-editor',
        menubar: false,
        plugins: 'link lists autolink',
        toolbar: 'bold italic underline | link | bullist numlist | removeformat',
        height: 240,
        branding: false,
        promotion: false,
        statusbar: false,
        link_default_target: '_blank',
        link_assume_external_targets: true,
        skin: 'oxide',
        content_css: false,
        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 14px; color: #1a1a1a; margin: 8px; }',
        setup: function(editor) {
            // Sync back to textarea on any change so form saves correctly
            editor.on('change input', function() {
                editor.save();
            });
        }
    });
    </script>
    <script>
    // Inject CSRF token into every form on submit
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form[action="save.php"]').forEach(function(form) {
            form.addEventListener('submit', function() {
                // CSRF token
                var inp = form.querySelector('input[name="csrf_token"]');
                if (!inp) {
                    inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'csrf_token';
                    form.appendChild(inp);
                }
                inp.value = CSRF_TOKEN;
            });
        });
    });
    </script>
</head>
<body class="admin-body">
<div class="admin-wrapper">

    <div class="admin-header">
        <h1>Homepage Admin Panel</h1>
        <div>
            <a href="../index.php" target="_blank" class="preview-link">View site &rarr;</a>
            &nbsp;|&nbsp;
            <a href="logout.php">Log out</a>
        </div>
    </div>

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <a class="tab-link <?= $tab === 'header' ? 'active' : '' ?>" href="?tab=header">Header</a>
        <a class="tab-link <?= $tab === 'theme' ? 'active' : '' ?>" href="?tab=theme">Theme / Colors</a>
        <a class="tab-link <?= $tab === 'content' ? 'active' : '' ?>" href="?tab=content">Home Page</a>
        <a class="tab-link <?= $tab === 'pages' ? 'active' : '' ?>" href="?tab=pages">Landing Pages</a>
        <a class="tab-link <?= $tab === 'blog' ? 'active' : '' ?>" href="?tab=blog">Blog</a>
        <a class="tab-link <?= $tab === 'footer' ? 'active' : '' ?>" href="?tab=footer">Footer</a>
        <a class="tab-link <?= $tab === 'popups' ? 'active' : '' ?>" href="?tab=popups">Popups</a>
        <a class="tab-link <?= $tab === 'media' ? 'active' : '' ?>" href="?tab=media">Media Library</a>
        <a class="tab-link <?= $tab === 'seo' ? 'active' : '' ?>" href="?tab=seo">SEO / Schema</a>
    </div>

    <!-- ================= HEADER TAB ================= -->
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
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="sticky" value="1" <?= !empty($header['sticky']) ? 'checked' : '' ?>>
                        Sticky header (stays at top of page when scrolling)
                    </label>
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
                <p class="hint" style="margin-bottom:14px;">Add links to your social profiles. Leave blank to hide.</p>
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

    <!-- ================= THEME TAB ================= -->
    <div class="tab-content" style="<?= $tab === 'theme' ? '' : 'display:none;' ?>">
        <form action="save.php" method="post">
            <input type="hidden" name="section" value="theme">
            <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Theme</button></div>

            <?php
            $colorGroups = [
                'Header' => [
                    'header_bg'     => 'Nav bar background color',
                    'header_top_bg' => 'Top announcement bar background',
                    'header_text'   => 'Text & menu color',
                ],
                'Main Content' => [
                    'content_bg'    => 'Background color',
                    'content_text'  => 'Text color',
                    'heading_color' => 'Heading color',
                ],
                'Footer' => [
                    'footer_bg'   => 'Background color',
                    'footer_text' => 'Text & link color',
                ],
            ];
            foreach ($colorGroups as $groupLabel => $fields):
            ?>
                <div class="card">
                    <h2><?= h($groupLabel) ?></h2>
                    <?php foreach ($fields as $key => $label):
                        $value = $theme[$key] ?? '#000000';
                    ?>
                        <div class="form-group">
                            <label for="<?= $key ?>"><?= h($label) ?></label>
                            <div class="color-field">
                                <input type="color" id="<?= $key ?>_picker" value="<?= h($value) ?>"
                                       oninput="document.getElementById('<?= $key ?>').value = this.value;">
                                <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= h($value) ?>"
                                       oninput="document.getElementById('<?= $key ?>_picker').value = this.value;">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="card">
                <h2>Accent Color</h2>
                <div class="form-group">
                    <label for="accent_color">Accent color</label>
                    <div class="color-field">
                        <input type="color" id="accent_color_picker" value="<?= h($theme['accent_color'] ?? '#2563eb') ?>"
                               oninput="document.getElementById('accent_color').value = this.value;">
                        <input type="text" id="accent_color" name="accent_color" value="<?= h($theme['accent_color'] ?? '#2563eb') ?>"
                               oninput="document.getElementById('accent_color_picker').value = this.value;">
                    </div>
                    <span class="hint">Used for links and buttons across the header, main content, and footer.</span>
                </div>
            </div>

            <div class="card">
                <h2>Typography &amp; Buttons</h2>
                <div class="form-group">
                    <label for="primary_font">Primary font</label>
                    <select id="primary_font" name="primary_font">
                        <?php
                        $currentFont = $theme['primary_font'] ?? 'sans-serif';
                        $fonts = [
                            'sans-serif'             => 'System sans-serif (default)',
                            'Arial, sans-serif'      => 'Arial',
                            'Helvetica, sans-serif'  => 'Helvetica',
                            'Verdana, sans-serif'    => 'Verdana',
                            'Trebuchet MS, sans-serif' => 'Trebuchet MS',
                            'Georgia, serif'         => 'Georgia (serif)',
                            'serif'                  => 'System serif',
                        ];
                        foreach ($fonts as $val => $label):
                        ?>
                            <option value="<?= h($val) ?>" <?= $val === $currentFont ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="button_radius">Button corner radius</label>
                    <input type="number" id="button_radius" name="button_radius" min="0" max="50"
                           value="<?= h($theme['button_radius'] ?? '4') ?>" style="width:90px;">
                    <span class="hint">Pixels. 0 = square corners, 4 = slightly rounded, 24+ = pill shape.</span>
                </div>
            </div>

            <div class="card">
                <h2>Analytics &amp; Tracking</h2>
                <p class="hint" style="margin-bottom:14px;">Paste your tracking code here. It will be added to the <code>&lt;head&gt;</code> of every page automatically.</p>
                <div class="form-group">
                    <label for="analytics_head">Google Analytics / GA4 snippet</label>
                    <textarea id="analytics_head" name="analytics_head" rows="5"
                              style="font-family:monospace;font-size:0.82rem;"><?= h($theme['analytics_head'] ?? '') ?></textarea>
                    <span class="hint">Paste the full <code>&lt;script&gt;...&lt;/script&gt;</code> block from Google Analytics or Tag Manager.</span>
                </div>
                <div class="form-group">
                    <label for="facebook_pixel">Facebook Pixel / Meta Pixel snippet</label>
                    <textarea id="facebook_pixel" name="facebook_pixel" rows="5"
                              style="font-family:monospace;font-size:0.82rem;"><?= h($theme['facebook_pixel'] ?? '') ?></textarea>
                    <span class="hint">Paste the full Pixel base code here.</span>
                </div>
            </div>

            <button type="submit" class="btn">Save Theme</button>
        </form>

        <form action="save.php" method="post" style="margin-top:12px;" onsubmit="return confirm('Reset all colors to the default white backgrounds with black text?');">
            <input type="hidden" name="section" value="theme_reset">
            <button type="submit" class="btn btn-secondary">Reset to Defaults</button>
        </form>
    </div>

    <!-- ================= CONTENT TAB ================= -->
    <div class="tab-content" style="<?= $tab === 'content' ? '' : 'display:none;' ?>">
        <form action="save.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="section" value="content">
            <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn">Save Content</button>
                <a href="../index.php" target="_blank" class="btn btn-secondary">Preview Home Page &rarr;</a>
            </div>

            <?php if ($tab === 'content'): ?>
            <?php render_content_blocks_editor($blocks); ?>

            <?php render_seo_editor($seo); ?>
            <?php endif; ?>

            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn">Save Content</button>
                <a href="../index.php" target="_blank" class="btn btn-secondary">Preview Home Page &rarr;</a>
            </div>
        </form>
    </div>

    <!-- ================= PAGES TAB ================= -->
    <div class="tab-content" style="<?= $tab === 'pages' ? '' : 'display:none;' ?>">
        <?php if ($editingPage === null): ?>

            <div class="card">
                <h2>Add a New Page</h2>
                <p class="hint" style="margin-bottom:18px;">
                    All pages share the same header, footer, and colors as your home page,
                    but have their own content and SEO settings.
                </p>
                <form action="save.php" method="post">
                    <input type="hidden" name="section" value="page_add">
                    <div class="form-group">
                        <label for="new_page_title">Page title</label>
                        <input type="text" id="new_page_title" name="title" placeholder="e.g. About Us" required>
                    </div>
                    <div class="form-group">
                        <label for="new_page_slug">URL slug (optional)</label>
                        <input type="text" id="new_page_slug" name="slug" placeholder="e.g. about-us">
                        <span class="hint">Letters, numbers, and hyphens only. Leave blank to generate one automatically from the title.</span>
                    </div>
                    <div class="form-group">
                        <label for="new_page_type">Page type</label>
                        <select id="new_page_type" name="page_type">
                            <option value="landing">Landing Page</option>
                            <option value="other">Other Page</option>
                        </select>
                        <span class="hint">Landing pages are city/service pages built for SEO cloning. Other pages are things like Privacy Policy, Terms, Contact.</span>
                    </div>
                    <button type="submit" class="btn">Add Page</button>
                </form>
            </div>

            <?php
            $landingPages = array_filter($pages, fn($p) => ($p['page_type'] ?? 'landing') !== 'other');
            $otherPages   = array_filter($pages, fn($p) => ($p['page_type'] ?? 'landing') === 'other');
            $renderPageList = function($list) {
                if (empty($list)) { echo '<p class="hint">None yet.</p>'; return; }
                echo '<div class="repeat-items">';
                foreach ($list as $pid => $p) {
                    echo '<div class="repeat-row" style="align-items:center;">';
                    echo '<div style="flex:1;"><strong>' . h($p['title'] !== '' ? $p['title'] : '(untitled)') . '</strong><br>';
                    echo '<span class="hint">/' . h($p['slug']) . ' &mdash; <a href="../page.php?slug=' . h($p['slug']) . '" target="_blank" rel="noopener">preview</a></span></div>';
                    echo '<a class="btn btn-secondary btn-small" href="?tab=pages&page=' . h($pid) . '">Edit</a>';
                    echo '<form action="save.php" method="post" style="display:inline;" onsubmit="return confirm(\'Delete this page? This cannot be undone.\');">';
                    echo '<input type="hidden" name="section" value="page_delete">';
                    echo '<input type="hidden" name="page_id" value="' . h($pid) . '">';
                    echo '<button type="submit" class="remove-row" title="Delete page">&times;</button>';
                    echo '</form></div>';
                }
                echo '</div>';
            };
            ?>

            <div class="card">
                <h2>Landing Pages</h2>
                <?php $renderPageList($landingPages); ?>
            </div>

            <div class="card">
                <h2>Other Pages</h2>
                <?php $renderPageList($otherPages); ?>
            </div>

        <?php else: ?>

            <p style="margin-bottom:16px;"><a href="?tab=pages">&larr; Back to all landing pages</a></p>

            <form action="save.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="section" value="content">
                <input type="hidden" name="page_id" value="<?= h($editingPageId) ?>">
                <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Page</button>
                    <a href="../page.php?slug=<?= h($editingPage['slug'] ?? '') ?>" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
                </div>

                <div class="card">
                    <h2>Page Settings</h2>
                    <div class="form-group">
                        <label for="page_title">Page title</label>
                        <input type="text" id="page_title" name="page_title" value="<?= h($editingPage['title']) ?>">
                        <span class="hint">Shown in the browser tab and used as the page's SEO title.</span>
                    </div>
                    <div class="form-group">
                        <label for="page_slug">URL slug</label>
                        <input type="text" id="page_slug" name="page_slug" value="<?= h($editingPage['slug']) ?>">
                        <span class="hint">
                            This page is available at
                            <code>/page.php?slug=<?= h($editingPage['slug']) ?></code>
                            (or <code>/<?= h($editingPage['slug']) ?></code> if pretty URLs are enabled &mdash; see README).
                        </span>
                    </div>
                </div>

                <?php render_content_blocks_editor($editingPage['content_blocks']); ?>

                <?php render_seo_editor($editingPage['seo']); ?>

                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Page</button>
                    <a href="../page.php?slug=<?= h($editingPage['slug']) ?>" target="_blank" class="btn btn-secondary">Preview Page &rarr;</a>
                </div>
            </form>

            <form action="save.php" method="post" style="margin-top:12px;" onsubmit="return confirm('Delete this landing page? This cannot be undone.');">
                <input type="hidden" name="section" value="page_delete">
                <input type="hidden" name="page_id" value="<?= h($editingPageId) ?>">
                <button type="submit" class="btn btn-danger">Delete This Page</button>
            </form>

        <?php endif; ?>
    </div>

    <!-- ================= BLOG TAB ================= -->
    <div class="tab-content" style="<?= $tab === 'blog' ? '' : 'display:none;' ?>">
        <?php if ($editingPost === null): ?>

            <div class="card">
                <h2>Blog Settings</h2>
                <form action="save.php" method="post">
                    <input type="hidden" name="section" value="blog_settings">
                    <div class="form-group">
                        <label for="blog_heading">Blog page heading</label>
                        <input type="text" id="blog_heading" name="blog_heading" value="<?= h($blogSettings['blog_heading']) ?>">
                        <span class="hint">Shown at the top of /blog. Shortcodes like {city} are supported.</span>
                    </div>
                    <div class="form-group">
                        <label for="blog_intro">Blog intro text</label>
                        <textarea id="blog_intro" name="blog_intro" rows="2"><?= h($blogSettings['blog_intro']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="posts_per_page">Posts per page</label>
                        <input type="number" id="posts_per_page" name="posts_per_page" min="1" max="50" value="<?= h($blogSettings['posts_per_page']) ?>">
                    </div>
                    <button type="submit" class="btn">Save Blog Settings</button>
                </form>
            </div>

            <div class="card">
                <h2>Add a New Post</h2>
                <form action="save.php" method="post">
                    <input type="hidden" name="section" value="post_add">
                    <div class="form-group">
                        <label for="new_post_title">Post title</label>
                        <input type="text" id="new_post_title" name="title" placeholder="e.g. 5 Signs You Have a Termite Problem" required>
                    </div>
                    <div class="form-group">
                        <label for="new_post_slug">URL slug (optional)</label>
                        <input type="text" id="new_post_slug" name="slug" placeholder="e.g. signs-of-termites">
                        <span class="hint">Leave blank to generate one automatically from the title.</span>
                    </div>
                    <button type="submit" class="btn">Add Post</button>
                </form>
            </div>

            <div class="card">
                <h2>Posts</h2>
                <?php
                if (empty($posts)) {
                    echo '<p class="hint">None yet.</p>';
                } else {
                    $sortedPosts = $posts;
                    uasort($sortedPosts, fn($a, $b) => strcmp($b['published_at'] ?? '', $a['published_at'] ?? ''));
                    echo '<div class="repeat-items">';
                    foreach ($sortedPosts as $pid => $p) {
                        echo '<div class="repeat-row" style="align-items:center;">';
                        echo '<div style="flex:1;"><strong>' . h($p['title'] !== '' ? $p['title'] : '(untitled)') . '</strong> ';
                        echo '<span class="hint">(' . h($p['status']) . ')</span><br>';
                        echo '<span class="hint">/blog/' . h($p['slug']) . ' &mdash; ' . h($p['published_at']) . ' &mdash; <a href="../blog.php?slug=' . h($p['slug']) . '" target="_blank" rel="noopener">preview</a></span></div>';
                        echo '<a class="btn btn-secondary btn-small" href="?tab=blog&post=' . h($pid) . '">Edit</a>';
                        echo '<form action="save.php" method="post" style="display:inline;" onsubmit="return confirm(\'Delete this post? This cannot be undone.\');">';
                        echo '<input type="hidden" name="section" value="post_delete">';
                        echo '<input type="hidden" name="post_id" value="' . h($pid) . '">';
                        echo '<button type="submit" class="remove-row" title="Delete post">&times;</button>';
                        echo '</form></div>';
                    }
                    echo '</div>';
                }
                ?>
            </div>

        <?php else: ?>

            <p style="margin-bottom:16px;"><a href="?tab=blog">&larr; Back to all posts</a></p>

            <form action="save.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="section" value="content">
                <input type="hidden" name="post_id" value="<?= h($editingPostId) ?>">
                <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Post</button>
                    <a href="../blog.php?slug=<?= h($editingPost['slug'] ?? '') ?>" target="_blank" class="btn btn-secondary">Preview Post &rarr;</a>
                </div>

                <div class="card">
                    <h2>Post Settings</h2>
                    <div class="form-group">
                        <label for="post_title">Post title</label>
                        <input type="text" id="post_title" name="post_title" value="<?= h($editingPost['title']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="post_slug">URL slug</label>
                        <input type="text" id="post_slug" name="post_slug" value="<?= h($editingPost['slug']) ?>">
                        <span class="hint">This post is available at <code>/blog/<?= h($editingPost['slug']) ?></code>.</span>
                    </div>
                    <div class="form-group">
                        <label for="post_status">Status</label>
                        <select id="post_status" name="post_status">
                            <option value="draft" <?= $editingPost['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= $editingPost['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="post_published_at">Published date</label>
                        <input type="date" id="post_published_at" name="post_published_at" value="<?= h($editingPost['published_at']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="post_author">Author</label>
                        <input type="text" id="post_author" name="post_author" value="<?= h($editingPost['author']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="post_tag">Tag</label>
                        <input type="text" id="post_tag" name="post_tag" value="<?= h($editingPost['tag']) ?>" placeholder="e.g. Termites">
                        <span class="hint">Readers can click this tag to filter the blog list to posts with the same tag.</span>
                    </div>
                    <div class="form-group">
                        <label for="post_excerpt">Excerpt</label>
                        <textarea id="post_excerpt" name="post_excerpt" rows="2"><?= h($editingPost['excerpt']) ?></textarea>
                        <span class="hint">Shown on the blog list card. Falls back to meta description if blank.</span>
                    </div>
                    <div class="form-group">
                        <label for="post_featured_image">Featured image</label>
                        <?php if (!empty($editingPost['featured_image'])): ?>
                            <p><img src="../<?= h($editingPost['featured_image']) ?>" style="max-width:200px;border-radius:8px;" alt=""></p>
                        <?php endif; ?>
                        <input type="file" id="post_featured_image" name="post_featured_image" accept="image/*">
                        <input type="hidden" name="post_featured_image_existing" value="<?= h($editingPost['featured_image']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="post_featured_image_alt">Featured image alt text</label>
                        <input type="text" id="post_featured_image_alt" name="post_featured_image_alt" value="<?= h($editingPost['featured_image_alt']) ?>">
                    </div>
                </div>

                <?php render_content_blocks_editor($editingPost['content_blocks']); ?>

                <?php render_seo_editor($editingPost['seo']); ?>

                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn">Save Post</button>
                    <a href="../blog.php?slug=<?= h($editingPost['slug']) ?>" target="_blank" class="btn btn-secondary">Preview Post &rarr;</a>
                </div>
            </form>

            <form action="save.php" method="post" style="margin-top:12px;" onsubmit="return confirm('Delete this post? This cannot be undone.');">
                <input type="hidden" name="section" value="post_delete">
                <input type="hidden" name="post_id" value="<?= h($editingPostId) ?>">
                <button type="submit" class="btn btn-danger">Delete This Post</button>
            </form>

        <?php endif; ?>
    </div>

    <!-- ================= FOOTER TAB ================= -->
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
                <h2>3 Footer Columns</h2>
                <p class="hint" style="margin-bottom:18px;">
                    Column type: <strong>Text</strong> = heading + paragraph &nbsp;|&nbsp; <strong>Links</strong> = heading + link list &nbsp;|&nbsp; <strong>Contact</strong> = phone + city + optional extras.
                </p>

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

    <!-- ================= POPUPS TAB ================= -->
    <div class="tab-content" style="<?= $tab === 'popups' ? '' : 'display:none;' ?>">
        <?php
        $popups = $data['popups'] ?? [];
        $infoPopup = $popups['info'] ?? [];
        ?>
        <form action="save.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="section" value="popups">
            <div style="margin-bottom:16px;"><button type="submit" class="btn">Save Popup</button></div>

            <div class="card">
                <h2>Info Popup</h2>
                <p class="hint" style="margin-bottom:18px;">
                    This popup opens when visitors click the <strong>ℹ️</strong> circle in the nav bar or sticky bottom bar.
                    Use it for "How Your Calls Are Handled" or any legal/info disclosure.
                </p>

                <div class="form-group">
                    <label>Popup heading</label>
                    <input type="text" name="popup_info_heading"
                           value="<?= h($infoPopup['heading'] ?? 'How Your Calls Are Handled') ?>"
                           placeholder="e.g. How Your Calls Are Handled">
                </div>

                <div class="form-group">
                    <label>Popup image (optional — shown at top of popup)</label>
                    <?php if (!empty($infoPopup['image'])): ?>
                        <img src="../<?= h($infoPopup['image']) ?>" style="max-height:80px;border-radius:6px;margin-bottom:8px;display:block;" onerror="this.style.display='none'">
                        <label style="font-weight:400;margin-bottom:8px;display:block;">
                            <input type="checkbox" name="popup_info_remove_image" value="1"> Remove image
                        </label>
                    <?php endif; ?>
                    <input type="file" name="popup_info_image" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="popup_info_image_existing" value="<?= h($infoPopup['image'] ?? '') ?>">
                    <?php photo_picker_btn('popup_info_image_existing'); ?>
                </div>

                <div class="form-group">
                    <label>Popup body text</label>
                    <textarea name="popup_info_body" rows="10" class="rich-editor"
                              placeholder="Enter the full popup text here. Leave a blank line between paragraphs."><?= h($infoPopup['body'] ?? '') ?></textarea>
                    <span class="hint">Leave a blank line between paragraphs. Surround text with **double asterisks** for bold.</span>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="popup_info_enabled" value="1"
                               <?= !empty($infoPopup['enabled']) ? 'checked' : '' ?>>
                        Show ℹ️ trigger button in header nav bar and sticky bottom bar
                    </label>
                </div>
            </div>

            <button type="submit" class="btn">Save Popup</button>
        </form>
    </div>

    <!-- ================= MEDIA LIBRARY TAB ================= -->
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

    <!-- ================= SEO / SCHEMA TAB ================= -->
    <div class="tab-content" style="<?= $tab === 'seo' ? '' : 'display:none;' ?>">
        <form method="post" action="save.php">
            <input type="hidden" name="section" value="local_business">
            <?php render_local_business_editor($data['local_business'] ?? []); ?>
            <div style="margin-top:24px;">
                <button type="submit" class="btn">Save Local Business Info</button>
            </div>
        </form>
    </div>

</div>

?>

<script>
/* ---------------------------------------------------------
   Generic row helpers (menu items, bottom links)
   --------------------------------------------------------- */
/* ── media library helpers ── */
function setBlockPhoto(uid, url, alt) {
    const existing = document.getElementById('existing_' + uid);
    const preview  = document.getElementById('preview_'  + uid);
    if (existing) existing.value = url;
    if (preview)  preview.innerHTML = '<img src="../' + url + '" alt="' + alt + '" style="max-width:100%;max-height:200px;border-radius:4px;">';
    // also fill nearby alt input if empty
    if (alt && preview) {
        const card = preview.closest('.form-group') || preview.parentElement;
        if (card) {
            const altInput = card.querySelector ? card.closest('.block-field-group, .card, form')?.querySelector('input[name="block_photo_alt[]"]') : null;
            if (altInput && !altInput.value) altInput.value = alt;
        }
    }
}

function removeRow(button, containerId) {
    const row = button.closest('.repeat-row');
    const container = containerId ? document.getElementById(containerId) : row.parentElement;
    if (container.children.length > 1) {
        row.remove();
    } else {
        row.querySelectorAll('input').forEach(i => i.value = '');
    }
}

let menuItemCount = <?= count($menu) ?>;

function addInfoItem() {
    const container = document.getElementById('extra-info-items');
    const row = document.createElement('div');
    row.style = 'display:flex;gap:10px;align-items:center;margin-bottom:10px;';
    row.innerHTML = `
        <div class="form-group" style="flex:0 0 80px;margin:0;">
            <label>Icon/emoji</label>
            <input type="text" name="info_icon[]" placeholder="🌐" style="font-size:1.2rem;">
        </div>
        <div class="form-group" style="flex:1;margin:0;">
            <label>Text</label>
            <input type="text" name="info_text[]" placeholder="e.g. Call for Great Service!">
        </div>
        <button type="button" class="remove-row" onclick="this.parentElement.remove()" style="margin-top:20px;">&times;</button>
    `;
    container.appendChild(row);
}

function addMenuRow() {
    const container = document.getElementById('menu-items');
    const mi = menuItemCount++;
    const card = document.createElement('div');
    card.className = 'menu-item-card';
    card.dataset.menuIndex = mi;
    card.innerHTML = `
        <div class="menu-item-top repeat-row">
            <input type="text" name="menu_label[]" placeholder="Label (e.g. Home)">
            <input type="text" name="menu_url[]" placeholder="Link (e.g. / or #about)">
            <button type="button" class="btn btn-secondary btn-small" onclick="toggleDropdown(this)" style="white-space:nowrap;">+ Sub-menu (0)</button>
            <button type="button" class="remove-row" onclick="removeMenuItem(this)">&times;</button>
        </div>
        <div class="menu-dropdown-editor is-hidden">
            <p class="hint" style="margin:6px 0 8px 0;">Sub-menu links — shown in a dropdown under this item.</p>
            <div class="dropdown-links"></div>
            <button type="button" class="btn btn-secondary btn-small" onclick="addDropdownLink(this, ${mi})" style="margin-top:6px;">+ Add sub-link</button>
        </div>
    `;
    container.appendChild(card);
}

function removeMenuItem(btn) {
    const container = document.getElementById('menu-items');
    const card = btn.closest('.menu-item-card');
    if (container.children.length > 1) card.remove();
    else card.querySelectorAll('input').forEach(i => i.value = '');
}

function toggleDropdown(btn) {
    const card = btn.closest('.menu-item-card');
    const editor = card.querySelector('.menu-dropdown-editor');
    editor.classList.toggle('is-hidden');
}

function addDropdownLink(btn, mi) {
    const card = btn.closest('.menu-item-card');
    const linksContainer = card.querySelector('.dropdown-links');
    const row = document.createElement('div');
    row.className = 'repeat-row dropdown-link-row';
    row.innerHTML = `
        <input type="text" name="menu_child_label[${mi}][]" placeholder="Sub-link label">
        <input type="text" name="menu_child_url[${mi}][]" placeholder="Sub-link URL">
        <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
    `;
    linksContainer.appendChild(row);
    // Update count on toggle button
    const toggleBtn = card.querySelector('.menu-item-top .btn');
    const count = linksContainer.children.length;
    toggleBtn.textContent = `+ Sub-menu (${count})`;
}

function addBottomLinkRow() {
    const container = document.getElementById('bottom-links');
    const row = document.createElement('div');
    row.className = 'repeat-row';
    row.innerHTML = `
        <input type="text" name="bottom_link_label[]" placeholder="Label (e.g. Privacy Policy)">
        <input type="text" name="bottom_link_url[]" placeholder="URL">
        <button type="button" class="remove-row" onclick="removeRow(this, 'bottom-links')">&times;</button>
    `;
    container.appendChild(row);
}

/* ---------------------------------------------------------
   Footer columns & links
   --------------------------------------------------------- */
let nextColumnIndex = <?= $nextColumnIndex ?>;

function switchColType(select) {
    const card = select.closest('.column-card');
    card.querySelectorAll('.col-type-panel').forEach(p => p.classList.add('is-hidden'));
    const panel = card.querySelector('.col-type-' + select.value);
    if (panel) panel.classList.remove('is-hidden');
}

function addContactExtra(btn) {
    const card = btn.closest('.column-card');
    const ci = card.dataset.colIndex;
    const links = card.querySelector('.column-links');
    const li = links ? links.children.length : 0;
    const row = document.createElement('div');
    row.className = 'repeat-row';
    row.innerHTML = `
        <input type="text" name="footer_columns[${ci}][contact_extras][${li}][icon]"  placeholder="Icon/emoji" style="flex:0 0 70px;">
        <input type="text" name="footer_columns[${ci}][contact_extras][${li}][label]" placeholder="Label text">
        <input type="text" name="footer_columns[${ci}][contact_extras][${li}][url]"   placeholder="Link (optional)">
        <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
    `;
    if (links) links.appendChild(row);
}

function addColumn() {
    const container = document.getElementById('footer-columns');
    const colIndex = nextColumnIndex++;
    const card = document.createElement('div');
    card.className = 'column-card';
    card.dataset.colIndex = colIndex;
    card.dataset.nextLinkIndex = '1';
    card.innerHTML = `
        <div class="column-card-header" style="gap:8px;">
            <input type="text" name="footer_columns[${colIndex}][title]" placeholder="Column heading">
            <select name="footer_columns[${colIndex}][type]" onchange="switchColType(this)"
                    style="flex:0 0 140px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:0.88rem;">
                <option value="links">Links column</option>
                <option value="text">Text column</option>
                <option value="contact">Contact column</option>
            </select>
            <button type="button" class="icon-btn remove-row" onclick="removeColumn(this)">Remove</button>
        </div>
        <div class="col-type-panel col-type-links">
            <div class="column-links">
                <div class="repeat-row">
                    <input type="text" name="footer_columns[${colIndex}][links][0][label]" placeholder="Link text">
                    <input type="text" name="footer_columns[${colIndex}][links][0][url]"   placeholder="URL">
                    <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
                </div>
            </div>
            <button type="button" class="btn btn-secondary btn-small" onclick="addLink(this)">+ Add link</button>
        </div>
        <div class="col-type-panel col-type-text is-hidden">
            <div class="form-group" style="margin-top:10px;">
                <textarea name="footer_columns[${colIndex}][text]" rows="5" placeholder="Column text..."></textarea>
            </div>
        </div>
        <div class="col-type-panel col-type-contact is-hidden">
            <p class="hint" style="margin:8px 0;">Phone and city shown automatically. Add extra items below.</p>
            <div class="column-links"></div>
            <button type="button" class="btn btn-secondary btn-small" onclick="addContactExtra(this)">+ Add item</button>
        </div>
    `;
    container.appendChild(card);
}

function removeColumn(button) {
    const container = document.getElementById('footer-columns');
    const card = button.closest('.column-card');
    card.remove();
    if (container.children.length === 0) {
        nextColumnIndex = 0;
    }
}

function addLink(button) {
    const card = button.closest('.column-card');
    const colIndex = card.dataset.colIndex;
    const linkIndex = parseInt(card.dataset.nextLinkIndex || '0', 10);
    card.dataset.nextLinkIndex = String(linkIndex + 1);

    const linksContainer = card.querySelector('.column-links');
    const row = document.createElement('div');
    row.className = 'repeat-row';
    row.innerHTML = `
        <input type="text" name="footer_columns[${colIndex}][links][${linkIndex}][label]" placeholder="Link text">
        <input type="text" name="footer_columns[${colIndex}][links][${linkIndex}][url]" placeholder="URL (e.g. /about or #faq)">
        <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
    `;
    linksContainer.appendChild(row);
}
</script>

<?php content_editor_scripts(); ?>

<!-- ===================== MEDIA LIBRARY TAB ===================== -->
<?php if ($tab === 'media'): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<style>
.ml-usage { font-size:.76rem; margin:5px 0 3px; line-height:1.3; }
.ml-unused { color:#9ca3af; }
.ml-in-use { color:#15803d; cursor:pointer; user-select:none; position:relative; }
.ml-in-use:hover { color:#166534; }
.ml-usage-list { display:none; margin:3px 0 0; padding:0; list-style:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:4px; overflow:hidden; }
.ml-in-use.ml-open .ml-usage-list { display:block; }
.ml-usage-list li { padding:3px 8px; font-size:.73rem; color:#166534; border-bottom:1px solid #dcfce7; }
.ml-usage-list li:last-child { border-bottom:none; }
</style>
<script>
(function() {
    const api = 'media_api.php';

    /* ── state ── */
    let allMedia    = [];
    let searchQ     = '';
    let mediaUsage  = {};
    let usageLoaded = false;
    let dupeGroups  = [];

    /* ── elements ── */
    const grid     = document.getElementById('media-grid');
    const searchEl = document.getElementById('media-search');
    const countEl  = document.getElementById('media-count');
    const dropzone = document.getElementById('media-dropzone');
    const fileInp  = document.getElementById('media-file-input');

    /* ── load ── */
    async function loadMedia() {
        const res  = await fetch(api + '?action=list');
        allMedia   = await res.json();
        renderGrid();
    }

    async function loadUsage() {
        const res  = await fetch(api + '?action=usage');
        mediaUsage = await res.json();
        usageLoaded = true;
        renderGrid();
    }

    function usageBadge(url) {
        if (!usageLoaded) return '';
        const list = mediaUsage[url] || [];
        if (list.length === 0) return '<div class="ml-usage ml-unused">Unused</div>';
        const items = list.map(u => `<li>${escHtml(u)}</li>`).join('');
        return `<div class="ml-usage ml-in-use" onclick="this.classList.toggle('ml-open')">`
             + `${list.length} place${list.length > 1 ? 's' : ''} &#9662;`
             + `<ul class="ml-usage-list">${items}</ul></div>`;
    }

    function renderGrid() {
        const q    = searchQ.toLowerCase();
        const items = allMedia.filter(m =>
            !q || m.filename.toLowerCase().includes(q) || (m.alt||'').toLowerCase().includes(q)
        );
        countEl.textContent = items.length + ' image' + (items.length !== 1 ? 's' : '');
        grid.innerHTML = items.map(m => `
            <div class="ml-card" data-fn="${escHtml(m.filename)}">
                <div class="ml-thumb" onclick="copyUrl('${escHtml(m.url)}')">
                    <img src="../${escHtml(m.url)}" alt="${escHtml(m.alt||'')}">
                    <div class="ml-thumb-overlay">Click to copy URL</div>
                </div>
                <div class="ml-info">
                    <div class="ml-name" title="${escHtml(m.filename)}">${escHtml(m.filename.replace('.webp',''))}</div>
                    <div class="ml-dims">${m.width}×${m.height} &nbsp;·&nbsp; ${fmtSize(m.size)}</div>
                    <input class="ml-alt-input" type="text" value="${escHtml(m.alt||'')}" placeholder="Alt text…" onchange="updateAlt('${escHtml(m.filename)}', this.value)">
                    ${usageBadge(m.url)}
                    <div class="ml-actions">
                        <button class="btn btn-small btn-secondary" onclick="copyUrl('${escHtml(m.url)}')">Copy URL</button>
                        <button class="btn btn-small btn-secondary" onclick="openCropper('${escHtml(m.filename)}','../${escHtml(m.url)}')">&#9986; Crop</button>
                        <button class="btn btn-small btn-secondary" onclick="openFocal('${escHtml(m.filename)}','../${escHtml(m.url)}',${m.focal_x!=null?m.focal_x:50},${m.focal_y!=null?m.focal_y:50})">&#10753; Focal</button>
                        <button class="btn btn-small btn-danger" onclick="deleteMedia('${escHtml(m.filename)}')">Delete</button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
    function fmtSize(b) { return b > 1048576 ? (b/1048576).toFixed(1)+' MB' : Math.round(b/1024)+' KB'; }

    window.copyUrl = function(url) {
        navigator.clipboard.writeText(url).then(() => {
            showToast('URL copied: ' + url);
        });
    };

    window.deleteMedia = async function(filename) {
        const item   = allMedia.find(m => m.filename === filename);
        const used   = item ? (mediaUsage[item.url] || []) : [];
        let msg = 'Delete ' + filename + '?';
        if (used.length > 0) {
            msg = '⚠ This image is used in ' + used.length + ' place(s):\n'
                + used.join('\n')
                + '\n\nDeleting it will break those pages. Continue?';
        }
        if (!confirm(msg)) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('filename', filename);
        fd.append('csrf_token', CSRF_TOKEN);
        await fetch(api, { method:'POST', body: fd });
        if (item) delete mediaUsage[item.url];
        allMedia = allMedia.filter(m => m.filename !== filename);
        renderGrid();
    };

    window.updateAlt = async function(filename, alt) {
        const fd = new FormData();
        fd.append('action', 'update');
        fd.append('filename', filename);
        fd.append('alt', alt);
        fd.append('csrf_token', CSRF_TOKEN);
        await fetch(api, { method:'POST', body: fd });
    };

    /* ── upload ── */
    async function uploadFiles(files) {
        for (const file of files) {
            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('file', file);
            fd.append('csrf_token', CSRF_TOKEN);
            const res  = await fetch(api, { method:'POST', body: fd });
            const data = await res.json();
            if (data.item) {
                allMedia.unshift(data.item);
                showToast('Uploaded: ' + data.item.filename);
            } else {
                showToast('Error: ' + (data.error || 'unknown'));
            }
        }
        renderGrid();
    }

    dropzone.addEventListener('click', () => fileInp.click());
    fileInp.addEventListener('change', () => { uploadFiles(fileInp.files); fileInp.value = ''; });
    dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag-over'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('drag-over');
        uploadFiles(e.dataTransfer.files);
    });

    searchEl.addEventListener('input', () => { searchQ = searchEl.value; renderGrid(); });

    function showToast(msg) {
        const t = document.createElement('div');
        t.className = 'ml-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    }

    /* ── image variation ── */
    window.applyVariation = async function() {
        const seed = parseInt(document.getElementById('var-seed').value, 10);
        if (!seed || seed < 1 || seed > 9999) {
            alert('Enter a seed number between 1 and 9999.\n\nUse a different number for each city site.');
            return;
        }
        const result = document.getElementById('var-result');
        const btn    = document.getElementById('var-apply-btn');
        const n      = allMedia.filter(m => !m.varied_seed || m.varied_seed !== seed).length;
        if (!confirm(
            'Apply variation seed ' + seed + ' to ' + n + ' image' + (n !== 1 ? 's' : '') + '?\n\n' +
            'This permanently modifies the image files. Use a different seed number for each city site.\n\n' +
            'Already-varied images with this seed are skipped automatically.'
        )) return;

        btn.disabled = true;
        btn.textContent = 'Applying…';
        result.innerHTML = '';

        const fd = new FormData();
        fd.append('action', 'vary_batch');
        fd.append('seed',   seed);
        fd.append('csrf_token', CSRF_TOKEN);
        const res  = await fetch(api, { method: 'POST', body: fd });
        const data = await res.json();

        btn.disabled = false;
        btn.textContent = 'Apply to All Images';

        if (data.success) {
            result.innerHTML = `<span style="color:#166534;">&#10003; ${data.varied} image${data.varied !== 1 ? 's' : ''} varied`
                + (data.skipped ? ` &nbsp;&middot;&nbsp; ${data.skipped} already done` : '')
                + (data.failed  ? ` &nbsp;&middot;&nbsp; <span style="color:#dc2626;">${data.failed} failed</span>` : '')
                + '</span>';
            await loadMedia(); // refresh dimensions/sizes
        } else {
            result.innerHTML = `<span style="color:#dc2626;">Error: ${escHtml(data.error || 'unknown')}</span>`;
        }
    };

    /* ── duplicate detector ── */
    window.findDuplicates = async function() {
        const btn = document.getElementById('dupe-btn');
        btn.disabled = true;
        btn.textContent = 'Scanning…';

        // Backfill hashes for any new images
        const hfd = new FormData(); hfd.append('action', 'hash_all'); hfd.append('csrf_token', CSRF_TOKEN);
        await fetch(api, { method: 'POST', body: hfd });

        // Get duplicate groups
        const res = await fetch(api + '?action=dupes');
        dupeGroups = await res.json();

        btn.disabled = false;
        btn.textContent = 'Find Duplicates';
        renderDupePanel();
    };

    window.closeDupePanel = function() {
        document.getElementById('dupe-panel').style.display = 'none';
        dupeGroups = [];
    };

    window.dupeDelete = async function(filename, gi) {
        const item = allMedia.find(m => m.filename === filename);
        const used = item ? (mediaUsage[item.url] || []) : [];
        let msg = 'Delete ' + filename + '?';
        if (used.length) msg = '⚠ Used in ' + used.length + ' place(s):\n' + used.join('\n') + '\n\nDelete anyway?';
        if (!confirm(msg)) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('filename', filename);
        fd.append('csrf_token', CSRF_TOKEN);
        await fetch(api, { method: 'POST', body: fd });

        if (item) delete mediaUsage[item.url];
        allMedia     = allMedia.filter(m => m.filename !== filename);
        dupeGroups[gi] = dupeGroups[gi].filter(i => i.filename !== filename);
        if (dupeGroups[gi].length <= 1) dupeGroups.splice(gi, 1);

        renderGrid();
        renderDupePanel();
    };

    function renderDupePanel() {
        const panel = document.getElementById('dupe-panel');

        if (dupeGroups.length === 0) {
            panel.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 16px;margin-bottom:16px;">
                <span style="color:#166534;font-weight:500;">&#10003; No duplicate images found</span>
                <button onclick="closeDupePanel()" style="background:none;border:none;cursor:pointer;color:#6b7280;font-size:1.1rem;line-height:1;">&#10005;</button>
            </div>`;
            panel.style.display = 'block';
            return;
        }

        const groupsHtml = dupeGroups.map((group, gi) => {
            // Sort: most-used first, then largest file
            const sorted = [...group].sort((a, b) => {
                const ua = (mediaUsage[a.url] || []).length;
                const ub = (mediaUsage[b.url] || []).length;
                return ub - ua || b.size - a.size;
            });

            const cards = sorted.map((img, ii) => {
                const usages  = mediaUsage[img.url] || [];
                const uLabel  = usages.length ? usages.length + ' place' + (usages.length > 1 ? 's' : '') : 'Unused';
                const uColor  = usages.length ? '#15803d' : '#9ca3af';
                const action  = ii === 0
                    ? `<div style="font-size:.71rem;color:#1d4ed8;font-weight:600;margin-top:4px;">&#9733; Keep</div>`
                    : `<button class="btn btn-small btn-danger" style="margin-top:5px;width:100%;font-size:.72rem;" onclick="dupeDelete('${escHtml(img.filename)}',${gi})">Delete</button>`;
                return `<div style="min-width:110px;max-width:150px;">
                    <img src="../${escHtml(img.url)}" style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:4px;display:block;border:${ii===0?'2px solid #1d4ed8':'1px solid #e5e7eb'};">
                    <div style="font-size:.71rem;color:#374151;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(img.filename)}">${escHtml(img.filename.replace('.webp','').replace('.svg','').replace('.gif',''))}</div>
                    <div style="font-size:.69rem;color:#6b7280;">${img.width}&#215;${img.height} &middot; ${fmtSize(img.size)}</div>
                    <div style="font-size:.69rem;color:${uColor};">${uLabel}</div>
                    ${action}
                </div>`;
            }).join('');

            return `<div style="margin-bottom:12px;padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;">
                <div style="font-size:.8rem;font-weight:600;color:#92400e;margin-bottom:8px;">${group.length} similar images</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">${cards}</div>
            </div>`;
        }).join('');

        panel.innerHTML = `<div style="background:#fff7ed;border:1px solid #f59e0b;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-weight:600;color:#92400e;">&#9888; ${dupeGroups.length} duplicate group${dupeGroups.length > 1 ? 's' : ''} found &mdash; keep one, delete the rest</span>
                <button onclick="closeDupePanel()" style="background:none;border:none;cursor:pointer;color:#6b7280;font-size:1.1rem;line-height:1;">&#10005;</button>
            </div>
            ${groupsHtml}
        </div>`;
        panel.style.display = 'block';
    }

    /* ── focal point tool ── */
    let focalFilename = '';
    let focalX = 50, focalY = 50;

    window.openFocal = function(filename, url, fx, fy) {
        focalFilename = filename;
        focalX = fx != null ? fx : 50;
        focalY = fy != null ? fy : 50;
        const modal = document.getElementById('focal-modal');
        const img   = document.getElementById('focal-image');
        const dot   = document.getElementById('focal-dot');
        const info  = document.getElementById('focal-info');
        img.src = '';
        modal.style.display = 'block';
        img.onload = function() {
            dot.style.left = focalX + '%';
            dot.style.top  = focalY + '%';
            info.textContent = 'Left: ' + Math.round(focalX) + '%, Top: ' + Math.round(focalY) + '%';
        };
        img.src = url;
    };

    window.closeFocal = function() {
        document.getElementById('focal-modal').style.display = 'none';
        document.getElementById('focal-image').src = '';
        focalFilename = '';
    };

    window.clickFocal = async function(e) {
        const img  = document.getElementById('focal-image');
        const rect = img.getBoundingClientRect();
        focalX = Math.min(100, Math.max(0, ((e.clientX - rect.left) / rect.width)  * 100));
        focalY = Math.min(100, Math.max(0, ((e.clientY - rect.top)  / rect.height) * 100));
        const dot  = document.getElementById('focal-dot');
        const info = document.getElementById('focal-info');
        dot.style.left = focalX + '%';
        dot.style.top  = focalY + '%';
        info.textContent = 'Left: ' + Math.round(focalX) + '%, Top: ' + Math.round(focalY) + '%';
        const fd = new FormData();
        fd.append('action',   'focal');
        fd.append('filename', focalFilename);
        fd.append('focal_x',  focalX.toFixed(1));
        fd.append('focal_y',  focalY.toFixed(1));
        fd.append('csrf_token', CSRF_TOKEN);
        const res  = await fetch(api, { method:'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const idx = allMedia.findIndex(m => m.filename === focalFilename);
            if (idx !== -1) { allMedia[idx].focal_x = data.focal_x; allMedia[idx].focal_y = data.focal_y; }
            showToast('Focal point saved');
        } else {
            showToast('Error: ' + (data.error || 'save failed'));
        }
    };

    /* ── crop tool ── */
    let cropperInstance = null;
    let cropFilename    = '';

    window.openCropper = function(filename, url) {
        cropFilename = filename;
        const modal = document.getElementById('crop-modal');
        const img   = document.getElementById('crop-image');
        if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
        img.src = '';
        modal.style.display = 'block';
        img.onload = function() {
            cropperInstance = new Cropper(img, {
                viewMode: 1,
                autoCropArea: 1,
                responsive: true,
                checkCrossOrigin: false,
            });
        };
        img.src = url;
    };

    window.closeCropper = function() {
        document.getElementById('crop-modal').style.display = 'none';
        if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
        document.getElementById('crop-image').src = '';
        cropFilename = '';
    };

    window.setCropRatio = function(ratio) {
        if (cropperInstance) cropperInstance.setAspectRatio(ratio);
    };

    window.applyCrop = async function() {
        if (!cropperInstance || !cropFilename) return;
        const d  = cropperInstance.getData(true);
        const fd = new FormData();
        fd.append('action',   'crop');
        fd.append('filename', cropFilename);
        fd.append('x',        d.x);
        fd.append('y',        d.y);
        fd.append('width',    d.width);
        fd.append('height',   d.height);
        fd.append('csrf_token', CSRF_TOKEN);
        const btn = document.getElementById('crop-apply-btn');
        btn.disabled = true;
        btn.textContent = 'Saving…';
        const res  = await fetch(api, { method:'POST', body: fd });
        const data = await res.json();
        btn.disabled = false;
        btn.textContent = 'Apply Crop';
        if (data.success) {
            const idx = allMedia.findIndex(m => m.filename === cropFilename);
            if (idx !== -1) {
                allMedia[idx].width  = data.width;
                allMedia[idx].height = data.height;
                allMedia[idx].size   = data.size;
            }
            window.closeCropper();
            renderGrid();
            showToast('Cropped and saved');
        } else {
            showToast('Error: ' + (data.error || 'crop failed'));
        }
    };

    loadMedia();
    loadUsage();
})();
</script>

<!-- ===================== FOCAL POINT MODAL ===================== -->
<div id="focal-modal" style="display:none;position:fixed;inset:0;z-index:10001;background:rgba(0,0,0,0.85);overflow-y:auto;">
    <div style="background:#fff;max-width:860px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.4);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:1.1rem;">Set Focal Point</h2>
            <button onclick="closeFocal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:16px 20px;">
            <p style="margin:0 0 10px;font-size:.85rem;color:#6b7280;">Click the most important part of the image — the crop will stay centered on that point.</p>
            <div style="border-radius:4px;overflow:hidden;background:#111;max-height:58vh;overflow-y:auto;">
                <div id="focal-img-wrap" style="position:relative;cursor:crosshair;line-height:0;" onclick="clickFocal(event)">
                    <img id="focal-image" src="" style="width:100%;height:auto;display:block;">
                    <div id="focal-dot" style="position:absolute;left:50%;top:50%;width:22px;height:22px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 2px #fd783b,0 2px 8px rgba(0,0,0,0.6);transform:translate(-50%,-50%);pointer-events:none;transition:left .08s,top .08s;"></div>
                </div>
            </div>
            <div id="focal-info" style="margin-top:8px;font-size:.82rem;color:#6b7280;text-align:center;">Click image to set focal point</div>
        </div>
        <div style="padding:16px 20px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn" onclick="closeFocal()">Done</button>
        </div>
    </div>
</div>

<!-- ===================== CROP MODAL ===================== -->
<div id="crop-modal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.85);overflow-y:auto;">
    <div style="background:#fff;max-width:920px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.4);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:1.1rem;">Crop Image</h2>
            <button onclick="closeCropper()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:16px 20px;">
            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
                <span style="font-size:.85rem;color:#6b7280;margin-right:4px;">Aspect ratio:</span>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(NaN)">Free</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(16/9)">16:9</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(4/3)">4:3</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(3/2)">3:2</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(1)">1:1</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(9/16)">9:16</button>
            </div>
            <div style="max-height:520px;overflow:hidden;background:#111;border-radius:4px;">
                <img id="crop-image" src="" style="max-width:100%;display:block;">
            </div>
        </div>
        <div style="padding:16px 20px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn btn-secondary" onclick="closeCropper()">Cancel</button>
            <button id="crop-apply-btn" class="btn" onclick="applyCrop()">Apply Crop</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===================== IMAGE PICKER MODAL ===================== -->
<div id="img-picker-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.6);overflow-y:auto;">
    <div style="background:#fff;max-width:960px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.3);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:1.1rem;">Pick from Media Library</h2>
            <button onclick="closeImgPicker()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:16px 20px;">
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;">
                <input id="picker-search" type="text" placeholder="Search images…" style="flex:1;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <span id="picker-count" style="font-size:.85rem;color:#6b7280;white-space:nowrap;"></span>
            </div>
            <div id="picker-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;max-height:500px;overflow-y:auto;"></div>
        </div>
    </div>
</div>

<script>
(function() {
    let pickerCallback = null;
    let pickerMedia    = [];

    window.openImgPicker = function(callback) {
        pickerCallback = callback;
        document.getElementById('img-picker-modal').style.display = 'block';
        loadPickerMedia();
    };

    window.closeImgPicker = function() {
        document.getElementById('img-picker-modal').style.display = 'none';
        pickerCallback = null;
    };

    document.getElementById('img-picker-modal').addEventListener('click', function(e) {
        if (e.target === this) closeImgPicker();
    });

    document.getElementById('picker-search').addEventListener('input', function() {
        renderPickerGrid(this.value.toLowerCase());
    });

    async function loadPickerMedia() {
        const res   = await fetch('media_api.php?action=list');
        pickerMedia = await res.json();
        renderPickerGrid('');
    }

    function renderPickerGrid(q) {
        const items = pickerMedia.filter(m =>
            !q || m.filename.toLowerCase().includes(q) || (m.alt||'').toLowerCase().includes(q)
        );
        document.getElementById('picker-count').textContent = items.length + ' images';
        document.getElementById('picker-grid').innerHTML = items.map(m => `
            <div onclick="pickImage('${esc(m.url)}','${esc(m.alt||'')}')" style="cursor:pointer;border:2px solid transparent;border-radius:6px;overflow:hidden;background:#f9fafb;transition:border-color .15s;" onmouseover="this.style.borderColor='#fd783b'" onmouseout="this.style.borderColor='transparent'">
                <img src="../${esc(m.url)}" alt="${esc(m.alt||m.filename)}" style="width:100%;aspect-ratio:1;object-fit:cover;display:block;">
                <div style="padding:4px 6px;font-size:.7rem;color:#4b5563;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(m.filename.replace('.webp',''))}</div>
            </div>
        `).join('');
    }

    window.pickImage = function(url, alt) {
        if (pickerCallback) pickerCallback(url, alt);
        closeImgPicker();
    };

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
})();
</script>

</body>
</html>
