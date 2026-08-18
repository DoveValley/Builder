<?php
/**
 * infra/lib/cf_alloc.php — which Cloudflare account a domain's zone belongs in.
 *
 * WHY THIS EXISTS. Bulk provisioning picked a box and a Cloudflare account from two
 * unrelated rotations, so a site's nameservers had nothing to do with the machine it
 * ran on. That sounds harmless and is not: every domain in one Cloudflare account
 * shares ONE nameserver pair, so the pair links it to that account's other domains,
 * while its IP links it to a DIFFERENT set on its box. Those links then CHAIN — A
 * shares nameservers with B, B shares an IP with C — and one discovered site unravels
 * the fleet. Binding each account to exactly one box makes the two links land on the
 * same twenty domains, which turns one connected web into isolated islands.
 *
 * (Today all 31 zones sit in one account behind melina+sullivan. Any one of them leads
 * to the other thirty.)
 *
 * THE PAIRING LIVES ON THE ACCOUNT, not on the server. `server_id` is one field holding
 * one value, so "an account can only be on one box" cannot be violated — there is
 * nowhere to write a second answer. A list of accounts stored per box would need
 * validation to enforce what the shape should make impossible.
 *
 * ⚠ CLOUDFLARE CANNOT MOVE A ZONE BETWEEN ACCOUNTS. Correcting a misplaced zone means
 * delete + recreate, which issues a NEW nameserver pair, which needs a registrar change
 * and re-propagation — downtime, once the site is live. So the box must be chosen
 * before the zone, which is what the go-live grid's `zone requires assign` enforces.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/fleet.php';

/** Default footprint policy for a new account: how many domains may share its pair. */
const INFRA_CF_DEFAULT_MAX = 50;

/**
 * How many zones each account actually holds, read from the LAST SWEEP of Cloudflare.
 *
 * Never a counter we increment. A number we maintain ourselves drifts the first time a
 * zone is created or deleted in Cloudflare's own dashboard, and it drifts silently —
 * which on this page would mean quietly overfilling an account and undoing the very
 * separation it exists to create.
 *
 * @return array<string,int> account id => zones held
 */
function infra_cf_zone_counts(): array
{
    $out = [];
    foreach (infra_cf_accounts() as $a) {
        $id = (string) ($a['id'] ?? '');
        if ($id === '') continue;
        // -1 = nobody has ever swept it. "Swept and empty" and "never asked" must not
        // both read as zero, or an unasked account gets filled on the strength of a
        // number that was never measured.
        $out[$id] = infra_cf_zones_swept($a) ? count(infra_cf_zones_cached($a)) : -1;
    }
    return $out;
}

/**
 * The accounts bound to one box, in the order they should be filled.
 *
 * @return array<int,array> each: the account record + used, max, free, unswept
 */
function infra_cf_accounts_for_server(string $serverId): array
{
    $serverId = trim($serverId);
    if ($serverId === '') return [];

    $counts = infra_cf_zone_counts();
    $out = [];
    foreach (infra_cf_accounts() as $a) {
        if (trim((string) ($a['server_id'] ?? '')) !== $serverId) continue;
        $id   = (string) ($a['id'] ?? '');
        $used = $counts[$id] ?? -1;
        $max  = max(0, (int) ($a['max_zones'] ?? INFRA_CF_DEFAULT_MAX));
        $out[] = $a + [
            'used'    => max(0, $used),
            'unswept' => $used < 0,
            'max'     => $max,
            // An unmeasured account has UNKNOWN room, not full room. Reporting $max
            // here made a never-swept CF #1 offer 31 free slots while actually holding
            // 31 — the cap silently not applying until somebody happened to press
            // Refresh. 0 is the honest floor; the `unswept` flag is what the UI reads
            // to say "not counted yet" rather than "full".
            'free'    => $used < 0 ? 0 : max(0, $max - $used),
        ];
    }
    usort($out, fn($x, $y) => [(int) ($x['order'] ?? 0), (string) $x['label']]
                         <=> [(int) ($y['order'] ?? 0), (string) $y['label']]);
    return $out;
}

/**
 * The account a new zone for this box should go in: the first bound account with room.
 *
 * REFUSES rather than falling back to some other box's account. Spilling over would
 * silently break the containment this whole module exists for, and it would do it at
 * the one moment nobody is watching — mid-batch. A full box is a thing to be told
 * about, not routed around.
 *
 * @return array{ok:bool, account:?array, why:string}
 */
function infra_cf_account_for_server(string $serverId): array
{
    $bound = infra_cf_accounts_for_server($serverId);
    if (!$bound) {
        return ['ok' => false, 'account' => null,
                'why' => 'no Cloudflare account is bound to this box — bind one on the Cloudflare tab'];
    }
    // An account nobody has counted is NOT an empty account. Left to "0 used, so
    // there is room", the allocator would have filled CF #1 — which holds 31 zones and
    // is capped at 31 — with another 31, straight into the registrar account the cap
    // exists to seal. Refuse until it has been measured; the cure is one Refresh.
    $unswept = array_filter($bound, fn($a) => !empty($a['unswept']));
    if (count($unswept) === count($bound)) {
        return ['ok' => false, 'account' => null,
                'why' => 'this box\'s Cloudflare account has never been counted, so there is no way to know '
                       . 'whether it has room — press "Refresh zones" at the top of the Cloudflare tab first'];
    }

    foreach ($bound as $a) {
        if (!empty($a['unswept'])) continue;      // never guess at an unmeasured account
        if ($a['free'] > 0) return ['ok' => true, 'account' => $a, 'why' => ''];
    }
    $total = array_sum(array_column($bound, 'used'));
    return ['ok' => false, 'account' => null,
            'why' => 'every account bound to this box is full (' . $total . ' zones across '
                   . count($bound) . ' account' . (count($bound) === 1 ? '' : 's')
                   . ') — add another account to it'];
}

/**
 * Accounts bound to no box at all. They still work, they are simply outside the scheme
 * — and an account nobody has bound is the reason a box reports "no account", so it
 * needs to be visible rather than merely absent.
 *
 * @return array<int,array>
 */
function infra_cf_accounts_unbound(): array
{
    $counts = infra_cf_zone_counts();
    $out = [];
    foreach (infra_cf_accounts() as $a) {
        if (trim((string) ($a['server_id'] ?? '')) !== '') continue;
        $id   = (string) ($a['id'] ?? '');
        $used = $counts[$id] ?? -1;
        $out[] = $a + ['used' => max(0, $used), 'unswept' => $used < 0,
                       'max' => max(0, (int) ($a['max_zones'] ?? INFRA_CF_DEFAULT_MAX))];
    }
    return $out;
}

/**
 * The estate in one line: is there room for the domains still to be staged?
 *
 * @return array{boxes:int, bound:int, unbound:int, capacity:int, used:int, free:int,
 *               boxes_with_none:int, boxes_full:int}
 */
function infra_cf_capacity(): array
{
    require_once __DIR__ . '/hestia_fleet.php';
    $out = ['boxes' => 0, 'bound' => 0, 'unbound' => count(infra_cf_accounts_unbound()),
            'capacity' => 0, 'used' => 0, 'free' => 0, 'boxes_with_none' => 0, 'boxes_full' => 0,
            // How many bound accounts nobody has counted. While this is non-zero the
            // totals beside it are a floor, not a measurement, and the page says so —
            // "0 zones held" reads as a fact and would be a guess.
            'unswept' => 0];

    foreach (infra_hestia_servers() as $s) {
        $out['boxes']++;
        $bound = infra_cf_accounts_for_server((string) ($s['id'] ?? ''));
        if (!$bound) { $out['boxes_with_none']++; continue; }
        $free = 0; $measured = 0;
        foreach ($bound as $a) {
            $out['bound']++;
            if (!empty($a['unswept'])) { $out['unswept']++; continue; }
            $measured++;
            $out['capacity'] += $a['max'];
            $out['used']     += $a['used'];
            $free            += $a['free'];
        }
        // A box whose accounts are all unmeasured is not "full" — it is unknown.
        if ($measured > 0 && $free === 0) $out['boxes_full']++;
    }
    $out['free'] = max(0, $out['capacity'] - $out['used']);
    return $out;
}

/**
 * Accounts this credential can SEE at Cloudflare, whether or not the console knows
 * them. Twenty account ids typed by hand is twenty chances to paste the wrong one, and
 * a wrong id is invisible until a zone lands in the wrong place — so the console asks
 * Cloudflare instead.
 *
 * Any one stored credential can list every account its user belongs to, which is what
 * makes "one admin email invited into all twenty" worth doing.
 *
 * @return array{ok:bool, msg:string, accounts:array<int,array{id:string,name:string,known:bool}>}
 */
function infra_cf_discover(): array
{
    $known = [];
    foreach (infra_cf_accounts() as $a) $known[strtolower((string) ($a['account_id'] ?? ''))] = true;

    $seen = [];
    $errs = [];
    foreach (infra_cf_accounts() as $a) {
        // Through the Cloudflare client, not a hand-rolled call: the base URL, the auth
        // headers and the error shape are its business, and a second copy of them here
        // would be free to drift from the first.
        $r = cf_list_accounts($a);
        if (!$r['ok']) { $errs[] = ($a['label'] ?? $a['id']) . ': ' . $r['error']; continue; }
        foreach ($r['accounts'] as $x) {
            if (isset($seen[$x['id']])) continue;
            $seen[$x['id']] = $x + ['known' => isset($known[strtolower($x['id'])])];
        }
    }

    $accounts = array_values($seen);
    $new = count(array_filter($accounts, fn($a) => !$a['known']));
    return [
        'ok'  => (bool) $accounts,
        'msg' => $accounts
            ? count($accounts) . ' account(s) visible, ' . $new . ' not yet in the console'
              . ($errs ? ' — ' . implode('; ', $errs) : '')
            : ('nothing could be listed' . ($errs ? ': ' . implode('; ', $errs) : '')),
        'accounts' => $accounts,
    ];
}
