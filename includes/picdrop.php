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
 *
 * fieldPath may be dotted for fields nested in a repeater ("ts_tabs.2.photo").
 */

require_once __DIR__ . '/../config.php';

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
 *   image           popups, owned by the Popups tab
 *   featured_image  blog posts, which are not pages
 *   city_image      a site_vars token, not a block field
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

/* Sibling alt path for an image path: "ts_tabs.2.photo" + "photo_alt" → "ts_tabs.2.photo_alt". */
function picdrop_alt_path(string $path, ?string $altKey): ?string {
    if ($altKey === null) return null;
    $segs = explode('.', $path);
    array_pop($segs);
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
    $fields = picdrop_fields();
    $slots  = [];

    foreach ($blocks as $bi => $block) {
        if (!is_array($block)) continue;
        $type = (string) ($block['type'] ?? 'block');

        foreach (picdrop_block_paths($block) as $path) {
            $leaf  = picdrop_leaf($path);
            $spec  = $fields[$leaf];
            $value = (string) picdrop_get($block, $path);

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

            $slots[] = [
                'key'        => "$scope:$id:$bi:$path",
                'scope'      => $scope,
                'page_id'    => $id,
                'block'      => $bi,
                'field'      => $path,
                'block_type' => $type,
                'block_label'=> picdrop_block_label($type),
                'label'      => $spec['label'],
                'kind'       => $spec['kind'],
                'value'      => $value,
                'exists'     => $file !== null,
                'token'      => $isToken,
                'w'          => (int) $w,
                'h'          => (int) $h,
                'bytes'      => $bytes,
                'alt_field'  => $altPath,
                'alt'        => $altVal,
            ];
        }
    }
    return $slots;
}

/**
 * Every page in the site, in display order, each with its picture slots.
 *
 * Order is Site-wide → Home → Core → Landing, which is how they nest conceptually
 * and matches how they are edited elsewhere in the admin.
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

            if (picdrop_set($target, $e['field'], $e['value'])) { $dirty = true; $ok++; }
            else $errors[] = basename($file) . ': field ' . $e['field'] . ' not present'
                           . ($root ? ' on ' . ($e['id'] ?: 'the document') : ' on block ' . $e['block']);

            unset($target);
        }

        if ($dirty && !picdrop_json_write($file, $doc)) {
            $errors[] = basename($file) . ': could not be written';
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

    if (!in_array($scope, ['global', 'home', 'core', 'landing'], true)) return null;
    if (!preg_match('/^\d+$/', $bi)) return null;
    if (!preg_match('/^[a-z0-9_]+(\.\d+\.[a-z0-9_]+)?$/i', $path)) return null;
    if (!isset(picdrop_fields()[picdrop_leaf($path)])) return null;
    if ($scope === 'landing' && !preg_match('/^[a-z0-9_\-]+\.json$/i', $id)) return null;
    if ($scope === 'core' && !preg_match('/^[a-z0-9_\-]+$/i', $id)) return null;

    return ['scope' => $scope, 'id' => $id, 'block' => (int) $bi, 'field' => $path];
}
