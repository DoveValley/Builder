<?php
/**
 * infra/views/domaincheck.php — "Domain Check" tab: save named lists of domains
 * and batch-check whether each one is actually up. Reuses lib/uptime.php's
 * infra_site_check_run()/infra_site_verdict() — the same checker the Servers tab
 * and the go-live pipeline already use — rather than a second implementation.
 * Storage only; see lib/domain_lists.php.
 */
require_once __DIR__ . '/../lib/domain_lists.php';
infra_header('domaincheck');
?>

<div class="ic-card">
    <h2>What this tab does</h2>
    <div class="body">
        <ul style="margin:0;padding-left:18px;line-height:1.8">
            <li><strong>Paste in any domains you want to keep an eye on</strong> — they don't have
                to be ones already tracked anywhere else in this panel. Save them under a name and
                they're stored permanently, so you can come back days or weeks later and check the
                same list again without re-typing it.</li>
            <li><strong>Save as many separate lists as you want</strong> — one per batch, one per
                client, one per niche, however you want to split them up. Each shows up as its own
                card below.</li>
            <li><strong>Nothing is checked automatically.</strong> Saving a list just stores it.
                Click <strong>"Check this batch now"</strong> on a list whenever you actually want an
                answer — that's the moment it goes and visits every domain in that list for real.</li>
            <li><strong>What "checking" actually means</strong>: for each domain, it tries to load
                the site over a secure connection (HTTPS) first. If that fails, it tries HTTPS
                without strictly checking the security certificate (so it can tell a truly dead site
                apart from one that's up but has a certificate problem). If even that fails, it tries
                a plain, unsecured connection. Whatever it finds is reported as-is.</li>
            <li><strong>Reading the results table</strong>: <em>Status</em> is a plain-English
                summary ("working", "not answering", etc.). <em>Code</em> is the raw web-server
                response code (200 means healthy; 4xx/5xx mean it answered but with an error).
                <em>Cert</em> says whether its security certificate is valid. <em>Response</em> is how
                long it took to answer, in milliseconds. <em>Checked</em> is how long ago that
                answer was obtained.</li>
            <li><strong>Results shown are always the last real check, not a live refresh on every
                page view</strong> — so if you haven't clicked "Check this batch now" in the last 15
                minutes, a domain shows as "not checked recently" rather than a guess. Click the
                button again any time to get a fresh answer.</li>
            <li><strong>Deleting a list only deletes the saved list itself</strong> — it never
                touches the actual domain, any site content, or anything else in the panel.</li>
        </ul>
    </div>
</div>

<div class="ic-card">
    <h2>Save a new list</h2>
    <div class="body">
        <p style="margin-top:0;color:#6b7280">Paste domains below — one per line, or separated by
        commas or spaces. Duplicates and anything that isn't a real domain are dropped
        automatically, and you'll be told how many were.</p>
        <form method="post" action="actions/domainlist_create.php">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <table>
                <tbody>
                <tr>
                    <td style="width:220px"><strong>List name</strong></td>
                    <td><input type="text" name="name" style="width:100%;max-width:360px" placeholder="e.g. Batch 12 — water niche"></td>
                </tr>
                <tr>
                    <td style="vertical-align:top"><strong>Domains</strong></td>
                    <td><textarea name="domains" rows="8" style="width:100%;max-width:480px" placeholder="example.com&#10;another-example.com"></textarea></td>
                </tr>
                </tbody>
            </table>
            <div style="margin-top:14px">
                <button class="btn" type="submit">Save list</button>
            </div>
        </form>
    </div>
</div>

<?php $lists = infra_domain_lists_all(); ?>
<?php if (!$lists): ?>
<div class="ic-card">
    <div class="body"><div class="ic-empty">No domain lists saved yet — save one above.</div></div>
</div>
<?php else: foreach ($lists as $l):
    $full = infra_domain_list_get((int) $l['id']);
?>
<div class="ic-card">
    <h2><?= ih($l['name']) ?>
        <span style="font-weight:400;color:#6b7280;font-size:13px">
            — <?= (int) $l['domain_count'] ?> domain<?= (int) $l['domain_count'] === 1 ? '' : 's' ?>,
            saved <?= ih($l['created_at']) ?>
        </span>
    </h2>
    <div class="body">
        <form method="post" action="actions/domainlist_check.php" style="display:inline-block">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
            <button class="btn" type="submit">Check this batch now</button>
        </form>
        <form method="post" action="actions/domainlist_delete.php" style="display:inline-block;margin-left:8px"
              onsubmit="return confirm('Delete this list? This only removes the saved list — it does not touch the domains themselves.');">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
            <button class="btn sec" type="submit">Delete list</button>
        </form>
        <?php if (!$full['domains']): ?>
            <div class="ic-empty" style="margin-top:14px">This list has no domains.</div>
        <?php else: ?>
        <table style="margin-top:14px">
            <thead><tr><th>Domain</th><th>Status</th><th>Code</th><th>Cert</th><th>Response</th><th>Checked</th></tr></thead>
            <tbody>
            <?php foreach ($full['domains'] as $d): $c = infra_site_check_cached($d); ?>
                <tr>
                    <td><code><?= ih($d) ?></code></td>
                    <td>
                        <?php if (!$c): ?>
                            <span class="badge b-mut">not checked recently</span>
                        <?php elseif (!empty($c['up'])): ?>
                            <span class="badge b-ok"><?= ih(infra_site_verdict($c)) ?></span>
                        <?php else: ?>
                            <span class="badge b-err"><?= ih(infra_site_verdict($c)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $c && (int) $c['code'] > 0 ? (int) $c['code'] : '—' ?></td>
                    <td><?= $c ? ($c['cert_ok'] ? 'OK' : 'no') : '—' ?></td>
                    <td><?= $c ? (int) $c['ms'] . ' ms' : '—' ?></td>
                    <td style="font-size:12px;color:#6b7280">
                        <?= $c ? ih(infra_ago(max(0, time() - strtotime($c['at'])))) : '—' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>

<?php infra_footer(); ?>
