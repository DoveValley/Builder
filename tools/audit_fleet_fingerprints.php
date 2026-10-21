<?php
/**
 * Does the fleet hold together as isolated islands, or does anything link one box's
 * domains to another's?
 *
 * Covers every LIVE domain plus every STAGED one that already has a zone — across all
 * batches, because the islands are a property of the fleet and not of a batch. Staged
 * is in scope on purpose: see the note on $scopeWhere below.
 *
 * ── WHAT A "FINGERPRINT" IS HERE ─────────────────────────────────────────────────
 * Every domain in one Cloudflare account shares ONE nameserver pair, so that pair links
 * it to the account's other domains. Its A record links it to everything else on its
 * box. cf_alloc.php binds one account to exactly one box precisely so both links land on
 * the SAME twenty domains — one island, linkable internally, linkable to nothing else.
 *
 * A fingerprint is therefore not "two domains are related" — inside an island that is
 * the accepted design. It is a link that CROSSES an island boundary, because that is
 * what chains islands into one connected web: A shares nameservers with B, B shares an
 * IP with C, and one discovered site unravels the fleet.
 *
 * This checks both halves, and they are different questions:
 *
 *   PER DOMAIN — is this domain internally consistent?
 *     1. the account that actually holds its zone == the account on record
 *     2. the box its account is bound to == the box on record
 *     3. its live A records (apex and www) == that box's IP
 *     4. its live nameservers == the pair on record
 *     5. the zone is active, and proxied
 *
 *   ACROSS DOMAINS — does the island structure hold?
 *     6. one nameserver pair must map to exactly one box
 *     7. one box IP must map to exactly one nameserver pair
 *     8. no account spanning two boxes, no box carrying two accounts
 *
 *   CONTENT — the fingerprints that survive any amount of DNS hygiene
 *     9. an analytics id, GSC token, phone, address or business name reused on
 *        domains that sit on DIFFERENT boxes. One shared analytics property is a
 *        harder link than a shared IP will ever be, and no amount of account
 *        separation hides it.
 *
 * Checks 1-5 go to Cloudflare for the live answer rather than reading the cached sweep:
 * the record is what we believe, and the point of this script is to find out where what
 * we believe is wrong.
 *
 * READ-ONLY. It changes nothing and has no --apply.
 *
 * Usage:
 *   php tools/audit_fleet_fingerprints.php
 *   php tools/audit_fleet_fingerprints.php --niche=mold
 *   php tools/audit_fleet_fingerprints.php --all        (every zoned domain, incl. `owned`)
 */

$base = dirname(__DIR__);
// One require: the module pulls in what it needs and owns every rule this prints.
require_once $base . '/admin/infra/lib/fingerprint_audit.php';
require_once $base . '/admin/infra/lib/hestia.php';   // hestia_docroot_files(), display only

$opt     = getopt('', ['niche::', 'all']);
$niche   = strtolower(trim((string) ($opt['niche'] ?? '')));
$allRows = isset($opt['all']);

function say(string $s = ''): void { echo $s, "\n"; }
$problems = [];
$flag = function (string $domain, string $what, string $detail) use (&$problems) {
    $problems[] = ['domain' => $domain, 'what' => $what, 'detail' => $detail];
};

/* ── The domains in scope ──────────────────────────────────────────────────────────
 *
 * LIVE **AND EVERYTHING ZONED AND ON ITS WAY** — not live alone.
 *
 * Live-only was the first cut and it was the wrong one. The entire value of this check
 * is catching a misalignment BEFORE it goes live, and a staged domain is where that
 * misalignment actually sits: the 11 water domains repaired on 2026-10-21 were staged
 * throughout, so a live-only audit would have shown green through the whole fault and
 * gone red only once the damage was public. On the Rollout screen for a 100-domain
 * batch with 35 live, live-only also means the banner is silent about precisely the 65
 * the operator is looking at the page to push.
 *
 * `owned` is deliberately NOT in the list. Those are bought-but-never-provisioned, and
 * all 31 zoned ones point at acct-01, an account the console no longer holds — a real
 * finding, but a standing one. Including them would leave the banner permanently red
 * and therefore unread. `--all` covers them when that is the question being asked. */
$scopeWhere = $allRows
    ? "cf_zone_id != ''"
    : "(status = 'live' OR (cf_zone_id != '' AND status IN ('staged', 'releasing', 'awaiting-ns')))";
$sql = "SELECT domain, niche, status, server_id, cf_account_id, cf_zone_id, nameservers, batch
          FROM domains WHERE " . $scopeWhere . " ORDER BY niche, domain";
$rows = infra_state_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if ($niche) $rows = array_values(array_filter($rows, fn($r) => strtolower((string) $r['niche']) === $niche));
if (!$rows) { say('No domains in scope.'); exit(0); }

$byNiche = [];
foreach ($rows as $r) $byNiche[$r['niche'] ?: '(none)'][] = $r['domain'];
say('Auditing ' . count($rows) . ' ' . ($allRows ? 'zoned' : 'LIVE + STAGED') . ' domain(s): '
    . implode(' · ', array_map(fn($k, $v) => $k . ' ' . count($v), array_keys($byNiche), $byNiche)));
say('Going to Cloudflare for the live answer — this takes a minute.');
say(str_repeat('─', 78));

/* ── Where each zone really lives, and what Cloudflare says about it ───────────── */
$zoneById = [];        // zone id => [account, zone record]
foreach (infra_cf_accounts() as $a) {
    foreach (cf_list_zones($a) as $z) $zoneById[(string) $z['id']] = [$a, $z];
}

$boxes = [];
foreach (infra_hestia_servers() as $s) $boxes[(string) $s['id']] = $s;

/* ── Per domain: checks 1-5 ────────────────────────────────────────────────────── */
$island = [];          // domain => ['ns' => pair, 'ip' => a-record, 'box' => id, 'acct' => id]
foreach ($rows as $r) {
    $d    = $r['domain'];
    $zid  = (string) $r['cf_zone_id'];
    $sid  = (string) $r['server_id'];
    $box  = $boxes[$sid] ?? null;
    $boxIp = $box ? (string) ($box['default_ip'] ?? $box['host'] ?? '') : '';

    if (!isset($zoneById[$zid])) {
        $flag($d, 'zone not found', 'zone ' . substr($zid, 0, 12) . '… is in none of the configured accounts');
        continue;
    }
    [$acctReal, $zone] = $zoneById[$zid];

    if ((string) $r['cf_account_id'] !== (string) $acctReal['id']) {
        $flag($d, 'account mismatch',
              'record says ' . infra_cf_account_label((string) $r['cf_account_id']) . ', zone is in ' . $acctReal['label']);
    }
    $bound = trim((string) ($acctReal['server_id'] ?? ''));
    if ($bound !== '' && $bound !== $sid) {
        $flag($d, 'box mismatch',
              $acctReal['label'] . ' is bound to ' . ($boxes[$bound]['label'] ?? $bound)
              . ' but the domain is on ' . ($box['label'] ?? $sid));
    }

    // 3 — the A records, live.
    $ips = [];
    foreach (cf_zone_dns($acctReal, $zid) as $rec) {
        if (($rec['type'] ?? '') !== 'A') continue;
        $name = strtolower((string) ($rec['name'] ?? ''));
        if ($name !== $d && $name !== 'www.' . $d) continue;
        $ips[$name] = (string) ($rec['content'] ?? '');
        if (empty($rec['proxied'])) $flag($d, 'not proxied', $name . ' is a grey-cloud record — it exposes the origin IP directly');
    }
    foreach ([$d, 'www.' . $d] as $want) {
        if (!isset($ips[$want])) { $flag($d, 'missing A record', 'no A record for ' . $want); continue; }
        if ($boxIp !== '' && $ips[$want] !== $boxIp) {
            $flag($d, 'A record points elsewhere',
                  $want . ' → ' . $ips[$want] . ' but its box ' . ($box['label'] ?? $sid) . ' is ' . $boxIp);
        }
    }

    // 4 — nameservers, live vs recorded.
    $nsLive = array_map('strtolower', (array) ($zone['name_servers'] ?? []));
    sort($nsLive);
    $nsRec = array_filter(array_map('trim', explode(',', strtolower((string) $r['nameservers']))));
    sort($nsRec);
    if ($nsRec && $nsLive && $nsRec !== $nsLive) {
        $flag($d, 'nameservers on record are stale',
              'record ' . implode(',', $nsRec) . ' · Cloudflare ' . implode(',', $nsLive));
    }

    // 5 — a LIVE domain whose zone is not active is not actually being served by CF.
    //     Keyed on this domain's own status, not on the scope: a staged domain's zone
    //     is SUPPOSED to be `pending` (its nameservers have not been switched yet), so
    //     testing the whole non---all scope would have flagged all 73 staged domains
    //     as broken for being exactly what they should be.
    if (($r['status'] ?? '') === 'live' && ($zone['status'] ?? '') !== 'active') {
        $flag($d, 'zone not active', 'status is "' . ($zone['status'] ?? '?') . '" though the domain is marked live');
    }

    $island[$d] = ['ns' => implode(',', $nsLive), 'ip' => $ips[$d] ?? '', 'box' => $sid,
                   'acct' => (string) $acctReal['id'], 'label' => $acctReal['label']];
}

/* ── Across domains: checks 6-8. This is the fingerprint graph. ────────────────── */
say('');
say('ISLAND STRUCTURE');
$nsToBox = []; $ipToNs = []; $acctToBox = []; $boxToAcct = [];
foreach ($island as $d => $i) {
    if ($i['ns'] !== '') $nsToBox[$i['ns']][$i['box']] = true;
    if ($i['ip'] !== '') $ipToNs[$i['ip']][$i['ns']] = true;
    $acctToBox[$i['acct']][$i['box']] = true;
    $boxToAcct[$i['box']][$i['acct']] = true;
}
$crossed = 0;
foreach ($nsToBox as $ns => $bx) {
    if (count($bx) < 2) continue;
    $crossed++;
    $flag('(fleet)', 'one nameserver pair spans several boxes',
          $ns . ' → ' . implode(', ', array_map(fn($b) => $boxes[$b]['label'] ?? $b, array_keys($bx))));
}
foreach ($ipToNs as $ip => $nss) {
    if (count($nss) < 2) continue;
    $crossed++;
    $flag('(fleet)', 'one box IP serves several nameserver pairs',
          $ip . ' → ' . count($nss) . ' different pairs, which links those accounts through this box');
}
foreach ($acctToBox as $a => $bx) {
    if (count($bx) < 2) continue;
    $crossed++;
    $flag('(fleet)', 'one account spans several boxes', infra_cf_account_label($a));
}
foreach ($boxToAcct as $b => $as) {
    if (count($as) < 2) continue;
    $crossed++;
    $flag('(fleet)', 'one box carries several accounts',
          ($boxes[$b]['label'] ?? $b) . ' → ' . implode(', ', array_map('infra_cf_account_label', array_keys($as))));
}
say('  ' . count($nsToBox) . ' distinct nameserver pairs · ' . count($ipToNs) . ' box IPs · '
    . count($acctToBox) . ' accounts · ' . count($boxToAcct) . ' boxes in use');
say('  ' . ($crossed === 0 ? '✓ every pair, IP and account stays inside one island'
                           : '⚠ ' . $crossed . ' structural crossing(s) — listed below'));

/* ── Content fingerprints that cross a box boundary: check 9 ───────────────────── */
say('');
say('SHARED CONTENT ACROSS BOXES');
$boxOf = [];
foreach ($island as $d => $i) $boxOf[$d] = $i['box'];

$watch = ['analytics_id' => 'analytics id', 'gsc_verification' => 'Search Console token',
          'phone' => 'phone number', 'address' => 'street address', 'business' => 'business name'];
$seen = [];            // field => value => [domain,...]
foreach (glob($base . '/sites/*/batches/*/params.csv') ?: [] as $csv) {
    $fh = @fopen($csv, 'r'); if (!$fh) continue;
    $head = fgetcsv($fh); if (!is_array($head)) { fclose($fh); continue; }
    $col = array_flip($head);
    while (($row = fgetcsv($fh)) !== false) {
        $d = strtolower(trim((string) ($row[$col['domain']] ?? '')));
        if (!isset($boxOf[$d])) continue;                         // only domains in scope
        foreach ($watch as $field => $_) {
            if (!isset($col[$field])) continue;
            $v = trim((string) ($row[$col[$field]] ?? ''));
            if ($v === '') continue;
            $seen[$field][strtolower($v)][$d] = true;
        }
    }
    fclose($fh);
}
$shared = 0;
foreach ($watch as $field => $label) {
    foreach ($seen[$field] ?? [] as $value => $doms) {
        if (count($doms) < 2) continue;
        $bx = array_unique(array_map(fn($d) => $boxOf[$d], array_keys($doms)));
        if (count($bx) < 2) continue;                              // same island — by design
        $shared++;
        $flag('(fleet)', 'same ' . $label . ' on different boxes',
              '"' . $value . '" on ' . implode(', ', array_keys($doms)));
    }
}
say('  ' . ($shared === 0
        ? '✓ no analytics id, GSC token, phone, address or business name is reused across boxes'
        : '⚠ ' . $shared . ' value(s) shared across boxes — listed below'));

/* ── Persist the verdict so the batch page can show it without re-running ───────
 *
 * Written whatever the outcome, and always with `at` and `scope`: a page that shows
 * "✓ clean" without saying WHEN, or over WHAT, is reassurance rather than information.
 * A clean result from before the last go-live is not a clean result. */
$result = [
    'at'       => gmdate('c'),
    'scope'    => ($allRows ? 'all zoned' : 'live + staged') . ($niche ? ' · niche=' . $niche : ''),
    'checked'  => count($rows),
    'niches'   => array_map('count', $byNiche),
    'clean'    => !$problems,
    'problems' => $problems,
];
$statePath = fingerprint_state_path();
// Only a FULL live audit is allowed to overwrite the headline — a --niche run answers
// a narrower question, and letting it stand in for the whole fleet would quietly
// shrink what "clean" covers.
if (!$niche) {
    $tmp = $statePath . '.tmp';
    if (file_put_contents($tmp, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false) {
        rename($tmp, $statePath);
        @chmod($statePath, 0664);
    }
}

/* ── Verdict ───────────────────────────────────────────────────────────────────── */
say('');
say(str_repeat('─', 78));
if (!$problems) {
    say('✓ CLEAN — ' . count($rows) . ' domain(s), no misalignment and no cross-box fingerprint.');
    exit(0);
}
say('⚠ ' . count($problems) . ' PROBLEM(S)');
$byWhat = [];
foreach ($problems as $p) $byWhat[$p['what']][] = $p;
foreach ($byWhat as $what => $list) {
    say('');
    say(strtoupper($what) . '  (' . count($list) . ')');
    foreach ($list as $p) say('   ' . str_pad($p['domain'], 30) . $p['detail']);
}
exit(1);
