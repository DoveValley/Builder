<?php
/**
 * tools/hestia_probe.php — does HestiaCP actually do what the fleet needs?
 *
 * This is the probe that was written on paper in July and never run. It does not
 * read documentation; it creates a real site on a real box, uploads a real file
 * over FTP, fetches it back over HTTP, installs a real certificate, and then
 * removes everything it made. Every row it prints is a measurement.
 *
 * The two capabilities under test, in the order they matter:
 *   API — create / list / exists / delete a site, unattended
 *   FTP — log in as that site, upload, and have the bytes served
 *
 * USAGE (run as www-data; it writes nothing outside /tmp)
 *   sudo -u www-data php tools/hestia_probe.php \
 *        --host=1.2.3.4 --key=ACCESS_KEY --secret=SECRET_KEY \
 *        --domain=probe1.yourdomain.com [--ip=1.2.3.4] [--keep]
 *
 *   ...or put the credentials in admin/infra/config/hestia.json and pass only
 *   --domain. Shape: {"host":"","port":8083,"access_key":"","secret_key":"",
 *                     "default_ip":"","contact_email":""}
 *
 * --domain must be a name you control and are willing to have created and then
 * DELETED on this server. The probe also creates a throwaway SUBDOMAIN of it to
 * prove two sites can share one account without sharing FTP access; both are
 * removed at the end. HTTP checks force their own DNS resolution, so the name
 * does not have to be pointed at the box first.
 *
 * --site_user names the single account every site is filed under (default
 * "fleet"). The probe deliberately LEAVES that account behind — deleting it is
 * what would wipe a real server — so remove it by hand to fully reset a test box.
 *
 * --keep leaves both sites in place for inspection instead of tearing them down.
 */

require_once __DIR__ . '/../admin/infra/lib/hestia.php';

/* ─────────────────────────── arguments + config ─────────────────────────── */

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/i', $a, $m)) $opt[$m[1]] = $m[2] ?? '1';
}

$cfgPath = __DIR__ . '/../admin/infra/config/hestia.json';
$cfg     = is_readable($cfgPath) ? (json_decode((string) file_get_contents($cfgPath), true) ?: []) : [];
// hestia.json holds a servers[] list (same shape as servers.json) so the Servers
// tab can manage several. The probe tests one box: --server picks it by id,
// otherwise the first entry. A flat single-server object still works.
if (!empty($cfg['servers']) && is_array($cfg['servers'])) {
    $want = trim((string) ($opt['server'] ?? ''));
    $pick = $cfg['servers'][0];
    if ($want !== '') {
        foreach ($cfg['servers'] as $s) if (($s['id'] ?? '') === $want) { $pick = $s; break; }
    }
    $cfg = is_array($pick) ? $pick : [];
}

$server = [
    'host'          => $opt['host']   ?? ($cfg['host']          ?? ''),
    'port'          => (int) ($opt['port'] ?? ($cfg['port']     ?? 8083)),
    'access_key'    => $opt['key']    ?? ($cfg['access_key']    ?? ''),
    'secret_key'    => $opt['secret'] ?? ($cfg['secret_key']    ?? ''),
    'api_user'      => $opt['user']   ?? ($cfg['api_user']      ?? 'admin'),
    'api_password'  => $opt['pass']   ?? ($cfg['api_password']  ?? ''),
    'contact_email' => $cfg['contact_email'] ?? '',
    'package'       => $cfg['package']       ?? 'default',
    'site_user'     => $opt['site_user'] ?? ($cfg['site_user'] ?? 'fleet'),
];
$server['default_ip'] = $opt['ip'] ?? ($cfg['default_ip'] ?? $server['host']);

$domain = strtolower(trim($opt['domain'] ?? ''));
$keep   = isset($opt['keep']);

if ($server['host'] === '' || $domain === '') {
    fwrite(STDERR, "Need --host (or config/hestia.json) and --domain.\nSee the header of this file.\n");
    exit(2);
}
if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
    fwrite(STDERR, "--domain does not look like a hostname: {$domain}\n");
    exit(2);
}

/* ───────────────────────────── result plumbing ──────────────────────────── */

$rows = [];
/** @var array{user:string,ftp_user:string,ftp_pass:string,docroot:string} $site */
$site  = ['user' => '', 'ftp_user' => '', 'ftp_pass' => '', 'docroot' => ''];
$site2 = ['user' => '', 'ftp_user' => '', 'ftp_pass' => '', 'docroot' => ''];
// A second domain on the same box, to prove the shared account still isolates
// FTP logins. A subdomain of the probe domain needs no extra DNS.
$domain2 = '';

function check(string $name, callable $fn): bool
{
    global $rows;
    $t0 = microtime(true);
    try {
        $r = $fn();
    } catch (Throwable $e) {
        $r = ['ok' => false, 'note' => get_class($e) . ': ' . $e->getMessage()];
    }
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $stat = $r['ok'] === null ? 'SKIP' : ($r['ok'] ? 'PASS' : 'FAIL');
    $rows[] = [$stat, $name, $r['note'] ?? '', $ms];
    printf("  %-4s  %-42s %6dms  %s\n", $stat, $name, $ms, $r['note'] ?? '');
    return $r['ok'] === true;
}

/* ─────────────────────────── transport helpers ──────────────────────────── */

/**
 * FTP via curl rather than PHP's ext/ftp. Both are available on the factory box,
 * but curl is what the console already uses everywhere else, and it gives the
 * same passive/timeout behaviour the Deploy tab gets. (The probe_ prefix is not
 * cosmetic: ext/ftp already owns the names ftp_put() and ftp_list().)
 */
function probe_ftp_put(array $s, string $remote, string $content): array
{
    $tmp = tmpfile();
    fwrite($tmp, $content);
    rewind($tmp);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'ftp://' . $s['host'] . '/' . ltrim($remote, '/'),
        CURLOPT_USERPWD        => $s['ftp_user'] . ':' . $s['ftp_pass'],
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $tmp,
        CURLOPT_INFILESIZE     => strlen($content),
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $ok  = curl_exec($ch) !== false;
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($tmp);
    return ['ok' => $ok, 'error' => $err, 'code' => $code];
}

/** Fetch a file over FTP. Used to prove one site CANNOT read another's. */
function probe_ftp_get(array $s, string $remote): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'ftp://' . $s['host'] . '/' . ltrim($remote, '/'),
        CURLOPT_USERPWD        => $s['ftp_user'] . ':' . $s['ftp_pass'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $out !== false, 'body' => is_string($out) ? $out : '', 'error' => $err];
}

/** Directory listing — proves login separately from write permission. */
function probe_ftp_list(array $s): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'ftp://' . $s['host'] . '/',
        CURLOPT_USERPWD        => $s['ftp_user'] . ':' . $s['ftp_pass'],
        CURLOPT_FTPLISTONLY    => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $out !== false, 'error' => $err, 'listing' => is_string($out) ? trim($out) : ''];
}

/**
 * Fetch a URL forcing the domain to resolve to the probe box, so the test does
 * not depend on public DNS having propagated. Returns cert subject too.
 */
function fetch(string $scheme, string $domain, string $ip, string $path = '/'): array
{
    $port = $scheme === 'https' ? 443 : 80;
    $ch   = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $scheme . '://' . $domain . $path,
        CURLOPT_RESOLVE        => [$domain . ':' . $port . ':' . $ip],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CERTINFO       => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'ok'      => $body !== false,
        'code'    => $code,
        'body'    => is_string($body) ? $body : '',
        'subject' => $info['certinfo'][0]['Subject'] ?? '',
        'error'   => $err,
    ];
}

/* ──────────────────────────────── the probe ─────────────────────────────── */

$marker = 'hestia-probe-' . bin2hex(random_bytes(6));

echo "\nHestiaCP capability probe\n";
echo "  target   {$server['host']}:{$server['port']}\n";
echo "  domain   {$domain}\n";
echo "  auth     " . ($server['access_key'] !== '' ? 'access key' : 'admin user/password') . "\n";
echo "  teardown " . ($keep ? 'DISABLED (--keep)' : 'enabled') . "\n\n";

echo "API\n";

check('API reachable and authenticated', function () use ($server) {
    $p = hestia_probe($server);
    return ['ok' => $p['ok'], 'note' => $p['ok'] ? '' : $p['error']];
});

check('Server identifies itself (version)', function () use ($server) {
    $i = hestia_server_info($server);
    if (!is_array($i)) return ['ok' => false, 'note' => 'no parseable sys-info'];
    $k = array_key_first($i);
    $v = $i[$k]['VERSION'] ?? ($i['VERSION'] ?? '');
    return ['ok' => $v !== '', 'note' => $v !== '' ? 'Hestia ' . $v : 'version field absent'];
});

check('FTP daemon is running', function () use ($server) {
    $r = hestia_api($server, 'v-list-sys-services', ['json'], false);
    if (!$r['ok'] || !is_array($r['json'])) return ['ok' => null, 'note' => 'service list unreadable'];
    foreach ($r['json'] as $name => $s) {
        if (preg_match('/ftp/i', (string) $name)) {
            $state = strtolower((string) ($s['STATE'] ?? ''));
            return ['ok' => $state === 'running', 'note' => $name . ' ' . ($state ?: '?')];
        }
    }
    return ['ok' => false, 'note' => 'no FTP service present — Hestia installed without one'];
});

$created = check('Create site under the fleet account', function () use ($server, $domain, &$site) {
    $u = 'up' . bin2hex(random_bytes(3));
    $p = bin2hex(random_bytes(10)) . 'Aa1!';
    $r = hestia_create_site($server, $domain, $u, $p, $server['default_ip']);
    if ($r['ok']) {
        $site = ['user' => $r['user'], 'ftp_user' => $r['ftp_user'], 'ftp_pass' => $r['ftp_pass'],
                 'docroot' => $r['docroot']];
    }
    return ['ok' => $r['ok'], 'note' => $r['message']];
});

check('Domain is listed back', function () use ($server, $domain, $site) {
    if ($site['user'] === '') return ['ok' => null, 'note' => 'nothing was created'];
    foreach (hestia_list_sites($server) as $d) {
        if (strcasecmp($d['name'], $domain) === 0) return ['ok' => true, 'note' => $d['docroot']];
    }
    return ['ok' => false, 'note' => 'created but absent from the listing'];
});

check('Idempotency guard (exists-check)', function () use ($server, $domain, $site) {
    if ($site['user'] === '') return ['ok' => null, 'note' => 'nothing was created'];
    $hit  = hestia_site_exists($server, $domain, $site['user']);
    $miss = hestia_site_exists($server, 'absent-' . bin2hex(random_bytes(4)) . '.test', $site['user']);
    return ['ok' => $hit && !$miss, 'note' => $hit ? ($miss ? 'false positive on a missing domain' : '') : 'false negative'];
});

check('Cost of listing the whole server', function () use ($server) {
    $t0    = microtime(true);
    $users = hestia_list_users($server);
    // An unreachable box also returns zero users, and "0 sites in 0ms" would
    // otherwise print as a PASS — the measurement has to prove it measured something.
    if ($users === []) return ['ok' => null, 'note' => 'no users returned; nothing to measure'];
    $sites = hestia_list_sites($server);
    $ms    = (int) round((microtime(true) - $t0) * 1000);
    $calls = 1 + count($users);
    $per   = $ms / $calls;
    return ['ok' => true, 'note' => sprintf(
        '%d users, %d sites, %d API calls, ~%dms each -> ~%ds at 500 sites',
        count($users), count($sites), $calls, (int) $per, (int) round($per * 501 / 1000)
    )];
});

echo "\nFTP\n";

check('FTP login', function () use ($server, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site to log in to'];
    $s = $site + ['host' => $server['host']];
    $r = probe_ftp_list($s);
    return ['ok' => $r['ok'], 'note' => $r['ok'] ? 'as ' . $site['ftp_user'] : $r['error']];
});

check('FTP upload into the docroot', function () use ($server, $site, $marker) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site to upload to'];
    $s = $site + ['host' => $server['host']];
    $r = probe_ftp_put($s, 'index.html', "<!doctype html><title>probe</title>{$marker}\n");
    return ['ok' => $r['ok'], 'note' => $r['ok'] ? 'index.html written' : ($r['error'] ?: 'FTP ' . $r['code'])];
});

check('Uploaded bytes are served over HTTP', function () use ($server, $domain, $marker, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'nothing uploaded'];
    $r = fetch('http', $domain, $server['default_ip']);
    if (!$r['ok']) return ['ok' => false, 'note' => $r['error']];
    if (strpos($r['body'], $marker) !== false) return ['ok' => true, 'note' => 'HTTP 200, marker matched'];
    return ['ok' => false, 'note' => 'HTTP ' . $r['code'] . ' but the marker is absent — '
                                   . 'FTP path and docroot disagree'];
});

echo "\nShared fleet account\n";

check('Second site reuses the same account', function () use ($server, $domain, $site, &$site2, &$domain2) {
    if ($site['user'] === '') return ['ok' => null, 'note' => 'no first site'];
    $domain2 = 'p2-' . bin2hex(random_bytes(3)) . '.' . $domain;
    $u = 'up' . bin2hex(random_bytes(3));
    $p = bin2hex(random_bytes(10)) . 'Aa1!';
    $r = hestia_create_site($server, $domain2, $u, $p, $server['default_ip']);
    if (!$r['ok']) { $domain2 = ''; return ['ok' => false, 'note' => $r['message']]; }
    $site2 = ['user' => $r['user'], 'ftp_user' => $r['ftp_user'], 'ftp_pass' => $r['ftp_pass'],
              'docroot' => $r['docroot']];
    $same = $r['user'] === $site['user'];
    return ['ok' => $same, 'note' => $same
        ? 'both under ' . $r['user'] . ' — no second Linux account created'
        : 'expected ' . $site['user'] . ', got ' . $r['user']];
});

check('Separate FTP logins despite the shared account', function () use ($server, $site, $site2, $marker) {
    if ($site2['ftp_user'] === '') return ['ok' => null, 'note' => 'no second site'];
    if ($site2['ftp_user'] === $site['ftp_user']) {
        return ['ok' => false, 'note' => 'both sites got the SAME FTP login'];
    }
    return ['ok' => true, 'note' => 'distinct logins issued per domain'];
});

check("Site B's FTP cannot read site A's files", function () use ($server, $domain, $site, $site2, $marker) {
    if ($site2['ftp_user'] === '') return ['ok' => null, 'note' => 'no second site'];
    $b = $site2 + ['host' => $server['host']];

    // B's own docroot is empty, so a plain read must not return A's marker...
    $own = probe_ftp_get($b, 'index.html');
    if ($own['ok'] && strpos($own['body'], $marker) !== false) {
        return ['ok' => false, 'note' => 'B landed in A\'s docroot — the FTP paths are not per-domain'];
    }
    // ...and neither must an explicit walk up and across into A's directory.
    $cross = probe_ftp_get($b, '../../' . $domain . '/public_html/index.html');
    if ($cross['ok'] && strpos($cross['body'], $marker) !== false) {
        return ['ok' => false, 'note' => 'traversal succeeded: B read A\'s index.html'];
    }
    return ['ok' => true, 'note' => 'both direct read and traversal denied'];
});

echo "\nSSL (Cloudflare Origin CA path)\n";

check('Custom certificate installs from an FTP upload', function () use ($server, $domain, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    if (trim((string) shell_exec('command -v openssl')) === '') {
        return ['ok' => null, 'note' => 'openssl absent locally; cannot mint a test cert'];
    }
    // Stand-in for a Cloudflare Origin CA cert: same shape (crt + key), and the
    // install path is identical. What is under test is whether the API can be
    // handed a certificate at all without shell access to the box.
    $dir = '/tmp/hestia_probe_' . bin2hex(random_bytes(4));
    @mkdir($dir, 0700);
    $cmd = sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj %s -keyout %s -out %s 2>&1',
        escapeshellarg('/CN=' . $domain),
        escapeshellarg($dir . '/k.pem'),
        escapeshellarg($dir . '/c.pem')
    );
    shell_exec($cmd);
    if (!is_file($dir . '/c.pem') || !is_file($dir . '/k.pem')) {
        return ['ok' => false, 'note' => 'openssl did not produce a cert'];
    }

    // The API cannot take cert CONTENTS, only a server-side directory, so the
    // files have to travel by FTP first. If this fails, Full (strict) has no
    // unattended path on Hestia and that is a blocking finding, not a detail.
    $s   = $site + ['host' => $server['host']];
    $up1 = probe_ftp_put($s, 'ssl/' . $domain . '.crt', (string) file_get_contents($dir . '/c.pem'));
    $up2 = probe_ftp_put($s, 'ssl/' . $domain . '.key', (string) file_get_contents($dir . '/k.pem'));
    @unlink($dir . '/c.pem'); @unlink($dir . '/k.pem'); @rmdir($dir);

    if (!$up1['ok'] || !$up2['ok']) {
        return ['ok' => false, 'note' => 'could not upload cert files: ' . ($up1['error'] ?: $up2['error'])];
    }
    $sslDir = rtrim($site['docroot'], '/') . '/ssl';
    $r = hestia_install_cert($server, $domain, $site['user'], $sslDir);
    return ['ok' => $r['ok'], 'note' => $r['message']];
});

check('HTTPS serves the installed certificate', function () use ($server, $domain, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $r = fetch('https', $domain, $server['default_ip']);
    if (!$r['ok']) return ['ok' => false, 'note' => $r['error']];
    $matches = stripos($r['subject'], $domain) !== false;
    return ['ok' => $matches, 'note' => $matches
        ? 'peer cert CN matches the domain'
        : 'served cert subject is "' . ($r['subject'] ?: 'unknown') . '" — the a.q111.xyz failure mode'];
});

echo "\nTeardown\n";

if ($keep) {
    $rows[] = ['SKIP', 'Delete sites', '--keep: left in place for inspection', 0];
    printf("  %-4s  %-42s %6dms  %s\n", 'SKIP', 'Delete sites', 0, '--keep: left in place');
    echo "\n  account  {$site['user']}\n";
    if ($site['ftp_user']  !== '') echo "  {$domain}  ftp {$site['ftp_user']} / {$site['ftp_pass']}\n";
    if ($site2['ftp_user'] !== '') echo "  {$domain2}  ftp {$site2['ftp_user']} / {$site2['ftp_pass']}\n";
    echo "  clean up with: v-delete-web-domain {$site['user']} <domain> (per site), "
       . "then v-delete-user {$site['user']}\n";
} else {
    check('Delete site A', function () use ($server, $domain, $site) {
        if ($site['user'] === '') return ['ok' => null, 'note' => 'nothing to delete'];
        $r = hestia_delete_site($server, $domain, $site['user']);
        return ['ok' => $r['ok'], 'note' => $r['message']];
    });

    // THE check that guards the shared-account model. Deleting one site must not
    // disturb the account, because under one-user-per-server that account owns
    // every other site on the box — v-delete-user here would be a fleet-wide
    // outage triggered by removing a single domain.
    check('Deleting A left site B and the account alone', function () use ($server, $site, $site2, $domain2) {
        if ($site['user'] === '') return ['ok' => null, 'note' => 'nothing to verify'];
        $userAlive = in_array($site['user'], hestia_list_users($server), true);
        $bAlive    = $domain2 === '' ? null : hestia_site_exists($server, $domain2, $site['user']);
        if (!$userAlive) return ['ok' => false, 'note' => 'account ' . $site['user'] . ' was deleted — '
                                                        . 'this would take every site on the server with it'];
        if ($bAlive === false) return ['ok' => false, 'note' => 'site B vanished when A was deleted'];
        return ['ok' => true, 'note' => $bAlive === null
            ? 'account intact (no site B to check)'
            : 'account intact, site B still serving'];
    });

    check('Delete site B', function () use ($server, $site2, $domain2) {
        if ($domain2 === '') return ['ok' => null, 'note' => 'no second site'];
        $r = hestia_delete_site($server, $domain2, $site2['user']);
        return ['ok' => $r['ok'], 'note' => $r['message']];
    });

    check('Both domains gone; account deliberately kept', function () use ($server, $domain, $domain2, $site) {
        if ($site['user'] === '') return ['ok' => null, 'note' => 'nothing to verify'];
        $aGone = !hestia_site_exists($server, $domain, $site['user']);
        $bGone = $domain2 === '' || !hestia_site_exists($server, $domain2, $site['user']);
        if ($aGone && $bGone) {
            return ['ok' => true, 'note' => 'remove user ' . $site['user'] . ' by hand to fully reset the box'];
        }
        return ['ok' => false, 'note' => ($aGone ? '' : $domain . ' remains; ') . ($bGone ? '' : $domain2 . ' remains')];
    });
}

/* ──────────────────────────────── verdict ───────────────────────────────── */

$pass = $fail = $skip = 0;
foreach ($rows as $r) {
    if ($r[0] === 'PASS') $pass++; elseif ($r[0] === 'FAIL') $fail++; else $skip++;
}

echo "\n" . str_repeat('─', 78) . "\n";
printf("  %d passed, %d failed, %d skipped\n", $pass, $fail, $skip);

if ($fail > 0) {
    echo "\n  Failed:\n";
    foreach ($rows as $r) if ($r[0] === 'FAIL') echo "    - {$r[1]}: {$r[2]}\n";
}
if ($skip > 0) {
    echo "\n  Skipped (inconclusive, not a pass):\n";
    foreach ($rows as $r) if ($r[0] === 'SKIP') echo "    - {$r[1]}: {$r[2]}\n";
}
echo "\n";

exit($fail > 0 ? 1 : 0);
