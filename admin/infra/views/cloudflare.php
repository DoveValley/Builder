<?php

    /** Add and edit are the same fields, so the same function renders both. */
    function infra_cf_form(?array $a): void {
        $isEdit = $a !== null;
        $v = fn(string $k, string $d = '') => ih((string) ($a[$k] ?? $d));
        ?>
        <form method="post" action="actions/cf_save.php">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $v('id') ?>"><?php endif; ?>
            <table>
                <tbody>
                <tr>
                    <td style="width:250px"><strong>Name</strong><br>
                        <span style="color:#6b7280;font-size:12px">Anything that helps you tell accounts apart</span></td>
                    <td><input name="label" value="<?= $v('label') ?>" placeholder="e.g. CF #2" style="width:100%;max-width:420px" required></td>
                </tr>
                <tr>
                    <td><strong>Account ID</strong><br>
                        <span style="color:#6b7280;font-size:12px">Cloudflare dashboard &rarr; any domain &rarr; right-hand sidebar</span></td>
                    <td><input name="account_id" value="<?= $v('account_id') ?>" style="width:100%;max-width:420px" required></td>
                </tr>
                <tr>
                    <td><strong>API token</strong><br>
                        <span style="color:#6b7280;font-size:12px">My Profile &rarr; API Tokens &rarr; Create Token</span></td>
                    <td><input name="api_token" type="password" autocomplete="new-password"
                               placeholder="<?= $isEdit ? 'leave blank to keep the current token' : 'paste the token' ?>"
                               style="width:100%;max-width:420px" <?= $isEdit ? '' : 'required' ?>>
                        <?php if ($isEdit): ?><br><span style="color:#9ca3af;font-size:12px">A token is stored. It is never shown here.</span><?php endif; ?></td>
                </tr>
                </tbody>
            </table>
            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Add this account' ?></button>
                <button class="btn sec" type="submit" name="action" value="test">Test without saving</button>
                <?php if ($isEdit): ?><a class="btn sec" href="index.php?view=cloudflare">Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php
    }

    $editId   = (string) ($_GET['edit'] ?? '');
    $accounts = infra_cf_accounts();

    // Looking inside the zones is on demand: one API call each, so 400 zones is 400
    // calls. Same rule as checking whether websites answer.
    $scanId = (string) ($_GET['scan'] ?? '');
    if ($scanId !== '') {
        $n = 0;
        foreach ($accounts as $a) {
            if (($a['id'] ?? '') !== $scanId) continue;
            foreach (infra_discover_cf_zones($a) as $z) {
                if (!empty($z['id'])) { infra_zone_contents_run($a, $z['id']); $n++; }
            }
        }
        infra_set_flash('ok', 'Looked inside ' . $n . ' zone' . ($n === 1 ? '' : 's') . '.');
        header('Location: index.php?view=cloudflare'); exit;
    }

    // What fleet state believes, so the page can show where the two disagree.
    $recorded = [];   // domain(lower) => true when a zone id is already on the record
    $known    = [];   // domain(lower) => status, for domains we track at all
    try {
        foreach (infra_state_db()->query("SELECT lower(domain) d, status, COALESCE(cf_zone_id,'') z FROM domains") as $row) {
            $known[$row['d']] = $row['status'];
            if ($row['z'] !== '') $recorded[$row['d']] = true;
        }
    } catch (Throwable $e) { /* fleet state unreadable — the zone list still stands alone */ }

    infra_header('cloudflare');
    ?>
    <?php
    // Oldest stored zone list across the accounts: the bar should reflect the
    // staleset thing on the page, not the freshest.
    $cfAge = null;
    foreach ($accounts as $a) {
        $t = infra_cache_age(infra_cf_zones_key($a));
        if ($t === null) { $cfAge = null; break; }
        $cfAge = $cfAge === null ? $t : max($cfAge, $t);
    }
    infra_freshness_bar([
        'age'         => $cfAge,
        'stale_after' => 900,
        'noun'        => 'Cloudflare',
        'href'        => 'index.php?view=cloudflare&refresh=1',
        'button'      => 'Refresh zones',
    ]);

    // Which box each account serves, and whether there is room — above the per-account
    // cards, which keep doing credentials, zone lists, Test and Remove. Two questions,
    // two sections, rather than one card trying to answer both.
    require __DIR__ . '/_cf_boxes.php';
    ?>

    <?php foreach ($accounts as $a):
        // Both read the last stored answer. Only the Refresh button above goes live —
        // see infra_cf_probe_cached() / infra_cf_zones_cached() for why.
        $probe    = infra_cf_probe_cached($a);
        $ok       = !empty($probe['ok']);
        $never    = !empty($probe['never']);
        $zones    = infra_cache_fresh() ? infra_discover_cf_zones($a, 0) : infra_cf_zones_cached($a);
        $pending  = array_values(array_filter($zones, fn($z) => ($z['status'] ?? '') !== 'active'));
        $unlinked = array_values(array_filter($zones, fn($z) => !isset($recorded[strtolower($z['name'] ?? '')])));
        $strays   = array_values(array_filter($zones, fn($z) => !isset($known[strtolower($z['name'] ?? '')])));
        $usesGlobalKey = !empty($a['email']) && !empty($a['global_key']);
    ?>
    <div class="ic-card">
        <h2><?= ih($a['label'] ?? $a['id'] ?? 'Cloudflare account') ?>
            <?= $ok ? '<span class="badge b-ok">connected</span>'
                 : ($never ? '<span class="badge b-mut">not checked yet</span>'
                           : '<span class="badge b-err">credentials rejected</span>') ?>
        </h2>
        <div class="body">

            <?php if ($never): ?>
            <!-- Not asked yet and asked-and-refused are different facts, and only the
                 second is a fault. -->
            <div class="ic-note">
                <strong>These credentials have not been tested yet.</strong>
                Press <strong>Refresh zones</strong> above to check them and read the zone list.
                Anything shown below is from fleet state, not from Cloudflare.
            </div>
            <?php elseif (!$ok): ?>
            <div class="ic-note" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b">
                <strong>Cloudflare would not accept these credentials.</strong><br>
                <?= ih($probe['error'] ?: 'no reply') ?>
            </div>
            <?php endif; ?>

            <table style="margin-bottom:18px">
                <tbody>
                    <tr><td style="width:250px;color:#6b7280">Cloudflare account ID</td>
                        <td><code><?= ih($a['account_id'] ?? '—') ?></code></td></tr>
                    <tr><td style="color:#6b7280">How the console signs in</td>
                        <td><?= $usesGlobalKey
                            ? 'Global API key <span style="color:#92400e">— full access to the whole account; a scoped token is also stored and would be used if this were removed</span>'
                            : 'Scoped API token' ?></td></tr>
                    <tr><td style="color:#6b7280">Domains in this account</td>
                        <td><strong><?= count($zones) ?></strong>
                            <?php if ($zones): ?>
                            <span style="color:#6b7280">— <?= count($zones) - count($pending) ?> with nameservers set, <?= count($pending) ?> waiting</span>
                            <?php endif; ?>
                            <div style="color:#9ca3af;font-size:12px">Nameservers set means Cloudflare is answering for the domain. It does not mean a website is behind it.</div></td></tr>
                </tbody>
            </table>

            <?php if ($pending || $unlinked || $strays): ?>
            <div style="background:#fffbeb;border:1px solid #fde047;border-radius:8px;padding:12px 14px;margin-bottom:18px">
                <strong style="color:#854d0e">Worth a look</strong>
                <ul style="margin:8px 0 0 18px;color:#854d0e;line-height:1.8">
                    <?php if ($pending): ?>
                    <li><strong><?= count($pending) ?></strong> domain(s) set up here but still pointing at their old nameservers — they are not live yet. Go-Live switches them.</li>
                    <?php endif; ?>
                    <?php if ($unlinked): ?>
                    <li><strong><?= count($unlinked) ?></strong> domain(s) have a zone here that the console has not recorded. Worth knowing that a domain bought <em>at</em> Cloudflare gets a zone automatically, pointing at Cloudflare's own nameservers — so an unrecorded zone usually means "bought here", not "already provisioned". The zone is likely empty until a build fills it in.</li>
                    <?php endif; ?>
                    <?php if ($strays): ?>
                    <li><strong><?= count($strays) ?></strong> domain(s) in this account that the console has never heard of at all.</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php
            // Nameserver spread. One pair carrying everything is a public link between
            // every domain in the account, which undoes spreading them across registrars.
            $nsPairs = infra_ns_pairs($zones);
            if ($nsPairs):
                $biggest = max($nsPairs);
                $leaky   = count($nsPairs) === 1 && $biggest > 1;
            ?>
            <div style="border:1px solid <?= $leaky ? '#fde047' : '#e5e7eb' ?>;background:<?= $leaky ? '#fffbeb' : '#f9fafb' ?>;border-radius:8px;padding:12px 14px;margin-bottom:18px">
                <strong style="color:<?= $leaky ? '#854d0e' : '#374151' ?>">Nameservers these domains hand out</strong>
                <table style="margin-top:8px">
                    <thead><tr>
                        <th style="width:420px">Nameserver pair</th>
                        <th>Domains handing it out</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($nsPairs as $pair => $cnt): ?>
                        <tr>
                            <td><code style="font-size:12px"><?= ih($pair) ?></code></td>
                            <td><strong><?= $cnt ?></strong> domain<?= $cnt === 1 ? '' : 's' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($leaky): ?>
                <div style="color:#854d0e;margin-top:8px;font-size:13px">
                    &#9888; Every domain in this account hands out the same two nameservers. Anyone can look up
                    one domain's nameservers and find all the others. Spreading domains across registrars hides
                    who owns them; this puts it back. Cloudflare gives a pair per account, so more accounts
                    means more pairs &mdash; provisioning already shares new domains out between whatever
                    accounts are listed here.
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="display:flex;align-items:center;gap:12px;margin:0 0 8px">
                <h2 style="font-size:15px;margin:0">Domains in this account (<?= count($zones) ?>)</h2>
                <?php if ($zones): ?>
                <a class="btn sec" style="padding:3px 10px;font-size:12px"
                   href="index.php?view=cloudflare&amp;scan=<?= ih($a['id'] ?? '') ?>">Look inside these zones</a>
                <?php endif; ?>
            </div>
            <?php if (!$zones): ?>
                <div class="ic-empty"><?= $ok ? 'No domains in this Cloudflare account yet.' : 'Cannot list domains until the credentials work.' ?></div>
            <?php else: ?>
                <!-- The list scrolls inside the card, so the headings stick to the top of it —
                     otherwise you scroll past row 12 and no longer know which column is which. -->
                <div style="max-height:520px;overflow:auto">
                <table class="ic-sticky">
                    <thead><tr><th>Domain</th><th>Cloudflare status</th><th>What's in the zone</th><th>Points at</th><th>Since</th><th>In your records?</th></tr></thead>
                    <tbody>
                    <?php foreach ($zones as $z):
                        $nm   = strtolower($z['name'] ?? '');
                        $live = ($z['status'] ?? '') === 'active';
                        $dns  = !empty($z['id']) ? infra_zone_contents_cached($z['id']) : null;
                    ?>
                        <tr>
                            <td><strong><?= ih($z['name'] ?? '?') ?></strong></td>
                            <td style="color:<?= $live ? '#166534' : '#92400e' ?>">
                                <strong><?= $live ? 'nameservers set' : 'waiting on nameservers' ?></strong></td>
                            <td style="font-size:13px">
                                <?php if ($dns === null): ?>
                                    <span style="color:#9ca3af">not looked at yet</span>
                                <?php elseif ($dns['n'] === 0): ?>
                                    <span style="color:#92400e"><strong>empty</strong> &mdash; nothing to serve</span>
                                <?php else: ?>
                                    <span style="color:#166534"><?= (int) $dns['n'] ?> record<?= $dns['n'] === 1 ? '' : 's' ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#6b7280">
                                <?php if ($dns === null || empty($dns['a'])): ?>&mdash;
                                <?php else: foreach (array_slice($dns['a'], 0, 2) as $rec): ?>
                                    <div><code><?= ih($rec['ip']) ?></code><?= $rec['proxied'] ? ' <span style="color:#f59e0b">proxied</span>' : '' ?></div>
                                <?php endforeach; endif; ?>
                            </td>
                            <td style="font-size:12px;color:#6b7280">
                                <?= ih(substr((string) ($z['activated_on'] ?? $z['created_on'] ?? ''), 0, 10) ?: '—') ?></td>
                            <td style="font-size:12px">
                                <?php if (isset($recorded[$nm])): ?><span style="color:#166534">yes</span>
                                <?php elseif (isset($known[$nm])): ?><span style="color:#92400e">not written down</span>
                                <?php else: ?><span style="color:#991b1b">unknown domain</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>

            <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <a class="btn sec" href="index.php?view=cloudflare&amp;edit=<?= ih($a['id'] ?? '') ?>#cf-<?= ih($a['id'] ?? '') ?>">Edit these settings</a>
                <form method="post" action="actions/cf_save.php" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= ih($a['id'] ?? '') ?>">
                    <button class="btn" style="background:#991b1b" type="submit"
                            onclick="return confirm('Remove &quot;<?= ih($a['label'] ?? $a['id']) ?>&quot; from this console?\n\nYour Cloudflare account is NOT touched — the zones stay, DNS keeps working, the websites stay up. It just stops appearing here.')">
                        Remove from console
                    </button>
                </form>
            </div>

            <?php if ($editId === ($a['id'] ?? '')): ?>
            <div id="cf-<?= ih($a['id'] ?? '') ?>" style="margin-top:18px;border-top:1px solid #e5e7eb;padding-top:16px">
                <h2 style="font-size:15px;margin:0 0 10px">Edit this account</h2>
                <?php infra_cf_form($a); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="ic-card" id="add-cf">
        <h2>Add a Cloudflare account</h2>
        <div class="body">
            <div class="ic-note">Cloudflare limits how many domains one account can hold, so a large fleet is normally spread over several. Each one you add here is listed separately above.</div>
            <?php infra_cf_form(null); ?>
        </div>
    </div>
    <?php
    infra_footer(); exit;
