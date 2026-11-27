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
 * escapeshellarg()'d pieces.
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

/** Domains straight from Hestia — no local cache, nothing to drift. */
function lpc_demo_box_domains(): array
{
    $server = lpc_demo_box_hestia_server();
    $r = hestia_api($server, 'v-list-web-domains', [LPC_DEMO_BOX_SITE_USER, 'json'], false);
    if (!$r['ok'] || !is_array($r['json'])) return [];
    $domains = array_keys($r['json']);
    sort($domains);
    return $domains;
}

/**
 * Every real site on this box. Deliberately does NOT check theme or running
 * status here — both require live WordPress bootstraps / HTTP pings across
 * every site on a 2-vCPU box, which made this page take up to ~20s to load
 * and caused false "Not responding" badges from the check's own cold-start
 * contention. That's now a separate, explicit action — see
 * lpc_demo_check_sites_status() — triggered by a button instead of every
 * page load.
 */
/** Where a connected real domain's niche is remembered — 'demo-{niche}.q111.xyz'
 *  can derive its niche from the domain string itself, but a real business
 *  domain (meaderestoration.com) can't, so once a site's domain no longer
 *  matches that pattern its niche has to come from somewhere durable instead. */
function lpc_demo_niche_overrides_file(): string
{
    return BASE_DIR . '/.lpc_demo_niche_overrides.json';
}

function lpc_demo_niche_overrides(): array
{
    return json_decode((string) @file_get_contents(lpc_demo_niche_overrides_file()), true) ?: [];
}

function lpc_demo_set_niche_override(string $domain, string $niche): void
{
    $overrides = lpc_demo_niche_overrides();
    $overrides[$domain] = $niche;
    @file_put_contents(lpc_demo_niche_overrides_file(), json_encode($overrides, JSON_PRETTY_PRINT));
}

/** Same file-per-concern pattern as the niche overrides above: the Site Demos
 *  screen's own custom sort order + a free-text note per domain, since Hestia
 *  itself has no concept of either and always returns domains alphabetically. */
function lpc_demo_display_file(): string
{
    return BASE_DIR . '/.lpc_demo_display.json';
}

function lpc_demo_display_data(): array
{
    $data = json_decode((string) @file_get_contents(lpc_demo_display_file()), true) ?: [];
    return [
        'order' => $data['order'] ?? [],
        'notes' => $data['notes'] ?? [],
    ];
}

function lpc_demo_save_display_data(array $data): void
{
    @file_put_contents(lpc_demo_display_file(), json_encode($data, JSON_PRETTY_PRINT));
}

/** Stored order first (dropping any domain no longer on the box), then any
 *  domain Hestia reports that isn't in the stored order yet, appended at the
 *  end in its original (alphabetical) position — so a freshly built site
 *  always shows up without needing the order to be re-saved first. */
function lpc_demo_ordered_domains(array $domains): array
{
    $order = lpc_demo_display_data()['order'];
    $known = array_values(array_intersect($order, $domains));
    $new = array_values(array_diff($domains, $known));
    return array_merge($known, $new);
}

function lpc_demo_move_domain(array $domains, string $domain, string $dir): void
{
    $order = lpc_demo_ordered_domains($domains);
    $pos = array_search($domain, $order, true);
    if ($pos === false) return;
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($swap < 0 || $swap >= count($order)) return;
    [$order[$pos], $order[$swap]] = [$order[$swap], $order[$pos]];
    lpc_demo_save_display_data(['order' => $order, 'notes' => lpc_demo_display_data()['notes']]);
}

function lpc_demo_set_note(string $domain, string $note): void
{
    $data = lpc_demo_display_data();
    $data['notes'][$domain] = $note;
    lpc_demo_save_display_data($data);
}

function lpc_demo_list_sites(): array
{
    $siteUser = LPC_DEMO_BOX_SITE_USER;
    $domains = lpc_demo_box_domains();
    $overrides = lpc_demo_niche_overrides();

    $sites = [];
    foreach ($domains as $d) {
        $niche = preg_match('/^demo-/', $d)
            ? preg_replace('/^demo-/', '', explode('.', $d)[0])
            : ($overrides[$d] ?? explode('.', $d)[0]);
        $sites[] = [
            'domain'  => $d,
            'niche'   => $niche,
            'theme'   => null,   // unknown until lpc_demo_check_sites_status() runs
            'running' => null,   // unknown until lpc_demo_check_sites_status() runs
            'docroot' => '/home/' . $siteUser . '/web/' . $d . '/public_html',
        ];
    }
    return $sites;
}

/**
 * The expensive live check, run only on explicit request (the "Check Status"
 * button), never on page load. Themes are fetched in parallel over one SSH
 * call (each lookup is a full WordPress bootstrap — connects to its DB,
 * loads core+theme — ~6.8s for all sites even in parallel on this box).
 * Running status is batched at 6 domains at a time: each domain cold-starts
 * its own tiny php-fpm pool on demand (pm.max_children 4-8), and firing all
 * of them at once caused real resource contention on this 2-vCPU box — some
 * requests genuinely timed out under the burst, misreporting real running
 * sites as down. Batches of 6 avoid that while still beating one-at-a-time.
 */
function lpc_demo_check_sites_status(): array
{
    $siteUser = LPC_DEMO_BOX_SITE_USER;
    $domains = lpc_demo_box_domains();
    if (!$domains) return [];

    $themes = [];
    $cmds = [];
    foreach ($domains as $d) {
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

    $out = [];
    foreach ($domains as $d) {
        $out[$d] = ['theme' => $themes[$d] ?? '—', 'running' => $running[$d] ?? false];
    }
    return $out;
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
