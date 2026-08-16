<?php

    require_once __DIR__ . '/../lib/hestia_fleet.php';
    infra_header('servers');
    ?>
    <div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn" href="index.php?view=servers&amp;refresh=1">&#8635; Refresh</a>
        <!-- One call per host area, so it is a button and not something every page
             load pays for. Fleet-wide because eight boxes is eight clicks otherwise. -->
        <a class="btn sec" href="index.php?view=servers&amp;content=all">Check every server for files</a>
    </div>

    <!-- How a site gets onto a box. Written out because the order is not
         obvious, two of the steps fail silently, and the consequence of getting
         step 5 early is a domain Google crawls while it is empty. -->
    <details class="ic-card" open>
        <summary style="padding:14px 16px;font-size:15px;font-weight:600;cursor:pointer">
            How a batch goes live
            <span style="color:#9ca3af;font-weight:400;font-size:13px">— five phases, in this order</span>
        </summary>
        <div class="body" style="font-size:13.5px;line-height:1.65">

            <p style="margin:0 0 16px;color:#6b7280">
                Each phase finishes for the <em>whole batch</em> before the next begins, and every phase is
                safe to run twice. The order exists so failures land where they are cheap: phase 2 costs
                seconds, phase 4 costs an hour, and phase 5 is the only one the outside world can see.
            </p>

            <h3 style="font-size:14px;margin:0 0 4px">1. Define batch scope</h3>
            <ul style="margin:0 0 16px 18px;padding:0">
                <li>Decide which domains, which niche, and which box they land on.</li>
                <li><strong>Only owned domains.</strong> A domain that is planned, queued or merely
                    available is not a batch member &mdash; ownership is a receipt, not an intention.</li>
                <li>Pick the box on headroom and spread the work. Stacking one box concentrates the
                    blast radius that running several providers was meant to avoid.</li>
                <li>Nothing here touches a server. This phase produces a list, and a list is free to
                    throw away.</li>
            </ul>

            <h3 style="font-size:14px;margin:0 0 4px">2. Create all host folders &amp; verify</h3>
            <ul style="margin:0 0 16px 18px;padding:0">
                <li>For every domain: the nginx rule (<em>&ldquo;this domain &rarr; this folder&rdquo;</em>), the
                    folder itself, and an FTP login locked to that folder alone.</li>
                <li>All sites are filed under <strong>one account per box</strong>. A Hestia account is a
                    Linux user &mdash; one each would mean 500 home directories and a listing costing 501
                    API calls instead of 2.</li>
                <li><strong>Restart the web server once, at the end</strong> &mdash; not once per site.
                    nginx does not notice new rules on its own, and until it restarts it serves
                    Hestia&rsquo;s default page with a <code>200 OK</code>. Nothing says <em>error</em>:
                    Hestia confirms the folder and uploads land correctly while the wrong page is served.
                    <code>v-rebuild-web-domains</code> does not fix it; only a restart does.</li>
                <li><strong>Then verify, because this is the cheap phase.</strong> Three checks:
                    every vhost is listed (one call for the batch); each FTP login actually connects and
                    writes (<em>exit code 0 is not evidence</em>); and one domain fetched by
                    <code>Host</code> header returns its own folder rather than the default page.</li>
                <li>Seconds of work. Finding a broken box here costs nothing; finding it in phase 4 costs
                    an hour and leaves half a batch uploaded.</li>
            </ul>

            <h3 style="font-size:14px;margin:0 0 4px">3. Generate all sites</h3>
            <ul style="margin:0 0 16px 18px;padding:0">
                <li>Build every site&rsquo;s static HTML locally on the factory. Nothing goes over the wire
                    in this phase.</li>
                <li><strong>A build failure here costs nothing.</strong> That is the entire reason it is
                    separate from uploading &mdash; a batch that dies on site 37 should not leave 36 sites
                    live and 14 missing.</li>
                <li>Read the output before it leaves the building: no unresolved <code>{tokens}</code>,
                    no placeholder phone numbers, no invented statistics.</li>
            </ul>

            <h3 style="font-size:14px;margin:0 0 4px">4. Upload all sites</h3>
            <ul style="margin:0 0 16px 18px;padding:0">
                <li>Each site goes up through its own FTP login into its own folder, so a leaked
                    credential stops at one site.</li>
                <li>The slow phase, and the one worth making re-runnable: a second run should skip what
                    is already there rather than send it again.</li>
                <li><strong>Confirm the folders are no longer empty.</strong> A vhost whose folder still
                    holds only the two placeholder files is a site that was created and never filled &mdash;
                    the failure that is invisible until someone visits.</li>
            </ul>

            <h3 style="font-size:14px;margin:0 0 4px">5. Go live (point DNS)</h3>
            <ul style="margin:0 0 6px 18px;padding:0">
                <li>Only now point DNS at the box &mdash; proxied through Cloudflare, cache everything,
                    SSL Full (strict) against an Origin CA certificate.</li>
                <li><strong>Never let a rank-and-rent domain resolve while it is empty.</strong> DNS first
                    means a window where Google crawls the domain and finds a blank page, and a first
                    impression of &ldquo;empty site&rdquo; is expensive to undo.</li>
                <li>Everything before this point is invisible from outside. The batch can sit finished
                    but unpointed for as long as you like &mdash; which is what makes the earlier phases
                    safe to get wrong.</li>
                <li>Then check each site answers on its real hostname <em>and</em> that the certificate
                    matches. Serving fine behind a bad certificate is a browser warning for every visitor.</li>
            </ul>
        </div>
    </details>
    <?php
    /** The add form and the edit form are the same fields, so they are the same
     *  function. $srv = null renders "add"; an existing server renders "edit"
     *  with its values filled in — except the key pair, which is never printed
     *  back into the page. Blank on an edit keeps the stored one. */
    function infra_hestia_form(?array $srv): void {
        $isEdit = $srv !== null;
        $v = fn(string $k, string $d = '') => ih((string) ($srv[$k] ?? $d));
        ?>
        <form method="post" action="actions/hestia_save.php">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $v('id') ?>"><?php endif; ?>

            <table>
                <tbody>
                <tr>
                    <td style="width:250px"><label><strong>Name</strong><br>
                        <span style="color:#6b7280;font-size:12px">Anything that helps you tell it apart</span></label></td>
                    <td><input name="label" value="<?= $v('label') ?>"
                               placeholder="e.g. Hestia trial box" style="width:100%;max-width:420px" required></td>
                </tr>
                <tr>
                    <td><label><strong>Address you log in to Hestia at</strong><br>
                        <span style="color:#6b7280;font-size:12px">IP or hostname &mdash; no https://</span></label></td>
                    <td><input name="host" value="<?= $v('host') ?>" placeholder="e.g. 5.9.12.34"
                               style="width:100%;max-width:420px" required></td>
                </tr>
                <tr>
                    <td><label><strong>Port</strong><br>
                        <span style="color:#6b7280;font-size:12px">Hestia uses 8083 unless you changed it</span></label></td>
                    <td><input name="port" type="number" value="<?= $v('port', '8083') ?>" style="width:120px"></td>
                </tr>
                <tr>
                    <td><label><strong>Access key</strong><br>
                        <span style="color:#6b7280;font-size:12px">Hestia &rarr; Server Settings &rarr; Configure &rarr; Security &rarr; Access Keys.
                        Leave both key boxes empty if Hestia is not on this machine yet.</span></label></td>
                    <td><input name="access_key" type="password" autocomplete="new-password"
                               placeholder="<?= $isEdit ? 'leave blank to keep the stored pair' : 'paste the access key' ?>"
                               style="width:100%;max-width:420px"></td>
                </tr>
                <tr>
                    <td><label><strong>Secret key</strong><br>
                        <span style="color:#6b7280;font-size:12px">Shown once, when the access key is created</span></label></td>
                    <td><input name="secret_key" type="password" autocomplete="new-password"
                               placeholder="<?= $isEdit ? 'leave blank to keep the stored pair' : 'paste the secret key' ?>"
                               style="width:100%;max-width:420px">
                        <?php if ($isEdit): ?><br><span style="color:#9ca3af;font-size:12px">A key pair is stored. It is never shown here.</span><?php endif; ?></td>
                </tr>
                <tr>
                    <td><label><strong>Account the websites are filed under</strong><br>
                        <span style="color:#6b7280;font-size:12px">One account holds every site on this box. Created on first use.</span></label></td>
                    <td><input name="site_user" value="<?= $v('site_user', 'fleet') ?>" style="width:200px"></td>
                </tr>
                <tr>
                    <td><label><strong>IP address the websites answer on</strong><br>
                        <span style="color:#6b7280;font-size:12px">Cloudflare points at this. Blank = same as above</span></label></td>
                    <td><input name="default_ip" value="<?= $v('default_ip') ?>" placeholder="usually the same address"
                               style="width:100%;max-width:420px"></td>
                </tr>
                <tr>
                    <td><label style="color:#6b7280">Contact email and package
                        <span style="font-size:12px">(used when the account is created)</span></label></td>
                    <td><input name="contact_email" value="<?= $v('contact_email') ?>" placeholder="you@example.com" style="width:260px">
                        <input name="package" value="<?= $v('package', 'default') ?>" placeholder="default" style="width:140px"></td>
                </tr>
                <tr>
                    <td><label style="color:#6b7280">What this machine is
                        <span style="font-size:12px">(optional &mdash; host, plan, size, price, where it is)</span></label></td>
                    <td><input name="notes" value="<?= $v('notes') ?>"
                               placeholder="e.g. Hetzner CX23 · 2 vCPU · 4 GB · 40 GB · Helsinki · €6.49/mo"
                               style="width:100%;max-width:420px"></td>
                </tr>
                </tbody>
            </table>

            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Add this server' ?></button>
                <button class="btn sec" type="submit" name="action" value="test">Test without saving</button>
                <?php if ($isEdit): ?><a class="btn sec" href="index.php?view=servers#hestia">Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php
    }

    $hEditId  = (string) ($_GET['hedit'] ?? '');
    $hServers = infra_hestia_servers();

    // Deliberately on demand: one outbound request per site, so a box with 40 on it
    // should ask when you press the button, not on every page load.
    // Read what is actually in each host area's folder. On demand: one API call per
    // host area, so it is asked for, not paid for on every page load.
    $contentId = (string) ($_GET['content'] ?? '');
    if ($contentId !== '') {
        $tot = ['checked' => 0, 'with_files' => 0, 'empty' => 0];
        foreach (infra_hestia_servers() as $srv) {
            if ($contentId !== 'all' && ($srv['id'] ?? '') !== $contentId) continue;
            $r = infra_hestia_content_run($srv);
            foreach (['checked', 'with_files', 'empty'] as $k) $tot[$k] += $r[$k];
        }
        infra_set_flash($tot['empty'] > 0 ? 'warn' : 'ok',
            $tot['checked'] === 0 ? 'No host areas to check.'
            : ($tot['with_files'] . ' of ' . $tot['checked'] . ' host area(s) contain a site'
               . ($tot['empty'] > 0 ? ' — ' . $tot['empty'] . ' still hold only the placeholder, so nothing has been uploaded into them.' : '.')));
        header('Location: index.php?view=servers#hestia'); exit;
    }

    $hCheckId = (string) ($_GET['hcheck'] ?? '');
    if ($hCheckId !== '') {
        $n = 0;
        foreach ($hServers as $srv) {
            if (($srv['id'] ?? '') !== $hCheckId) continue;
            foreach (infra_discover_hestia($srv)['sites'] as $s) {
                if (($s['name'] ?? '') !== '') { infra_site_check_run($s['name']); $n++; }
            }
        }
        infra_set_flash('ok', 'Checked ' . $n . ' website' . ($n === 1 ? '' : 's') . ' on the Hestia box.');
        header('Location: index.php?view=servers#hestia'); exit;
    }

    // One shared reader (infra_hestia_fleet) rather than this page's own loop —
    // it is the same discovery, and the derived numbers stay consistent with the
    // dashboard and the batch page by construction.
    $hRows = [];
    foreach (infra_hestia_fleet() as $b) $hRows[] = ['srv' => $b['server'], 'd' => infra_discover_hestia($b['server']), 'b' => $b];
    ?>

    <!-- id="hestia" is kept: links elsewhere on this page still target it. -->
    <div id="hestia"></div>

    <?php if (empty($hServers)): ?>
        <div class="ic-empty">No HestiaCP server registered yet. Add one below.</div>
    <?php else: ?>

    <?php foreach ($hRows as $r): $srv = $r['srv']; $d = $r['d']; $b = $r['b'];
          $f = ['panel_version' => $b['version'], 'platform' => $b['platform'], 'hostname' => $b['hostname']];
          $unset = $b['pending'];
          $acct  = $b['accounts'];
          // Collapsed once it is healthy — a working box is a one-line fact and
          // eight of them should fit on a screen. Anything wrong opens itself,
          // because a problem nobody clicks on is a problem nobody sees.
          $openIt = !$d['ok'] || $hEditId === ($srv['id'] ?? ''); ?>
    <details class="ic-card srv-card" <?= $openIt ? 'open' : '' ?>>
        <summary style="cursor:pointer">
        <h2 style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="color:#9ca3af;font-weight:400">▸</span>
            <?= ih($srv['label'] ?? $srv['id']) ?>
            <span class="badge" style="background:#dbeafe;color:#1e40af">HestiaCP</span>
            <?= $d['ok'] ? '<span class="badge b-ok">up</span>'
                 : ($unset ? '<span class="badge" style="background:#fef3c7;color:#92400e">not set up yet</span>'
                           : '<span class="badge b-err">cannot reach it</span>') ?>
            <span style="font-weight:400;font-size:13px;color:#6b7280">
                <code><?= ih($srv['host'] ?? '') ?></code>
                <?php if ($d['ok']): ?>
                    &middot; Hestia <?= ih($f['panel_version'] ?: '?') ?>
                    &middot; <?= ih($f['platform'] ?: '?') ?>
                <?php endif; ?>
            </span>
        </h2>

        <?php if ($d['ok']): ?>
        <!-- The four numbers worth seeing without opening anything. Sites first:
             it is the one that answers "is this box doing any work". -->
        <div style="display:flex;gap:26px;flex-wrap:wrap;padding:2px 16px 14px 30px">
            <?php
            // Files first: a host area whose folder is empty is the failure that stays
            // invisible until someone visits, so it reads before the count of hosts.
            // '?' until asked — the check costs one API call per host area.
            $content = infra_hestia_content_cached($srv);
            $tiles = [
                ['Files', $content === null ? '?' : (int) $content['with_files'],
                 $content === null ? '#9ca3af' : ((int) $content['empty'] > 0 ? '#92400e' : '#166534')],
                // DEPLOYED sites, not vhosts: the panel's own hostname vhost is an
                // artefact of installing Hestia, and counting it made every empty
                // box report one website. The full vhost list is still below.
                // "Hosts", not "Websites": this counts the host areas created on the
                // box — the vhost + folder + FTP login that step 3 makes. Whether a
                // site has been uploaded into one is a different question, and calling
                // it Websites implied this number answered it.
                ['Hosts',               $b['deployed'],          '#111827'],
                ['Accounts',            $acct['total'],          '#111827'],
                ['&hellip; with a site', $acct['with_site'],     '#166534'],
                ['&hellip; with none',   $acct['without_site'],  $acct['without_site'] > 0 ? '#92400e' : '#9ca3af'],
            ];
            foreach ($tiles as [$label, $val, $col]): ?>
                <div>
                    <div style="font-size:20px;font-weight:700;color:<?= $col ?>;line-height:1.1"><?= is_int($val) ? $val : ih((string) $val) ?></div>
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em"><?= $label ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$acct['exact']): ?>
            <div style="font-size:11px;color:#9ca3af;max-width:260px;align-self:center">
                Accounts counts what the console can see. <code>v-list-users</code> returns only the
                key's own account, so an unrelated empty account would not appear.
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </summary>
        <div class="body">

            <?php if ($unset): ?>
            <div class="ic-note" style="background:#fffbeb;border-color:#fcd34d;color:#92400e">
                <strong>The machine is recorded here, but there is no Hestia on it yet.</strong>
                Nothing has been contacted — it has no key pair, so there is nothing to authenticate with.
                Three things left, in order:
                <ol style="margin:8px 0 0 18px;padding:0">
                    <li>Install Hestia on it (the command is in "Add a HestiaCP server" at the bottom of this page).</li>
                    <li>On the box: <code>v-add-sys-api</code>, then put <code>187.127.254.206</code> in <code>API_ALLOWED_IP</code>.</li>
                    <li><code>v-add-access-key user</code> with no permissions argument, then paste the pair into
                        "Edit these settings" below.</li>
                </ol>
            </div>
            <?php elseif (!$d['ok']): ?>
            <div class="ic-note" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b">
                <strong>The console could not talk to this server.</strong><br><?= ih($d['error']) ?>
                <?php if (($d['error'] ?? '') !== '' && stripos($d['error'], 'allowed') !== false): ?>
                <br><br>Hestia answers HTTP 200 with the body <code>Error</code> when the API is switched
                off or the caller's IP is not allowed — it looks like nothing is wrong. On the box:
                <code>v-add-sys-api</code>, then add this server's IP to <code>API_ALLOWED_IP</code>.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <table style="margin-bottom:18px">
                <tbody>
                    <tr><td style="width:230px;color:#6b7280">Address you'd log in at</td>
                        <td><code>https://<?= ih($srv['host'] ?? '') ?>:<?= ih((string)($srv['port'] ?? '')) ?></code></td></tr>
                    <tr><td style="color:#6b7280">IP address the sites point at</td>
                        <td><code><?= ih($srv['default_ip'] ?? '—') ?></code></td></tr>
                    <?php if (trim((string) ($srv['notes'] ?? '')) !== ''): ?>
                    <tr><td style="color:#6b7280">What this machine is</td>
                        <td><?= ih($srv['notes']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td style="color:#6b7280">Hestia version</td>
                        <td><?= $unset ? '<span style="color:#92400e">not installed yet</span>' : ih($f['panel_version'] ?: '—') ?></td></tr>
                    <tr><td style="color:#6b7280">Server's own hostname</td>
                        <td><code><?= ih($f['hostname'] ?: '—') ?></code></td></tr>
                    <tr><td style="color:#6b7280">Operating system</td>
                        <td><?= ih($f['platform'] ?: '—') ?></td></tr>
                    <tr><td style="color:#6b7280">Host areas on it</td>
                        <td><strong><?= count($d['sites']) ?></strong></td></tr>
                    <tr><td style="color:#6b7280">Account they're filed under</td>
                        <td><code><?= ih($srv['site_user'] ?? 'fleet') ?></code>
                            <span style="color:#9ca3af">— <?= count($d['users']) ?> account<?= count($d['users']) === 1 ? '' : 's' ?> on the box</span></td></tr>

                    <!-- Hestia has no "list every domain" call — domains hang off
                         accounts, so this is 1 + one call per account. Filing every site
                         under a single account is what keeps it at two. -->
                    <tr><td style="color:#6b7280"><strong>Cost to read this server</strong></td>
                        <td>
                        <?php if ($unset): ?>
                            <span style="color:#9ca3af">not measured — nothing has been read from it</span>
                            <div style="font-size:12px;color:#6b7280">A box with no key pair costs nothing to
                                "read" because no request is sent. Zero here would otherwise look like a
                                very fast server rather than an absent one.</div>
                        <?php else: ?>
                            <strong><?= (int) $d['calls'] ?></strong> API call<?= (int) $d['calls'] === 1 ? '' : 's' ?>,
                            <strong><?= (int) $d['ms'] ?>ms</strong>
                            <div style="font-size:12px;color:#6b7280">
                                measured <?= ih(date('j M H:i', strtotime($d['at'] ?? 'now'))) ?>, then cached for
                                <?= (int) INFRA_HESTIA_TTL ?>s. Hestia has no "list every domain" call — domains hang
                                off accounts, so this is 1 + one call per account. Filing every site under a single
                                account is what keeps that at two instead of one-per-site.
                            </div>
                        <?php endif; ?>
                        </td></tr>
                </tbody>
            </table>

            <div style="display:flex;align-items:center;gap:12px;margin:0 0 8px">
                <h2 style="font-size:15px;margin:0">Websites on this server (<?= count($d['sites']) ?>)</h2>
                <?php if ($d['sites']): ?>
                <a class="btn sec" style="padding:3px 10px;font-size:12px"
                   href="index.php?view=servers&amp;hcheck=<?= ih($srv['id'] ?? '') ?>">Check if they're up</a>
                <a class="btn sec" style="padding:3px 10px;font-size:12px"
                   href="index.php?view=servers&amp;content=<?= ih($srv['id'] ?? '') ?>">Check for files</a>
                <?php endif; ?>
            </div>
            <?php if (!$d['sites']): ?>
                <div class="ic-empty">Nothing on this server yet.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Website</th><th>Is it up?</th><th>Account</th><th>SSL</th><th>Folder its files live in</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['sites'] as $s):
                        $name = (string) ($s['name'] ?? '');
                        $chk  = $name !== '' ? infra_site_check_cached($name) : null;
                        // Green only when it answers AND the certificate matches. Serving
                        // fine behind a bad cert is a browser warning for every visitor and
                        // must not read as healthy.
                        $col  = $chk === null ? '#9ca3af' : ((!empty($chk['up']) && !empty($chk['cert_ok'])) ? '#166534' : (!empty($chk['up']) ? '#92400e' : '#991b1b'));
                    ?>
                        <tr>
                            <td><strong><?= ih($name ?: '?') ?></strong></td>
                            <td style="color:<?= $col ?>">
                                <?php if ($chk === null): ?>
                                    <span style="color:#9ca3af">not checked yet</span>
                                <?php else: ?>
                                    <strong><?= ih(infra_site_verdict($chk)) ?></strong>
                                    <div style="font-size:12px;color:#6b7280">
                                        <?= $chk['code'] ? ih((string) $chk['code']) . ' · ' : '' ?>
                                        <?= $chk['ms'] ? ih((string) $chk['ms']) . 'ms · ' : '' ?>
                                        checked <?= ih(date('j M H:i', strtotime($chk['at'] ?? 'now'))) ?>
                                    </div>
                                    <?php if (!empty($chk['error'])): ?>
                                    <div style="font-size:12px;color:#991b1b"><?= ih(substr($chk['error'], 0, 120)) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:12px"><?= ih($s['user'] ?? '—') ?></code></td>
                            <td><?= strtolower((string) ($s['ssl'] ?? '')) === 'yes'
                                    ? '<span style="color:#166534">yes</span>'
                                    : '<span style="color:#9ca3af">no</span>' ?></td>
                            <td><code style="font-size:12px"><?= ih($s['docroot'] ?? '—') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <a class="btn sec" href="index.php?view=servers&amp;refresh=1#hestia">&#8635; Re-read this server</a>
                <a class="btn sec" href="index.php?view=servers&amp;hedit=<?= ih($srv['id'] ?? '') ?>#hform-<?= ih($srv['id'] ?? '') ?>">Edit these settings</a>
                <form method="post" action="actions/hestia_save.php" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= ih($srv['id'] ?? '') ?>">
                    <button class="btn" style="background:#991b1b" type="submit"
                            onclick="return confirm('Remove &quot;<?= ih($srv['label'] ?? $srv['id']) ?>&quot; from this console?\n\nThe server itself is NOT touched — it keeps running and its websites stay up. It just stops appearing here.')">
                        Remove from console
                    </button>
                </form>
            </div>

            <?php if ($hEditId === ($srv['id'] ?? '')): ?>
            <div id="hform-<?= ih($srv['id'] ?? '') ?>" style="margin-top:18px;border-top:1px solid #e5e7eb;padding-top:16px">
                <h2 style="font-size:15px;margin:0 0 10px">Edit this server</h2>
                <?php infra_hestia_form($srv); ?>
            </div>
            <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="ic-card" id="add-hestia">
        <h2>Add a HestiaCP server</h2>
        <div class="body">
            <div class="ic-note">
                You buy the VPS and install Hestia yourself. Install it without mail, DNS or a
                database — these sites are static HTML and every service you skip is one less
                thing exposed:
                <br><br>
                <code style="display:block;white-space:pre-wrap;font-size:12px;line-height:1.7">bash hst-install.sh --interactive no --hostname panel.example.com --email you@example.com \
  --username user --password 'STRONG_PW' --apache no --phpfpm no --multiphp no --mysql no \
  --postgresql no --exim no --dovecot no --clamav no --spamassassin no --named no \
  --vsftpd yes --iptables yes --fail2ban yes --quota no --api yes</code>
                <br>
                Three things about that command, each of which cost a failed run:
                <ul style="margin:6px 0 0 18px;padding:0">
                    <li><strong>There is no <code>--nginx</code> option.</strong> Nginx is always
                        installed; <code>--apache no</code> is what makes it nginx-only. Passing
                        <code>--nginx yes</code> aborts the whole run with
                        <code>illegal option -- -</code>.</li>
                    <li><strong><code>--username user</code> is required.</strong> Hestia refuses
                        <code>admin</code> as the admin account name, and with
                        <code>--interactive no</code> it does not say so — it loops
                        "Please use a valid username" forever. This is why the account on these
                        boxes is called <code>user</code>.</li>
                    <li><strong>Do not write <code>--force yes</code>.</strong> <code>--force</code>
                        takes no value, so the stray <code>yes</code> becomes a positional argument,
                        <code>getopts</code> stops there, and <em>every flag after it is silently
                        ignored</em> — you get a default install with mail, DNS and a database on it.</li>
                </ul>
                <br>
                <code>--api yes</code> switches the API on during the install, so
                <code>v-add-sys-api</code> is only needed if you left it out. Either way you must add
                this server's IP (<code>187.127.254.206</code>) to <code>API_ALLOWED_IP</code> in
                <code>/usr/local/hestia/conf/hestia.conf</code>. Until you do, every call comes back
                as HTTP 200 with the body <code>Error</code> — which looks like nothing is wrong.
                Firewall port 8083 to that IP only.
            </div>
            <?php infra_hestia_form(null); ?>
        </div>
    </div>
    <?php
    infra_footer(); exit;
