<?php
/**
 * infra/actions/domain_buy.php — buy ONE domain. THE ONLY PURPOSE OF THIS FILE.
 *
 * Exists as its own endpoint so a Buy button can sit inside the Domains table.
 * That table is one large form posting to domains_bulk.php, and HTML forbids
 * nesting another form inside it — so the button uses `formaction` to redirect
 * itself here, carrying the domain as its own name/value pair.
 *
 * Because the whole surrounding form comes along for the ride, this deliberately
 * IGNORES every other field: the ticked rows, the registrar selects, the date
 * inputs and the niche dropdowns are all discarded. Pressing Buy on one row must
 * never quietly save edits to a hundred others.
 *
 * Having one file that can only do one thing also means there is no `action`
 * parameter to get wrong — a request that reaches here can only be a purchase.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/acquire.php';

$from = (string) ($_POST['from'] ?? 'view=domains');
// \z rather than $ — PCRE's $ also matches before a trailing newline.
if (!preg_match('/^view=[a-z]+[A-Za-z0-9=&_.\-%]*\z/', $from)) $from = 'view=domains';
$back = '../index.php?' . $from;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}

// The session has been read (auth + CSRF). Let go of its lock so a slow job
// here does not queue up every other click in the console — see
// infra_session_release() in bootstrap.php.
infra_session_release();

$domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
if ($domain === '') {
    infra_set_flash('err', 'No domain given.');
    header('Location: ' . $back); exit;
}

// ONE YEAR EVERYWHERE, Namecheap included. It used to buy a 3-year term there
// because Namecheap's auto-renew cannot be set over its API, and a longer term is
// the only protection that does not depend on someone visiting a dashboard — but
// across a fleet this size that ties up two extra years of cost per domain up
// front. The longer term never removed the lapse risk anyway, it deferred it, so
// the risk is REPORTED on the purchase instead (see infra_reg_namecheap_register,
// which reads the real auto-renew state back and says so).
//
// The years field on the New Site / Bulk forms still lets a longer term be chosen
// deliberately for a domain worth protecting that way.
$years = 1;

$r = infra_domain_buy($domain, ['years' => $years, 'auto_renew' => true]);
infra_set_flash($r['ok'] ? 'ok' : 'err',
    ($r['ok'] ? "✓ BOUGHT {$domain} — " : "✗ Purchase refused for {$domain}: ") . $r['message']);

infra_cache_forget('reg_owned');   // it is ours now; the owned index is stale
header('Location: ' . $back);
