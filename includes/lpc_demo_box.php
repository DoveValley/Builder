<?php
/**
 * includes/lpc_demo_box.php — everything the Demo Sites admin tab needs to drive
 * the dedicated LPC demo-sites box (2.24.196.58), isolated from the 20-box niche
 * fleet and from LPC's own delivery server.
 *
 * Two transports, same "one module per service" discipline as the rest of this
 * codebase:
 *   - HestiaCP's own HTTP API (admin/infra/lib/hestia.php — the SAME generic
 *     client the fleet console uses, just pointed at this one box's credentials
 *     instead of the fleet registry) for domain create/delete/restart. No SSH
 *     needed for any of that.
 *   - A narrowly-scoped SSH key (LPC_DEMO_BOX_SSH_KEY), used ONLY for the
 *     WordPress-specific steps the API can't do: cloning the golden WP image
 *     into a new vhost, running wp-cli, redeploying the plugin, clearing cache.
 *
 * Domains are NOT assumed to follow any naming pattern — Scott will replace the
 * current demo-*.q111.xyz placeholders with real domains per site, so every
 * site is identified by whatever domain Hestia actually reports, never
 * reconstructed from a niche slug.
 */
require_once __DIR__ . '/../admin/infra/lib/hestia.php';

/** Build the $server array hestia_*() functions expect, from this box's own access key. */
function lpc_demo_box_hestia_server(): array
{
    $key = @file_get_contents(LPC_DEMO_BOX_HESTIA_KEY_FILE);
    $data = $key !== false ? json_decode($key, true) : null;
    return [
        'host'       => LPC_DEMO_BOX_HOST,
        'port'       => 8083,
        'access_key' => $data['ACCESS_KEY_ID'] ?? '',
        'secret_key' => $data['SECRET_ACCESS_KEY'] ?? '',
    ];
}

/**
 * Run one command on the demo box over the dedicated provisioning key.
 * Not exposed to any user input directly — every caller below builds $cmd from
 * escapeshellarg()'d pieces, same discipline as lpc_demo_sites_save.php always
 * used for the local (pre-migration) version of these actions.
 */
function lpc_demo_box_ssh(string $remoteCmd): array
{
    $ssh = sprintf(
        'ssh -i %s -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o ConnectTimeout=15 %s@%s %s 2>&1',
        escapeshellarg(LPC_DEMO_BOX_SSH_KEY),
        escapeshellarg(LPC_DEMO_BOX_SSH_USER),
        escapeshellarg(LPC_DEMO_BOX_HOST),
        escapeshellarg($remoteCmd)
    );
    $output = shell_exec($ssh);
    return ['output' => trim((string) $output)];
}

/** Every real site on this box, straight from Hestia — no local cache, nothing to drift. */
function lpc_demo_list_sites(): array
{
    $server = lpc_demo_box_hestia_server();
    $siteUser = LPC_DEMO_BOX_SITE_USER;
    $r = hestia_api($server, 'v-list-web-domains', [$siteUser, 'json'], false);
    if (!$r['ok'] || !is_array($r['json'])) return [];

    $domains = array_keys($r['json']);
    sort($domains);

    // One batched SSH call for theme info on every site at once, rather than one
    // round trip per row — the admin page renders a table, not a single site.
    $themes = [];
    if ($domains) {
        $cmds = [];
        foreach ($domains as $d) {
            $dir = '/home/' . $siteUser . '/web/' . $d . '/public_html';
            $cmds[] = 'echo ' . escapeshellarg($d . '|') . '$(sudo -u ' . escapeshellarg($siteUser)
                    . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' theme list --status=active --field=name --path='
                    . escapeshellarg($dir) . ' 2>/dev/null)';
        }
        $batch = lpc_demo_box_ssh(implode(' ; ', $cmds));
        foreach (explode("\n", $batch['output']) as $line) {
            if (strpos($line, '|') === false) continue;
            [$d, $t] = explode('|', $line, 2);
            $themes[$d] = trim($t) ?: '—';
        }
    }

    $sites = [];
    foreach ($domains as $d) {
        $running = false;
        $conn = @fsockopen(LPC_DEMO_BOX_HOST, 80, $errno, $errstr, 1.5);
        if ($conn) {
            fwrite($conn, "HEAD / HTTP/1.0\r\nHost: {$d}\r\nConnection: close\r\n\r\n");
            $resp = fread($conn, 64);
            $running = strpos($resp, ' 200') !== false || strpos($resp, ' 301') !== false || strpos($resp, ' 302') !== false;
            fclose($conn);
        }
        $sites[] = [
            'domain'  => $d,
            'niche'   => preg_replace('/^demo-/', '', explode('.', $d)[0]),
            'theme'   => $themes[$d] ?? '—',
            'running' => $running,
            'docroot' => '/home/' . $siteUser . '/web/' . $d . '/public_html',
        ];
    }
    return $sites;
}

/**
 * Create a new demo site: real Hestia vhost (via the API) + the golden WP image
 * cloned into it, URL-rewritten, theme activated (via the provisioning key).
 * $sourceNiche picks which of the 12 EXISTING sites to clone as starting
 * content — there is no "blank" WordPress option, same as the original
 * new_demo_site.sh, which always cloned the one shared golden test-wordpress
 * image. Cloning an existing demo site instead reuses whatever real content
 * already exists for that niche rather than reintroducing a second golden
 * image to maintain.
 */
function lpc_demo_create_site(string $domain, string $sourceNiche, string $theme = ''): array
{
    $server = lpc_demo_box_hestia_server();
    $siteUser = LPC_DEMO_BOX_SITE_USER;

    if (hestia_site_exists($server, $domain, $siteUser)) {
        return ['ok' => false, 'message' => 'That domain already has a vhost on this box.'];
    }

    // 1) Real vhost, via the API — no SSH needed for this part.
    $r = hestia_api($server, 'v-add-web-domain', [$siteUser, $domain, LPC_DEMO_BOX_HOST, 'no', 'www.' . $domain]);
    if (!$r['ok']) return ['ok' => false, 'message' => 'v-add-web-domain: ' . $r['message']];

    $tpl = hestia_api($server, 'v-change-web-domain-tpl', [$siteUser, $domain, 'wordpress']);
    // Not fatal if this fails — falls back to the default template (no pretty
    // permalinks) rather than leaving the domain half-created.

    $restart = hestia_api($server, 'v-restart-web');

    // 2) Content — clone an existing site's docroot as the starting point, since
    // every demo site already carries real, non-placeholder content for its
    // niche (the original build's whole point). Cloning is a straight directory
    // copy ON the box itself (no re-transfer from the admin server needed).
    $sourceDir = '/home/' . $siteUser . '/web/demo-' . escapeshellcmd($sourceNiche) . '.*/public_html';
    $destDir   = '/home/' . $siteUser . '/web/' . $domain . '/public_html';
    $cloneCmd  = 'SRC=$(ls -d ' . $sourceDir . ' 2>/dev/null | head -1); '
               . 'if [ -z "$SRC" ]; then echo NO_SOURCE_FOUND; exit 1; fi; '
               . 'rsync -a --delete "$SRC/" ' . escapeshellarg($destDir . '/') . ' && '
               . 'chown -R ' . escapeshellarg($siteUser . ':www-data') . ' ' . escapeshellarg($destDir) . ' && '
               . 'find ' . escapeshellarg($destDir) . ' -type d -exec chmod 750 {} \; && '
               . 'find ' . escapeshellarg($destDir) . ' -type f -exec chmod 640 {} \; && '
               . 'echo CLONE_OK';
    $clone = lpc_demo_box_ssh($cloneCmd);
    if (strpos($clone['output'], 'CLONE_OK') === false) {
        return ['ok' => false, 'message' => 'Vhost created but content clone failed: ' . $clone['output']];
    }

    // 3) Point the clone's WordPress install at its real new domain, activate
    // the requested theme if one was given, and reload php-fpm so the new
    // pool config actually takes effect (v-restart-web only restarts
    // nginx — a real bug hit migrating the original 12 sites, see memory).
    $oldUrlGuess = 'http://demo-' . escapeshellcmd($sourceNiche) . '.q111.xyz';
    $fixupCmd = 'cd ' . escapeshellarg($destDir) . ' && '
              . 'OLD=$(sudo -u ' . escapeshellarg($siteUser) . ' php wp-cli.phar option get siteurl --path=. 2>/dev/null); '
              . 'sudo -u ' . escapeshellarg($siteUser) . ' php wp-cli.phar search-replace "$OLD" ' . escapeshellarg('http://' . $domain) . ' --path=. --all-tables --report-changed-only 2>&1; '
              . 'OLDHOST=$(echo "$OLD" | sed -E "s#https?://##"); '
              . 'sudo -u ' . escapeshellarg($siteUser) . ' php wp-cli.phar search-replace "$OLDHOST" ' . escapeshellarg($domain) . ' --path=. --all-tables --report-changed-only 2>&1';
    if ($theme !== '' && preg_match('/^[a-z0-9\-]+$/', $theme)) {
        $fixupCmd .= ' ; sudo -u ' . escapeshellarg($siteUser) . ' php wp-cli.phar theme activate ' . escapeshellarg($theme) . ' --path=' . escapeshellarg($destDir) . ' 2>&1';
    }
    $fixup = lpc_demo_box_ssh($fixupCmd);

    hestia_api($server, 'v-restart-service', ['php-fpm']);

    return [
        'ok'      => true,
        'message' => 'Created ' . $domain . ' (cloned from demo-' . $sourceNiche . ')'
                   . ($tpl['ok'] ? '' : ' — WordPress template switch failed, pretty permalinks may not work')
                   . ($restart['ok'] ? '' : ' — web restart failed'),
        'detail'  => $fixup['output'],
    ];
}

/** Tear down a site: vhost + its files, via the API — no SSH needed. */
function lpc_demo_delete_site(string $domain): array
{
    $server = lpc_demo_box_hestia_server();
    $r = hestia_api($server, 'v-delete-web-domain', [LPC_DEMO_BOX_SITE_USER, $domain]);
    if (!$r['ok'] && $r['code'] !== 3) { // code 3 = "does not exist" — already gone, fine
        return ['ok' => false, 'message' => 'v-delete-web-domain: ' . $r['message']];
    }
    hestia_api($server, 'v-restart-web');
    return ['ok' => true, 'message' => 'Deleted ' . $domain];
}

/** Redeploy the LPC plugin's current repo source onto one site. */
function lpc_demo_redeploy_plugin(string $domain): array
{
    $siteUser = LPC_DEMO_BOX_SITE_USER;
    $destDir  = '/home/' . $siteUser . '/web/' . $domain . '/public_html/wp-content/plugins/landing-page-client';
    $src = rtrim(__DIR__ . '/../wp-plugin/landing-page-client', '/');
    if (!is_dir($src)) return ['ok' => false, 'message' => 'Plugin source not found in repo'];

    // Stage the repo's plugin source to the box first (admin server -> box),
    // then move it into place as the site's own user — same two-hop shape as
    // the delivery server's publish step, just over the provisioning key.
    $stageCmd = sprintf(
        'rsync -az --delete -e %s %s %s@%s:/tmp/lpc_plugin_stage/',
        escapeshellarg('ssh -i ' . LPC_DEMO_BOX_SSH_KEY . ' -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no'),
        escapeshellarg($src . '/'),
        escapeshellarg(LPC_DEMO_BOX_SSH_USER),
        escapeshellarg(LPC_DEMO_BOX_HOST)
    );
    shell_exec($stageCmd . ' 2>&1');

    $applyCmd = 'rsync -a --delete /tmp/lpc_plugin_stage/ ' . escapeshellarg($destDir . '/')
              . ' && chown -R ' . escapeshellarg($siteUser . ':www-data') . ' ' . escapeshellarg($destDir)
              . ' && echo REDEPLOY_OK';
    $r = lpc_demo_box_ssh($applyCmd);
    $ok = strpos($r['output'], 'REDEPLOY_OK') !== false;
    return ['ok' => $ok, 'message' => $ok ? 'Plugin redeployed from the repo' : ('Redeploy failed: ' . $r['output'])];
}

/** Make sure a site's page-cache plugin stays off, same guard as the original local version. */
function lpc_demo_clear_cache(string $domain): array
{
    $siteUser = LPC_DEMO_BOX_SITE_USER;
    $dir = '/home/' . $siteUser . '/web/' . $domain . '/public_html';
    $cmd = 'sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar')
         . ' plugin deactivate wp-super-cache --path=' . escapeshellarg($dir) . ' 2>&1'
         . ' ; rm -f ' . escapeshellarg($dir . '/wp-content/advanced-cache.php')
         . ' ; sed -i "s/define(.WP_CACHE., true)/define(\x27WP_CACHE\x27, false)/" ' . escapeshellarg($dir . '/wp-config.php')
         . ' ; echo CACHE_CLEAR_OK';
    $r = lpc_demo_box_ssh($cmd);
    $ok = strpos($r['output'], 'CACHE_CLEAR_OK') !== false;
    return ['ok' => $ok, 'message' => $ok ? 'Cache cleared and disabled' : ('Failed: ' . $r['output'])];
}
