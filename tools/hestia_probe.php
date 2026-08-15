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
 * Raw FTP verbs (RNFR/RNTO, DELE, MKD, RMD, SITE CHMOD) that have no dedicated
 * curl option. They run as QUOTE commands before the transfer, so the URL is
 * just something to connect to — the commands are the payload.
 *
 * curl reports a failed QUOTE as a failed transfer, which is what we want: a
 * server that silently ignores an unsupported verb must not read as a pass.
 */
function probe_ftp_quote(array $s, array $cmds, string $path = '/'): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'ftp://' . $s['host'] . '/' . ltrim($path, '/'),
        CURLOPT_USERPWD        => $s['ftp_user'] . ':' . $s['ftp_pass'],
        CURLOPT_QUOTE          => array_values($cmds),
        CURLOPT_NOBODY         => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $ok  = curl_exec($ch) !== false;
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $ok, 'error' => $err];
}

/** True if $name appears in the site's FTP listing. */
function probe_ftp_has(array $s, string $name, string $path = '/'): bool
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'ftp://' . $s['host'] . '/' . ltrim($path, '/'),
        CURLOPT_USERPWD        => $s['ftp_user'] . ':' . $s['ftp_pass'],
        CURLOPT_FTPLISTONLY    => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    if (!is_string($out)) return false;
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if (trim($line) === $name) return true;
    }
    return false;
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
        // Serial distinguishes one cert from its replacement when both have the
        // same CN — which is exactly the case when testing a renewal.
        'serial'  => $info['certinfo'][0]['Serial Number'] ?? ($info['certinfo'][0]['Serial'] ?? ''),
        'issuer'  => $info['certinfo'][0]['Issuer'] ?? '',
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

$apiOk = check('API reachable and authenticated', function () use ($server) {
    $p = hestia_probe($server);
    return ['ok' => $p['ok'], 'note' => $p['ok'] ? '' : $p['error']];
});

/*
 * STOP HERE if the credentials were refused. Every later check would make its
 * own request with the same bad key, and Hestia runs fail2ban on port 8083 —
 * a full run against a wrong key is ~30 failed logins in a few seconds, which
 * bans this factory from the panel and turns a fixable credentials problem into
 * an unreachable box. Learned the hard way on 2026-08-15, and previously on the
 * Plesk box's plesk-panel jail.
 *
 * A connection failure is NOT treated this way: that is where an existing ban
 * already shows up, and saying so is more useful than silence.
 */
if (!$apiOk) {
    $why = hestia_probe($server)['error'] ?? '';
    echo "\n" . str_repeat('─', 78) . "\n";
    if (stripos($why, 'connect') !== false || stripos($why, 'timed out') !== false) {
        echo "  Port {$server['port']} did not answer at all.\n\n"
           . "  If it answered earlier, this factory is probably fail2ban-banned. On the box:\n"
           . "      fail2ban-client unban 187.127.254.206\n"
           . "  and add 187.127.254.206 to ignoreip in /etc/fail2ban/jail.local to stop it recurring.\n\n";
    } else {
        echo "  Stopped after the first check: {$why}\n\n"
           . "  The remaining 37 checks would each retry the same rejected credentials, and\n"
           . "  Hestia bans repeated failures on port {$server['port']}. Fix the key first.\n\n";
    }
    exit(1);
}

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

echo "\nAccount lifecycle\n";

/*
 * Everything in this section runs against a THROWAWAY account, never the fleet
 * account. v-delete-user is the one destructive verb Hestia has — under the
 * one-user-per-server model it would take every site on the box — so the only
 * safe way to test it is on an account that owns nothing. The name is randomised
 * and the account is removed at the end of the section, pass or fail.
 */
$acct     = 'pr' . bin2hex(random_bytes(3));   // <=30 chars, starts alpha
$acctPass = bin2hex(random_bytes(10)) . 'Aa1!';
$acctMade = false;

$acctMade = check('Create a throwaway account', function () use ($server, $acct, $acctPass) {
    // NOT probe@<host>: the host is an IP, and Hestia rejects an address whose
    // domain is a bare IP with exit code 2 ("invalid value") — which reads as a
    // bad username or password rather than a bad email. example.com is reserved
    // for exactly this (RFC 2606) and always parses.
    $email = $server['contact_email'] ?: 'probe@example.com';
    $r = hestia_api($server, 'v-add-user', [$acct, $acctPass, $email, $server['package'] ?: 'default', 'Probe']);
    return ['ok' => $r['ok'], 'note' => $r['ok'] ? 'v-add-user ' . $acct : $r['message']];
});

check('Account is listed back with its package', function () use ($server, $acct, $acctMade) {
    if (!$acctMade) return ['ok' => null, 'note' => 'no account created'];
    $r = hestia_api($server, 'v-list-user', [$acct, 'json'], false);
    $pkg = $r['json'][$acct]['PACKAGE'] ?? '';
    return ['ok' => $pkg !== '', 'note' => $pkg !== '' ? 'package ' . $pkg : 'user absent from v-list-user'];
});

check('Modify: change the contact email', function () use ($server, $acct, $acctMade) {
    if (!$acctMade) return ['ok' => null, 'note' => 'no account'];
    $want = 'changed-' . bin2hex(random_bytes(3)) . '@example.com';
    $r = hestia_api($server, 'v-change-user-contact', [$acct, $want]);
    if (!$r['ok']) return ['ok' => false, 'note' => 'v-change-user-contact: ' . $r['message']];
    // Judge by re-reading, not by the exit code — a 0 that did not persist is
    // the failure mode that matters when provisioning unattended.
    $c = hestia_api($server, 'v-list-user', [$acct, 'json'], false);
    $got = $c['json'][$acct]['CONTACT'] ?? '';
    return ['ok' => strcasecmp($got, $want) === 0, 'note' => $got === $want ? 'persisted' : "read back '{$got}'"];
});

check('Modify: change the password', function () use ($server, $acct, $acctMade) {
    if (!$acctMade) return ['ok' => null, 'note' => 'no account'];
    $r = hestia_api($server, 'v-change-user-password', [$acct, bin2hex(random_bytes(12)) . 'Bb2!']);
    return ['ok' => $r['ok'], 'note' => $r['ok'] ? 'accepted' : $r['message']];
});

check('Modify: change the package', function () use ($server, $acct, $acctMade) {
    if (!$acctMade) return ['ok' => null, 'note' => 'no account'];
    $pk = hestia_api($server, 'v-list-user-packages', ['json'], false);
    $names = is_array($pk['json']) ? array_keys($pk['json']) : [];
    $cur = hestia_api($server, 'v-list-user', [$acct, 'json'], false)['json'][$acct]['PACKAGE'] ?? '';
    $alt = '';
    foreach ($names as $n) if ($n !== $cur) { $alt = $n; break; }
    if ($alt === '') return ['ok' => null, 'note' => 'only one package on the box; nothing to switch to'];
    $r = hestia_api($server, 'v-change-user-package', [$acct, $alt]);
    if (!$r['ok']) return ['ok' => false, 'note' => 'v-change-user-package: ' . $r['message']];
    $got = hestia_api($server, 'v-list-user', [$acct, 'json'], false)['json'][$acct]['PACKAGE'] ?? '';
    return ['ok' => $got === $alt, 'note' => $got === $alt ? $cur . ' -> ' . $alt : "read back '{$got}'"];
});

check('Suspend and unsuspend', function () use ($server, $acct, $acctMade) {
    if (!$acctMade) return ['ok' => null, 'note' => 'no account'];
    $s = hestia_api($server, 'v-suspend-user', [$acct]);
    if (!$s['ok']) return ['ok' => false, 'note' => 'v-suspend-user: ' . $s['message']];
    $mid = hestia_api($server, 'v-list-user', [$acct, 'json'], false)['json'][$acct]['SUSPENDED'] ?? '';
    $u = hestia_api($server, 'v-unsuspend-user', [$acct]);
    if (!$u['ok']) return ['ok' => false, 'note' => 'suspended but v-unsuspend-user failed: ' . $u['message']];
    $end = hestia_api($server, 'v-list-user', [$acct, 'json'], false)['json'][$acct]['SUSPENDED'] ?? '';
    $ok = strcasecmp($mid, 'yes') === 0 && strcasecmp($end, 'no') === 0;
    return ['ok' => $ok, 'note' => $ok ? 'yes -> no' : "SUSPENDED read '{$mid}' then '{$end}'"];
});

check('Delete the throwaway account', function () use ($server, $acct, $acctMade) {
    if (!$acctMade) return ['ok' => null, 'note' => 'no account'];
    $r = hestia_api($server, 'v-delete-user', [$acct]);
    if (!$r['ok']) return ['ok' => false, 'note' => 'v-delete-user: ' . $r['message'] . ' — REMOVE ' . $acct . ' BY HAND'];
    $gone = !hestia_user_exists($server, $acct);
    return ['ok' => $gone, 'note' => $gone ? 'removed' : 'still present after delete — REMOVE ' . $acct . ' BY HAND'];
});

check('The fleet account was not touched by any of that', function () use ($server) {
    $fleet = hestia_fleet_user($server);
    // "Absent" and "unreadable" are the same false from hestia_user_exists(), and
    // conflating them turns an unreachable API into a fleet-wide-outage alarm.
    // Prove the box is answering BEFORE believing anything is missing.
    if (!hestia_probe($server)['ok']) {
        return ['ok' => null, 'note' => 'API unreachable; cannot tell absent from unreadable'];
    }
    $alive = hestia_user_exists($server, $fleet);
    return ['ok' => $alive, 'note' => $alive ? $fleet . ' intact' : $fleet . ' IS GONE — every site on this box went with it'];
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

/*
 * The rest of the FTP verbs the deploy path actually uses. The Deploy tab does
 * not merely upload — it re-uploads over existing files, makes directories for
 * nested pages, and removes files that left the build. A panel that allows PUT
 * but refuses DELE or MKD would pass the upload test above and still be unable
 * to carry a second deploy of a site whose pages changed.
 */
check('FTP download (read back what was written)', function () use ($server, $site, $marker) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $s = $site + ['host' => $server['host']];
    $r = probe_ftp_get($s, 'index.html');
    if (!$r['ok']) return ['ok' => false, 'note' => $r['error']];
    return ['ok' => strpos($r['body'], $marker) !== false,
            'note' => strpos($r['body'], $marker) !== false ? 'bytes match' : 'downloaded, but content differs'];
});

check('FTP overwrite an existing file', function () use ($server, $site, $marker) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $s = $site + ['host' => $server['host']];
    $r = probe_ftp_put($s, 'index.html', "<!doctype html><title>probe</title>{$marker}-v2\n");
    if (!$r['ok']) return ['ok' => false, 'note' => $r['error'] ?: 'FTP ' . $r['code']];
    $back = probe_ftp_get($s, 'index.html');
    return ['ok' => strpos($back['body'], $marker . '-v2') !== false,
            'note' => strpos($back['body'], $marker . '-v2') !== false ? 'second write wins' : 'overwrite did not take'];
});

check('FTP mkdir (nested page directory)', function () use ($server, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $s = $site + ['host' => $server['host']];
    $r = probe_ftp_quote($s, ['MKD probedir']);
    if (!$r['ok']) return ['ok' => false, 'note' => $r['error']];
    return ['ok' => probe_ftp_has($s, 'probedir'), 'note' => probe_ftp_has($s, 'probedir') ? 'probedir created' : 'MKD returned OK but the directory is absent'];
});

check('FTP rename', function () use ($server, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $s = $site + ['host' => $server['host']];
    $put = probe_ftp_put($s, 'probe-rename-src.txt', "rename me\n");
    if (!$put['ok']) return ['ok' => null, 'note' => 'could not stage a file to rename'];
    $r = probe_ftp_quote($s, ['RNFR probe-rename-src.txt', 'RNTO probe-rename-dst.txt']);
    if (!$r['ok']) return ['ok' => false, 'note' => $r['error']];
    $moved = probe_ftp_has($s, 'probe-rename-dst.txt') && !probe_ftp_has($s, 'probe-rename-src.txt');
    return ['ok' => $moved, 'note' => $moved ? 'src -> dst' : 'RNFR/RNTO returned OK but the listing disagrees'];
});

check('FTP chmod', function () use ($server, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $s = $site + ['host' => $server['host']];
    $r = probe_ftp_quote($s, ['SITE CHMOD 644 probe-rename-dst.txt']);
    return ['ok' => $r['ok'], 'note' => $r['ok'] ? 'SITE CHMOD 644 accepted' : ($r['error'] ?: 'refused')];
});

check('FTP delete file', function () use ($server, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $s = $site + ['host' => $server['host']];
    $r = probe_ftp_quote($s, ['DELE probe-rename-dst.txt']);
    if (!$r['ok']) return ['ok' => false, 'note' => $r['error']];
    $gone = !probe_ftp_has($s, 'probe-rename-dst.txt');
    return ['ok' => $gone, 'note' => $gone ? 'removed' : 'DELE returned OK but the file is still listed'];
});

check('FTP rmdir', function () use ($server, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $s = $site + ['host' => $server['host']];
    $r = probe_ftp_quote($s, ['RMD probedir']);
    if (!$r['ok']) return ['ok' => false, 'note' => $r['error']];
    $gone = !probe_ftp_has($s, 'probedir');
    return ['ok' => $gone, 'note' => $gone ? 'removed' : 'RMD returned OK but the directory is still listed'];
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

/*
 * A certificate that installs once is not enough. Origin CA certs expire and
 * have to be replaced in place, on a live site, without taking it down — so the
 * replacement path is tested as its own case rather than assumed from the first
 * install succeeding.
 */
check('Certificate can be REPLACED in place (renewal)', function () use ($server, $domain, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $before = fetch('https', $domain, $server['default_ip'])['serial'] ?? '';
    if (trim((string) shell_exec('command -v openssl')) === '') {
        return ['ok' => null, 'note' => 'openssl absent locally'];
    }
    $dir = '/tmp/hestia_probe_' . bin2hex(random_bytes(4));
    @mkdir($dir, 0700);
    shell_exec(sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -days 60 -subj %s -keyout %s -out %s 2>&1',
        escapeshellarg('/CN=' . $domain), escapeshellarg($dir . '/k.pem'), escapeshellarg($dir . '/c.pem')
    ));
    if (!is_file($dir . '/c.pem')) return ['ok' => false, 'note' => 'openssl produced no replacement cert'];

    $s = $site + ['host' => $server['host']];
    probe_ftp_put($s, 'ssl/' . $domain . '.crt', (string) file_get_contents($dir . '/c.pem'));
    probe_ftp_put($s, 'ssl/' . $domain . '.key', (string) file_get_contents($dir . '/k.pem'));
    @unlink($dir . '/c.pem'); @unlink($dir . '/k.pem'); @rmdir($dir);

    $r = hestia_install_cert($server, $domain, $site['user'], rtrim($site['docroot'], '/') . '/ssl');
    if (!$r['ok']) return ['ok' => false, 'note' => 'reinstall refused: ' . $r['message']];
    $after = fetch('https', $domain, $server['default_ip'])['serial'] ?? '';
    if ($before === '' || $after === '') return ['ok' => null, 'note' => 'could not read cert serials to compare'];
    return ['ok' => $before !== $after, 'note' => $before !== $after
        ? 'serial changed — the new cert is being served'
        : 'serial unchanged — the old cert is still live despite a successful install'];
});

check('Force-HTTPS redirect can be switched on', function () use ($server, $domain, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $r = hestia_api($server, 'v-add-web-domain-ssl-force', [$site['user'], $domain]);
    if (!$r['ok']) return ['ok' => false, 'note' => 'v-add-web-domain-ssl-force: ' . $r['message']];
    $plain = fetch('http', $domain, $server['default_ip']);
    $isRedirect = $plain['code'] >= 300 && $plain['code'] < 400;
    return ['ok' => $isRedirect, 'note' => $isRedirect
        ? 'HTTP ' . $plain['code'] . ' -> HTTPS'
        : 'flag accepted but plain HTTP still answers ' . $plain['code']];
});

check('Certificate can be removed', function () use ($server, $domain, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $r = hestia_api($server, 'v-delete-web-domain-ssl', [$site['user'], $domain]);
    return ['ok' => $r['ok'], 'note' => $r['ok'] ? 'SSL removed from the domain' : $r['message']];
});

/*
 * Let's Encrypt is the fallback if the Origin CA path above fails. It can only
 * work when the domain's PUBLIC DNS already points at this box, because the ACME
 * challenge is fetched by Let's Encrypt, not by us — so a failure here is only
 * meaningful if DNS is actually pointed. The check resolves first and SKIPs
 * rather than reporting a misleading FAIL.
 */
check('Let\'s Encrypt issues for a real, pointed domain', function () use ($server, $domain, $site) {
    if ($site['ftp_user'] === '') return ['ok' => null, 'note' => 'no site'];
    $public = trim((string) shell_exec('dig +short ' . escapeshellarg($domain) . ' A 2>/dev/null | tail -1'));
    if ($public === '') return ['ok' => null, 'note' => 'no public A record for ' . $domain . '; ACME cannot run'];
    if ($public !== $server['default_ip']) {
        return ['ok' => null, 'note' => 'public DNS points at ' . $public . ', not ' . $server['default_ip']];
    }
    $r = hestia_api($server, 'v-add-letsencrypt-domain', [$site['user'], $domain]);
    if (!$r['ok']) return ['ok' => false, 'note' => 'v-add-letsencrypt-domain: ' . $r['message']];
    $c = fetch('https', $domain, $server['default_ip']);
    $real = stripos($c['issuer'], 'let') !== false || stripos($c['issuer'], 'encrypt') !== false;
    return ['ok' => $real, 'note' => $real ? 'issued and served' : 'command succeeded but issuer is "' . $c['issuer'] . '"'];
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
        // hestia_user_exists(), NOT a search of hestia_list_users(): that call only
        // returns the access key's own account, so a perfectly healthy fleet account
        // is absent from it and this check screamed that the whole server had been
        // wiped. Ask about the account by name instead.
        $userAlive = hestia_user_exists($server, $site['user']);
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
