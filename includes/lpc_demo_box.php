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

    // Theme info, cached with a short TTL. Each lookup is a full WordPress
    // bootstrap via wp-cli (connects to its DB, loads core+theme), and even
    // run in parallel across all sites, a 2-vCPU box genuinely can't do 19 of
    // those at once without real CPU contention — measured ~6.8s on its own,
    // on top of everything else, every single page load. Themes essentially
    // never change outside of an explicit create/redeploy action, so a 5-minute
    // cache trades negligible staleness for a page that loads instantly on
    // every repeat visit instead of re-paying this cost every time.
    $cacheFile = BASE_DIR . '/.lpc_demo_box_theme_cache.json';
    $cache = json_decode((string) @file_get_contents($cacheFile), true) ?: ['at' => 0, 'themes' => []];
    $themes = $cache['themes'];
    $missing = array_diff($domains, array_keys($themes));
    if ($missing || (time() - $cache['at']) > 300) {
        $toFetch = $missing ?: $domains; // full refresh past the TTL, else just the gaps
        $cmds = [];
        foreach ($toFetch as $d) {
            $dir = '/home/' . $siteUser . '/web/' . $d . '/public_html';
            $cmds[] = '(echo ' . escapeshellarg($d . '|') . '$(sudo -u ' . escapeshellarg($siteUser)
                    . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' theme list --status=active --field=name --path='
                    . escapeshellarg($dir) . ' 2>/dev/null)) &';
        }
        if ($cmds) {
            $batch = lpc_demo_box_ssh(implode(' ', $cmds) . ' wait');
            foreach (explode("\n", $batch['output']) as $line) {
                if (strpos($line, '|') === false) continue;
                [$d, $t] = explode('|', $line, 2);
                $themes[$d] = trim($t) ?: '—';
            }
        }
        @file_put_contents($cacheFile, json_encode(['at' => time(), 'themes' => $themes]));
    }

    // "Running" check, parallelized but CAPPED at a small batch size — each
    // domain cold-starts its own tiny php-fpm pool on demand (pm.max_children
    // 4-8) on a 2-vCPU box, so firing all 19 at once caused real resource
    // contention: some requests genuinely timed out under the burst, silently
    // misreporting real running sites as down. Batches of 6 avoid that while
    // still being far faster than one-at-a-time (was up to 1.5s EACH, serial).
    $running = array_fill_keys($domains, false);
    foreach (array_chunk($domains, 6) as $batch) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $d) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'http://' . LPC_DEMO_BOX_HOST . '/',
                CURLOPT_HTTPHEADER => ['Host: ' . $d],
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$d] = $ch;
        }
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($active && $status === CURLM_OK) {
            if (curl_multi_select($mh, 3) === -1) usleep(10000);
            do {
                $status = curl_multi_exec($mh, $active);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }
        foreach ($handles as $d => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $running[$d] = in_array($code, [200, 301, 302], true);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    $sites = [];
    foreach ($domains as $d) {
        $sites[] = [
            'domain'  => $d,
            'niche'   => preg_replace('/^demo-/', '', explode('.', $d)[0]),
            'theme'   => $themes[$d] ?? '—',
            'running' => $running[$d] ?? false,
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

    // SSL — only for the current .q111.xyz placeholder domains, using the one
    // genuinely self-signed wildcard cert covering all of them (Hestia's own
    // "default" cert is a REAL Let's Encrypt cert for the box's own hostname,
    // not self-signed, so it can never pass as a CA for these — found the hard
    // way). Real future domains need their own real cert (Let's Encrypt via
    // Cloudflare, same as the niche fleet) once DNS is actually pointed —
    // deliberately not attempted here.
    if (preg_match('/\.q111\.xyz$/', $domain)) {
        lpc_demo_box_ssh(
            'cp /opt/landing-page-clients/ssl/wildcard.crt ' . escapeshellarg('/opt/landing-page-clients/ssl/' . $domain . '.crt')
            . ' && cp /opt/landing-page-clients/ssl/wildcard.key ' . escapeshellarg('/opt/landing-page-clients/ssl/' . $domain . '.key')
        );
        hestia_api($server, 'v-add-web-domain-ssl', [$siteUser, $domain, '/opt/landing-page-clients/ssl', 'same']);
    }

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
    lpc_demo_deploy_back_link($domain);

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

/**
 * Deploy the internal-only "back to Site Factory" badge as an mu-plugin — mu-plugins
 * autoload with no activation step, so this can't be left switched off like a
 * normal plugin could. Lives outside wp-content/plugins/landing-page-client on
 * purpose: that plugin ships to real clients eventually, this badge must never
 * go with it.
 */
function lpc_demo_deploy_back_link(string $domain): array
{
    $siteUser = LPC_DEMO_BOX_SITE_USER;
    $destDir  = '/home/' . $siteUser . '/web/' . $domain . '/public_html/wp-content/mu-plugins';
    $src = rtrim(__DIR__ . '/../wp-plugin/demo-site-back-link', '/');
    if (!is_dir($src)) return ['ok' => false, 'message' => 'Plugin source not found in repo'];

    $stageCmd = sprintf(
        'rsync -az -e %s %s %s@%s:/tmp/lpc_backlink_stage/',
        escapeshellarg('ssh -i ' . LPC_DEMO_BOX_SSH_KEY . ' -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no'),
        escapeshellarg($src . '/'),
        escapeshellarg(LPC_DEMO_BOX_SSH_USER),
        escapeshellarg(LPC_DEMO_BOX_HOST)
    );
    shell_exec($stageCmd . ' 2>&1');

    $applyCmd = 'mkdir -p ' . escapeshellarg($destDir)
              . ' && rsync -a /tmp/lpc_backlink_stage/ ' . escapeshellarg($destDir . '/')
              . ' && chown -R ' . escapeshellarg($siteUser . ':www-data') . ' ' . escapeshellarg($destDir)
              . ' && echo BACKLINK_OK';
    $r = lpc_demo_box_ssh($applyCmd);
    $ok = strpos($r['output'], 'BACKLINK_OK') !== false;
    return ['ok' => $ok, 'message' => $ok ? 'Back-link badge deployed' : ('Deploy failed: ' . $r['output'])];
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

/**
 * Candidate premium Kadence templates shortlisted for rebuilding the sites that
 * came back "not great" in Scott's review — curated by hand from the real
 * catalog (api.startertemplatecloud.com), not auto-generated, since "looks
 * good" is a judgment call only Scott can make. Grouped by which sites each
 * one is a candidate for. Scott reviews the live preview link for each and
 * marks yes/no before any site actually gets rebuilt with it.
 */
function lpc_kadence_template_candidates(): array
{
    return [
        'Home services / trades' => [
            ['slug' => 'g61', 'name' => 'Roofing', 'pro' => true, 'pages' => 7],
            ['slug' => 'g62', 'name' => 'Lawn Care', 'pro' => true, 'pages' => 7],
            ['slug' => 'g58', 'name' => 'Painting Service', 'pro' => false, 'pages' => 7],
            ['slug' => 'g59', 'name' => 'Cleaning Service', 'pro' => false, 'pages' => 6],
            ['slug' => 'g54', 'name' => 'Builder', 'pro' => true, 'pages' => 6],
        ],
        'Legal' => [
            ['slug' => 'g10', 'name' => 'Law Firm', 'pro' => false, 'pages' => 6],
        ],
        'Medical / dental' => [
            ['slug' => 'g55', 'name' => 'Dentist', 'pro' => true, 'pages' => 6],
        ],
    ];
}

function lpc_kadence_template_verdicts_file(): string
{
    return BASE_DIR . '/.lpc_template_review.json';
}

function lpc_kadence_template_verdicts(): array
{
    return json_decode((string) @file_get_contents(lpc_kadence_template_verdicts_file()), true) ?: [];
}

function lpc_kadence_set_template_verdict(string $slug, string $verdict): array
{
    if (!in_array($verdict, ['yes', 'no'], true)) {
        return ['ok' => false, 'message' => 'Invalid verdict'];
    }
    $verdicts = lpc_kadence_template_verdicts();
    $verdicts[$slug] = $verdict;
    @file_put_contents(lpc_kadence_template_verdicts_file(), json_encode($verdicts, JSON_PRETTY_PRINT));
    return ['ok' => true, 'message' => 'Saved'];
}
