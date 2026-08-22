<?php
/**
 * infra/lib/hestia.php — the HestiaCP client. THE panel client: lib/plesk.php was
 * deleted once nothing referenced it.
 *
 * Its shape is inherited from the Plesk client it replaced — same arguments,
 * same return shape) so the console can switch a server between panels by type
 * without any call site changing. Compare:
 *
 *     plesk_probe()        hestia_probe()
 *     plesk_server_info()  hestia_server_info()
 *     plesk_list_sites()   hestia_list_sites()
 *     plesk_site_exists()  hestia_site_exists()
 *     plesk_create_site()  hestia_create_site()
 *     plesk_delete_site()  hestia_delete_site()
 *
 * TRANSPORT NOTE — this is not REST. Hestia's API is a form-encoded POST to a
 * single endpoint that runs one shell utility: cmd=v-add-web-domain plus
 * positional arg1..arg9. There is no resource model, and no JSON except where a
 * v-list-* command is explicitly asked for `json` as its format argument.
 * Success or failure comes back as a BARE NUMBER in the response body. So every
 * call here has to interpret an exit code, which is why hestia_err() exists.
 *
 * On status codes, corrected 2026-08-15 against a live 1.9 box: this version DOES
 * map exit codes onto real HTTP codes (401 for a rejected key), so a non-200 is
 * not automatically a transport failure and the body is still worth reading. But
 * it is not reliable either — an API that is switched off, or a caller whose IP
 * is not allowed, still answers 200 with the body "Error". Both shapes have to
 * be handled, and the BODY is the more trustworthy of the two.
 *
 * Self-contained: no dependency on any factory code.
 */
require_once __DIR__ . '/http.php';

/** Hestia's shell exit codes, in words. Source: $HESTIA/func/main.sh. */
function hestia_err(int $code): string
{
    static $map = [
        0  => 'OK',
        1  => 'not enough arguments',
        2  => 'invalid value',
        3  => 'does not exist',
        4  => 'already exists',
        5  => 'password mismatch',
        6  => 'forbidden',
        7  => 'account disabled',
        8  => 'config parse error',
        9  => 'out of disk space',
        10 => 'account limit reached',
        11 => 'permission denied',
        12 => 'connection failed',
        13 => 'ftp error',
        14 => 'database error',
        15 => 'rrd error',
        16 => 'update failed',
        17 => 'service restart failed',
        19 => 'API is disabled or the caller IP is not allowed',
    ];
    return $map[$code] ?? ('exit code ' . $code);
}

/**
 * Auth payload. Supports BOTH of Hestia's schemes, deliberately — the same
 * hedge cf_auth_headers() makes for Cloudflare's global-key vs scoped-token.
 * Access keys are preferred (they are scopeable and revocable); the admin
 * user/password fallback exists because older boxes only have that.
 */
function hestia_auth(array $server): array
{
    $ak = trim((string) ($server['access_key'] ?? ''));
    $sk = trim((string) ($server['secret_key'] ?? ''));
    // SEPARATE FIELDS, not hash="<key>:<secret>". Sending the pair joined into
    // hash falls through to the legacy user/password branch, which can never
    // match an access key, and every call fails as "authentication failed" —
    // a wrong-credentials message for what is really a wrong-format request.
    // Verified live: access_key/secret_key gets "invalid access_key_id format",
    // i.e. the field names are recognised and the value is being validated.
    if ($ak !== '' && $sk !== '') return ['access_key' => $ak, 'secret_key' => $sk];

    $hash = trim((string) ($server['api_hash'] ?? ''));
    if ($hash !== '') return ['hash' => $hash];

    return [
        'user'     => (string) ($server['api_user'] ?? 'admin'),
        'password' => (string) ($server['api_password'] ?? ''),
    ];
}

/**
 * One API call = one shell utility.
 *
 * @param array  $args       positional args, mapped to arg1..arg9 in order
 * @param bool   $returncode true  -> body is a numeric exit code (mutations)
 *                           false -> body is the command's stdout (listings)
 * @return array{ok:bool,code:int,raw:string,json:mixed,message:string}
 */
function hestia_api(array $server, string $cmd, array $args = [], bool $returncode = true): array
{
    $host = $server['host'] ?? '';
    $port = $server['port'] ?? 8083;
    $url  = 'https://' . $host . ':' . $port . '/api/';

    $post = hestia_auth($server) + ['cmd' => $cmd, 'returncode' => $returncode ? 'yes' : 'no'];
    foreach (array_values($args) as $i => $v) {
        if ($i > 8) break;                       // arg1..arg9 is the hard ceiling
        $post['arg' . ($i + 1)] = (string) $v;
    }

    $r = infra_http('POST', $url, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query($post),
        'verify'  => false,                      // :8083 is self-signed out of the box
        'timeout' => 60,                         // v-add-user rebuilds configs; it is slow
    ]);

    $raw = trim($r['raw']);

    if ($r['error'] !== '' || $r['code'] !== 200) {
        // Prefer whatever Hestia SAID over the status code it said it with. This
        // version does set real codes (401 on a rejected key), and reporting a
        // bare "HTTP 401" throws away the one line that explains it — the panel
        // showed "HTTP 401" for a request whose body read "invalid access_key_id
        // format", which is a formatting bug wearing a credentials error's face.
        $msg = $r['error'] !== '' ? $r['error'] : ('HTTP ' . $r['code']);
        if (stripos($raw, 'error') === 0) {
            $msg = rtrim(preg_replace('/\s+/', ' ', $raw)) . ' (HTTP ' . $r['code'] . ')';
            // Hestia is strict about key SHAPE before it ever checks validity.
            if (stripos($raw, 'access_key_id format') !== false) {
                $msg .= ' — an access key is exactly 20 characters and its secret exactly 40;'
                      . ' check for a truncated paste or a swapped pair.';
            }
        }
        return ['ok' => false, 'code' => -1, 'raw' => $raw, 'json' => null, 'message' => $msg];
    }

    // The single most dangerous response Hestia gives you: an HTTP 200 whose body
    // is the word "Error" (API off, or caller IP not in API_ALLOWED_IP). Nothing
    // about the status code says anything went wrong.
    if (stripos($raw, 'error') === 0) {
        return ['ok' => false, 'code' => 19, 'raw' => $raw, 'json' => null, 'message' => hestia_err(19)];
    }

    if ($returncode) {
        // A well-formed reply here is JUST digits.
        if (!preg_match('/^\d+$/', $raw)) {
            return ['ok' => false, 'code' => -1, 'raw' => $raw, 'json' => null,
                    'message' => 'unexpected reply: ' . substr($raw, 0, 200)];
        }
        $code = (int) $raw;
        return ['ok' => $code === 0, 'code' => $code, 'raw' => $raw, 'json' => null,
                'message' => hestia_err($code)];
    }

    $json = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) $json = null;
    return ['ok' => true, 'code' => 0, 'raw' => $raw, 'json' => $json, 'message' => 'OK'];
}

/** Reachability + auth probe. @return array{ok:bool,code:int,error:string} */
function hestia_probe(array $server): array
{
    $r = hestia_api($server, 'v-list-sys-info', ['json'], false);
    return [
        'ok'    => $r['ok'] && is_array($r['json']),
        'code'  => $r['code'],
        'error' => $r['ok'] ? ($r['json'] === null ? 'authenticated, but reply was not JSON' : '') : $r['message'],
    ];
}

/** The SSH port, asked only to prove the machine is alive. Never connected to. */
const HESTIA_SSH_PORT = 22;

/**
 * WHY A FAILED PROBE IS NOT ONE FACT.
 *
 * hestia_probe() answers one question — did the panel API reply — and every way of
 * failing it comes back the same shape. But "the machine is gone" and "the machine is
 * fine and the panel is not" need opposite responses: the first is a call to the
 * hosting company, the second is a login over SSH to start a service. The console said
 * "cannot reach it" to both, so the next step had to be worked out by hand every time.
 *
 * BOX 14 is the case that forced this (2026-08-18). Vultr's panel showed it Running and
 * the console showed it unreachable, and BOTH were right: the VM was up and answering
 * on 22 while 80, 443 and 8083 were all dropped, because what was on that IP was no
 * longer our install. That is one TCP connection's worth of information, and it decided
 * the whole diagnosis.
 *
 * So: ask the panel port, then the SSH port, and name what the pair means.
 *
 *   ok        the panel answered — the failure was auth or config, not the network
 *   panel     panel port open, API still refused — the port is up, the API is not
 *   host_up   panel port dead, SSH answering — the machine lives, the panel does not
 *   dark      neither answers — nothing on this address is talking to us
 *
 * Only ever called on a failure, and only from the sweep, so a healthy fleet pays
 * nothing for it. Verdict FIRST, sentence second: callers branch on the verdict and
 * must never have to pattern-match English to do it.
 *
 * @return array{verdict:string,panel:array,ssh:array,at:string}
 */
function hestia_reach(array $server): array
{
    $host = (string) ($server['host'] ?? '');
    $port = (int) ($server['port'] ?? 8083);

    $panel = infra_tcp_probe($host, $port);
    // Not asked when the panel port is open: the answer would change nothing, and a
    // diagnosis that keeps probing after it knows is just a slower diagnosis.
    $ssh   = $panel['open'] ? ['open' => false, 'verdict' => 'skipped', 'ms' => 0, 'error' => '']
                            : infra_tcp_probe($host, HESTIA_SSH_PORT);

    if ($panel['open'])  $verdict = 'panel';
    elseif ($ssh['open']) $verdict = 'host_up';
    else                  $verdict = 'dark';

    return ['verdict' => $verdict, 'panel' => $panel, 'ssh' => $ssh, 'at' => date('c')];
}

/**
 * The verdict as a sentence, and what to do about it. Kept beside the verdict rather
 * than in the view, because the Servers tab is not the only place that has to explain
 * a box that will not answer.
 *
 * @return array{title:string,detail:string,tone:string}
 */
function hestia_reach_words(array $reach, array $server): array
{
    $host = (string) ($server['host'] ?? 'the server');
    $port = (int) ($server['port'] ?? 8083);

    switch ($reach['verdict'] ?? '') {
        case 'panel':
            return [
                'title'  => 'The panel port is open — the API is what refused.',
                'detail' => 'Something is listening on ' . $host . ':' . $port . ', so this is not a network'
                          . ' problem. Hestia\'s API is switched off, the caller IP is not allowed, or the key'
                          . ' pair is wrong. Press Test connection for the exact code.',
                'tone'   => 'warn',
            ];
        case 'host_up':
            return [
                'title'  => 'The machine is up. The panel is not.',
                'detail' => $host . ' answered on port ' . HESTIA_SSH_PORT . ' but nothing answered on '
                          . $port . '. The box is running, so the hosting company will show it healthy —'
                          . ' but Hestia is stopped, its port is firewalled, or the OS on that address is'
                          . ' no longer the one we installed. Log in over SSH before rebuilding anything.',
                'tone'   => 'warn',
            ];
        case 'dark':
            return [
                'title'  => 'Nothing on this address is answering at all.',
                'detail' => 'Neither ' . $port . ' nor ' . HESTIA_SSH_PORT . ' replied on ' . $host . '.'
                          . ' The machine is off, gone, or entirely firewalled — this one belongs in the'
                          . ' hosting company\'s panel, not here.',
                'tone'   => 'err',
            ];
    }
    return ['title' => '', 'detail' => '', 'tone' => 'err'];
}

/** Server info (also serves as a reachability/auth check). @return array|null */
function hestia_server_info(array $server): ?array
{
    $r = hestia_api($server, 'v-list-sys-info', ['json'], false);
    return ($r['ok'] && is_array($r['json'])) ? $r['json'] : null;
}

/** All Hestia system users. @return string[] */
function hestia_list_users(array $server): array
{
    $r = hestia_api($server, 'v-list-users', ['json'], false);
    return ($r['ok'] && is_array($r['json'])) ? array_keys($r['json']) : [];
}

/**
 * How many real files sit in one site's docroot.
 *
 * A host area can exist — vhost, folder, FTP login, all correct — and hold nothing
 * but the index.html and robots.txt Hestia drops in when it creates the site. That
 * site answers with a placeholder and looks provisioned from every angle except this
 * one, so the count deliberately EXCLUDES those two placeholders: what is wanted is
 * "has anything of ours landed here", not "is the directory non-empty".
 *
 * Uses v-list-fs-directory, which returns type|perm|date|time|owner|group|size|name
 * per line and is live — unlike the disk figures, which a cron updates at 02:15.
 *
 * @return array{files:int,dirs:int,bytes:int,placeholder_only:bool}
 */
function hestia_docroot_files(array $server, string $user, string $domain): array
{
    $path = '/home/' . $user . '/web/' . $domain . '/public_html';
    $r = hestia_api($server, 'v-list-fs-directory', [$user, $path, 'json'], false);
    // The reply is pipe-delimited text, not JSON, so json_decode leaves it in raw.
    $raw = is_string($r['json'] ?? null) ? $r['json'] : (string) ($r['raw'] ?? '');

    $files = 0; $dirs = 0; $bytes = 0; $names = [];
    foreach (explode("\n", $raw) as $line) {
        $p = explode('|', trim($line));
        if (count($p) < 8) continue;
        $name = trim($p[7]);
        if ($name === '') continue;          // the '.' entry has an empty name
        if (($p[0] ?? '') === 'd') { $dirs++; $names[] = $name; continue; }
        $files++; $bytes += (int) ($p[6] ?? 0); $names[] = $name;
    }
    sort($names);
    // A built site brings folders (assets/, blog/, pages) as well as files, so a
    // docroot holding ONLY Hestia's two placeholders is the empty case.
    $placeholderOnly = ($files + $dirs) === 0 || $names === ['index.html', 'robots.txt'];
    return ['files' => $files, 'dirs' => $dirs, 'bytes' => $bytes, 'placeholder_only' => $placeholderOnly];
}

/**
 * Every account this box has, as well as we can know it.
 *
 * v-list-users cannot be trusted to enumerate: on two boxes in the same state, with
 * identically-owned full-permission keys, it returned [user] on one and [user, fleet]
 * on the other. So the fleet account — whose name we know, because it is in the config
 * — is confirmed BY NAME rather than discovered, the same way hestia_list_sites() has
 * to do it. Costs one extra call only when the listing already omitted it.
 *
 * @return string[] account names
 */
function hestia_account_list(array $server): array
{
    $users = hestia_list_users($server);
    $fleet = hestia_fleet_user($server);
    if (!in_array($fleet, $users, true) && hestia_user_exists($server, $fleet)) $users[] = $fleet;
    return $users;
}

/**
 * List every web domain on the server.
 *
 * Hestia has no global domain list — web domains hang off users, so this is
 * 1 + N calls where N is the number of USERS (not sites). That is the whole
 * reason this driver files every site under one fleet user: N is 1, so listing
 * a 500-site box costs 2 calls rather than 501. The code below did not change
 * when the model did; it just got cheap. Still worth caching (lib/cache.php),
 * and still never call it in a loop.
 *
 * @return array<int,array{name:string,user:string,docroot:string,ssl:string}>
 */
function hestia_list_sites(array $server): array
{
    $out = [];
    // v-list-users does NOT enumerate the box — it returns only the account the
    // ACCESS KEY belongs to. Verified 2026-08-15: with a key owned by "user",
    // v-list-users returned just "user" while v-list-user fleet happily returned
    // a populated fleet account. Trusting it alone made every site filed under
    // fleet invisible, which read as "the site was created but is absent from the
    // listing" and, worse, as "the fleet account was deleted" during teardown.
    //
    // So the fleet account is always consulted by name rather than discovered.
    // We know what it is called — it is in the config — and not needing discovery
    // is the point of filing everything under one known account.
    $users = hestia_list_users($server);
    $fleet = hestia_fleet_user($server);
    if (!in_array($fleet, $users, true) && hestia_user_exists($server, $fleet)) {
        $users[] = $fleet;
    }
    foreach ($users as $user) {
        $r = hestia_api($server, 'v-list-web-domains', [$user, 'json'], false);
        if (!$r['ok'] || !is_array($r['json'])) continue;
        foreach ($r['json'] as $name => $d) {
            $out[] = [
                'name'    => (string) $name,
                'user'    => $user,
                'docroot' => '/home/' . $user . '/web/' . $name . '/public_html',
                'ssl'     => (string) ($d['SSL'] ?? 'no'),
            ];
        }
    }
    return $out;
}

/**
 * True if a domain already exists on the server (idempotency guard).
 * Cheaper than hestia_list_sites() when the owning user is known.
 */
function hestia_site_exists(array $server, string $domain, string $user = ''): bool
{
    if ($user !== '') {
        $r = hestia_api($server, 'v-list-web-domain', [$user, $domain, 'json'], false);
        return $r['ok'] && is_array($r['json']) && $r['json'] !== [];
    }
    foreach (hestia_list_sites($server) as $d) {
        if (strcasecmp($d['name'], $domain) === 0) return true;
    }
    return false;
}

/**
 * The single account every site on this server is filed under.
 *
 * ONE USER PER SERVER, not one per site. A Hestia user is a Linux account, and
 * the per-site alternative means 500 of them per box: 500 home directories,
 * 500 slow v-add-user calls, and a 501-call listing (see hestia_list_sites).
 * Filing all domains under one user costs nothing a visitor can see — same
 * vhosts, same docroots — and it does NOT merge the FTP logins, because
 * v-add-web-domain-ftp issues an account per DOMAIN regardless of owner.
 *
 * What it gives up is the OS-level wall between sites. That wall matters when
 * the sites belong to different customers who can execute code; these are
 * static HTML, nothing runs, and they are all ours. There is no tenant
 * boundary here to enforce.
 */
function hestia_fleet_user(array $server): string
{
    $u = strtolower(trim((string) ($server['site_user'] ?? '')));
    return preg_match('/^[a-z][a-z0-9_]{0,29}$/', $u) ? $u : 'fleet';
}


/** Does this Hestia user exist? */
function hestia_user_exists(array $server, string $user): bool
{
    $r = hestia_api($server, 'v-list-user', [$user, 'json'], false);
    return $r['ok'] && is_array($r['json']) && $r['json'] !== [];
}

/**
 * Create the owning account if it is not already there. Idempotent: exit code 4
 * ("already exists") counts as success, so two concurrent provisions racing to
 * create the fleet user cannot make one of them fail.
 *
 * @return array{ok:bool,created:bool,message:string}
 */
function hestia_ensure_user(array $server, string $user): array
{
    if (hestia_user_exists($server, $user)) {
        return ['ok' => true, 'created' => false, 'message' => 'user exists'];
    }
    $email = $server['contact_email'] ?? ('admin@' . ($server['host'] ?? 'localhost'));
    $pkg   = $server['package'] ?? 'default';
    $r = hestia_api($server, 'v-add-user', [$user, bin2hex(random_bytes(12)) . 'Aa1!', $email, $pkg, 'Fleet sites']);
    if ($r['ok'])        return ['ok' => true, 'created' => true,  'message' => 'user created'];
    if ($r['code'] === 4) return ['ok' => true, 'created' => false, 'message' => 'user exists (race)'];
    return ['ok' => false, 'created' => false, 'message' => 'v-add-user: ' . $r['message']];
}

/**
 * Create a site: web domain + its own FTP account, filed under the server's
 * fleet user (created on first use).
 *
 * @param string $owner Leave empty for the normal one-user-per-server model.
 *                      Passing a name forces one-user-per-site — the probe uses
 *                      this to measure both; provisioning never does.
 * @return array{ok:bool,id:null,ftp_user:string,ftp_pass:string,user:string,docroot:string,message:string}
 */
function hestia_create_site(array $server, string $domain, string $ftpUser, string $ftpPass, string $ip = '', string $owner = '', bool $restart = true): array
{
    $user = $owner !== '' ? $owner : hestia_fleet_user($server);
    $fail = function (string $m) use ($ftpUser, $ftpPass) {
        return ['ok' => false, 'id' => null, 'ftp_user' => $ftpUser, 'ftp_pass' => $ftpPass,
                'user' => '', 'docroot' => '', 'message' => $m];
    };

    // 1) owning account — created once per server, reused by every site after
    $u = hestia_ensure_user($server, $user);
    if (!$u['ok']) return $fail($u['message']);

    // 2) web domain, with the www alias
    $r = hestia_api($server, 'v-add-web-domain', [$user, $domain, $ip, 'no', 'www.' . $domain]);
    if (!$r['ok']) {
        // Roll back the user ONLY if this call is what created it. Under the
        // fleet model it is almost always pre-existing and shared with every
        // other site on the box — deleting it here would take them all down.
        if ($u['created']) hestia_api($server, 'v-delete-user', [$user]);
        return $fail('v-add-web-domain: ' . $r['message']);
    }

    // 3) FTP account. Per DOMAIN, not per user — this is why sharing one owning
    //    account costs no credential isolation. Hestia PREFIXES the login with
    //    the owner: the real username is "<user>_<ftpUser>". Getting that wrong
    //    is a login failure that reads like a bad password.
    $r = hestia_api($server, 'v-add-web-domain-ftp', [$user, $domain, $ftpUser, $ftpPass, 'public_html']);
    if (!$r['ok']) {
        hestia_api($server, 'v-delete-web-domain', [$user, $domain]);
        if ($u['created']) hestia_api($server, 'v-delete-user', [$user]);
        return $fail('v-add-web-domain-ftp: ' . $r['message']);
    }

    // 4) make nginx aware of it. Without this the domain is configured but not
    //    served — see hestia_restart_web(). Bulk callers provisioning many sites
    //    at once should pass $restart=false and call hestia_restart_web() once at
    //    the end; restarting the web server 500 times is both slow and a series of
    //    small blips for every site already live on the box.
    $restarted = '';
    if ($restart) {
        $w = hestia_restart_web($server);
        $restarted = $w['ok'] ? ', web restarted' : ', BUT the web restart failed (' . $w['message']
                              . ') — the site will serve the default page until it is restarted';
    }

    return [
        'ok'       => true,
        'id'       => null,                              // Hestia has no numeric site id
        'ftp_user' => $user . '_' . $ftpUser,            // the LOGIN, not the argument
        'ftp_pass' => $ftpPass,
        'user'     => $user,
        'docroot'  => '/home/' . $user . '/web/' . $domain . '/public_html',
        'message'  => 'created under ' . $user . ($u['created'] ? ' (account created)' : '') . $restarted,
    ];
}

/**
 * Restart the web server so newly-created vhosts actually answer.
 *
 * ⚠ NOT OPTIONAL. Verified 2026-08-15: after v-add-web-domain the domain is fully
 * configured — v-list-web-domain returns it with the right DOCUMENT_ROOT, and an
 * FTP upload lands in that directory — yet HTTP still serves Hestia's default
 * "Success!" page, because nginx has not picked the vhost up. v-rebuild-web-domains
 * does NOT fix it. Only a restart does.
 *
 * The failure is nasty precisely because every individual step reports success:
 * the site exists, the files are in place, and the server returns HTTP 200. It is
 * simply the wrong 200. (Every *.q111.xyz on this box shows the same page.)
 */
function hestia_restart_web(array $server): array
{
    $r = hestia_api($server, 'v-restart-web');
    return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'web server restarted' : $r['message']];
}

/**
 * Install a custom certificate — the Cloudflare Origin CA path.
 *
 * ⚠ THE AWKWARD ONE. v-add-web-domain-ssl does NOT accept certificate contents.
 * It reads three files from a directory ON THE SERVER: <domain>.crt, <domain>.key
 * and optionally <domain>.ca. The API has no file-upload verb, so the cert has
 * to arrive by some other transport first. Since every site already has an FTP
 * account pointed at public_html, the workable route is: upload the three files
 * over FTP, install from there, then delete them. The probe tests exactly that,
 * because if it fails, Full (strict) has no unattended path on Hestia.
 */
function hestia_install_cert(array $server, string $domain, string $user, string $sslDir): array
{
    $r = hestia_api($server, 'v-add-web-domain-ssl', [$user, $domain, $sslDir, 'same']);
    if ($r['ok']) return ['ok' => true, 'message' => 'certificate installed'];

    // Exit code 4 is "already exists" — v-add refuses to install over a cert that
    // is already there. That makes the first install succeed and every RENEWAL
    // fail, which is the case that actually recurs: Origin CA certs expire and
    // have to be swapped on a live site. Replace rather than add.
    if ($r['code'] === 4) {
        // Preferred, because it never leaves the site without a certificate.
        $c = hestia_api($server, 'v-change-web-domain-sslcert', [$user, $domain, $sslDir, 'same']);
        if ($c['ok']) return ['ok' => true, 'message' => 'certificate replaced in place'];

        // Fallback for versions lacking that verb. There is a window of a second
        // or two with no cert on the domain, so it is the second choice, not the
        // first — a renewal should not take the site down to succeed.
        hestia_api($server, 'v-delete-web-domain-ssl', [$user, $domain]);
        $r2 = hestia_api($server, 'v-add-web-domain-ssl', [$user, $domain, $sslDir, 'same']);
        return ['ok' => $r2['ok'], 'message' => $r2['ok']
            ? 'certificate replaced (removed first — brief gap with no SSL)'
            : 'replace failed: ' . $r2['message']];
    }

    return ['ok' => false, 'message' => $r['message']];
}

/**
 * Write one file to a domain's own FTP login — the transport for getting a
 * certificate onto the box for hestia_install_cert() to read (see its
 * docblock: the Hestia API has no upload verb).
 */
function hestia_ftp_put(string $host, string $ftpUser, string $ftpPass, string $remotePath, string $content): array
{
    $tmp = tmpfile();
    fwrite($tmp, $content);
    rewind($tmp);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL                      => 'ftp://' . $host . '/' . ltrim($remotePath, '/'),
        CURLOPT_USERPWD                  => $ftpUser . ':' . $ftpPass,
        CURLOPT_UPLOAD                   => true,
        CURLOPT_INFILE                   => $tmp,
        CURLOPT_INFILESIZE               => strlen($content),
        CURLOPT_FTP_CREATE_MISSING_DIRS  => CURLFTP_CREATE_DIR,
        CURLOPT_CONNECTTIMEOUT           => 15,
        CURLOPT_TIMEOUT                  => 45,
        CURLOPT_RETURNTRANSFER           => true,
    ]);
    $ok  = curl_exec($ch) !== false;
    $err = curl_error($ch);
    curl_close($ch);
    fclose($tmp);
    return ['ok' => $ok, 'message' => $ok ? 'uploaded' : $err];
}

/** Delete one file over FTP — cleans up the staged cert once Hestia has read it. */
function hestia_ftp_delete(string $host, string $ftpUser, string $ftpPass, string $remotePath): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL             => 'ftp://' . $host . '/',
        CURLOPT_USERPWD         => $ftpUser . ':' . $ftpPass,
        CURLOPT_QUOTE           => ['DELE ' . ltrim($remotePath, '/')],
        CURLOPT_CONNECTTIMEOUT  => 15,
        CURLOPT_TIMEOUT         => 20,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_NOBODY          => true,
    ]);
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $err === '', 'message' => $err ?: 'deleted'];
}

/**
 * Delete a domain. Removes the WEB DOMAIN ONLY and never the owning account.
 *
 * ⚠ This is the load-bearing consequence of one-user-per-server: v-delete-user
 * takes every domain that user owns with it. Under the old per-site model that
 * meant one site; here it would mean the entire server. Teardown removes
 * domains individually, and the fleet account is left in place even when it
 * drops to zero sites — an empty account costs nothing, and the guard is worth
 * more than the tidiness.
 */
function hestia_delete_site(array $server, string $domain, string $user = ''): array
{
    if ($user === '') {
        foreach (hestia_list_sites($server) as $d) {
            if (strcasecmp($d['name'], $domain) === 0) { $user = $d['user']; break; }
        }
    }
    if ($user === '') return ['ok' => true, 'message' => 'not present'];

    hestia_api($server, 'v-delete-web-domain', [$user, $domain]);

    // Judge by ACTUAL removal, not by the exit code — same lesson as Plesk, where
    // a fail2ban post-hook returned non-zero on a site that was really gone.
    $gone = !hestia_site_exists($server, $domain, $user);
    return ['ok' => $gone, 'message' => $gone ? 'removed' : 'still present after delete'];
}
