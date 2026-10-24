<?php
/**
 * includes/picdrop.php — enumerate every replaceable picture slot in a site.
 *
 * One "slot" is one image-valued field on one block on one page. The Pic Drop tab
 * renders these; picdrop_api.php reads and writes them. Nothing else in the factory
 * had a page-by-page view of images — media_api.php's `usage` action came closest
 * but only ever looked at site.json, so it missed every generated landing page.
 *
 * Slots are addressed by an opaque key, "scope:id:blockIndex:fieldPath":
 *
 *     home::0:hs_photo
 *     core:page_8845f40cd5d04:3:it_photo
 *     landing:tpl_attic_water_damage_city_lufkin_tx.json:1:hs_photo
 *     global:services_links:0:bg_photo
 *     template:tpl_attic_water_damage:1:hs_photo
 *
 * fieldPath may be dotted for fields nested in a repeater ("ts_tabs.2.photo").
 */

// For CC_IMAGE_REQUIRED_BLOCKS — Site Final Check already worked out which block
// types actually go visibly wrong with a blank photo field vs. which ones have a
// real designed fallback (repeater rows that just skip the image, hero types with
// a color background, etc). Reusing that constant here instead of a second,
// independently-maintained list is what keeps "missing" meaning the same thing in
// both tools.
require_once __DIR__ . '/multisite/content_check.php';

require_once __DIR__ . '/../config.php';

/**
 * Per-slot "real photo" vs "AI photo" memory — a sidecar file, not part of site.json,
 * because the render side (site-template.php) only ever needs ONE value per field
 * (whichever is active) and must never learn this structure exists.
 *
 * One entry per slot key: {real: path|null, ai: path|null, active: 'real'|'ai'}.
 * Dropping a file always writes+activates 'real'; using a generated image always
 * writes+activates 'ai'; switching without a new drop/generate just flips 'active'.
 * Whichever side has never been filled is simply absent from the row, so a slot
 * that has only ever had a real photo costs nothing extra to render.
 */
function picdrop_pairs_file(): string {
    return (ACTIVE_SITE_DIR !== '' ? ACTIVE_SITE_DIR : BASE_DIR) . '/data/picdrop_pairs.json';
}

function picdrop_pairs_load(): array {
    $f = picdrop_pairs_file();
    if (!file_exists($f)) return [];
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function picdrop_pairs_save(array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp  = picdrop_pairs_file() . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) !== false) {
        rename($tmp, picdrop_pairs_file());
    } else {
        @unlink($tmp);
    }
}

/**
 * Strip every pairs-sidecar entry belonging to a template (and the pages built
 * from it) that was just deleted outright — called from the Templates tab's
 * delete action, so the sidecar doesn't keep accumulating entries for slots that
 * can never be read again (picdrop_groups()/picdrop_template_groups() only ever
 * enumerate what currently exists, so a stale entry is inert, not harmful — this
 * is cleanup, not a correctness fix). Matches the template's own key exactly
 * (scope=template, id=$tplId) and every built page's key by prefix (scope=landing,
 * id starting with "{$tplId}_city_"), since one template can produce many pages.
 */
function picdrop_pairs_remove_for_template(string $tplId): int {
    $pairs   = picdrop_pairs_load();
    $removed = 0;
    foreach (array_keys($pairs) as $key) {
        $parts = picdrop_parse_key($key);
        if (!$parts) continue;
        $match = ($parts['scope'] === 'template' && $parts['id'] === $tplId)
              || ($parts['scope'] === 'landing' && strpos($parts['id'], $tplId . '_city_') === 0);
        if ($match) { unset($pairs[$key]); $removed++; }
    }
    if ($removed) picdrop_pairs_save($pairs);
    return $removed;
}

/** @return array{real:?string,ai:?string,active:string} */
function picdrop_pairs_get(string $key): array {
    $row = picdrop_pairs_load()[$key] ?? [];
    return [
        'real'   => $row['real']   ?? null,
        'ai'     => $row['ai']     ?? null,
        'active' => $row['active'] ?? 'real',
    ];
}

/**
 * Like picdrop_pairs_get(), but self-healing against the slot's actual live value.
 *
 * The pairs sidecar is only ever kept in sync by Pic Drop's OWN write actions
 * (place/generate/adjust/set_active). Anything else that writes the page field
 * directly — a manual templates.json edit, a batch regenerate, hand-editing site.json
 * — has no idea the sidecar exists, so the two can drift apart: the sidecar keeps
 * claiming "the active side is photo X" while the live field has long since moved on
 * to photo Y. Found live on mold-site: a template's hs_photo field held a generic
 * stock handshake photo while picdrop_pairs.json still said "real" was an actual
 * mold-wall photo from an earlier drop — so the tab's own "Real ✓" thumbnail showed
 * Scott the mold photo while every actual action (Generate's reference, the page
 * itself) was silently working from the stock photo instead. He had no way to know
 * the thumbnail he was looking at wasn't what was actually live.
 *
 * The live field is always ground truth — it's what the page renders and what every
 * action resolves its reference from. When the pair's ACTIVE side doesn't match it,
 * the active side is corrected to the live value and saved, so this is caught and
 * fixed the moment it's next read, from any entry point (the tab's own render, or any
 * single-slot write action, both of which resolve a slot through here). The INACTIVE
 * side is never touched — it has nothing to do with what's currently live and isn't
 * what drifted.
 */
function picdrop_pairs_resolve(string $key, string $value): array {
    $all = picdrop_pairs_load();
    $row = $all[$key] ?? null;
    if ($row === null) return ['real' => null, 'ai' => null, 'active' => 'real'];

    $active = ($row['active'] ?? 'real') === 'ai' ? 'ai' : 'real';
    if ($value !== '' && (string) ($row[$active] ?? '') !== $value) {
        $row[$active] = $value;
        $all[$key]    = $row;
        picdrop_pairs_save($all);
    }
    return [
        'real'   => $row['real']   ?? null,
        'ai'     => $row['ai']     ?? null,
        'active' => $active,
    ];
}

/**
 * The image fields Pic Drop manages, and the alt field each one pairs with.
 *
 * This is a WHITELIST, deliberately. A blacklist would silently pick up every new
 * image-ish key someone adds to a block — including ones that are not pictures at
 * all. The keys left out on purpose:
 *
 *   icon            excluded by request — the renderer puts it in a ~60px circular
 *                   badge, so a photograph dropped there looks broken
 *   logo, favicon,
 *   lb_logo         branding, owned by the Header and Gen-Visual tabs
 *   og_image        social-share only, never rendered on the page; SEO tab owns it
 *   featured_image  blog posts, which are not pages
 *   city_image      a site_vars token, not a block field
 *
 * `image` was excluded here too, under the belief it belonged to popups — it does
 * not: the info popup's image lives at $data['popups']['info']['image'], a
 * completely separate top-level structure Pic Drop's block-scanning functions
 * (picdrop_slots_for_blocks() and friends) never walk, so that exclusion protected
 * nothing. What it actually did was hide the real per-item photo used by cards,
 * steps, feature_columns, and logo_bar (each repeater row's own `image` key) from
 * Pic Drop entirely. Enabled below with the shared 'alt' sibling key every one of
 * those four repeaters already uses.
 *
 * Note the alt keys are NOT uniformly "<field>_alt" — image_text pairs it_photo with
 * it_alt. Assuming the pattern would have written a field the renderer never reads.
 */
function picdrop_fields(): array {
    return [
        'hs_photo'      => ['label' => 'Hero photo',      'alt' => 'hs_photo_alt', 'kind' => 'photo'],
        'hs_bg_photo'   => ['label' => 'Hero background', 'alt' => null,           'kind' => 'background'],
        'it_photo'      => ['label' => 'Image & text',    'alt' => 'it_alt',       'kind' => 'photo'],
        'fs_photo'      => ['label' => 'Feature split',   'alt' => 'fs_photo_alt', 'kind' => 'photo'],
        'hg_photo'      => ['label' => 'Hero grid',       'alt' => 'hg_photo_alt', 'kind' => 'photo'],
        'wb_photo'      => ['label' => 'Wide banner',     'alt' => 'wb_photo_alt', 'kind' => 'photo'],
        'if_photo'      => ['label' => 'Image features',  'alt' => 'if_photo_alt', 'kind' => 'photo'],
        'lg_photo'      => ['label' => 'Links grid',      'alt' => 'lg_photo_alt', 'kind' => 'photo'],
        'mi_info_photo' => ['label' => 'Map & info',      'alt' => 'mi_info_alt',  'kind' => 'photo'],
        'mi_photo'      => ['label' => 'Map photo',       'alt' => 'mi_info_alt',  'kind' => 'photo'],
        'photo'         => ['label' => 'Tab photo',       'alt' => 'photo_alt',    'kind' => 'photo'],
        'bg_photo'      => ['label' => 'Background',      'alt' => null,           'kind' => 'background'],
        'image'         => ['label' => 'Item photo',      'alt' => 'alt',          'kind' => 'photo'],
    ];
}

/* Human label for a block type — same vocabulary media_api.php's `usage` action uses. */
function picdrop_block_label(string $type): string {
    static $labels = [
        'hero'            => 'Hero Banner',    'hero_split'     => 'Hero Split',
        'feature_split'   => 'Feature Split',  'image_features' => 'Image Features',
        'wide_banner'     => 'Wide Banner',    'hero_grid'      => 'Hero Grid',
        'tab_services'    => 'Tab Services',   'service_cards'  => 'Service Cards',
        'image_left'      => 'Image Left',     'image_right'    => 'Image Right',
        'feature_columns' => 'Feature Cols',   'steps'          => 'Steps',
        'cards'           => 'Cards',          'gallery'        => 'Gallery',
        'map_info'        => 'Map & Info',     'links_grid'     => 'Links Grid',
        'image_text'      => 'Image & Text',   'cta_card'       => 'CTA Card',
        'split_cta'       => 'Split CTA',      'stats'          => 'Stats',
    ];
    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

/**
 * How this slot actually crops, in plain English — for the person dropping the photo,
 * not the person reading the CSS. Keyed by "block_type.leaf_field" first (a few block
 * types have more than one photo field with different behavior), falling back to just
 * block_type, then to a generic default that's still true of every slot on the site:
 * there is no separate mobile crop anywhere, only a smaller version of the same one.
 *
 * Source of truth is style.src.css + the render_content_block() case for each type —
 * if either changes, this table can drift out of date the same way it could describe
 * the wrong thing today; it is a hint, not a computed fact.
 */
function picdrop_crop_hint(string $blockType, string $field): string {
    static $hints = [
        'hero.hero_bg_image'        => 'Full-width background — cropped to fill the hero, same shape on mobile and desktop.',
        'hero_split.hs_photo'       => 'Fixed 4:3 box — cropped the same on mobile and desktop, just shown smaller. Keep the subject centered, especially top/bottom.',
        'hero_split.hs_bg_photo'    => 'Background photo — box height follows the surrounding text, shrinks further on mobile. Center the subject vertically.',
        'feature_split.fs_photo'    => 'Fixed 4:3 box — same crop on mobile and desktop.',
        'image_features.if_photo'   => 'Fixed 4:3 box — same crop on mobile and desktop.',
        'hero_grid.hg_photo'        => 'Background panel — fixed height (480px desktop, 300px mobile), so mobile shows a shorter slice of the same photo. Keep the subject vertically centered.',
        'wide_banner.wb_photo'      => 'Background photo — has a height floor now, but still grows with the heading/subtext length. Center the subject vertically.',
        'tab_services.photo'        => 'Fixed-height strip (420px desktop, 240px mobile) — keep the subject centered.',
        'map_info.mi_info_photo'    => 'Capped at 320px tall, no locked ratio — the most forgiving photo slot on the site.',
        'gallery.photo'             => 'Fixed 4:3 grid tile — same crop on mobile and desktop.',
        'image_left.photo'          => 'Ratio set by this block\'s own Photo Ratio field — same crop on mobile and desktop.',
        'image_right.photo'         => 'Ratio set by this block\'s own Photo Ratio field — same crop on mobile and desktop.',
        'image_text.it_photo'       => 'Ratio set by this block\'s own Photo Ratio field — same crop on mobile and desktop.',
    ];
    $key = $blockType . '.' . $field;
    if (isset($hints[$key])) return $hints[$key];
    return 'Cropped to fill its box — same shape on mobile and desktop, just shown smaller.';
}

/**
 * No Pic Drop-managed slot is ever meant to be this small in both directions (the
 * most forgiving documented slot, map_info.mi_info_photo, only caps height at 320 —
 * width is never constrained this tight). Below it, the slot's current size is not a
 * real target, it's a stray thumbnail that got into the field some other way.
 */
const PICDROP_MIN_SANE_DIM = 150;

/**
 * The target box to fit/crop a new or adjusted image into, derived from the slot's
 * own current size — EXCEPT when that size is too small in both directions to be a
 * real photo slot. Trusting it anyway is how one bad seed file (a 90×90 placeholder
 * from outside Pic Drop) kept re-squashing every later replacement down to 90×90
 * forever: "self-configuring" only works when what's already there is correct.
 *
 * [0, 0] tells the caller "no sane target", which img_fit_to() already treats as
 * "optimise only, don't force a box" — the same escape hatch it uses for a slot with
 * no size at all, reused here rather than inventing a second one.
 */
function picdrop_slot_target(array $slot): array {
    $w = (int) $slot['w'];
    $h = (int) $slot['h'];
    if ($w < PICDROP_MIN_SANE_DIM && $h < PICDROP_MIN_SANE_DIM) return [0, 0];
    return [$w, $h];
}

/**
 * Turn a stored image value into an absolute filesystem path, or null.
 *
 * Stored values are webroot-relative ("sites/water-site/uploads/media/x.webp" is the
 * common form; a few legacy ones are "uploads/media/x.webp"). Anything absolute, or
 * carrying a {token}, belongs to another system and is not ours to resolve.
 */
function picdrop_resolve(string $value): ?string {
    $v = trim($value);
    if ($v === '' || str_contains($v, '{') || preg_match('#^(https?:)?//#i', $v)) return null;
    $v = ltrim($v, '/');
    if (str_contains($v, '..')) return null;

    $candidates = [BASE_DIR . '/' . $v];
    if (str_starts_with($v, 'uploads/') && ACTIVE_SITE_DIR !== '') {
        $candidates[] = ACTIVE_SITE_DIR . '/' . $v;
    }
    foreach ($candidates as $p) {
        if (is_file($p)) return $p;
    }
    return null;
}

/* Read a dotted path out of a block. */
function picdrop_get(array $block, string $path) {
    $cur = $block;
    foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) $cur = $cur[$seg];
        else return null;
    }
    return $cur;
}

/* Write a dotted path into a block, in place. Returns false if the path does not exist. */
function picdrop_set(array &$block, string $path, $value): bool {
    $segs = explode('.', $path);
    $last = array_pop($segs);
    $cur  = &$block;
    foreach ($segs as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) return false;
        $cur = &$cur[$seg];
    }
    if (!is_array($cur)) return false;
    $cur[$last] = $value;
    return true;
}

/* The leaf key of a dotted path — "ts_tabs.2.photo" → "photo". */
function picdrop_leaf(string $path): string {
    $segs = explode('.', $path);
    return (string) end($segs);
}

/**
 * Lists whose items pair a `photo` with a plain `alt`, not the `photo_alt` that the
 * same leaf means everywhere else (block-level photo, team_members). Keyed by the
 * list's own field name, which is what tells them apart - the leaf alone can't.
 * Without this, Pic Drop's alt box on a home tab or gallery image wrote photo_alt,
 * which blocks.php never reads (ts_tabs renders $tab['alt'] ?? label, gallery
 * $img['alt']), so a typed alt was silently thrown away.
 */
const PICDROP_LIST_ALT_KEYS = ['ts_tabs' => 'alt', 'gallery_images' => 'alt'];

/* Sibling alt path for an image path: "hero.photo" + "photo_alt" → "hero.photo_alt";
   "ts_tabs.2.photo" → "ts_tabs.2.alt" (see PICDROP_LIST_ALT_KEYS). */
function picdrop_alt_path(string $path, ?string $altKey): ?string {
    if ($altKey === null) return null;
    $segs = explode('.', $path);
    array_pop($segs);
    $n = count($segs);
    if ($n >= 2 && ctype_digit($segs[$n - 1]) && isset(PICDROP_LIST_ALT_KEYS[$segs[$n - 2]])) {
        $altKey = PICDROP_LIST_ALT_KEYS[$segs[$n - 2]];
    }
    $segs[] = $altKey;
    return implode('.', $segs);
}

/**
 * Every managed image field on one block, as dotted paths.
 * Recurses one level into list-of-dicts repeaters (ts_tabs, sc_items, …).
 */
function picdrop_block_paths(array $block): array {
    $fields = picdrop_fields();
    $out    = [];

    foreach ($block as $k => $v) {
        if (is_string($v) && isset($fields[$k])) {
            $out[] = $k;
        } elseif (is_array($v)) {
            foreach ($v as $i => $row) {
                if (!is_array($row)) continue;
                foreach ($row as $rk => $rv) {
                    if (is_string($rv) && isset($fields[$rk])) $out[] = "$k.$i.$rk";
                }
            }
        }
    }
    return $out;
}

/* Build the slot descriptors for one array of blocks. */
function picdrop_slots_for_blocks(array $blocks, string $scope, string $id): array {
    $fields     = picdrop_fields();
    $slots      = [];
    $blockTotal = count($blocks);

    foreach ($blocks as $bi => $block) {
        if (!is_array($block)) continue;
        $type = (string) ($block['type'] ?? 'block');

        foreach (picdrop_block_paths($block) as $path) {
            $leaf  = picdrop_leaf($path);
            $spec  = $fields[$leaf];
            $value = (string) picdrop_get($block, $path);

            // Two block types this common enough to name explicitly ("Ai Block",
            // "Steps") carry no clue about WHERE on the page they sit or WHICH one
            // they are when a page has more than one — Scott had to ask which of two
            // "Ai Block · Image & text" rows was which. $blockPos/$blockTotal place it
            // on the page; $blockHint adds a human-readable distinguisher: which
            // repeater row ("Item 2 of 3") or, for an ai_block, its ai_type_id (the
            // one thing that already names what content it holds, e.g. "city_intro").
            $blockPos  = $bi + 1;
            $blockHint = '';
            $pathParts = explode('.', $path);
            if (count($pathParts) === 3) {
                $repKey   = $pathParts[0];
                $rowIdx   = (int) $pathParts[1];
                $rowTotal = is_array($block[$repKey] ?? null) ? count($block[$repKey]) : $rowIdx + 1;
                $blockHint = 'Item ' . ($rowIdx + 1) . ' of ' . $rowTotal;
            } elseif ($type === 'ai_block' && (string) ($block['ai_type_id'] ?? '') !== '') {
                $blockHint = (string) $block['ai_type_id'];
            }

            // A {token} value is not a picture — map_info's mi_info_photo is normally
            // "{city_image}", which the City Image plugin fills per city at render time.
            // Overwriting it with a file would silently pin every city to one photo, so
            // these are surfaced as locked and the API refuses to write them.
            $isToken = str_contains($value, '{');

            $file = picdrop_resolve($value);
            $w = $h = 0; $bytes = 0;
            if ($file !== null) {
                [$w, $h] = @getimagesize($file) ?: [0, 0];
                $bytes   = (int) @filesize($file);
            }

            $altPath = picdrop_alt_path($path, $spec['alt']);
            $altVal  = $altPath !== null ? (string) (picdrop_get($block, $altPath) ?? '') : '';

            // An 'ai_block' is a placeholder — render_content_block() proxies it as
            // whatever ai_render_as says once generated (see the 'ai_block' case in
            // blocks.php), so that's the type CC_IMAGE_REQUIRED_BLOCKS needs to match
            // against, not the literal 'ai_block' string.
            $effType    = $type === 'ai_block' ? (string) ($block['ai_render_as'] ?? '') : $type;
            $isRequired = !$isToken && (CC_IMAGE_REQUIRED_BLOCKS[$effType] ?? null) === $leaf;
            $needsPhoto = $isRequired && ($value === '' || $file === null);

            $slotKey = "$scope:$id:$bi:$path";
            $slots[] = [
                'key'        => $slotKey,
                'scope'      => $scope,
                'page_id'    => $id,
                'block'      => $bi,
                'field'      => $path,
                'block_type' => $type,
                // Stable identity for this exact block (see ensure_block_ids(),
                // includes/layout_variations.php), NOT the index $bi — home-page section
                // order can rotate per domain (structure.home) but this survives it, since
                // rotation moves whole blocks and never rebuilds them. Empty for a block
                // that predates the id scheme; ms_image_ai_resolve_block() falls back to
                // type+field matching in that case.
                'block_id'   => (string) ($block['id'] ?? ''),
                'block_label'=> picdrop_block_label($type),
                'block_pos'  => $blockPos,
                'block_total'=> $blockTotal,
                'block_hint' => $blockHint,
                'label'      => $spec['label'],
                'kind'       => $spec['kind'],
                'value'      => $value,
                'exists'     => $file !== null,
                'token'      => $isToken,
                'needs_photo'=> $needsPhoto,
                'w'          => (int) $w,
                'h'          => (int) $h,
                'bytes'      => $bytes,
                'alt_field'  => $altPath,
                'alt'        => $altVal,
                'pair'       => $isToken ? null : picdrop_pairs_resolve($slotKey, $value),
            ];
        }
    }
    return $slots;
}

/**
 * One slot, by key — reads only the ONE file that key lives in, not the whole site.
 *
 * picdrop_api.php used to answer every single click (place, generate, switch, ...) by
 * calling picdrop_groups(), which reads site.json AND every landing-page file on disk
 * — dozens of files, for a site with any real number of pages — just to find the one
 * slot the click was about. This is what that lookup should have been from the start:
 * the key already says which scope/page/block/field it is, so go straight there.
 *
 * picdrop_groups() still exists for the tab's own page render, which legitimately
 * needs everything at once to draw the whole list.
 */
function picdrop_find_slot(string $key): ?array {
    $parts = picdrop_parse_key($key);
    if ($parts === null) return null;

    switch ($parts['scope']) {
        case 'global':
            $site = file_exists(DATA_FILE) ? (json_decode((string) file_get_contents(DATA_FILE), true) ?: []) : [];
            $sl = $site['services_links'] ?? null;
            $blocks = is_array($sl) ? [$sl + ['type' => 'links_grid']] : [];
            break;
        case 'home':
            $site = file_exists(DATA_FILE) ? (json_decode((string) file_get_contents(DATA_FILE), true) ?: []) : [];
            $blocks = $site['content_blocks'] ?? [];
            break;
        case 'core':
            $site = file_exists(DATA_FILE) ? (json_decode((string) file_get_contents(DATA_FILE), true) ?: []) : [];
            $blocks = $site['pages'][$parts['id']]['content_blocks'] ?? [];
            break;
        case 'landing':
            $f = defined('PAGES_DIR') ? PAGES_DIR . basename($parts['id']) : null;
            $page = $f !== null ? json_decode((string) @file_get_contents($f), true) : null;
            $blocks = is_array($page) ? ($page['content_blocks'] ?? []) : [];
            break;
        case 'template':
            $tpls = defined('TEMPLATES_FILE') && is_file(TEMPLATES_FILE)
                ? (json_decode((string) @file_get_contents(TEMPLATES_FILE), true) ?: [])
                : [];
            $blocks = [];
            foreach ($tpls as $t) {
                if (is_array($t) && ($t['id'] ?? '') === $parts['id']) { $blocks = $t['content_blocks'] ?? []; break; }
            }
            break;
        default:
            return null;
    }

    foreach (picdrop_slots_for_blocks($blocks, $parts['scope'], $parts['id']) as $s) {
        if ($s['key'] === $key) return $s;
    }
    return null;
}

/**
 * Every page in the site, in display order, each with its picture slots.
 *
 * Order is Site-wide → Home → Core → Landing, which is how they nest conceptually
 * and matches how they are edited elsewhere in the admin. Used by the tab's own page
 * render, which needs everything; a single write only ever needs picdrop_find_slot().
 */
function picdrop_groups(): array {
    $site = file_exists(DATA_FILE)
        ? (json_decode((string) file_get_contents(DATA_FILE), true) ?: [])
        : [];

    $groups = [];

    // ── Site-wide: blocks stored outside content_blocks that render on every page.
    $sl = $site['services_links'] ?? null;
    if (is_array($sl)) {
        $slots = picdrop_slots_for_blocks([$sl + ['type' => 'links_grid']], 'global', 'services_links');
        if ($slots) {
            $groups[] = [
                'scope' => 'global', 'id' => 'services_links',
                'title' => 'Services Links', 'sub' => 'Site-wide — appears on every page',
                'slots' => $slots,
            ];
        }
    }

    // ── Home
    $groups[] = [
        'scope' => 'home', 'id' => '',
        'title' => 'Home', 'sub' => 'Homepage',
        'slots' => picdrop_slots_for_blocks($site['content_blocks'] ?? [], 'home', ''),
    ];

    // ── Core pages (stored inside site.json under "pages")
    foreach (($site['pages'] ?? []) as $pid => $page) {
        if (!is_array($page)) continue;
        $groups[] = [
            'scope' => 'core', 'id' => (string) $pid,
            'title' => (string) ($page['title'] ?: $pid),
            'sub'   => 'Core page · /' . ltrim((string) ($page['slug'] ?? ''), '/'),
            'slots' => picdrop_slots_for_blocks($page['content_blocks'] ?? [], 'core', (string) $pid),
        ];
    }

    // ── Landing pages (one file each under data/pages/)
    $files = defined('PAGES_DIR') ? (glob(PAGES_DIR . '*.json') ?: []) : [];
    sort($files, SORT_NATURAL);
    foreach ($files as $f) {
        $page = json_decode((string) @file_get_contents($f), true);
        if (!is_array($page)) continue;
        $fn = basename($f);
        $groups[] = [
            'scope' => 'landing', 'id' => $fn,
            'title' => (string) ($page['title'] ?: $fn),
            'sub'   => 'Landing page · /' . ltrim((string) ($page['slug'] ?? ''), '/'),
            'slots' => picdrop_slots_for_blocks($page['content_blocks'] ?? [], 'landing', $fn),
        ];
    }

    return $groups;
}

/**
 * One group per landing-page TEMPLATE, each with its own photo slots — the
 * template-level counterpart to picdrop_groups()'s per-PAGE list. A site with many
 * cities can have dozens of already-built pages per template; managing a photo one
 * page at a time does not scale. This lets Pic Drop show ONE row per template
 * instead, using the exact same drop/library/AI-generate widget a page row already
 * uses (nothing about that mechanism changes — only what it's pointed at).
 */
function picdrop_template_groups(): array {
    if (!defined('TEMPLATES_FILE') || !is_file(TEMPLATES_FILE)) return [];
    $tpls = json_decode((string) @file_get_contents(TEMPLATES_FILE), true);
    if (!is_array($tpls)) return [];

    $groups = [];
    foreach ($tpls as $t) {
        if (!is_array($t) || ($t['id'] ?? '') === '') continue;
        $groups[] = [
            'scope' => 'template', 'id' => (string) $t['id'],
            'title' => (string) ($t['title'] ?: $t['id']),
            'sub'   => 'Landing template',
            'slots' => picdrop_slots_for_blocks($t['content_blocks'] ?? [], 'template', (string) $t['id']),
        ];
    }
    return $groups;
}

/**
 * Write JSON back to a file KEEPING THE FILE'S EXISTING INDENT.
 *
 * This matters more than it looks. PHP's JSON_PRETTY_PRINT always emits 4 spaces,
 * but water-site's site.json is stored at 2 (generate.py wrote it) while
 * appliance-site's is at 4. Re-encoding with the wrong one reformats every line, so
 * a one-field image swap comes back as a several-thousand-line diff with the real
 * change buried inside it. We have lost review time to exactly this before.
 *
 * Re-indenting by regex is safe here because pretty-printed JSON only ever indents
 * in multiples of 4, and any newline inside a string value is escaped as \n — so a
 * line can never begin with whitespace that belongs to the data.
 */
function picdrop_json_write(string $path, array $data): bool {
    $indent = 4;
    if (is_file($path)) {
        $head = (string) @file_get_contents($path, false, null, 0, 4096);
        if (preg_match('/\n( +)\S/', $head, $m)) $indent = strlen($m[1]);
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;

    if ($indent !== 4) {
        $json = preg_replace_callback(
            '/^( +)/m',
            static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 4) * $indent),
            $json
        );
    }

    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) { @unlink($tmp); return false; }
    if (!rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/* The file that backs a given scope, and the JSON path to its block array. */
function picdrop_scope_file(string $scope, string $id): ?string {
    return match ($scope) {
        'global', 'home', 'core' => DATA_FILE,
        'landing'                => defined('PAGES_DIR') ? PAGES_DIR . basename($id) : null,
        'template'               => defined('TEMPLATES_FILE') ? TEMPLATES_FILE : null,
        default                  => null,
    };
}

/**
 * Apply a batch of field edits and save each touched file exactly once.
 *
 * $edits is a list of ['scope','id','block','field','value']. Grouping by file first
 * means a propagate across 150 landing pages is 150 writes rather than 150 × fields,
 * and a home + core edit in the same call cannot clobber each other by both loading
 * site.json, mutating, and saving in sequence.
 *
 * Returns ['ok' => n, 'errors' => [...]].
 */
function picdrop_apply_edits(array $edits): array {
    $byFile = [];
    foreach ($edits as $e) {
        $file = picdrop_scope_file($e['scope'], $e['id']);
        if ($file === null) continue;
        $byFile[$file][] = $e;
    }

    $ok = 0; $errors = [];

    foreach ($byFile as $file => $fileEdits) {
        if (!is_file($file)) { $errors[] = basename($file) . ': not found'; continue; }
        $doc = json_decode((string) file_get_contents($file), true);
        if (!is_array($doc)) { $errors[] = basename($file) . ': unparseable'; continue; }

        $dirty = false;
        // Counted per-file, added to $ok only once the write below actually succeeds
        // — picdrop_set() below only mutates $doc in memory. Counting straight into
        // $ok there let a failed picdrop_json_write() (permission issue, disk full,
        // a losing race) still report success: the in-memory edit "worked," so $ok
        // was already > 0 by the time the write was even attempted, and pd_commit()
        // (admin/picdrop_api.php) treats any ok > 0 as full success.
        $fileOk = 0;
        foreach ($fileEdits as $e) {
            // Two kinds of edit. A block edit addresses one block inside a document;
            // a ROOT edit ($e['root']) addresses the document itself, which is how
            // seo.og_image is reached — it hangs off the page, not off any block.
            $root = !empty($e['root']);

            // Locate the container this edit lives in, by reference.
            if ($e['scope'] === 'global') {
                if (!isset($doc[$e['id']]) || !is_array($doc[$e['id']])) { $errors[] = $e['id'] . ': missing'; continue; }
                $target = &$doc[$e['id']];
            } elseif ($e['scope'] === 'home') {
                if ($root) { $target = &$doc; }
                elseif (!isset($doc['content_blocks'][$e['block']])) { $errors[] = 'home block ' . $e['block'] . ': out of range'; continue; }
                else { $target = &$doc['content_blocks'][$e['block']]; }
            } elseif ($e['scope'] === 'core') {
                if (!isset($doc['pages'][$e['id']])) { $errors[] = $e['id'] . ': no such core page'; continue; }
                if ($root) { $target = &$doc['pages'][$e['id']]; }
                elseif (!isset($doc['pages'][$e['id']]['content_blocks'][$e['block']])) { $errors[] = $e['id'] . ' block ' . $e['block'] . ': out of range'; continue; }
                else { $target = &$doc['pages'][$e['id']]['content_blocks'][$e['block']]; }
            } elseif ($e['scope'] === 'template') {
                // templates.json is a LIST. Address by template id, never by position —
                // adding or removing a template would silently repoint an index.
                $ti = null;
                foreach ($doc as $i => $t) { if (is_array($t) && ($t['id'] ?? '') === $e['id']) { $ti = $i; break; } }
                if ($ti === null) { $errors[] = 'template ' . $e['id'] . ': not found'; continue; }
                if ($root) { $target = &$doc[$ti]; }
                elseif (!isset($doc[$ti]['content_blocks'][$e['block']])) { $errors[] = 'template ' . $e['id'] . ' block ' . $e['block'] . ': out of range'; continue; }
                else { $target = &$doc[$ti]['content_blocks'][$e['block']]; }
            } else {
                if ($root) { $target = &$doc; }
                elseif (!isset($doc['content_blocks'][$e['block']])) { $errors[] = basename($file) . ' block ' . $e['block'] . ': out of range'; continue; }
                else { $target = &$doc['content_blocks'][$e['block']]; }
            }

            if (picdrop_set($target, $e['field'], $e['value'])) { $dirty = true; $fileOk++; }
            else $errors[] = basename($file) . ': field ' . $e['field'] . ' not present'
                           . ($root ? ' on ' . ($e['id'] ?: 'the document') : ' on block ' . $e['block']);

            unset($target);
        }

        if ($dirty) {
            if (picdrop_json_write($file, $doc)) {
                $ok += $fileOk;
            } else {
                $errors[] = basename($file) . ': could not be written';
            }
        }
    }

    return ['ok' => $ok, 'errors' => $errors];
}

/**
 * Every OTHER slot in the site holding the same image in the same kind of field.
 *
 * This is what "apply everywhere" means: the 153 appliance landing pages are template
 * clones, so one cooktop hero is usually the same file on every cooktop page. Matching
 * on value AND leaf field keeps a hero swap from also rewriting a body image that
 * happens to reuse the same picture.
 */
function picdrop_matching_slots(string $excludeKey, string $value, string $leaf): array {
    if (trim($value) === '') return [];
    $out = [];
    foreach (picdrop_groups() as $g) {
        foreach ($g['slots'] as $s) {
            if ($s['key'] === $excludeKey) continue;
            if ($s['value'] === $value && picdrop_leaf($s['field']) === $leaf) {
                $out[] = $s + ['page_title' => $g['title']];
            }
        }
    }
    return $out;
}

/**
 * Landing-template slots holding the same image in the same kind of field.
 *
 * Pic Drop edits PAGES; the templates in data/templates.json are what pages are
 * generated FROM. Replace a hero on three landing pages and the template still holds
 * the old file, so the next regen of any of them quietly puts it back. Anyone who then
 * looked would conclude Pic Drop had not saved.
 *
 * Returned as ready-made edits rather than slots — templates are not pages and have no
 * row in the tab; they only ever ride along with a propagate.
 */
function picdrop_template_matches(string $value, string $leaf): array {
    if (trim($value) === '' || !defined('TEMPLATES_FILE') || !is_file(TEMPLATES_FILE)) return [];
    $tpls = json_decode((string) @file_get_contents(TEMPLATES_FILE), true);
    if (!is_array($tpls)) return [];

    $out = [];
    foreach ($tpls as $t) {
        if (!is_array($t) || ($t['id'] ?? '') === '') continue;
        foreach (($t['content_blocks'] ?? []) as $bi => $block) {
            if (!is_array($block)) continue;
            foreach (picdrop_block_paths($block) as $path) {
                if (picdrop_leaf($path) !== $leaf) continue;
                if ((string) picdrop_get($block, $path) !== $value) continue;
                $out[] = ['scope' => 'template', 'id' => (string) $t['id'], 'block' => $bi, 'field' => $path];
            }
        }
    }
    return $out;
}

/**
 * Documents whose seo.og_image is the very file being replaced.
 *
 * og_image is deliberately NOT a Pic Drop slot — it is never rendered on the page and
 * the SEO tab owns it. But when it points at the same file as the picture being
 * swapped, it was tracking that picture, and leaving it behind means the social
 * preview keeps showing an image that is no longer anywhere on the page. When it
 * points at something else it is an independent choice and is left alone.
 *
 * Scanned across home, core pages, landing pages and templates. Returns root edits.
 */
function picdrop_og_matches(string $value): array {
    if (trim($value) === '') return [];
    $out = [];

    $site = file_exists(DATA_FILE) ? (json_decode((string) file_get_contents(DATA_FILE), true) ?: []) : [];
    if ((string) ($site['seo']['og_image'] ?? '') === $value) {
        $out[] = ['scope' => 'home', 'id' => '', 'block' => 0, 'field' => 'seo.og_image', 'root' => true];
    }
    foreach (($site['pages'] ?? []) as $pid => $page) {
        if (is_array($page) && (string) ($page['seo']['og_image'] ?? '') === $value) {
            $out[] = ['scope' => 'core', 'id' => (string) $pid, 'block' => 0, 'field' => 'seo.og_image', 'root' => true];
        }
    }
    foreach ((defined('PAGES_DIR') ? (glob(PAGES_DIR . '*.json') ?: []) : []) as $f) {
        $page = json_decode((string) @file_get_contents($f), true);
        if (is_array($page) && (string) ($page['seo']['og_image'] ?? '') === $value) {
            $out[] = ['scope' => 'landing', 'id' => basename($f), 'block' => 0, 'field' => 'seo.og_image', 'root' => true];
        }
    }
    if (defined('TEMPLATES_FILE') && is_file(TEMPLATES_FILE)) {
        foreach ((array) json_decode((string) @file_get_contents(TEMPLATES_FILE), true) as $t) {
            if (is_array($t) && ($t['id'] ?? '') !== '' && (string) ($t['seo']['og_image'] ?? '') === $value) {
                $out[] = ['scope' => 'template', 'id' => (string) $t['id'], 'block' => 0, 'field' => 'seo.og_image', 'root' => true];
            }
        }
    }
    return $out;
}

/* Split a slot key back into its four parts, or null if it is malformed. */
function picdrop_parse_key(string $key): ?array {
    $parts = explode(':', $key, 4);
    if (count($parts) !== 4) return null;
    [$scope, $id, $bi, $path] = $parts;

    if (!in_array($scope, ['global', 'home', 'core', 'landing', 'template'], true)) return null;
    if (!preg_match('/^\d+$/', $bi)) return null;
    if (!preg_match('/^[a-z0-9_]+(\.\d+\.[a-z0-9_]+)?$/i', $path)) return null;
    if (!isset(picdrop_fields()[picdrop_leaf($path)])) return null;
    if ($scope === 'landing' && !preg_match('/^[a-z0-9_\-]+\.json$/i', $id)) return null;
    if ($scope === 'core' && !preg_match('/^[a-z0-9_\-]+$/i', $id)) return null;
    if ($scope === 'template' && !preg_match('/^[a-z0-9_\-]+$/i', $id)) return null;

    return ['scope' => $scope, 'id' => $id, 'block' => (int) $bi, 'field' => $path];
}

/**
 * Default prompt for Pic Drop's "Subtle variation" mode.
 *
 * Names nothing that might be in the photo. Every earlier wording said what to
 * leave alone - "do not change the equipment or the person" - and on a reference
 * with no equipment and no person (a plain house exterior) the image model read
 * those nouns as things to draw: it came back with a technician, drying machines
 * and a van. Image models act on the nouns they are given and handle "do not"
 * poorly, so this mode describes only the change (the viewpoint) and nothing else.
 */
function picdrop_vary_prompt(): string {
    return 'Same photo, taken again from a very slightly different camera position. '
         . 'Everything in it stays exactly as it is and nothing new is added — only the viewpoint '
         . 'shifts a little, just enough that it reads as a distinct photo from the original. '
         . 'Nothing already in frame should end up cropped off at the edges. '
         . 'No visible text or logos.';
}

/**
 * "No technician, no equipment" mode's base prompt — names nothing specific in the
 * scene, same reasoning as picdrop_vary_prompt(). Unlike "notech" (which keeps the
 * equipment and can safely name it via $subjectDesc, since naming something you want
 * KEPT reinforces that), this mode's whole point is REMOVING the equipment — naming it
 * here would be asking the model to both remove it and keep it in the same prompt.
 */
function picdrop_bare_scene_prompt(): string {
    return 'Similar picture of the same location, with only a very subtle change in camera '
         . 'angle. Remove any technician or other person, AND all equipment, tools, and '
         . 'vehicles, from the scene entirely — show only the plain, empty surroundings, as if '
         . 'no job were in progress and no one were present. Wide or medium-wide shot. '
         . 'Bright, natural daylight. No visible text or logos.';
}

/**
 * "Subtle variation + background color" mode's base prompt — picdrop_vary_prompt()
 * with one change, but NOT a clause bolted onto that text: the plain "vary" prompt's
 * whole point is "everything stays exactly as it is and nothing new is added", which
 * would directly contradict an instruction to recolor the background appended after
 * it. This is its own prompt that says so up front instead.
 */
function picdrop_vary_bg_prompt(): string {
    return 'Same photo, taken again from a very slightly different camera position, with the '
         . 'background changed to a different color or setting. Everything else — the subject, '
         . 'equipment, and composition — stays exactly as it is; only the viewpoint shifts a '
         . 'little and the background changes. Nothing already in frame should end up cropped '
         . 'off at the edges. No visible text or logos.';
}

/**
 * "Technician with appliance & customer" mode (6.5) — Scott's own prompt. Originally
 * named race/gender/age as alternatives for "a different technician"; revised
 * 2026-10-24 to ask for a similar-looking technician with just a different
 * hairstyle and face instead — same approach mode 4 ("Different person",
 * $aiPromptNewPerson above) already uses, and it drops the demographic list
 * entirely rather than needing one resolved out of it.
 *
 * Falls through picdrop_reference_prompt()'s default branch (same as "tech") rather
 * than getting its own case — the prompt already fully describes the one change
 * (a similar technician, plus a customer now in frame), so the shared "keep this
 * close to the reference" suffix is exactly what's needed, nothing extra.
 */
function picdrop_tech_customer_prompt(): string {
    // Picked at random once per page render (same timing as every other mode's prompt
    // text) purely to vary the homeowner's clothing across generations — otherwise the
    // model tends to default to a similar outfit every time. Clothing only, no
    // demographic specifics — see feedback_no_demographic_specification_in_image_prompts.
    $customerOutfits = [
        'a light gray crewneck sweater',
        'a navy blue button-down shirt',
        'a forest green quarter-zip pullover',
        'a burgundy cardigan over a white t-shirt',
        'a mustard yellow knit sweater',
        'a denim jacket over a plain white t-shirt',
        'a striped long-sleeve shirt',
        'a plain white cotton blouse',
    ];
    $outfit = $customerOutfits[array_rand($customerOutfits)];
    return 'Photorealistic photo, bright white modern kitchen with subway tile and stainless '
         . 'appliances, natural light. A similar-looking appliance technician to the one in the '
         . 'first image, but with a different hairstyle and face, in a light blue short-sleeve '
         . 'work shirt and navy pants (no logos). He stands beside a stainless steel range with '
         . 'the oven door open, explaining the repair to a homeowner in their 40s-50s, wearing '
         . $outfit . ', who is listening and smiling. Unbranded appliance, no text or logos '
         . 'visible. Candid, warm, trustworthy. Horizontal 4:3.';
}

/**
 * The full instruction sent with a reference edit: the prompt from the textarea plus
 * a mode-specific "stay close to the reference" suffix (mode = Pic Drop's radio
 * group: tech | notech | bare | newperson | vary | varybg | techcustomer | custom).
 *
 * The shared suffix names equipment and vehicles on purpose for the modes that are
 * ABOUT the scene's contents while still wanting that equipment to stay put (tech,
 * notech, newperson). "vary", "bare", "varybg", and "custom" each need their own
 * suffix with no such nouns, for reasons that don't all point the same way: "vary"
 * isn't changing anything at all, so naming a van to "keep" it is how a van appeared
 * in front of a house that never had one (see picdrop_vary_prompt()); "bare" is
 * actively REMOVING the equipment, so the shared "same equipment" clause would flatly
 * contradict it; "varybg" is "vary" PLUS one explicit change (the background), so it
 * can't reuse either vary's own suffix ("reproduce only what is already in the
 * reference, nothing more" — directly forbids the very change this mode asks for) or
 * the shared one ("same setting" — the background IS the setting that's supposed to
 * change); "custom" is free text typed by hand — it could ask for absolutely anything
 * (wall color, a sign, swapping the vehicle), so a suffix that names or presupposes
 * ANY specific thing to keep ("same equipment", "same setting") risks directly
 * contradicting whatever that typed instruction turns out to be about.
 */
function picdrop_reference_prompt(string $prompt, string $mode): string {
    if ($mode === 'vary') {
        return $prompt . ' Reproduce only what is already in the reference photo, nothing more.';
    }
    if ($mode === 'varybg') {
        return $prompt . ' Keep the subject, equipment, and composition exactly as they are in '
            . 'the reference photo — change only the background\'s color or setting, nothing '
            . 'else.';
    }
    if ($mode === 'custom') {
        // Deliberately names nothing — the typed instruction is the only place anything
        // specific is named, so this can never contradict it regardless of what it asks
        // for (a wall color, a prop, a vehicle swap, anything).
        return $prompt . ' Keep everything else in the photo exactly as it is in the reference — '
            . 'apply only the change described above, and nothing else.';
    }
    if ($mode === 'bare') {
        return $prompt . ' Keep this photo close to the reference — same setting and location, '
            . 'same camera angle — and apply only the change described above. Remove any '
            . 'technician or other person, AND all equipment, tools, and vehicles, from the '
            . 'scene entirely, leaving only the plain, empty surroundings as if no job were in '
            . 'progress.';
    }
    $suffix = ' Keep this photo close to the reference — same setting, same equipment, '
        . 'same vehicle if one is visible — and apply only the change described above.';
    if ($mode === 'notech') {
        $suffix .= ' Remove any technician or other person from the scene entirely — show only '
            . 'the equipment and surroundings, as if no one is present.';
    } elseif ($mode === 'newperson') {
        $suffix .= ' Give any person in the photo a different face and hairstyle than the '
            . 'reference photo, while keeping their role the same.';
    }
    return $prompt . $suffix;
}

/**
 * Every place outside the media library that still points at this image: any site's
 * page data, templates, Pic Drop pairs, and multisite caches (per-domain image maps).
 * Matched on the file's stem, so a reference to its "-mobile" or a further-derived copy
 * also counts — erring toward keeping a file, never toward deleting one in use.
 *
 * @return string[] relative paths of the JSON files that mention it
 */
function picdrop_file_references(string $value): array {
    $stem = pathinfo(basename($value), PATHINFO_FILENAME);
    if ($stem === '') return ['(no filename)'];

    // Every site this file could conceivably belong to: the one actually active right
    // now (a legacy single-site root, or a CLI worker's ephemeral clone — see
    // config.php's 3-tier routing — neither of which lives under sites/), PLUS every
    // registered multisite site, kept as a cross-site safety net since filenames carry
    // a random suffix and a collision would only ever be accidental. Without the
    // active-site entry, a legacy single-site install (or a worker run) would see zero
    // references for anything it owns — nothing in it lives under sites/ — so this
    // function would call every one of its own files unused, no matter how many pages
    // still point at it.
    $siteDirs = array_unique(array_filter(array_merge(
        [ACTIVE_SITE_DIR !== '' ? ACTIVE_SITE_DIR : BASE_DIR],
        glob(BASE_DIR . '/sites/*', GLOB_ONLYDIR) ?: []
    )));

    $hits = [];
    foreach ($siteDirs as $site) {
        foreach (['data', 'multisite'] as $sub) {
            if (!is_dir("$site/$sub")) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$site/$sub", FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'json' || $f->getFilename() === 'media.json') continue;
                $raw = @file_get_contents($f->getPathname());
                if ($raw !== false && strpos($raw, $stem) !== false) {
                    // A CLI worker's site can sit outside BASE_DIR entirely (an
                    // ephemeral temp path) — fall back to the full path rather than a
                    // garbled/empty substr when that's the case.
                    $path = $f->getPathname();
                    $hits[] = str_starts_with($path, BASE_DIR . '/') ? substr($path, strlen(BASE_DIR) + 1) : $path;
                }
            }
        }
    }
    return $hits;
}

/**
 * Delete an image file (plus its -mobile sibling and media-library entry) ONLY when
 * nothing references it any more. Pic Drop routinely shares one file between a
 * template's hero, another template's body photo and a social image, so deleting on
 * request alone would break pages that were never touched.
 *
 * @return array{deleted:bool,refs:string[]}
 */
function picdrop_delete_if_unused(string $value): array {
    $path = picdrop_resolve($value);
    if ($path === null || !is_file($path)) return ['deleted' => false, 'refs' => []];
    if (!str_starts_with(realpath($path) ?: '', realpath(MEDIA_DIR) ?: "\0")) {
        return ['deleted' => false, 'refs' => ['(outside this site\'s media library)']];
    }
    $refs = picdrop_file_references($value);
    if ($refs) return ['deleted' => false, 'refs' => $refs];

    $info   = pathinfo($path);
    $mobile = $info['dirname'] . '/' . $info['filename'] . '-mobile.' . ($info['extension'] ?? 'webp');
    @unlink($path);
    if (is_file($mobile)) @unlink($mobile);
    $name = basename($path);
    media_save(array_values(array_filter(media_load(), fn($i) => ($i['filename'] ?? '') !== $name)));
    return ['deleted' => true, 'refs' => []];
}
