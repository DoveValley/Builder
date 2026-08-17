<?php
/**
 * infra/lib/hosts.php — which company a box is rented from, and where you log in to them.
 *
 * The console knows a box's IP, its Hestia version and what is on it, but it never knew
 * WHO IT IS RENTED FROM — that fact lived only in the free-text `notes` field, so when a
 * box stopped answering the next step ("go and look in the provider's own panel") meant
 * reading the notes, recognising the brand, and hunting for the right login URL. Ten
 * providers across twenty boxes makes that a lookup you do from memory every time.
 *
 * There is no `provider` field on a server record, and this deliberately does not add
 * one: twenty boxes are already recorded, and a new required field would leave every one
 * of them blank until someone re-typed what the notes already say. The provider is
 * DERIVED instead, from patterns matched against the notes and the host address.
 *
 * Matching notes, not a stored value, means an unrecognised box says nothing rather than
 * guessing — infra_host_provider() returns null and the card renders exactly as before.
 * Wrong is worse than absent here: a link to the wrong company's login page during an
 * outage costs more than no link at all.
 *
 * `login` is the ACCOUNT login — the page you sign in at to see your products and
 * billing — not a deep link into one server. Deep links need per-instance ids that this
 * console does not hold, and they rot; the account page is one click from everything.
 */

/**
 * The hosting companies the fleet is spread across, in no particular order.
 *
 * `match` is tested (case-insensitively) against the notes and the host address joined
 * together, so either can identify the box. Order matters where one brand has separate
 * regional companies with separate logins: the specific entries are listed before the
 * general one and the first match wins.
 *
 * @return array<string,array{name:string,login:string,match:string[]}>
 */
function infra_host_providers(): array
{
    return [
        // OVH's regional entities are separate companies with separate accounts, so
        // ca/us are matched off the VPS hostname suffix before the generic fallback.
        'ovh_ca' => [
            'name'  => 'OVHcloud Canada',
            'login' => 'https://ca.ovh.com/manager/',
            'match' => ['/\.ovh\.ca\b/', '/ovh.*canada/'],
        ],
        'ovh_us' => [
            'name'  => 'OVHcloud US',
            'login' => 'https://us.ovhcloud.com/manager/',
            'match' => ['/\.ovh\.us\b/', '/ovh.*\bus\b/'],
        ],
        'ovh' => [
            'name'  => 'OVHcloud',
            'login' => 'https://www.ovh.com/manager/',
            'match' => ['/\bovh(cloud)?\b/'],
        ],
        'ionos_uk' => [
            'name'  => 'IONOS UK',
            'login' => 'https://login.ionos.co.uk/',
            'match' => ['/\bionos\b[^-]*\buk\b/'],
        ],
        'ionos' => [
            'name'  => 'IONOS',
            'login' => 'https://login.ionos.com/',
            'match' => ['/\bionos\b/', '/\.cserverhost\.cloud\b/'],
        ],
        'hostinger' => [
            'name'  => 'Hostinger',
            'login' => 'https://hpanel.hostinger.com/',
            'match' => ['/\bhostinger\b/', '/\.hstgr\.cloud\b/'],
        ],
        'hetzner' => [
            'name'  => 'Hetzner',
            'login' => 'https://console.hetzner.cloud/',
            'match' => ['/\bhetzner\b/'],
        ],
        'netcup' => [
            'name'  => 'netcup',
            'login' => 'https://www.customercontrolpanel.de/',
            'match' => ['/\bnetcup\b/', '/\.(quick|super)srv\.de\b/'],
        ],
        'digitalocean' => [
            'name'  => 'DigitalOcean',
            'login' => 'https://cloud.digitalocean.com/droplets',
            'match' => ['/\bdigital ?ocean\b/'],
        ],
        'vultr' => [
            'name'  => 'Vultr',
            'login' => 'https://my.vultr.com/',
            'match' => ['/\bvultr\b/'],
        ],
        'scaleway' => [
            'name'  => 'Scaleway',
            'login' => 'https://console.scaleway.com/',
            'match' => ['/\bscaleway\b/'],
        ],
        'contabo' => [
            'name'  => 'Contabo',
            'login' => 'https://my.contabo.com/',
            'match' => ['/\bcontabo\b/', '/\.contaboserver\.net\b/'],
        ],
        // The only two boxes whose notes never name the company: they were recorded by
        // the product name ("KVM Linux VPS Slice"), and "Slice" is InterServer's own
        // word for it. Confirmed against the IP range — ARIN returns Interserver, Inc
        // for 162.35.96.0/19 — so the address is matched too rather than the word alone.
        'interserver' => [
            'name'  => 'InterServer',
            'login' => 'https://my.interserver.net/',
            'match' => ['/\binterserver\b/', '/\bslices?\b/', '/(^|\s)162\.35\./'],
        ],
    ];
}

/**
 * Which company is this box rented from? null when nothing matches.
 *
 * @param  array $srv A server record from config/hestia.json
 * @return array{name:string,login:string}|null
 */
function infra_host_provider(array $srv): ?array
{
    $hay = strtolower(trim(($srv['notes'] ?? '') . ' ' . ($srv['host'] ?? '')));
    if ($hay === '') return null;

    foreach (infra_host_providers() as $p) {
        foreach ($p['match'] as $re) {
            if (preg_match($re, $hay)) return ['name' => $p['name'], 'login' => $p['login']];
        }
    }
    return null;
}
