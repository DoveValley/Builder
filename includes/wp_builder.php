<?php
/**
 * includes/wp_builder.php — the "WP Builder" tool: creates and updates a
 * real, plain, plugin-free (besides landing-page-client) WordPress site on
 * the LPC demo box from a small set of inputs, for use as an "existing
 * customer site" demo in the LPC product — or, via a profile with its own
 * custom header/footer/blocks, a fully bespoke build.
 *
 * Deliberately NOT a page-builder import (that's what caused nearly every bug
 * found rehearsing the original 12 demo sites — mismatched plugin versions,
 * leftover placeholder content, dead block types). Every page here is plain
 * core WordPress blocks, so it renders correctly on any theme with zero
 * page-builder plugin dependency, and stays editable in the normal block
 * editor afterward.
 *
 * This file is shared plumbing only — vhost creation, Gutenberg-safe content
 * writing, photo upload, the sites registry. What gets built (which pages,
 * which blocks, which header/footer) is defined per "profile" — see
 * wp_builder_registry.php and wp_builder_profiles/*.php. Reuses
 * lpc_demo_box.php's already-proven vhost+clone+search-replace mechanism by
 * cloning a dedicated blank seed site (demo-wpbuilder-seed, Hello Elementor
 * theme — confirmed to render NO header/footer of its own).
 *
 * wpb_create_site() and wpb_update_site() are both thin orchestrators over
 * the same handful of steps below (normalize, resolve photos, apply site
 * options, deploy chrome, write pages, set the home page) — there is
 * deliberately only one implementation of each step, used by both, not two
 * parallel copies that could drift apart.
 */
require_once __DIR__ . '/lpc_demo_box.php';
require_once __DIR__ . '/wp_builder_registry.php';
require_once __DIR__ . '/wp_builder_gutenberg.php';

const WPB_SEED_NICHE = 'wpbuilder-seed';

function wpb_registry_file(): string
{
    return BASE_DIR . '/.wp_builder_sites.json';
}

function wpb_registry(): array
{
    return json_decode((string) @file_get_contents(wpb_registry_file()), true) ?: [];
}

function wpb_registry_save(string $domain, array $inputs): void
{
    $all = wpb_registry();
    $all[$domain] = $inputs + ['created_at' => $all[$domain]['created_at'] ?? date('c')];
    @file_put_contents(wpb_registry_file(), json_encode($all, JSON_PRETTY_PRINT));
}

function wpb_registry_delete(string $domain): void
{
    $all = wpb_registry();
    unset($all[$domain]);
    @file_put_contents(wpb_registry_file(), json_encode($all, JSON_PRETTY_PRINT));
}

/** List of sites this tool built, newest first, with a live running-check (same lightweight curl HEAD lpc_demo_list_sites uses). */
function wpb_list_sites(): array
{
    $all = wpb_registry();
    $out = [];
    foreach ($all as $domain => $data) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://' . LPC_DEMO_BOX_HOST . '/',
            CURLOPT_HTTPHEADER => ['Host: ' . $domain],
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $out[] = $data + ['domain' => $domain, 'running' => in_array($code, [200, 301, 302], true)];
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $out;
}

/** SSH output is often prefixed with a one-time "Permanently added ... to the list of known hosts" line (UserKnownHostsFile=/dev/null never persists one) — the real result is always the last non-empty line. */
function wpb_last_line(string $output): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn($l) => $l !== ''));
    return $lines ? end($lines) : '';
}

function wpb_slug(string $text): string
{
    $s = strtolower(trim($text));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function wpb_site_dir(string $domain): string
{
    return '/home/' . LPC_DEMO_BOX_SITE_USER . '/web/' . $domain . '/public_html';
}

function wpb_wp_cli(string $domain, string $args): string
{
    return 'sudo -u ' . escapeshellarg(LPC_DEMO_BOX_SITE_USER) . ' php ' . escapeshellarg(wpb_site_dir($domain) . '/wp-cli.phar')
        . ' ' . $args . ' --path=' . escapeshellarg(wpb_site_dir($domain));
}

/** Copy a local file onto the box over scp, returning the remote path it landed at. */
function wpb_scp_to_box(string $localPath, string $remotePath): void
{
    shell_exec(sprintf(
        'scp -i %s -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no %s %s@%s:%s 2>&1',
        escapeshellarg(LPC_DEMO_BOX_SSH_KEY), escapeshellarg($localPath),
        escapeshellarg(LPC_DEMO_BOX_SSH_USER), escapeshellarg(LPC_DEMO_BOX_HOST), escapeshellarg($remotePath)
    ));
}

/** Stage a local file onto the box and import it into that site's media library. Returns the attachment URL, or '' on failure. */
function wpb_upload_photo(string $domain, string $localTmpPath, string $origName): string
{
    if ($localTmpPath === '' || !is_file($localTmpPath)) return '';
    $dir = wpb_site_dir($domain);
    $remoteStage = '/tmp/wpb_photo_' . bin2hex(random_bytes(6)) . '_' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $origName);
    wpb_scp_to_box($localTmpPath, $remoteStage);

    // media import (run as the site user) leaves new files owned demo:demo —
    // unlike the clone step's files, which are explicitly chowned demo:www-data,
    // so without this nginx (www-data) can't read them and every uploaded photo
    // 403s on the front end despite a perfectly valid <img> tag pointing at it.
    $cmd = wpb_wp_cli($domain, 'media import ' . escapeshellarg($remoteStage) . ' --porcelain') . ' 2>&1'
         . ' ; rm -f ' . escapeshellarg($remoteStage)
         . ' ; chown -R ' . escapeshellarg(LPC_DEMO_BOX_SITE_USER . ':www-data') . ' ' . escapeshellarg($dir . '/wp-content/uploads')
         . ' ; find ' . escapeshellarg($dir . '/wp-content/uploads') . ' -type f -exec chmod 640 {} \;'
         . ' ; find ' . escapeshellarg($dir . '/wp-content/uploads') . ' -type d -exec chmod 750 {} \;';
    $r = lpc_demo_box_ssh($cmd);
    $id = wpb_last_line($r['output']);
    if (!ctype_digit($id)) return '';

    $urlR = lpc_demo_box_ssh(wpb_wp_cli($domain, 'post get ' . escapeshellarg($id) . ' --field=guid') . ' 2>&1');
    return wpb_last_line($urlR['output']);
}

/**
 * Rsync a local mu-plugin folder onto a site, as an ADDITION to whatever's
 * already in wp-content/mu-plugins (not a replacement) — called once for the
 * shared base chrome, and again for a profile's own chrome_dir if it has one.
 * Safe to re-run on an existing site: rsync just overwrites with the current
 * source, so an update picks up any chrome code changes too.
 */
function wpb_deploy_mu_plugin_dir(string $domain, string $localDir): void
{
    $chromeDest = wpb_site_dir($domain) . '/wp-content/mu-plugins';
    $stageTag = 'wpb_chrome_stage_' . bin2hex(random_bytes(4));

    $stage = sprintf(
        'rsync -az -e %s %s %s@%s:/tmp/%s/',
        escapeshellarg('ssh -i ' . LPC_DEMO_BOX_SSH_KEY . ' -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no'),
        escapeshellarg(rtrim($localDir, '/') . '/'),
        escapeshellarg(LPC_DEMO_BOX_SSH_USER),
        escapeshellarg(LPC_DEMO_BOX_HOST),
        $stageTag
    );
    shell_exec($stage . ' 2>&1');
    lpc_demo_box_ssh(
        'mkdir -p ' . escapeshellarg($chromeDest)
        . ' && rsync -a /tmp/' . $stageTag . '/ ' . escapeshellarg($chromeDest . '/')
        . ' && rm -rf ' . escapeshellarg('/tmp/' . $stageTag)
        . ' && chown -R ' . escapeshellarg(LPC_DEMO_BOX_SITE_USER . ':www-data') . ' ' . escapeshellarg($chromeDest)
    );
}

/** Deploys the shared base chrome plus a profile's own chrome_dir override, if it has one. Idempotent — safe on both create and update. */
function wpb_deploy_profile_chrome(string $domain, array $profile): void
{
    wpb_deploy_mu_plugin_dir($domain, __DIR__ . '/../wp-plugin/wp-builder-site');
    if (!empty($profile['chrome_dir'])) {
        wpb_deploy_mu_plugin_dir($domain, $profile['chrome_dir']);
    }
}

/** Normalizes raw input through a profile's own normalize callback, then fills in any field defaults it left blank. */
function wpb_normalize_for_profile(array $profile, array $rawInput): array
{
    $d = call_user_func($profile['normalize'], $rawInput);
    foreach ($profile['fields'] as $key => $cfg) {
        if (($d[$key] ?? '') === '' && isset($cfg['default'])) {
            $d[$key] = $cfg['default'];
        }
    }
    return $d;
}

/**
 * Resolves every photo a profile declares: uploads a new file if one was
 * submitted for that slot, otherwise falls back to whatever URL is already
 * on record (passed in as $existingPhotos — empty on create, since nothing
 * exists yet). This is what lets an update leave untouched photos alone
 * instead of requiring them to be re-uploaded every time.
 */
function wpb_resolve_photos(array $profile, string $domain, array $files, array $existingPhotos = []): array
{
    $photos = [];
    foreach ($profile['photos'] ?? [] as $shortKey => $pCfg) {
        $fieldName = $pCfg['field'];
        $tmpName = $files[$fieldName]['tmp_name'] ?? '';
        if ($tmpName !== '' && is_file($tmpName)) {
            $photos[$shortKey] = wpb_upload_photo($domain, $tmpName, $files[$fieldName]['name'] ?? '');
        } else {
            $photos[$shortKey] = $existingPhotos[$shortKey] ?? '';
        }
    }
    return $photos;
}

/** Sets blogname plus every wp_option a profile's site_options callback declares. Safe to re-run — every value is just overwritten. */
function wpb_apply_site_options(string $domain, array $profile, array $d, array $photos): string
{
    $siteTitle = isset($profile['site_title']) ? call_user_func($profile['site_title'], $d) : $domain;
    $cmds = [wpb_wp_cli($domain, 'option update blogname ' . escapeshellarg($siteTitle))];
    $siteOptions = isset($profile['site_options']) ? call_user_func($profile['site_options'], $d, $photos) : [];
    foreach ($siteOptions as $k => $v) {
        $cmds[] = is_array($v)
            ? wpb_wp_cli($domain, 'option update ' . escapeshellarg($k) . ' --format=json ' . escapeshellarg(json_encode($v)))
            : wpb_wp_cli($domain, 'option update ' . escapeshellarg($k) . ' ' . escapeshellarg((string) $v));
    }
    lpc_demo_box_ssh(implode(' && ', $cmds));
    return $siteTitle;
}

/**
 * Writes (or rewrites) every page a profile's page_types describe, via the
 * shared insert-or-update-by-slug helper — safe to call repeatedly, which is
 * exactly what makes this one function usable by both create and update.
 * Returns pageKey => post ID.
 */
function wpb_write_profile_pages(string $profileKey, array $profile, string $domain, array $d, array $photos): array
{
    $dir = wpb_site_dir($domain);
    $helperRemote = '/tmp/wpb_page_helper_' . bin2hex(random_bytes(6)) . '.php';
    wpb_scp_to_box(__DIR__ . '/wp_builder_create_page.php', $helperRemote);

    $pageIds = [];
    foreach ($profile['page_types'] as $pageKey => $pt) {
        $ctx = ['photos' => $photos, 'page' => $pageKey];
        $content = '';
        foreach ($pt['blocks'] as $blockKey) {
            $fn = wpb_get_block($profileKey, $blockKey);
            if ($fn) {
                $content .= call_user_func($fn, $d, $ctx);
            }
        }
        $seoFn = $pt['seo'] ?? null;
        [$title, $metaDesc] = $seoFn ? call_user_func($seoFn, $d) : [$pt['title'] ?? $pageKey, ''];
        $slug = $pt['slug'] ?? $pageKey;
        $pageTitle = $pt['title'] ?? ucfirst($pageKey);
        // A page_type can nest under another by naming its page_type key as 'parent'
        // (e.g. a niche page under 'industries') — resolved from pages already
        // written this same run, so the parent must be declared earlier in
        // page_types than its children.
        $parentId = !empty($pt['parent']) ? ($pageIds[$pt['parent']] ?? 0) : 0;

        $tmpFile = sys_get_temp_dir() . '/wpb_content_' . bin2hex(random_bytes(6)) . '.html';
        file_put_contents($tmpFile, $content);
        $remoteTmp = '/tmp/' . basename($tmpFile);
        wpb_scp_to_box($tmpFile, $remoteTmp);
        @unlink($tmpFile);

        $cmd = wpb_wp_cli($domain, 'eval-file ' . escapeshellarg($helperRemote)
                . ' ' . escapeshellarg($slug) . ' ' . escapeshellarg($pageTitle) . ' ' . escapeshellarg($remoteTmp) . ' ' . escapeshellarg($metaDesc) . ' ' . escapeshellarg($title) . ' ' . escapeshellarg((string) $parentId))
            . ' 2>&1 ; rm -f ' . escapeshellarg($remoteTmp);
        $r = lpc_demo_box_ssh($cmd);
        $id = wpb_last_line($r['output']);
        if (ctype_digit($id)) {
            $pageIds[$pageKey] = (int) $id;
        } else {
            error_log('wp_builder: page write failed for ' . $pageKey . ' on ' . $domain . ': ' . $r['output']);
        }
    }

    lpc_demo_box_ssh('rm -f ' . escapeshellarg($helperRemote));
    return $pageIds;
}

/** Makes a profile's declared home_page the site's static front page. Cheap and idempotent, so it's run after every write, not just the first. */
function wpb_set_home_page(string $domain, array $profile, array $pageIds): void
{
    if (empty($profile['home_page']) || empty($pageIds[$profile['home_page']])) {
        return;
    }
    $homeId = $pageIds[$profile['home_page']];
    lpc_demo_box_ssh(
        wpb_wp_cli($domain, 'option update show_on_front page')
        . ' && ' . wpb_wp_cli($domain, 'option update page_on_front ' . (int) $homeId)
    );
}

/**
 * The create flow: clone the blank seed (reusing lpc_demo_create_site's
 * proven vhost+clone+search-replace steps), then run through the same
 * shared steps wpb_update_site() uses.
 */
function wpb_create_site(string $profileKey, string $domain, array $rawInput, array $files, string $cloneFromNiche = ''): array
{
    $profile = wpb_get_profile($profileKey);
    if (!$profile) {
        return ['ok' => false, 'message' => "Unknown profile: $profileKey"];
    }
    foreach ($profile['fields'] as $key => $cfg) {
        if (!empty($cfg['required']) && trim((string) ($rawInput[$key] ?? '')) === '' && empty($cfg['default'])) {
            return ['ok' => false, 'message' => "Missing required field: $key"];
        }
    }

    // Astra, 2026-11-26: the most common free WP theme among real agencies,
    // already sitting in the golden image's wp-content/themes/ (confirmed via
    // `wp theme list` before wiring this — lpc_demo_create_site()'s $theme
    // param already did a real `wp theme activate`, it just never had a
    // caller pass anything). Every WP Builder site gets real theme chrome
    // instead of whatever the seed happened to have active, not just this one.
    //
    // $cloneFromNiche lets a caller start from an existing demo site's WP
    // install instead of the generic blank seed (added 2026-11-27, converting
    // demo-lawyer.q111.xyz into the starting point for a new insurance-agency
    // demo) — same clone mechanism either way, just a different source.
    $create = lpc_demo_create_site($domain, $cloneFromNiche !== '' ? $cloneFromNiche : WPB_SEED_NICHE, 'astra');
    if (!$create['ok']) {
        return $create;
    }

    // The seed clone carries the "Demo Site Back Link" badge (internal-only,
    // its own docblock says "Never ship this on a real client site") — any
    // profile building something real, not an internal rehearsal site, opts
    // out of it here.
    if (!empty($profile['strip_internal_badges'])) {
        lpc_demo_box_ssh('rm -f ' . escapeshellarg(wpb_site_dir($domain) . '/wp-content/mu-plugins/demo-site-back-link.php'));
    }

    // Wipe the seed's own leftover pages before writing this profile's own.
    lpc_demo_box_ssh(wpb_wp_cli($domain, 'post delete $(' . wpb_wp_cli($domain, 'post list --post_type=page --format=ids') . ') --force') . ' 2>&1');

    $d = wpb_normalize_for_profile($profile, $rawInput);
    wpb_deploy_profile_chrome($domain, $profile);
    $photos = wpb_resolve_photos($profile, $domain, $files);
    $siteTitle = wpb_apply_site_options($domain, $profile, $d, $photos);
    $pageIds = wpb_write_profile_pages($profileKey, $profile, $domain, $d, $photos);
    wpb_set_home_page($domain, $profile, $pageIds);

    $registryEntry = $rawInput;
    $registryEntry['profile'] = $profileKey;
    $registryEntry['business_name'] = $d['business_name'] ?? $siteTitle;
    foreach ($photos as $shortKey => $url) {
        $registryEntry[$shortKey . '_photo_url'] = $url;
    }
    wpb_registry_save($domain, $registryEntry);

    return ['ok' => true, 'message' => 'Created ' . $domain . ' with ' . count($pageIds) . ' of ' . count($profile['page_types']) . ' pages'];
}

/**
 * The update flow: regenerate an existing site's pages/options/chrome from
 * its stored profile and merged inputs, writing everything IN PLACE — no
 * vhost touch, no fresh clone, no photo re-upload unless a new file is
 * actually submitted. This is the exact same set of steps wpb_create_site()
 * uses after its own clone step, just pointed at a site that already exists.
 */
function wpb_update_site(string $domain, array $rawInput, array $files): array
{
    $existing = wpb_registry()[$domain] ?? null;
    if (!$existing) {
        return ['ok' => false, 'message' => "No WP Builder record for $domain — use Create instead."];
    }
    $profileKey = $existing['profile'] ?? 'local-business';
    $profile = wpb_get_profile($profileKey);
    if (!$profile) {
        return ['ok' => false, 'message' => "Unknown profile: $profileKey"];
    }

    // Blank fields in the submitted form keep whatever was already on record.
    $merged = $existing;
    foreach (array_keys($profile['fields']) as $key) {
        if (trim((string) ($rawInput[$key] ?? '')) !== '') {
            $merged[$key] = $rawInput[$key];
        }
    }

    $existingPhotos = [];
    foreach (array_keys($profile['photos'] ?? []) as $shortKey) {
        $existingPhotos[$shortKey] = $existing[$shortKey . '_photo_url'] ?? '';
    }

    $d = wpb_normalize_for_profile($profile, $merged);
    wpb_deploy_profile_chrome($domain, $profile);
    $photos = wpb_resolve_photos($profile, $domain, $files, $existingPhotos);
    $siteTitle = wpb_apply_site_options($domain, $profile, $d, $photos);
    $pageIds = wpb_write_profile_pages($profileKey, $profile, $domain, $d, $photos);
    wpb_set_home_page($domain, $profile, $pageIds);

    $registryEntry = $merged;
    $registryEntry['profile'] = $profileKey;
    $registryEntry['business_name'] = $d['business_name'] ?? $siteTitle;
    foreach ($photos as $shortKey => $url) {
        $registryEntry[$shortKey . '_photo_url'] = $url;
    }
    wpb_registry_save($domain, $registryEntry);

    return ['ok' => true, 'message' => 'Updated ' . $domain . ' (' . count($pageIds) . ' of ' . count($profile['page_types']) . ' pages)'];
}

function wpb_delete_site(string $domain): array
{
    $r = lpc_demo_delete_site($domain);
    if ($r['ok']) {
        wpb_registry_delete($domain);
    }
    return $r;
}

// Every profile registers itself on include — new ones just need a new file here.
foreach (glob(__DIR__ . '/wp_builder_profiles/*.php') as $profileFile) {
    require_once $profileFile;
}
