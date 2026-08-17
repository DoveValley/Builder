<?php
/**
 * infra/lib/registrar.php — nameserver switching at the registrar (Phase-3 go-live).
 * Pluggable per registrar type. All SEVEN (namesilo, dynadot, porkbun, spaceship,
 * gandi, namecheap, cloudflare) are wired for availability/purchase/NS as their APIs
 * allow — see infra_registrar_types() for what each one can actually do; anything
 * else falls back to MANUAL.
 * Config: config/registrar.json = { "registrars": { "namesilo": {"type":"namesilo","api_key":"…"} } }
 * A domain's stored `registrar` name is matched (lowercased) to a config key.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/cache.php';   // contact-set cache for Namecheap

/**
 * Registrant phone in the form registrars ask for: +CC.NUMBER (`+1.2146633693`).
 *
 * Gandi and Spaceship both pass `contact_phone` through to their APIs untouched,
 * and both document this shape. A number typed the way a person says it —
 * 12146633693, (214) 663-3693 — looks obviously right in the form and is rejected
 * at contact creation, which happens on the FIRST PURCHASE: past availability,
 * past the money question, at the step nobody is watching.
 *
 * Deliberately conservative. Splitting a country code off a bare international
 * string is guesswork (codes are 1–3 digits), so anything that is not clearly
 * North American is returned UNCHANGED — it then fails loudly at the API instead
 * of being silently turned into a different, valid-looking number.
 */
function infra_registrar_normalize_phone(string $phone, string $country = ''): string
{
    $phone = trim($phone);
    if ($phone === '') return '';
    if (preg_match('/^\+\d{1,3}\.\d{4,}$/', $phone)) return $phone;   // already correct

    $plus   = str_starts_with($phone, '+');
    $digits = preg_replace('/\D+/', '', $phone);
    $cc     = strtoupper(trim($country));

    // +1 is safe to infer only for NANP: 11 digits starting with 1, or 10 digits
    // when the registrant is in a +1 country.
    if (strlen($digits) === 11 && $digits[0] === '1') return '+1.' . substr($digits, 1);
    if (strlen($digits) === 10 && !$plus && ($cc === '' || $cc === 'US' || $cc === 'CA')) return '+1.' . $digits;

    return $phone;
}

function infra_registrar_config(string $name): array
{
    $name = strtolower(trim($name));
    $cfg  = infra_load_json(infra_config_path('registrar.json'), []);
    $row  = $cfg['registrars'][$name] ?? [];

    // Cloudflare falls back to the account registry ONLY when nothing has been
    // saved on the Registrars page — so it works before you fill the form in, and
    // once you do, what you typed is exactly what is used.
    if ($name === 'cloudflare' && !array_filter(array_diff_key($row, ['type' => 1]))) {
        $acct = infra_cf_accounts()[0] ?? [];
        if ($acct) return $acct + ['type' => 'cloudflare'];
    }
    return $row;
}

/**
 * Registrars available to assign. Keys of config/registrar.json, plus Cloudflare
 * whenever a Cloudflare account exists — it is a real registrar you can hold
 * domains at, even though its credentials live on the Cloudflare side.
 *
 * SORTED INTO THE SAME ORDER AS THE REGISTERS PAGE, because this list is what
 * fills every registrar dropdown in the console. Unsorted it came out in the
 * order the credentials happened to be saved in — so the Register column on
 * D.Buy listed them differently from the page that explains them, and picking
 * one meant re-reading the list every time. One order, decided in one place:
 * infra_registrar_types(). Anything configured but not a known type sorts last
 * rather than being dropped.
 * @return string[]
 */
function infra_registrar_names(): array
{
    $cfg   = infra_load_json(infra_config_path('registrar.json'), []);
    $names = array_keys($cfg['registrars'] ?? []);
    if (!in_array('cloudflare', $names, true) && infra_cf_accounts()) $names[] = 'cloudflare';

    $order = array_flip(array_keys(infra_registrar_types()));
    $rank  = function (string $n) use ($cfg, $order): int {
        $type = strtolower($cfg['registrars'][$n]['type'] ?? $n);
        return $order[$type] ?? PHP_INT_MAX;
    };
    usort($names, fn($a, $b) => [$rank($a), $a] <=> [$rank($b), $b]);
    return $names;
}

/**
 * Supported registrar types and what each can actually do over its API.
 *
 * `fields` drives the admin form (and which keys are treated as secrets).
 *
 * `login` is where you sign in to that registrar's own dashboard. It is here rather
 * than in the view because the things this console cannot do are named all over these
 * definitions — Namecheap's auto-renew, Porkbun's per-domain API toggle, everything
 * about Cosmotown — and every one of them ends in "so do it in their dashboard". The
 * address of that dashboard belongs with the fact that you will need it. Account login
 * pages, deliberately, not deep links: a per-domain URL needs ids this console does not
 * hold, and they rot.
 *
 * Two SEPARATE buy flags, because conflating them would let the UI promise
 * something the code cannot deliver:
 *   `buy`       — the registrar's API is capable of registering a domain.
 *                 Drives planning: which registrars may be assigned / spread.
 *   `buy_wired` — a purchase adapter is implemented HERE. All seven are: the first
 *                 five as of 2026-08-06, Spaceship and Gandi on 2026-08-16. The flag
 *                 stays because it is what the UI reads to avoid promising a buy it
 *                 cannot complete — an eighth registrar would arrive with `buy`
 *                 true and `buy_wired` false.
 * Neither is a promise the account is funded or the key valid — that is Test.
 *
 * Only types listed here can be added. Spaceship is Namecheap-owned, so it adds no
 * independence from that parent — it was left out for exactly that reason, and added
 * back on one merit: its API can set auto-renew, which Namecheap's cannot.
 *
 * THE ORDER OF THIS ARRAY IS THE ORDER OF THE CARDS on the Registrars page, and it
 * is deliberate: the ones worth buying fleet domains at first, Namecheap late
 * because its auto-renew cannot be set by API, and Cloudflare LAST because it
 * cannot check availability and is a place to *hold* domains rather than shop at.
 */
function infra_registrar_types(): array
{
    return [
        'namesilo' => [
            'label'  => 'NameSilo',
            'login'  => 'https://www.namesilo.com/login',
            'check_bulk' => 50,
            'autorenew' => ['ok' => true, 'note' =>
                'Set at registration via <code>auto_renew</code>, and changeable later with '
              . '<code>addAutoRenewal</code> / <code>removeAutoRenewal</code>. Immediate, and nothing surprising about it.'],
            'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]],
            'check' => true, 'buy' => true, 'buy_wired' => true, 'ns' => true, 'balance' => true,
            'note'  => 'No IP allowlist. Availability check returns a price. Free WHOIS privacy on registration. The only registrar whose purchase adapter is written today.',
        ],
        'dynadot' => [
            'label'  => 'Dynadot',
            'login'  => 'https://www.dynadot.com/account/sign-in',
            'check_bulk' => 1,      // 1 per request, no rate limit
            'autorenew' => ['ok' => true, 'note' =>
                '<code>set_renew_option</code> with <code>renew_option=auto|no</code>, as a <strong>separate call after registration</strong> '
              . '&mdash; the register command has no auto-renew parameter. The first attempt normally fails with '
              . '"could not find domain in your account" because a freshly registered domain is not immediately queryable; '
              . 'the console pauses, retries, then reads the state back.'],
            'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]],
            'check' => true, 'buy' => true, 'buy_wired' => true, 'ns' => true, 'balance' => true,
            'note'  => 'Cheapest .com of the seven. Registration uses the account\'s default contact, so no contact set is needed. May require external nameservers be added to the account before they can be set on a domain.',
        ],
        'porkbun' => [
            'label'  => 'Porkbun',
            'login'  => 'https://porkbun.com/account/login',
            'check_bulk' => 0,      // 1 per request AND 1 per 10 seconds
            'autorenew' => ['ok' => true, 'note' =>
                '<code>/domain/updateAutoRenew/{domain}</code> takes <code>status: on|off</code> &mdash; note <em>status</em>, and '
              . '<em>on/off</em>, not the <code>autoRenew: yes/no</code> its own create call uses. The obvious parameter name is rejected.'],
            'fields' => [
                'api_key'        => ['label' => 'API key',        'secret' => true],
                'secret_api_key' => ['label' => 'Secret API key', 'secret' => true],
            ],
            'check' => true, 'buy' => true, 'buy_wired' => true, 'ns' => true, 'balance' => false,
            'note'  => 'API Access must be toggled ON per-domain in the Porkbun dashboard. Registration DOES work over the API (/domain/create) but it requires programmatically accepting Porkbun\'s registration agreement, terms and automatic-renewal terms, and an integer "cost" confirming the expected price. No balance endpoint, so funds cannot be checked before buying. Availability is limited to ONE CHECK PER 10 SECONDS — use NameSilo or Dynadot for bulk lists and keep Porkbun for spot checks.',
        ],
        'spaceship' => [
            'label'  => 'Spaceship',
            'login'  => 'https://www.spaceship.com/login',
            'check_bulk' => 50,     // one request for the whole list
            'autorenew' => ['ok' => true, 'note' =>
                '<code>PUT /domains/{domain}/autorenew</code> with <code>isEnabled</code>, and it can also be set at '
              . 'registration with <code>autoRenew</code>. This is the reason to hold domains here rather than at '
              . 'Namecheap, its own parent, whose API cannot set auto-renew at all.'],
            'fields' => [
                'api_key'         => ['label' => 'API key',    'secret' => true],
                'api_secret'      => ['label' => 'API secret', 'secret' => true],
                // Registration takes contact IDs, so a contact is created from these
                // once and its id cached back into this config.
                'contact_first'   => ['label' => 'Registrant first name', 'secret' => false],
                'contact_last'    => ['label' => 'Registrant last name',  'secret' => false],
                'contact_email'   => ['label' => 'Registrant email',      'secret' => false],
                'contact_phone'   => ['label' => 'Registrant phone', 'secret' => false,
                                      'hint' => 'saved as +1.2146633693 — a US/Canada number is converted for you; other countries must be typed as +CC.NUMBER'],
                'contact_address' => ['label' => 'Street address',        'secret' => false],
                'contact_city'    => ['label' => 'City',                  'secret' => false],
                // State/postcode are passed when present and omitted when not, so they
                // are not required here either.
                'contact_state'   => ['label' => 'State / province',      'secret' => false, 'optional' => true],
                'contact_zip'     => ['label' => 'Postcode',              'secret' => false, 'optional' => true],
                'contact_country' => ['label' => 'Country (2-letter)',    'secret' => false, 'default' => 'US'],
                // MUST be optional: it is written back by infra_reg_spaceship_contact_id()
                // after the first purchase. Required, it made this card impossible to
                // save at all — the only way to fill it was to already have it.
                'contact_id'      => ['label' => 'Contact ID', 'secret' => false, 'optional' => true,
                                      'hint' => 'left blank — filled in automatically the first time a contact is created'],
            ],
            'check' => true, 'buy' => true, 'buy_wired' => true, 'ns' => true, 'balance' => false,
            'note'  => 'Two headers, no IP allowlist. Availability takes the whole list in one request but returns NO price for standard names (only premiums quote one). There is no balance endpoint, so funds cannot be checked before a purchase — same blind spot as Porkbun. Registration answers 202 Accepted and completes ASYNCHRONOUSLY, so a domain is not registered the moment the call returns. Its auto-renew API works, which its parent Namecheap\'s does not.',
        ],
        'gandi' => [
            'label'  => 'Gandi',
            'login'  => 'https://admin.gandi.net/',
            'check_bulk' => 1,      // one name per request
            'autorenew' => ['ok' => true, 'note' =>
                '<code>PATCH /v5/domain/domains/{domain}/autorenew</code> with <code>enabled</code>. It is a '
              . 'SEPARATE call after registration — the create body has no auto-renew field — so a purchase that '
              . 'stops halfway leaves a domain that will not renew itself. A GET on that same path answers 404 by '
              . 'design; only PATCH exists.'],
            'fields' => [
                'token' => ['label' => 'Personal access token', 'secret' => true],
                // Optional in fact AND in the flag. Left blank, the contact is copied
                // from a domain the account already holds, so nothing needs retyping.
                // The labels said "(optional)" while the flag was absent, so the save
                // handler — which reads the flag, not the label — rejected the whole
                // form over eight fields the adapter never needed. A label is not a
                // rule; the flag is the rule.
                'contact_first'   => ['label' => 'Registrant first name', 'secret' => false, 'optional' => true],
                'contact_last'    => ['label' => 'Registrant last name',  'secret' => false, 'optional' => true],
                'contact_email'   => ['label' => 'Registrant email',      'secret' => false, 'optional' => true],
                'contact_phone'   => ['label' => 'Registrant phone', 'secret' => false, 'optional' => true,
                                      'hint' => 'saved as +1.2146633693 — a US/Canada number is converted for you; other countries must be typed as +CC.NUMBER'],
                'contact_address' => ['label' => 'Street address',        'secret' => false, 'optional' => true],
                'contact_city'    => ['label' => 'City',                  'secret' => false, 'optional' => true],
                'contact_zip'     => ['label' => 'Postcode',              'secret' => false, 'optional' => true],
                'contact_country' => ['label' => 'Country (2-letter)',    'secret' => false, 'optional' => true, 'default' => 'US'],
            ],
            'check' => true, 'buy' => true, 'buy_wired' => true, 'ns' => true, 'balance' => false,
            'note'  => 'One bearer token, no IP allowlist. Availability quotes a REAL PRICE, which most of the others here do not. The registrant contact is copied from a domain the account already holds, so no contact setup is needed before the first buy. ⚠ A Gandi token carries an explicit scope list and there is no way to test "can I create" without attempting a purchase — Test reports the scopes so the gap is visible rather than assumed. No balance endpoint. Registration answers 202 and completes asynchronously, and auto-renew is a separate call afterwards.',
        ],
        'namecheap' => [
            'label'  => 'Namecheap',
            'login'  => 'https://www.namecheap.com/myaccount/login/',
            'check_bulk' => 40,     // domains per request
            'autorenew' => ['ok' => false, 'note' =>
                '<strong>Domains bought here register with auto-renew OFF and there is no way to turn it on programmatically.</strong> '
              . 'An undocumented <code>namecheap.domains.setAutoRenew</code> endpoint exists and always answers '
              . '<code>IsSuccess="true"</code> &mdash; even when sent no parameter at all &mdash; while changing nothing. '
              . 'It is absent from Namecheap\'s documented API, and their knowledgebase says auto-renew is a dashboard-only setting. '
              . 'The console does not call it: it reads the real state and reports that.<br><br>'
              . '<strong>The console buys a 1-year term here, like everywhere else</strong>, so a Namecheap domain '
              . 'expires in a year unless something is done about it. It used to buy 3 years automatically; across a '
              . 'few hundred domains that ties up two extra years of cost up front to defer a risk rather than remove it.<br><br>'
              . '<strong>What to do, per domain:</strong> switch auto-renew on by hand in the Namecheap dashboard; '
              . 'or choose <strong>more years up front</strong> on the New Site / Bulk form (multi-year costs about '
              . '10&cent;/yr more &mdash; 1yr $11.28 vs 2&ndash;10yr $11.38 &mdash; so a long term is near-free insurance '
              . 'that cannot fail); or simply buy fleet domains at one of the other four.'],
            'fields' => [
                'api_user'  => ['label' => 'API user',  'secret' => false],
                'api_key'   => ['label' => 'API key',   'secret' => true],
                'username'  => ['label' => 'Username',  'secret' => false],
                'client_ip' => ['label' => 'Whitelisted IP', 'secret' => false, 'default' => '187.127.254.206'],
            ],
            'check' => true, 'buy' => true, 'buy_wired' => true, 'ns' => true, 'balance' => true,
            'note'  => 'Needs API access enabled, a funded balance, and this server\'s IP whitelisted in your Namecheap profile. Unlike NameSilo and Dynadot it will not fall back to an account default contact — registration must supply a full registrant/tech/admin/billing set, which is read from a domain you already hold rather than retyped. ⚠ AUTO-RENEW CANNOT BE SET BY API: domains register with it OFF and setAutoRenew reports success without applying anything, so every Namecheap purchase needs a manual dashboard visit or it lapses. Prefer NameSilo, Dynadot, Porkbun or Spaceship for fleet buying — Spaceship is Namecheap\'s own subsidiary and its API sets auto-renew fine, which is the whole reason it is here.',
        ],
        'cosmotown' => [
            'label'  => 'Cosmotown',
            // Their sign-in is an in-page route, so the #! is part of the address —
            // https://www.cosmotown.com/login answers a 301 to it rather than serving it.
            'login'  => 'https://www.cosmotown.com/#!/login',
            'check_bulk' => 0,
            // Every capability is false because NOTHING IS WIRED, not because the
            // registrar cannot do it. `pending_api` is what makes the difference
            // visible: without it the card renders four ✗ badges and reads as "this
            // place is useless", when the truth is "nobody has answered us yet".
            // When support grants API access: set these flags from their docs, add
            // the credential fields, and write the adapter cases — until then the
            // switches in this file fall through to their defaults, which say
            // "no adapter" and "set nameservers manually" rather than failing oddly.
            'pending_api' => 'API access is not set up yet — asked 2026-08-17, waiting to hear back from Cosmotown support. '
                           . 'Until they answer, nothing here is automated: availability, buying, nameserver switching and '
                           . 'auto-renew all have to be done in their dashboard. What the console CAN do meanwhile is '
                           . 'record that a domain is held here, so the fleet table is honest and go-live tells you to '
                           . 'switch the nameservers by hand instead of silently skipping it.',
            'fields' => [],         // unknown until support says what auth it uses
            'check' => false, 'buy' => false, 'buy_wired' => false, 'ns' => false, 'balance' => false,
            'note'  => 'Sells .com near cost, which is the reason to have an account here. Nothing is wired: no availability check, no auto-buy, no nameserver switch, no balance read — all of that waits on API access. Assigning a domain to Cosmotown is still worth doing: it records where the domain actually lives, and go-live will tell you to change the nameservers manually rather than assuming it was done.',
        ],
        'cloudflare' => [
            'label'  => 'Cloudflare Registrar',
            'login'  => 'https://dash.cloudflare.com/login',
            'check_bulk' => 0,      // no availability endpoint at all
            'autorenew' => ['ok' => true, 'note' =>
                'Works via <code>PUT /registrar/domains/{domain}</code>, and can be passed in the registration body &mdash; '
              . 'but Cloudflare <strong>defaults new registrations to auto-renew FALSE</strong>. The console sets it explicitly '
              . 'at purchase and verifies afterwards; without that a Cloudflare domain quietly expires in a year.'],
            // Credentials are entered here like every other registrar. They are the
            // same values as the Cloudflare account registry, so the form PREFILLS
            // the non-secret ones from it — but what is saved here is what is used,
            // with no hidden fallback to reason about.
            'fields' => [
                'account_id' => ['label' => 'Account ID', 'secret' => false,
                                 'hint' => 'Cloudflare dashboard → any domain → Overview, right-hand column'],
                'api_token'  => ['label' => 'API token', 'secret' => true, 'optional' => true,
                                 'hint' => 'needs Account → Domain Registration: Read'],
                'email'      => ['label' => 'Email (global-key auth)', 'secret' => false, 'optional' => true],
                'global_key' => ['label' => 'Global API key', 'secret' => true, 'optional' => true,
                                 'hint' => 'alternative to the token — used when email + global key are both set'],
            ],
            'prefill_from_cf' => ['account_id', 'email'],
            // Kept configured and wired, but NOT where new fleet domains are bought:
            // a Cloudflare-registered domain is locked to Cloudflare's own nameservers
            // and CDN, so registering here decides the hosting question as a side
            // effect of choosing a registrar. Domains already held here are fine and
            // stay — this is about where the next purchase goes.
            'not_in_use' => 'Not used for new purchases — a domain registered at Cloudflare is forced onto '
                          . 'Cloudflare nameservers and its CDN, which takes the choice of where the domain '
                          . 'points away from us. Buy elsewhere and put the zone on Cloudflare deliberately, '
                          . 'which is the same end state with the decision kept in our hands. Domains already '
                          . 'registered here keep working and need no action.',
            'check' => false, 'buy' => true, 'buy_wired' => true, 'ns' => false, 'balance' => false,
            'note'  => 'Sells AT COST, so it is the cheapest place to hold a domain. Registration works over the API — POST /registrar/registrations, not the /registrar/domains path that returns 403 — and needs the GLOBAL KEY, since a scoped token gets "Authentication error" on Registrar. Billed to the card on the account, so there is no balance to check. No availability endpoint (check a name at another registrar first). Nameservers are n/a: a Cloudflare-registered domain is always on Cloudflare nameservers, so go-live has nothing to switch.',
        ],
    ];
}

/** Resolve a configured registrar name to its type definition. */
function infra_registrar_type_def(string $name): array
{
    $cfg  = infra_registrar_config($name);
    $type = strtolower($cfg['type'] ?? $name);
    return infra_registrar_types()[$type] ?? [];
}

/** Configured registrars that can complete a purchase over the API. @return string[] */
function infra_registrar_buyable(): array
{
    $out = [];
    foreach (infra_registrar_names() as $n) {
        if (!empty(infra_registrar_type_def($n)['buy'])) $out[] = $n;
    }
    return $out;
}

/**
 * Configured registrars that can answer "is this available?", best first.
 *
 * Who CHECKS and who BUYS are separate decisions. Availability is a public fact —
 * every registrar reads the same registry — so there is no reason to ask the slow
 * one. Asking each domain's assigned registrar meant a 400-domain list crawled
 * through Porkbun at one per ten seconds and skipped Cloudflare entirely.
 *
 * @return array name => ['label'=>string, 'bulk'=>int, 'speed'=>string]
 */
function infra_registrar_checkers(): array
{
    $out = [];
    foreach (infra_registrar_names() as $n) {
        $def = infra_registrar_type_def($n);
        if (empty($def['check'])) continue;          // Cloudflare has no endpoint
        $bulk = (int) ($def['check_bulk'] ?? 1);
        $out[$n] = [
            'label' => $def['label'] ?? $n,
            'bulk'  => $bulk,
            'speed' => $bulk >= 10 ? 'fast — ' . $bulk . ' per request'
                     : ($bulk === 1 ? 'slow — one at a time' : 'very slow — one per 10 seconds'),
        ];
    }
    uasort($out, fn($a, $b) => $b['bulk'] <=> $a['bulk']);   // batched first
    return $out;
}

/** The best available checker, or '' if none is configured. */
function infra_default_checker(): string
{
    $c = infra_registrar_checkers();
    return $c ? (string) array_key_first($c) : '';
}

/* ============================= VERIFY + BALANCE ============================= */

/**
 * Credential test for the admin UI: does the key work, and what is the balance?
 * Read-only for every registrar. Balance is the number that decides whether a
 * scheduled buy can actually complete, so it is reported alongside reachability.
 * @return array{ok:bool, message:string, balance:?string, currency:string}
 */
function infra_registrar_verify(string $name): array
{
    $cfg  = infra_registrar_config($name);
    $type = strtolower($cfg['type'] ?? $name);
    if (!$cfg) return ['ok' => false, 'message' => 'no credentials saved', 'balance' => null, 'currency' => ''];

    switch ($type) {
        case 'gandi':      return infra_reg_gandi_verify($cfg);
        case 'spaceship':  return infra_reg_spaceship_verify($cfg);
        case 'namesilo':   return infra_reg_namesilo_verify2($cfg);
        case 'namecheap':  return infra_reg_namecheap_verify($cfg);
        case 'porkbun':    return infra_reg_porkbun_verify2($cfg);
        case 'dynadot':    return infra_reg_dynadot_verify($cfg);
        case 'cloudflare': return infra_reg_cloudflare_verify($cfg);
        default:          return ['ok' => false, 'message' => "no test adapter for type '{$type}'", 'balance' => null, 'currency' => ''];
    }
}

function infra_reg_namesilo_verify2(array $cfg): array
{
    $r = infra_reg_namesilo_call($cfg, 'getAccountBalance');
    if (!$r['ok']) return ['ok' => false, 'message' => "NameSilo error {$r['code']}: {$r['detail']}", 'balance' => null, 'currency' => ''];
    return ['ok' => true, 'message' => 'NameSilo API OK', 'balance' => (string) ($r['reply']['balance'] ?? ''), 'currency' => 'USD'];
}

function infra_reg_namecheap_verify(array $cfg): array
{
    $r = infra_reg_namecheap_call($cfg, 'namecheap.users.getBalances');
    if (!$r['ok']) return ['ok' => false, 'message' => 'Namecheap: ' . $r['message'], 'balance' => null, 'currency' => ''];
    $b = $r['xml']->CommandResponse->UserGetBalancesResult ?? null;
    return ['ok' => true, 'message' => 'Namecheap API OK',
            'balance' => $b ? (string) $b['AvailableBalance'] : '', 'currency' => $b ? (string) $b['Currency'] : ''];
}

function infra_reg_porkbun_verify2(array $cfg): array
{
    // NOT /ping — that endpoint answers SUCCESS without checking credentials at
    // all (verified: empty keys still return SUCCESS), so it would report a
    // working connection for a typo'd key. listAll actually requires auth.
    $r = infra_reg_porkbun_call($cfg, '/domain/listAll', ['start' => 0]);
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Porkbun: ' . $r['message'], 'balance' => null, 'currency' => ''];
    }
    $n = count($r['json']['domains'] ?? []);
    // Porkbun exposes no balance endpoint — absence is reported, not faked as 0.
    return ['ok' => true, 'message' => "Porkbun API OK — {$n} domain(s) held", 'balance' => null, 'currency' => ''];
}

function infra_reg_dynadot_verify(array $cfg): array
{
    $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query(
        ['key' => $cfg['api_key'] ?? '', 'command' => 'account_info']), ['verify' => true, 'timeout' => 30]);
    $j    = $r['json'] ?? [];
    $resp = $j['AccountInfoResponse'] ?? $j['Response'] ?? $j;
    $stat = strtolower((string) ($resp['ResponseCode'] ?? $resp['Status'] ?? ''));
    $ok   = $r['code'] >= 200 && $r['code'] < 300 && ($stat === '0' || $stat === 'success');
    $bal  = $resp['AccountInfo']['Balance'] ?? $resp['Balance'] ?? null;
    return ['ok' => $ok,
            'message' => $ok ? 'Dynadot API OK' : ('Dynadot: ' . (($resp['Error'] ?? '') ?: ('HTTP ' . $r['code']))),
            'balance' => $bal !== null ? (string) $bal : null, 'currency' => 'USD'];
}


/** Shared Namecheap XML call. @return array{ok:bool,message:string,xml:?SimpleXMLElement} */
function infra_reg_namecheap_call(array $cfg, string $command, array $params = []): array
{
    $q = array_merge([
        'ApiUser'  => $cfg['api_user'] ?? '',
        'ApiKey'   => $cfg['api_key'] ?? '',
        'UserName' => $cfg['username'] ?? ($cfg['api_user'] ?? ''),
        'ClientIp' => $cfg['client_ip'] ?? '',
        'Command'  => $command,
    ], $params);
    $r   = infra_http('GET', 'https://api.namecheap.com/xml.response?' . http_build_query($q), ['verify' => true, 'timeout' => 40]);
    $xml = @simplexml_load_string($r['raw']);
    if ($xml === false) {
        return ['ok' => false, 'message' => 'unparseable response (HTTP ' . $r['code'] . ')', 'xml' => null];
    }
    if ((string) $xml['Status'] !== 'OK') {
        $err = (string) ($xml->Errors->Error ?? 'error');
        // The single most common Namecheap failure — name it explicitly.
        if (stripos($err, 'ip') !== false) $err .= ' (is ' . ($cfg['client_ip'] ?? '?') . ' whitelisted in your Namecheap API settings?)';
        return ['ok' => false, 'message' => $err, 'xml' => $xml];
    }
    return ['ok' => true, 'message' => '', 'xml' => $xml];
}

/** A domain's real auto-renew state at Namecheap, or null if unreadable. */
function infra_reg_namecheap_autorenew(array $cfg, string $domain): ?bool
{
    $l = infra_reg_namecheap_call($cfg, 'namecheap.domains.getList',
             ['SearchTerm' => explode('.', $domain)[0], 'PageSize' => 100]);
    if (!$l['ok']) return null;
    foreach (($l['xml']->CommandResponse->DomainGetListResult->Domain ?? []) as $d) {
        if (strcasecmp((string) $d['Name'], $domain) === 0) {
            return strtolower((string) $d['AutoRenew']) === 'true';
        }
    }
    return null;
}

/**
 * The registrant contact set Namecheap needs to register anything.
 *
 * Namecheap is the odd one out: NameSilo and Dynadot fall back to the account's
 * default contact, but Namecheap demands a full set of registrant, tech, admin
 * and billing details on every create call. Rather than storing (and drifting
 * from) a second copy, this READS THE CONTACT OFF A DOMAIN ALREADY IN THE
 * ACCOUNT — one shared set, always matching what the other domains use.
 *
 * @return array|null the flat contact fields, or null if none could be read
 */
function infra_reg_namecheap_contacts(array $cfg, string $sourceDomain = ''): ?array
{
    $key = 'nc_contacts';
    $c = infra_cache_get($key, 86400);
    if ($c !== null && $sourceDomain === '') return $c;

    // Any domain in the account will do — take the first unless told otherwise.
    if ($sourceDomain === '') {
        $l = infra_reg_namecheap_call($cfg, 'namecheap.domains.getList', ['PageSize' => 100, 'Page' => 1]);
        if (!$l['ok']) return null;
        $first = $l['xml']->CommandResponse->DomainGetListResult->Domain[0] ?? null;
        if (!$first) return null;
        $sourceDomain = (string) $first['Name'];
    }

    $r = infra_reg_namecheap_call($cfg, 'namecheap.domains.getContacts', ['DomainName' => $sourceDomain]);
    if (!$r['ok']) return null;
    $reg = $r['xml']->CommandResponse->DomainContactsResult->Registrant ?? null;
    if (!$reg) return null;

    $out = ['_source' => $sourceDomain];
    foreach (['FirstName','LastName','Address1','Address2','City','StateProvince',
              'PostalCode','Country','Phone','EmailAddress','Organization','JobTitle'] as $f) {
        $v = trim((string) ($reg->$f ?? ''));
        if ($v !== '') $out[$f] = $v;
    }
    foreach (['FirstName','LastName','Address1','City','StateProvince','PostalCode','Country','Phone','EmailAddress'] as $req) {
        if (empty($out[$req])) return null;   // an incomplete set would fail mid-purchase
    }
    infra_cache_put($key, $out);
    return $out;
}

/**
 * Register a domain at Namecheap. Applies the same contact set to all four
 * required roles, and turns on the free WhoisGuard.
 *
 * Namecheap exposes no auto-renew setter over its API, so the setting is READ
 * BACK and reported rather than claimed — the account default decides it, and
 * saying "auto-renew on" without checking would be a guess.
 */
function infra_reg_namecheap_register(string $domain, int $years, array $cfg, array $opts = []): array
{
    $years    = max(1, min(10, $years));
    $contacts = infra_reg_namecheap_contacts($cfg);
    if (!$contacts) {
        return ['ok' => false, 'message' => 'Namecheap: could not read a complete registrant contact from an existing domain — registration needs one and none was available'];
    }

    $params = ['DomainName' => $domain, 'Years' => $years,
               'AddFreeWhoisguard' => 'yes', 'WGEnabled' => 'yes'];
    // The same person in all four roles; Namecheap requires every one of them.
    foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
        foreach ($contacts as $f => $v) {
            if ($f === '_source') continue;
            $params[$role . $f] = $v;
        }
    }

    $r = infra_reg_namecheap_call($cfg, 'namecheap.domains.create', $params);
    if (!$r['ok']) return ['ok' => false, 'message' => 'Namecheap: ' . $r['message']];

    $res     = $r['xml']->CommandResponse->DomainCreateResult ?? null;
    $charged = $res ? (string) $res['ChargedAmount'] : '';
    $msg = "Namecheap: registered {$domain} for {$years}yr"
         . ($charged !== '' ? " (charged \${$charged})" : '')
         . ', WhoisGuard on, contact from ' . $contacts['_source'];

    // Report the auto-renew state that is actually true.
    $want = array_key_exists('auto_renew', $opts) ? (bool) $opts['auto_renew'] : true;
    // No setAutoRenew call: Namecheap has no such documented method, and the
    // undocumented endpoint answers IsSuccess="true" while changing nothing.
    // Making a call known to do nothing only invites someone to trust it later.
    // The state is read back and reported as fact instead.
    $actual = infra_reg_namecheap_autorenew($cfg, $domain);
    if ($actual === null) {
        $msg .= ', auto-renew UNVERIFIED — check the dashboard';
    } elseif ($actual) {
        $msg .= ', auto-renew ON (verified)';
    } else {
        $msg .= ', auto-renew OFF — Namecheap cannot set this over its API. It expires in '
              . $years . 'yr and WILL NOT RENEW ITSELF: switch auto-renew on for it in the'
              . ' Namecheap dashboard, or extend the term there.';
    }
    return ['ok' => true, 'message' => $msg];
}

/* ============================= AVAILABILITY ============================= */

/**
 * Is each domain registrable? Batched where the API allows it, because 400 names
 * must not become 400 requests.
 *
 * Never guesses: a domain whose check failed comes back 'unknown', not 'taken',
 * so a network blip can never mark a good name as dead.
 *
 * @param  string[] $domains
 * @return array domain => {available:?bool, price:string, note:string}
 */
function infra_registrar_check_availability(array $domains, string $registrarName): array
{
    $domains = array_values(array_unique(array_filter(array_map(
        fn($d) => strtolower(trim((string) $d)), $domains))));
    if (!$domains) return [];

    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);
    $out  = [];
    foreach ($domains as $d) $out[$d] = ['available' => null, 'price' => '', 'note' => ''];

    switch ($type) {
        case 'gandi':     return infra_reg_gandi_check($domains, $cfg, $out);
        case 'spaceship': return infra_reg_spaceship_check($domains, $cfg, $out);
        case 'namesilo':  return infra_reg_namesilo_check($domains, $cfg, $out);
        case 'namecheap': return infra_reg_namecheap_check($domains, $cfg, $out);
        case 'porkbun':   return infra_reg_porkbun_check($domains, $cfg, $out);
        case 'dynadot':   return infra_reg_dynadot_check($domains, $cfg, $out);
        case 'cloudflare':
            foreach ($out as $d => $_) $out[$d]['note'] = 'Cloudflare has no availability API — check with another registrar';
            return $out;
        default:
            foreach ($out as $d => $_) $out[$d]['note'] = "no availability adapter for '{$registrarName}'";
            return $out;
    }
}

/** NameSilo: batched, and the only one that returns a price. */
function infra_reg_namesilo_check(array $domains, array $cfg, array $out): array
{
    foreach (array_chunk($domains, 50) as $chunk) {
        $r = infra_reg_namesilo_call($cfg, 'checkRegisterAvailability', ['domains' => implode(',', $chunk)]);
        if (!$r['ok']) {
            foreach ($chunk as $d) $out[$d]['note'] = "check failed: {$r['detail']}";
            continue;
        }
        // NameSilo returns TWO DIFFERENT SHAPES and the difference is easy to miss:
        //   one result   → reply.available.domain = {…}      (wrapped in "domain")
        //   many results → reply.available        = [{…},{…}] (no wrapper at all)
        // Reading only the wrapped form made every batch of 2+ return nothing —
        // which failed safe (verdict left unchanged) but meant bulk checking, the
        // entire reason this is batched, never actually worked.
        foreach (infra_reg_ns_results($r['reply']['available'] ?? []) as $a) {
            $name = strtolower(is_array($a) ? ($a['domain'] ?? '') : (string) $a);
            if (!isset($out[$name])) continue;
            $out[$name]['available'] = true;
            if (is_array($a) && isset($a['price'])) $out[$name]['price'] = (string) $a['price'];
            if (is_array($a) && !empty($a['premium'])) $out[$name]['note'] = 'premium';
        }
        foreach (infra_reg_ns_results($r['reply']['unavailable'] ?? []) as $u) {
            $name = strtolower(is_array($u) ? ($u['domain'] ?? '') : (string) $u);
            if (isset($out[$name])) { $out[$name]['available'] = false; $out[$name]['note'] = 'taken'; }
        }
    }
    return $out;
}

/** Namecheap: DomainList takes a comma list; no price for non-premium names. */
function infra_reg_namecheap_check(array $domains, array $cfg, array $out): array
{
    foreach (array_chunk($domains, 40) as $chunk) {
        $r = infra_reg_namecheap_call($cfg, 'namecheap.domains.check', ['DomainList' => implode(',', $chunk)]);
        if (!$r['ok']) {
            foreach ($chunk as $d) $out[$d]['note'] = 'check failed: ' . $r['message'];
            continue;
        }
        foreach ($r['xml']->CommandResponse->DomainCheckResult as $d) {
            $name = strtolower((string) $d['Domain']);
            if (!isset($out[$name])) continue;
            $avail = strtolower((string) $d['Available']) === 'true';
            $out[$name]['available'] = $avail;
            if (strtolower((string) $d['IsPremiumName']) === 'true') {
                $out[$name]['note']  = 'premium';
                $out[$name]['price'] = (string) $d['PremiumRegistrationPrice'];
            } elseif (!$avail) {
                $out[$name]['note'] = 'taken';
            }
        }
    }
    return $out;
}

/**
 * Porkbun: one request per domain, and it allows only ONE CHECK PER 10 SECONDS.
 * The response says so itself ("1 out of 1 checks within 10 seconds used", with
 * ttlRemaining) — an earlier 0.6s spacing sailed straight through the limit and
 * every domain after the first came back unchecked.
 *
 * That makes Porkbun unsuitable for bulk availability: 400 domains would take
 * over an hour. Use NameSilo (batched) or Dynadot for lists; this is for spot
 * checks. It honours the limit and retries once on the server's own timing.
 */
function infra_reg_porkbun_check(array $domains, array $cfg, array $out): array
{
    $domains = array_values($domains);
    foreach ($domains as $i => $d) {
        if ($i > 0) sleep(10);
        $r = infra_reg_porkbun_check_one($cfg, $d, 1);
        if (!$r['ok']) { $out[$d]['note'] = 'check failed: ' . $r['message']; continue; }

        $resp = $r['response'];
        $av   = strtolower((string) ($resp['avail'] ?? ''));
        if ($av !== 'yes' && $av !== 'no') { $out[$d]['note'] = 'check inconclusive'; continue; }
        $out[$d]['available'] = ($av === 'yes');
        $out[$d]['price']     = (string) ($resp['price'] ?? '');
        if ($av === 'no')                              $out[$d]['note'] = 'taken';
        elseif (strtolower((string) ($resp['premium'] ?? '')) === 'yes') $out[$d]['note'] = 'premium';
    }
    return $out;
}

/**
 * Dynadot: ONE DOMAIN PER REQUEST. Its search command rejects multiple domains
 * outright ("too many domains entered, please search one domain per command"),
 * so the batched version silently returned a verdict for nothing. Throttled,
 * because 400 domains here means 400 requests.
 */
function infra_reg_dynadot_check(array $domains, array $cfg, array $out): array
{
    foreach (array_values($domains) as $i => $d) {
        if ($i > 0) usleep(400000);
        $r = infra_reg_dynadot_call($cfg, 'search',
                 ['domain0' => $d, 'show_price' => 1, 'currency' => 'usd']);
        if (!$r['ok']) { $out[$d]['note'] = 'check failed: ' . $r['message']; continue; }

        $res = $r['data']['SearchResults'][0] ?? null;
        if (!$res) { $out[$d]['note'] = 'check inconclusive'; continue; }

        $av = strtolower((string) ($res['Available'] ?? ''));
        if ($av === 'yes' || $av === 'no') {
            $out[$d]['available'] = ($av === 'yes');
            if ($av === 'no') $out[$d]['note'] = 'taken';
        } else {
            $out[$d]['note'] = 'check inconclusive';
        }
        // Dynadot returns prose, not a number:
        // "Registration Price: 10.88 in USD and Renewal price: 10.88 in USD…"
        if (!empty($res['Price']) && preg_match('/Registration Price:\s*([\d.]+)/i', (string) $res['Price'], $pm)) {
            $out[$d]['price'] = $pm[1];
        }
    }
    return $out;
}

/**
 * One Dynadot api3 call. Their JSON nests the payload under a per-command key
 * ("RegisterResponse", "SetRenewOptionResponse", …) and sometimes just
 * "Response", so the envelope is unwrapped defensively rather than assumed.
 * @return array{ok:bool, code:string, message:string, data:array}
 */
function infra_reg_dynadot_call(array $cfg, string $command, array $params = []): array
{
    $q = array_merge(['key' => $cfg['api_key'] ?? '', 'command' => $command], $params);
    $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query($q),
                    ['verify' => true, 'timeout' => 60]);   // registration is slow

    $j = $r['json'] ?? [];
    $resp = null;
    foreach ($j as $k => $v) {                       // first object-valued key is the envelope
        if (is_array($v) && (isset($v['ResponseCode']) || isset($v['Status']))) { $resp = $v; break; }
    }
    if ($resp === null) $resp = $j['Response'] ?? $j;

    $code = (string) ($resp['ResponseCode'] ?? '');
    $stat = strtolower((string) ($resp['Status'] ?? ''));
    $ok   = ($r['code'] >= 200 && $r['code'] < 300) && ($code === '0' || $stat === 'success');
    // Dynadot reports some failures ONLY in Status (e.g. "insufficient_funds")
    // with no Error field at all — without this the message was raw JSON.
    $err = $resp['Error'] ?? $resp['error'] ?? '';
    if ($err === '' && !$ok && $stat !== '') $err = str_replace('_', ' ', $stat);
    return [
        'ok'      => $ok,
        'code'    => $code,
        'message' => $ok ? 'ok' : ($err !== '' ? (string) $err : ('HTTP ' . $r['code'] . ' ' . substr($r['raw'], 0, 120))),
        'data'    => is_array($resp) ? $resp : [],
    ];
}

/** A domain's current renew setting at Dynadot, or null if it cannot be read. */
function infra_reg_dynadot_renew_option(array $cfg, string $domain): ?string
{
    $l = infra_reg_dynadot_call($cfg, 'list_domain');
    foreach (($l['data']['MainDomains'] ?? []) as $d) {
        if (strcasecmp((string) ($d['Name'] ?? ''), $domain) === 0) {
            return (string) ($d['RenewOption'] ?? '');
        }
    }
    return null;
}

/**
 * Register a domain at Dynadot. Uses the account's DEFAULT CONTACT — verified on
 * the account, so no contact set is required here (same as NameSilo).
 *
 * Auto-renew is applied as a SEPARATE call after purchase: Dynadot's register
 * command has no auto-renew parameter, and a domain that registered but silently
 * kept the default renew setting is exactly the kind of thing that only surfaces
 * a year later. A failure to set it is reported rather than swallowed.
 */
function infra_reg_dynadot_register(string $domain, int $years, array $cfg, array $opts = []): array
{
    $years = max(1, min(10, $years));
    $r = infra_reg_dynadot_call($cfg, 'register',
             ['domain' => $domain, 'duration' => $years, 'currency' => 'usd']);
    if (!$r['ok']) {
        return ['ok' => false, 'code' => $r['code'], 'message' => 'Dynadot: ' . $r['message']];
    }

    $msg = "Dynadot: registered {$domain} for {$years}yr";
    $autoRenew = array_key_exists('auto_renew', $opts) ? (bool) $opts['auto_renew'] : true;

    // A freshly-registered domain is not immediately queryable — the first
    // set_renew_option comes back "could not find domain in your account", so it
    // gets a moment and a second try.
    $ar = infra_reg_dynadot_call($cfg, 'set_renew_option',
              ['domain' => $domain, 'renew_option' => $autoRenew ? 'auto' : 'no']);
    if (!$ar['ok']) {
        sleep(3);
        $ar = infra_reg_dynadot_call($cfg, 'set_renew_option',
                  ['domain' => $domain, 'renew_option' => $autoRenew ? 'auto' : 'no']);
    }

    // REPORT WHAT IS TRUE, NOT WHAT WAS ATTEMPTED. The first purchase said
    // "auto-renew NOT SET" while the domain was sitting there on auto-renew —
    // the call had failed but the account default had already done it. Same
    // principle as plesk_delete_site(), which judges by whether the site is
    // actually gone rather than by the CLI's exit code.
    $actual = infra_reg_dynadot_renew_option($cfg, $domain);
    if ($actual !== null) {
        $isAuto = (stripos($actual, 'auto') !== false);
        $msg .= ', auto-renew ' . ($isAuto ? 'ON' : 'OFF') . ' (verified)';
        if ($isAuto !== $autoRenew) $msg .= ' — NOT what was asked for; change it in the dashboard';
    } else {
        $msg .= ', auto-renew ' . ($ar['ok'] ? 'set but UNVERIFIED' : 'COULD NOT BE SET (' . $ar['message'] . ') — check the dashboard');
    }
    return ['ok' => true, 'code' => $r['code'], 'message' => $msg];
}

/* ============================= Cloudflare Registrar ============================= */
/* Sells at cost, but the API only lists/updates — there is no register endpoint,
   and no availability endpoint. A Cloudflare-registered domain is always on
   Cloudflare nameservers, so go-live has nothing to switch. Verified live:
   GET /accounts/{id}/registrar/domains returns 200. */

/** Registrar-scoped Cloudflare call. Accepts a token OR the global-key pair. */
function infra_reg_cloudflare_call(array $cfg, string $method, string $path, array $query = [], ?array $body = null): array
{
    // Registrar endpoints reject a scoped token ("Authentication error") and need
    // the global key, so that is preferred whenever it is configured.
    $headers = (!empty($cfg['email']) && !empty($cfg['global_key']))
        ? ['X-Auth-Email: ' . $cfg['email'], 'X-Auth-Key: ' . $cfg['global_key'], 'Content-Type: application/json']
        : ['Authorization: Bearer ' . ($cfg['api_token'] ?? ''), 'Content-Type: application/json'];
    $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($cfg['account_id'] ?? '') . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $opts = ['headers' => $headers, 'verify' => true, 'timeout' => 90];
    if ($body !== null) $opts['body'] = $body;
    return infra_http($method, $url, $opts);
}

/**
 * ONE fleet-wide registrant contact, in a registrar-neutral shape.
 *
 * Read off a domain already held at Namecheap rather than stored separately —
 * there is then a single source of truth for who owns these domains, and no
 * second copy of personal details to drift or leak. Cached for a day.
 * @return array|null
 */
function infra_fleet_contact(): ?array
{
    $c = infra_reg_namecheap_contacts(infra_registrar_config('namecheap'));
    if (!$c) return null;
    return [
        'first'   => $c['FirstName'], 'last' => $c['LastName'],
        'name'    => trim($c['FirstName'] . ' ' . $c['LastName']),
        'org'     => $c['Organization'] ?? '',
        'street'  => $c['Address1'], 'city' => $c['City'], 'state' => $c['StateProvince'],
        'postal'  => $c['PostalCode'], 'country' => $c['Country'],
        'phone'   => $c['Phone'], 'email' => $c['EmailAddress'],
        '_source' => $c['_source'],
    ];
}

/**
 * Register a domain at Cloudflare Registrar — sold AT COST, so the cheapest of
 * the seven to hold long term.
 *
 * Three things had to be discovered by probing, because the obvious guesses are
 * all wrong:
 *   · the endpoint is POST /registrar/REGISTRATIONS. /registrar/domains answers
 *     403 and reads exactly like "registration is not supported".
 *   · it needs the GLOBAL KEY. A scoped API token gets "Authentication error".
 *   · it refuses without a registrant contact and the account had no default
 *     address-book entry, so one is passed inline. postal_info.name and
 *     address.street are plain STRINGS, and the field is country_code.
 *
 * Cloudflare defaults auto-renew to FALSE, so it is set explicitly at purchase.
 */
function infra_reg_cloudflare_register(string $domain, int $years, array $cfg, array $opts = []): array
{
    // $years is accepted for signature parity but Cloudflare registers 1yr terms
    // only; multi-year is not offered by its registration API.
    if (($cfg['account_id'] ?? '') === '') {
        return ['ok' => false, 'message' => 'Cloudflare: no account_id configured'];
    }
    $c = infra_fleet_contact();
    if (!$c) {
        return ['ok' => false, 'message' => 'Cloudflare: no registrant contact available — it will not register without one'];
    }
    $autoRenew = array_key_exists('auto_renew', $opts) ? (bool) $opts['auto_renew'] : true;

    $body = [
        'domain_name' => $domain,
        'auto_renew'  => $autoRenew,
        'contacts'    => ['registrant' => [
            'email' => $c['email'],
            'phone' => $c['phone'],
            'postal_info' => [
                'name'         => $c['name'],
                'organization' => $c['org'],
                'address'      => [
                    'street'       => $c['street'],
                    'city'         => $c['city'],
                    'state'        => $c['state'],
                    'postal_code'  => $c['postal'],
                    'country_code' => $c['country'],
                ],
            ],
        ]],
    ];
    $r = infra_reg_cloudflare_call($cfg, 'POST', '/registrar/registrations', [], $body);
    $j = $r['json'] ?? [];
    if (empty($j['success'])) {
        return ['ok' => false, 'message' => 'Cloudflare: ' . ($j['errors'][0]['message'] ?? ('HTTP ' . $r['code']))];
    }
    // 201 = done; 202 = accepted and still running, which must not be read as owned.
    $state = (string) ($j['result']['state'] ?? '');
    if ($state !== 'succeeded' && empty($j['result']['completed'])) {
        return ['ok' => false, 'message' => "Cloudflare: registration accepted but not confirmed (state '{$state}') — check the dashboard before treating it as owned"];
    }
    $msg = "Cloudflare: registered {$domain} at cost, contact from " . $c['_source'];

    // Read the setting back rather than trusting the request.
    $l = infra_reg_cloudflare_call($cfg, 'GET', '/registrar/domains');
    foreach (($l['json']['result'] ?? []) as $d) {
        if (strcasecmp((string) ($d['name'] ?? ''), $domain) === 0) {
            $msg .= ', auto-renew ' . (!empty($d['auto_renew']) ? 'ON' : 'OFF') . ' (verified)'
                  . ', expires ' . substr((string) ($d['expires_at'] ?? '?'), 0, 10);
            return ['ok' => true, 'message' => $msg];
        }
    }
    return ['ok' => true, 'message' => $msg . ', auto-renew UNVERIFIED'];
}

function infra_reg_cloudflare_verify(array $cfg): array
{
    if (($cfg['account_id'] ?? '') === '') {
        return ['ok' => false, 'message' => 'Account ID is required', 'balance' => null, 'currency' => ''];
    }
    $r  = infra_reg_cloudflare_call($cfg, 'GET', '/registrar/domains', ['per_page' => 5]);
    $ok = $r['code'] === 200 && !empty($r['json']['success']);
    if (!$ok) {
        return ['ok' => false, 'balance' => null, 'currency' => '',
                'message' => 'Cloudflare: ' . ($r['json']['errors'][0]['message'] ?? ('HTTP ' . $r['code']))];
    }
    $n = (int) ($r['json']['result_info']['total_count'] ?? count($r['json']['result'] ?? []));
    // Cloudflare bills a card rather than a prepaid balance, so there is none to report.
    return ['ok' => true, 'message' => "Cloudflare Registrar API OK — {$n} domain(s) held",
            'balance' => null, 'currency' => ''];
}

/**
 * Normalise a NameSilo availability branch to a flat list, whichever shape it
 * arrived in — the bare list (many results) or the {"domain": …} wrapper (one).
 */
function infra_reg_ns_results($branch): array
{
    if ($branch === null || $branch === '') return [];
    if (is_array($branch) && isset($branch['domain'])) $branch = $branch['domain'];
    return infra_reg_xml_list($branch);
}

/** Normalise "one result = object, many = array" API shapes into a list. */
function infra_reg_xml_list($v): array
{
    if ($v === null || $v === '') return [];
    if (is_string($v)) return [$v];
    if (is_array($v)) {
        // A single associative record (has string keys) vs a list of records.
        return array_keys($v) !== range(0, count($v) - 1) ? [$v] : $v;
    }
    return [];
}

/**
 * Domains already in one of OUR registrar accounts. Used to tell "taken by
 * someone else" apart from "you already own this" — Scott's other projects hold
 * hundreds of domains that would otherwise read as dead names.
 * @return array lowercase domain => registrar name
 */
function infra_registrar_owned_index(): array
{
    $idx = [];
    foreach (infra_registrar_names() as $name) {
        foreach (infra_registrar_list_owned($name) as $d) $idx[strtolower($d)] = $name;
    }
    return $idx;
}

/** Domains held at one registrar. Read-only; [] when unsupported/unconfigured. */
function infra_registrar_list_owned(string $name): array
{
    $cfg  = infra_registrar_config($name);
    $type = strtolower($cfg['type'] ?? $name);
    $out  = [];
    switch ($type) {
        case 'namesilo':
            // Same two-shape trap as the availability check: one domain nests under
            // "domain", several come back as a bare list. Reading only the wrapped
            // form made an account holding two domains report none — which is how a
            // purchase looked like it had not happened.
            $r = infra_reg_namesilo_call($cfg, 'listDomains');
            foreach (infra_reg_ns_results($r['reply']['domains'] ?? []) as $d) {
                $out[] = is_array($d) ? ($d['domain'] ?? '') : (string) $d;
            }
            break;
        case 'namecheap':
            for ($page = 1; $page <= 40; $page++) {
                $r = infra_reg_namecheap_call($cfg, 'namecheap.domains.getList', ['PageSize' => 100, 'Page' => $page]);
                if (!$r['ok']) break;
                $n = 0;
                foreach (($r['xml']->CommandResponse->DomainGetListResult->Domain ?? []) as $d) { $out[] = (string) $d['Name']; $n++; }
                $total = (int) ($r['xml']->CommandResponse->Paging->TotalItems ?? 0);
                if ($n === 0 || count($out) >= $total) break;
            }
            break;
        case 'cloudflare':
            // PAGINATED, like Namecheap above. A truncated holdings list does not
            // fail loudly — it makes the collision guard answer "someone else owns
            // this" about a domain you hold, which is the exact mistake the guard
            // exists to prevent, and it only starts happening once the fleet is big
            // enough that nobody is checking by hand any more.
            // PAGES ARE 0-INDEXED HERE — unlike Cloudflare's own zone API, which
            // starts at 1. Asking for page 1 on a 15-domain account returns an empty
            // result with total_pages:1, i.e. it looks exactly like "you own nothing"
            // rather than like an error. Verified against the live account.
            for ($page = 0; $page <= 50; $page++) {
                $r = infra_reg_cloudflare_call($cfg, 'GET', '/registrar/domains', ['per_page' => 200, 'page' => $page]);
                $batch = $r['json']['result'] ?? [];
                if (!is_array($batch) || !$batch) break;
                foreach ($batch as $d) if (!empty($d['name'])) $out[] = $d['name'];
                $totalPages = (int) ($r['json']['result_info']['total_pages'] ?? 0);
                if ($totalPages ? ($page + 1) >= $totalPages : count($batch) < 200) break;
            }
            break;
        case 'porkbun':
            // listAll returns at most 1000 rows and takes a `start` offset.
            for ($start = 0; $start <= 20000; $start += 1000) {
                $r = infra_reg_porkbun_call($cfg, '/domain/listAll', ['start' => $start]);
                $batch = $r['json']['domains'] ?? [];
                if (!is_array($batch) || !$batch) break;
                foreach ($batch as $d) if (!empty($d['domain'])) $out[] = $d['domain'];
                if (count($batch) < 1000) break;
            }
            break;
        case 'dynadot':
            $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query(
                ['key' => $cfg['api_key'] ?? '', 'command' => 'list_domain']), ['verify' => true, 'timeout' => 40]);
            $list = $r['json']['ListDomainInfoResponse']['MainDomains'] ?? $r['json']['MainDomains'] ?? [];
            foreach ($list as $d) if (!empty($d['Name'])) $out[] = $d['Name'];
            break;
    }
    return array_values(array_filter(array_map('strval', $out)));
}

/**
 * Register (buy) a domain at the given registrar. Costs real money.
 * @return array{ok:bool, message:string}
 */
function infra_registrar_register(string $domain, int $years, string $registrarName, array $ns = [], array $opts = []): array
{
    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);
    $cfg['__name'] = $registrarName;   // the contact cache writes back under this key
    switch ($type) {
        case 'gandi':
            $r = infra_reg_gandi_register($domain, $years, $cfg, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        case 'spaceship':
            $r = infra_reg_spaceship_register($domain, $years, $cfg, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        case 'namesilo':
            $r = infra_reg_namesilo_register($domain, $years, $cfg, $ns, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        case 'dynadot':
            $r = infra_reg_dynadot_register($domain, $years, $cfg, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        case 'porkbun':
            $r = infra_reg_porkbun_register($domain, $years, $cfg, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        case 'namecheap':
            $r = infra_reg_namecheap_register($domain, $years, $cfg, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        case 'cloudflare':
            $r = infra_reg_cloudflare_register($domain, $years, $cfg, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        default:
            return ['ok' => false, 'message' => "auto-registration not wired for '{$registrarName}' — register manually"];
    }
}

/**
 * Point a domain's nameservers at Cloudflare.
 * @return array{ok:bool, manual:bool, message:string}
 */
function infra_registrar_set_ns(string $domain, array $ns, string $registrarName): array
{
    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);

    switch ($type) {
        case 'namesilo':  return infra_reg_namesilo_set_ns($domain, $ns, $cfg);
        case 'porkbun':   return infra_reg_porkbun_set_ns($domain, $ns, $cfg);
        case 'gandi':     return infra_reg_gandi_set_ns($domain, $ns, $cfg);
        case 'spaceship': return infra_reg_spaceship_set_ns($domain, $ns, $cfg);
        case 'dynadot':   return infra_reg_dynadot_set_ns($domain, $ns, $cfg);
        case 'namecheap': return infra_reg_namecheap_set_ns($domain, $ns, $cfg);
        case 'cloudflare':
            // A Cloudflare-registered domain is always on Cloudflare nameservers.
            // There is nothing to switch, so go-live is a no-op rather than a failure.
            return ['ok' => true, 'manual' => false,
                    'message' => 'Cloudflare Registrar: already on Cloudflare nameservers — no switch needed'];
        default:
            return [
                'ok'      => false,
                'manual'  => true,
                'message' => 'manual — set nameservers at the registrar: ' . implode(', ', $ns),
            ];
    }
}

/* ============================= NameSilo ============================= */

/**
 * Low-level NameSilo API call. All ops are GET; success = reply.code "300".
 * @return array{ok:bool, code:string, detail:string, reply:array}
 */
function infra_reg_namesilo_call(array $cfg, string $op, array $params = []): array
{
    $key = $cfg['api_key'] ?? '';
    $q   = array_merge(['version' => 1, 'type' => 'json', 'key' => $key], $params);
    $url = 'https://www.namesilo.com/api/' . $op . '?' . http_build_query($q);
    $r   = infra_http('GET', $url, ['verify' => true, 'timeout' => 30]);

    $reply = $r['json']['reply'] ?? [];
    $code  = (string) ($reply['code'] ?? '');
    return [
        'ok'     => $code === '300',
        'code'   => $code,
        'detail' => $reply['detail'] ?? ($r['error'] ?: ('HTTP ' . $r['code'])),
        'reply'  => is_array($reply) ? $reply : [],
    ];
}


/** Set a domain's nameservers (the go-live switch). NameSilo needs ≥2 NS. */
function infra_reg_namesilo_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    if (count($ns) < 2) {
        return ['ok' => false, 'manual' => false, 'message' => 'NameSilo requires at least 2 nameservers'];
    }
    $params = ['domain' => $domain];
    foreach (array_slice($ns, 0, 13) as $i => $n) $params['ns' . ($i + 1)] = $n;

    $r = infra_reg_namesilo_call($cfg, 'changeNameServers', $params);
    return [
        'ok'      => $r['ok'],
        'manual'  => false,
        'message' => $r['ok']
            ? 'NameSilo: nameservers set → ' . implode(', ', $ns)
            : "NameSilo error {$r['code']}: {$r['detail']}",
    ];
}

/**
 * Register a domain (free WHOIS privacy on). Optional NS at purchase.
 *
 * AUTO-RENEW DEFAULTS ON. A lapsed domain in a rank-and-rent fleet is not a small
 * problem — the site dies and the ranking goes with it, and the name may not come
 * back. Off is available per-purchase, but it must be chosen deliberately.
 */
function infra_reg_namesilo_register(string $domain, int $years, array $cfg, array $ns = [], array $opts = []): array
{
    $autoRenew = array_key_exists('auto_renew', $opts) ? (int) (bool) $opts['auto_renew'] : 1;
    $params = ['domain' => $domain, 'years' => max(1, $years), 'private' => 1, 'auto_renew' => $autoRenew];
    foreach (array_slice(array_values(array_filter($ns)), 0, 13) as $i => $n) $params['ns' . ($i + 1)] = $n;

    $r = infra_reg_namesilo_call($cfg, 'registerDomain', $params);
    return ['ok' => $r['ok'], 'code' => $r['code'],
            'message' => $r['ok']
                ? "NameSilo: registered {$domain} for {$params['years']}yr, auto-renew "
                  . ($autoRenew ? 'ON' : 'OFF') . ', WHOIS privacy on'
                : "NameSilo error {$r['code']}: {$r['detail']}"];
}


/* ============================= Porkbun ============================= */
/* NOTE: Porkbun requires "API Access" toggled ON per-domain in the dashboard. */

/** Low-level Porkbun call; success = json.status "SUCCESS". @return array{ok:bool,message:string,json:mixed} */
function infra_reg_porkbun_call(array $cfg, string $path, array $body = []): array
{
    $b = array_merge(['apikey' => $cfg['api_key'] ?? '', 'secretapikey' => $cfg['secret_api_key'] ?? ''], $body);
    $r = infra_http('POST', 'https://api.porkbun.com/api/json/v3' . $path, ['verify' => true, 'timeout' => 30, 'body' => $b]);
    $status = $r['json']['status'] ?? '';
    return ['ok' => $status === 'SUCCESS', 'message' => $r['json']['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code'])), 'json' => $r['json']];
}


/** Set a domain's nameservers (the go-live switch). */
function infra_reg_porkbun_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    if (count($ns) < 2) {
        return ['ok' => false, 'manual' => false, 'message' => 'Porkbun requires at least 2 nameservers'];
    }
    $r = infra_reg_porkbun_call($cfg, '/domain/updateNs/' . $domain, ['ns' => $ns]);
    return [
        'ok'      => $r['ok'],
        'manual'  => false,
        'message' => $r['ok']
            ? 'Porkbun: nameservers set → ' . implode(', ', $ns)
            : ('Porkbun error: ' . $r['message'] . ' (is "API Access" enabled for this domain?)'),
    ];
}

/**
 * One Porkbun availability check that survives the 1-per-10s limit by waiting the
 * server's own ttlRemaining and retrying. Shared, because the buy path quotes a
 * price immediately after the pre-purchase availability check — two requests in
 * the same window, which refused a real purchase until this existed.
 * @return array{ok:bool, message:string, response:array}
 */
function infra_reg_porkbun_check_one(array $cfg, string $domain, int $retries = 2): array
{
    for ($i = 0; $i <= $retries; $i++) {
        $r = infra_reg_porkbun_call($cfg, '/domain/checkDomain/' . $domain);
        if ($r['ok']) return ['ok' => true, 'message' => '', 'response' => $r['json']['response'] ?? []];
        if (strtoupper((string) ($r['json']['code'] ?? '')) !== 'RATE_LIMIT_EXCEEDED') {
            return ['ok' => false, 'message' => $r['message'], 'response' => []];
        }
        sleep(max(1, min(30, (int) ($r['json']['ttlRemaining'] ?? 10) + 1)));
    }
    return ['ok' => false, 'message' => 'rate limited after ' . $retries . ' retries', 'response' => []];
}

/**
 * Register a domain at Porkbun.
 *
 * Two things make this different from the other adapters:
 *
 * 1. AGREEMENT. Porkbun refuses the order without agreeToTerms=yes, which accepts
 *    their Registration Agreement, Terms of Service, Privacy Policy AND automatic
 *    renewal terms on the account holder's behalf. That is a legal acceptance, not
 *    a formality — it is passed only because the operator asked for this purchase.
 *
 * 2. COST IS THE EXACT PRICE IN CENTS. Two error messages pin this down between
 *    them: 11.08 is rejected as "must be a valid integer", while 11 and 12 are
 *    rejected as "must equal the cost of the domain for its minimum allowed
 *    duration". Both are only satisfiable by whole cents — 1108.
 *    The price is quoted live and converted, never assumed, so an unexpected
 *    price cannot be silently paid: a changed price simply fails the equality.
 */
function infra_reg_porkbun_register(string $domain, int $years, array $cfg, array $opts = []): array
{
    $years = max(1, min(10, $years));

    // Quote it live — the cost confirmation has to be based on the real number.
    // Rate-limit aware: the buy path has just spent this window's one check.
    $chk   = infra_reg_porkbun_check_one($cfg, $domain);
    $price = (float) ($chk['response']['price'] ?? 0);
    if (!$chk['ok'] || $price <= 0) {
        return ['ok' => false, 'message' => 'Porkbun: could not quote a price before buying (' . $chk['message'] . ') — not guessing at cost'];
    }
    if (strtolower((string) ($chk['response']['avail'] ?? '')) !== 'yes') {
        return ['ok' => false, 'message' => 'Porkbun: no longer available'];
    }

    $autoRenew = array_key_exists('auto_renew', $opts) ? (bool) $opts['auto_renew'] : true;
    // Cents first (the reading both error messages support); the bare figure as a
    // fallback in case a TLD is priced in whole units.
    $attempts  = array_values(array_unique([(int) round($price * 100), (int) round($price)]));

    $last = '';
    foreach ($attempts as $cost) {
        $r = infra_reg_porkbun_call($cfg, '/domain/create/' . $domain, [
            'cost'         => $cost,
            'years'        => $years,
            'agreeToTerms' => 'yes',
            'autoRenew'    => $autoRenew ? 'yes' : 'no',
        ]);
        if ($r['ok']) {
            return ['ok' => true, 'message' => "Porkbun: registered {$domain} for {$years}yr"
                . ' at the quoted $' . number_format($price, 2) . " (cost confirmation {$cost})"];
        }
        $last = $r['message'];
        // Only retry when the COST itself was the objection. Anything else — taken,
        // funds, TLD — must not be retried with a different number.
        if (stripos($last, 'cost') === false && stripos($last, 'price') === false) break;
        sleep(2);
    }
    return ['ok' => false, 'message' => 'Porkbun: ' . $last];
}

/* ============================= Spaceship =============================
 *
 * Two headers, no IP allowlist, no signing. Base https://spaceship.dev/api/v1.
 *
 * It is Namecheap-owned, which is why it was left out originally: another brand
 * under one parent adds cost without adding independence. It earns a place on one
 * point — its API CAN set auto-renew, which is exactly what Namecheap's cannot, and
 * what leaves every Namecheap domain needing a manual dashboard visit or it lapses.
 *
 * No balance endpoint (/billing/balance, /account/balance and /balance all 404), so
 * like Porkbun the funds cannot be confirmed before a purchase.
 */

function infra_reg_spaceship_call(array $cfg, string $method, string $path, ?array $body = null): array
{
    $opts = [
        'verify' => true, 'timeout' => 30,
        'headers' => [
            'X-Api-Key: '    . ($cfg['api_key'] ?? ''),
            'X-Api-Secret: ' . ($cfg['api_secret'] ?? ''),
            'Content-Type: application/json',
        ],
    ];
    if ($body !== null) $opts['body'] = $body;
    $r  = infra_http($method, 'https://spaceship.dev/api/v1' . $path, $opts);
    $ok = $r['code'] >= 200 && $r['code'] < 300;
    // Errors are {detail: "..."}, sometimes with data[] naming the offending field —
    // which is the half that says what to fix, so it is appended rather than dropped.
    $msg = $r['json']['detail'] ?? $r['json']['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code']));
    foreach ((array) ($r['json']['data'] ?? []) as $d) {
        if (is_array($d) && ($d['details'] ?? '') !== '') $msg .= ' — ' . $d['details'];
    }
    return ['ok' => $ok, 'code' => $r['code'], 'message' => $msg, 'json' => $r['json'] ?? []];
}

/** Read-only credential test; also reports how many domains the account holds. */
function infra_reg_spaceship_verify(array $cfg): array
{
    $r = infra_reg_spaceship_call($cfg, 'GET', '/domains?take=1&skip=0');
    if (!$r['ok']) return ['ok' => false, 'message' => 'Spaceship: ' . $r['message'], 'balance' => null, 'currency' => ''];
    $n = (int) ($r['json']['total'] ?? 0);
    // balance stays NULL, not 0: there is no endpoint for it, and a zero would read
    // as "no funds" and hold back a buy that would have gone through.
    return ['ok' => true, 'message' => 'Spaceship API OK — ' . $n . ' domain(s) on the account',
            'balance' => null, 'currency' => ''];
}

/** Availability — one request for the whole list; the endpoint takes an array. */
function infra_reg_spaceship_check(array $domains, array $cfg, array $out): array
{
    foreach (array_chunk($domains, 50) as $chunk) {
        $r = infra_reg_spaceship_call($cfg, 'POST', '/domains/available', ['domains' => array_values($chunk)]);
        if (!$r['ok']) {
            foreach ($chunk as $d) $out[$d]['note'] = 'check failed: ' . $r['message'];
            continue;
        }
        foreach ((array) ($r['json']['domains'] ?? []) as $row) {
            $name = strtolower((string) ($row['domain'] ?? ''));
            if (!isset($out[$name])) continue;
            $res = strtolower((string) ($row['result'] ?? ''));
            $out[$name]['available'] = $res === 'available';
            if ($res !== 'available') $out[$name]['note'] = $res ?: 'taken';
            // Standard names carry no price here — only premiums do — so price is left
            // blank rather than implying a quote that was never given.
            if (!empty($row['premiumPricing'])) {
                $out[$name]['note']  = 'premium';
                $p = $row['premiumPricing'][0]['registration'] ?? null;
                if ($p !== null) $out[$name]['price'] = (string) $p;
            }
        }
    }
    return $out;
}

/**
 * The registrant contact id, created from config the first time it is needed.
 *
 * Registration takes contact IDs, not inline details, so a fresh account cannot buy
 * anything until a contact exists. Rather than make that a manual step outside the
 * panel, it is created from this registrar's own config fields once and the id
 * written back — so every later purchase costs no extra call and the account does
 * not accumulate identical contact records.
 *
 * @return array{ok:bool,id:string,message:string}
 */
function infra_reg_spaceship_contact_id(array $cfg, string $name): array
{
    $stored = trim((string) ($cfg['contact_id'] ?? ''));
    if ($stored !== '') return ['ok' => true, 'id' => $stored, 'message' => ''];

    $need    = ['contact_first', 'contact_last', 'contact_email', 'contact_address', 'contact_city', 'contact_country', 'contact_phone'];
    $missing = array_values(array_filter($need, fn($k) => trim((string) ($cfg[$k] ?? '')) === ''));
    if ($missing) {
        return ['ok' => false, 'id' => '', 'message' => 'Spaceship needs registrant contact details before it can buy — fill in: '
            . implode(', ', array_map(fn($k) => str_replace('contact_', '', $k), $missing))];
    }

    $r = infra_reg_spaceship_call($cfg, 'PUT', '/contacts', [
        'firstName'     => $cfg['contact_first'],   'lastName'   => $cfg['contact_last'],
        'email'         => $cfg['contact_email'],   'address1'   => $cfg['contact_address'],
        'city'          => $cfg['contact_city'],    'country'    => strtoupper((string) $cfg['contact_country']),
        'phone'         => $cfg['contact_phone'],
        'stateProvince' => (string) ($cfg['contact_state'] ?? ''),
        'postalCode'    => (string) ($cfg['contact_zip'] ?? ''),
    ]);
    if (!$r['ok']) return ['ok' => false, 'id' => '', 'message' => 'Spaceship contact: ' . $r['message']];

    $id = (string) ($r['json']['contactId'] ?? '');
    if ($id === '') return ['ok' => false, 'id' => '', 'message' => 'Spaceship created a contact but returned no id'];

    $path = infra_config_path('registrar.json');
    $all  = infra_load_json($path, []);
    if (isset($all['registrars'][$name])) {
        $all['registrars'][$name]['contact_id'] = $id;
        infra_save_json($path, $all);
    }
    return ['ok' => true, 'id' => $id, 'message' => 'created contact ' . $id];
}

/**
 * Buy one domain.
 *
 * ⚠ Registration answers 202 ACCEPTED with an async operation id — the domain is not
 * registered when this returns. Reading a 202 as failure is a mistake already made
 * once here with Cloudflare's registrar API, and the cost of it is buying twice, so
 * a 202 is reported as accepted-and-pending rather than as an error.
 */
function infra_reg_spaceship_register(string $domain, int $years, array $cfg, array $opts = []): array
{
    $c = infra_reg_spaceship_contact_id($cfg, strtolower((string) ($cfg['__name'] ?? 'spaceship')));
    if (!$c['ok']) return ['ok' => false, 'message' => $c['message']];

    $r = infra_reg_spaceship_call($cfg, 'POST', '/domains/' . rawurlencode($domain), [
        'autoRenew' => !empty($opts['auto_renew']),
        'years'     => max(1, min(10, $years)),
        // level "high" is WHOIS privacy on; userConsent is required and asserts the
        // account holder accepted the registration terms.
        'privacyProtection' => ['level' => 'high', 'userConsent' => true],
        'contacts'  => ['registrant' => $c['id'], 'admin' => $c['id'], 'tech' => $c['id'], 'billing' => $c['id']],
    ]);

    if ($r['ok'] || $r['code'] === 202) {
        $op = (string) ($r['json']['operationId'] ?? $r['json']['id'] ?? '');
        return ['ok' => true, 'message' => 'Spaceship: registration accepted'
            . ($op !== '' ? ' (operation ' . $op . ')' : '')
            . ' — it completes asynchronously'
            . (!empty($opts['auto_renew']) ? ', auto-renew ON' : '')];
    }
    return ['ok' => false, 'message' => 'Spaceship error: ' . $r['message']];
}

/** Nameservers. provider must be "custom" or the hosts list is ignored. */
function infra_reg_spaceship_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    if (count($ns) < 2) return ['ok' => false, 'manual' => false, 'message' => 'Spaceship requires at least 2 nameservers'];

    $r = infra_reg_spaceship_call($cfg, 'PUT', '/domains/' . rawurlencode($domain) . '/nameservers',
        ['provider' => 'custom', 'hosts' => $ns]);
    return ['ok' => $r['ok'], 'manual' => false,
        'message' => $r['ok'] ? 'Spaceship: nameservers set → ' . implode(', ', $ns)
                              : 'Spaceship error: ' . $r['message']];
}

/** Auto-renew on/off — the capability this registrar is here for. */
function infra_reg_spaceship_set_autorenew(string $domain, bool $on, array $cfg): array
{
    $r = infra_reg_spaceship_call($cfg, 'PUT', '/domains/' . rawurlencode($domain) . '/autorenew', ['isEnabled' => $on]);
    return ['ok' => $r['ok'], 'message' => $r['ok']
        ? 'Spaceship: auto-renew ' . ($on ? 'ON' : 'OFF')
        : 'Spaceship error: ' . $r['message']];
}

/* ============================= Dynadot ============================= */
/* NOTE: Dynadot may require external nameservers be added to the account first. */

function infra_reg_dynadot_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    $q  = ['key' => $cfg['api_key'] ?? '', 'command' => 'set_ns', 'domain' => $domain];
    foreach (array_slice($ns, 0, 13) as $i => $n) $q['ns' . $i] = $n;   // ns0..ns12
    $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query($q), ['verify' => true, 'timeout' => 30]);

    $j    = $r['json'] ?? [];
    $resp = $j['SetNsResponse'] ?? $j['Response'] ?? $j;
    $sc   = $resp['ResponseCode'] ?? $resp['SetNsHeader']['SuccessCode'] ?? null;
    $stat = strtolower((string) ($resp['Status'] ?? $resp['SetNsHeader']['Status'] ?? ''));
    $ok   = ($r['code'] >= 200 && $r['code'] < 300) && ($sc === 0 || $sc === '0' || $stat === 'success');
    $err  = $resp['Error'] ?? $resp['SetNsHeader']['Error'] ?? substr($r['raw'], 0, 120);
    return ['ok' => $ok, 'manual' => false,
        'message' => $ok ? 'Dynadot: nameservers set → ' . implode(', ', $ns) : "Dynadot error: {$err}"];
}



/* ============================= Namecheap ============================= */
/* Requires: funded account, API access enabled, and ClientIp whitelisted. */

function infra_reg_namecheap_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns    = array_values(array_filter(array_map('trim', $ns)));
    $parts = explode('.', $domain, 2);
    $sld   = $parts[0];
    $tld   = $parts[1] ?? '';
    $q = [
        'ApiUser'     => $cfg['api_user'] ?? '',
        'ApiKey'      => $cfg['api_key'] ?? '',
        'UserName'    => $cfg['username'] ?? ($cfg['api_user'] ?? ''),
        'ClientIp'    => $cfg['client_ip'] ?? '',
        'Command'     => 'namecheap.domains.dns.setCustom',
        'SLD'         => $sld,
        'TLD'         => $tld,
        'NameServers' => implode(',', $ns),
    ];
    $r = infra_http('GET', 'https://api.namecheap.com/xml.response?' . http_build_query($q), ['verify' => true, 'timeout' => 30]);

    $ok = false; $msg = 'unparseable response';
    $xml = @simplexml_load_string($r['raw']);
    if ($xml !== false) {
        if ((string) $xml['Status'] === 'OK') { $ok = true; $msg = ''; }
        else { $msg = (string) ($xml->Errors->Error ?? 'error'); }
    }
    return ['ok' => $ok, 'manual' => false,
        'message' => $ok ? 'Namecheap: nameservers set → ' . implode(', ', $ns) : "Namecheap error: {$msg}"];
}

/* ============================= Gandi =============================
 *
 * One bearer token, no IP allowlist, no signing. Base https://api.gandi.net/v5.
 *
 * Two things it does better than most here: availability returns a real PRICE, and
 * the owner contact can be read off a domain the account already holds rather than
 * retyped — the same trick the Namecheap adapter uses, and the reason a purchase
 * needs no contact configuration.
 *
 * ⚠ SCOPES. A Gandi personal access token carries an explicit scope list, and the
 * one in use grants organization:view, payment:prepaid, payment:deferred,
 * domain:view, domain:tech and domain:contactadmin. Whether that is enough to BUY
 * cannot be settled by reading — Gandi has no "can I create" probe — so verify()
 * reports the scopes and says so rather than implying a purchase will clear.
 */

function infra_reg_gandi_call(array $cfg, string $method, string $path, ?array $body = null, string $base = 'https://api.gandi.net'): array
{
    $opts = [
        'verify' => true, 'timeout' => 30,
        'headers' => ['Authorization: Bearer ' . ($cfg['token'] ?? ''), 'Content-Type: application/json'],
    ];
    if ($body !== null) $opts['body'] = $body;
    $r  = infra_http($method, $base . $path, $opts);
    $ok = $r['code'] >= 200 && $r['code'] < 300;
    // Errors come back as {message} or {cause,message} — and on a bad body, an
    // "errors" array naming the field, which is the part worth reading.
    $msg = $r['json']['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code']));
    foreach ((array) ($r['json']['errors'] ?? []) as $e) {
        if (is_array($e)) $msg .= ' — ' . trim(($e['name'] ?? '') . ' ' . ($e['description'] ?? ''));
    }
    return ['ok' => $ok, 'code' => $r['code'], 'message' => $msg, 'json' => $r['json'] ?? []];
}

/** Read-only credential test: who the token belongs to, and what it may do. */
function infra_reg_gandi_verify(array $cfg): array
{
    $t = infra_reg_gandi_call($cfg, 'GET', '/tokeninfo', null, 'https://id.gandi.net');
    if (!$t['ok']) return ['ok' => false, 'message' => 'Gandi: ' . $t['message'], 'balance' => null, 'currency' => ''];

    $scopes = (array) ($t['json']['scope'] ?? []);
    $org    = infra_reg_gandi_call($cfg, 'GET', '/v5/organization/organizations');
    $name   = $org['ok'] ? (string) ($org['json'][0]['name'] ?? '') : '';
    $days   = (int) floor(((int) ($t['json']['expires_in'] ?? 0)) / 86400);

    // No balance endpoint for a personal account, so balance stays null rather than
    // being reported as zero — a zero would read as "no funds" and stop a good buy.
    $msg = 'Gandi API OK' . ($name !== '' ? ' — ' . $name : '')
         . ($days > 0 ? ', token expires in ' . $days . ' day(s)' : '')
         . '. Scopes: ' . (implode(', ', $scopes) ?: 'none');
    if (!in_array('payment:prepaid', $scopes, true) && !in_array('payment:deferred', $scopes, true)) {
        $msg .= ' ⚠ no payment scope — this token can read but probably cannot buy.';
    }
    return ['ok' => true, 'message' => $msg, 'balance' => null, 'currency' => ''];
}

/** Availability, one name per request — and it quotes a price, which most here do not. */
function infra_reg_gandi_check(array $domains, array $cfg, array $out): array
{
    foreach ($domains as $d) {
        $r = infra_reg_gandi_call($cfg, 'GET', '/v5/domain/check?' . http_build_query(['name' => $d, 'processes' => 'create']));
        if (!$r['ok']) { $out[$d]['note'] = 'check failed: ' . $r['message']; continue; }

        $products = (array) ($r['json']['products'] ?? []);
        if (!$products) { $out[$d]['note'] = 'no answer for this name'; continue; }
        $p      = $products[0];
        $status = strtolower((string) ($p['status'] ?? ''));
        $out[$d]['available'] = $status === 'available';
        if ($status !== 'available') $out[$d]['note'] = $status ?: 'taken';

        // Cheapest 1-year price, after taxes, in the currency the grid quotes.
        foreach ((array) ($p['prices'] ?? []) as $pr) {
            if ((int) ($pr['min_duration'] ?? 0) <= 1) {
                $out[$d]['price'] = (string) ($pr['price_after_taxes'] ?? $pr['price_before_taxes'] ?? '');
                break;
            }
        }
    }
    return $out;
}

/**
 * The owner contact for a purchase.
 *
 * Prefers config, then falls back to copying it off a domain the account already
 * holds — Gandi returns full contact blocks per domain, so an account that has ever
 * bought anything already has a valid registrant on file. That means no contact
 * setup before the first buy, and no second copy of the same details to keep in step.
 *
 * @return array{ok:bool,owner:array,message:string}
 */
function infra_reg_gandi_owner(array $cfg): array
{
    $keys = ['given' => 'contact_first', 'family' => 'contact_last', 'email' => 'contact_email',
             'streetaddr' => 'contact_address', 'city' => 'contact_city', 'country' => 'contact_country',
             'phone' => 'contact_phone', 'zip' => 'contact_zip'];
    $owner = [];
    foreach ($keys as $api => $cfgKey) {
        $v = trim((string) ($cfg[$cfgKey] ?? ''));
        if ($v !== '') $owner[$api] = $v;
    }
    if (isset($owner['given'], $owner['family'], $owner['email'], $owner['streetaddr'], $owner['country'])) {
        $owner['type'] = (int) ($cfg['contact_type'] ?? 0);   // 0 = individual
        return ['ok' => true, 'owner' => $owner, 'message' => 'contact from config'];
    }

    $list = infra_reg_gandi_call($cfg, 'GET', '/v5/domain/domains?per_page=1');
    $fqdn = (string) ($list['json'][0]['fqdn'] ?? '');
    if ($fqdn === '') {
        return ['ok' => false, 'owner' => [], 'message' =>
            'Gandi needs a registrant contact: either fill the contact fields here, or buy one domain '
          . 'in the Gandi dashboard first — after that the console copies the contact from it.'];
    }
    $c = infra_reg_gandi_call($cfg, 'GET', '/v5/domain/domains/' . rawurlencode($fqdn) . '/contacts');
    $o = (array) ($c['json']['owner'] ?? []);
    if (!$o) return ['ok' => false, 'owner' => [], 'message' => 'Gandi: could not read a contact from ' . $fqdn];

    // Only the fields a create accepts — the read includes extras that make it 400.
    $keep = ['given','family','email','streetaddr','city','country','phone','zip','state','type','orgname'];
    $owner = array_intersect_key($o, array_flip($keep));
    return ['ok' => true, 'owner' => $owner, 'message' => 'contact copied from ' . $fqdn];
}

/**
 * Buy one domain.
 *
 * Gandi answers 202 with an operation record — like Spaceship and Cloudflare, the
 * domain is not registered the moment this returns, so a 202 is success-and-pending,
 * never an error. Treating it as failure is how a domain gets bought twice.
 */
function infra_reg_gandi_register(string $domain, int $years, array $cfg, array $opts = []): array
{
    $o = infra_reg_gandi_owner($cfg);
    if (!$o['ok']) return ['ok' => false, 'message' => $o['message']];

    $body = [
        'fqdn'     => $domain,
        'duration' => max(1, min(10, $years)),
        'owner'    => $o['owner'],
    ];
    // Gandi's create takes no auto-renew flag; it is a separate PATCH afterwards.
    $r = infra_reg_gandi_call($cfg, 'POST', '/v5/domain/domains', $body);
    if (!$r['ok'] && $r['code'] !== 202) return ['ok' => false, 'message' => 'Gandi error: ' . $r['message']];

    $msg = 'Gandi: registration accepted (' . $o['message'] . ') — it completes asynchronously';
    if (!empty($opts['auto_renew'])) {
        $a = infra_reg_gandi_set_autorenew($domain, true, $cfg);
        $msg .= $a['ok'] ? ', auto-renew ON'
                         : ' ⚠ auto-renew NOT set (' . $a['message'] . ') — set it before the term lapses';
    }
    return ['ok' => true, 'message' => $msg];
}

/** Nameservers. */
function infra_reg_gandi_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    if (count($ns) < 2) return ['ok' => false, 'manual' => false, 'message' => 'Gandi requires at least 2 nameservers'];

    $r = infra_reg_gandi_call($cfg, 'PUT', '/v5/domain/domains/' . rawurlencode($domain) . '/nameservers',
        ['nameservers' => $ns]);
    return ['ok' => $r['ok'], 'manual' => false,
        'message' => $r['ok'] ? 'Gandi: nameservers set → ' . implode(', ', $ns)
                              : 'Gandi error: ' . $r['message']];
}

/** Auto-renew on/off. PATCH only — a GET on this sub-resource 404s by design. */
function infra_reg_gandi_set_autorenew(string $domain, bool $on, array $cfg): array
{
    $r = infra_reg_gandi_call($cfg, 'PATCH', '/v5/domain/domains/' . rawurlencode($domain) . '/autorenew',
        ['enabled' => $on, 'duration' => 1]);
    return ['ok' => $r['ok'], 'message' => $r['ok']
        ? 'Gandi: auto-renew ' . ($on ? 'ON' : 'OFF')
        : 'Gandi error: ' . $r['message']];
}

