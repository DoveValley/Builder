<?php
/**
 * infra/views/filedate.php — "File Date Refresh" tab.
 *
 * Fully standalone feature: own lib file, own queue/log table (file_date_refresh in
 * fleet.db), own cron (cron/filedate_tick.php). Not part of, and shares no state
 * with, golive_freshen.php/golive_tick.php — see lib/file_date_refresh.php's
 * docblock for why they're kept separate.
 */
require_once __DIR__ . '/../lib/file_date_refresh.php';
infra_header('filedate');
?>

<div class="ic-card">
    <h2>How file dates work on this fleet</h2>
    <div class="body">
        <p>Three separate things decide what date a visitor (or Google) sees for a page on one
        of our domains:</p>
        <ol style="margin:0 0 10px 18px;line-height:1.8">
            <li><strong>The physical file's timestamp</strong> (image, HTML, CSS — the
                <code>Last-Modified</code> header a web server sends). Every domain in a batch is
                built and FTP-uploaded together, so every file on every domain in that batch gets a
                near-identical real upload time. Confirmed live: domains released weeks apart still
                showed homepage dates inside the same 98 seconds of one upload. Fixed at go-live by
                <code>golive_freshen.php</code> (a separate, existing tool, not this one) — it
                re-touches a domain's files on its own staggered delay the moment that domain is
                confirmed live. <strong>This tab exists for later</strong>: domains already live for
                a while whose files have never been touched since.</li>
            <li><strong>The page/site date</strong> stored in each domain's data
                (<code>last_modified</code>, used in the sitemap). Already correct — every page's
                own date (or a single site-wide fallback when a page has none) is real, not a stamped
                build date.</li>
            <li><strong>Blog post dates</strong> — each post is deliberately backdated 14–270 days
                by a stable per-site/per-post calculation, so a domain's blog doesn't look like every
                post went up on build day. Already correct, unrelated to this tab.</li>
        </ol>
        <p style="margin:0;color:#6b7280">This tab only addresses #1, and only for domains that are
        already live. It makes <strong>no content changes</strong> — no text, image or chart is
        touched. It re-uploads a domain's existing files exactly as they are (which resets their
        timestamp) and clears Cloudflare's cache so the new date is actually visible, nothing more.</p>
    </div>
</div>

<div class="ic-card">
    <h2>Refresh some domains' file dates</h2>
    <div class="body">
        <p style="margin-top:0;color:#6b7280">Picks random eligible live domains from the niche(s)
        you choose below, and queues each one for a file-date touch. Each domain runs at its own
        randomly-staggered time (roughly 20 minutes to 20 hours later) — drained automatically by a
        cron job every 10 minutes — so queuing several at once doesn't just recreate the same
        same-instant-timestamp problem this is meant to fix. Nothing runs immediately when you click
        the button; check the log below as entries complete.</p>
        <form method="post" action="actions/filedate_run.php">
            <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
            <table>
                <tbody>
                <tr>
                    <td style="width:220px"><strong>How many domains</strong></td>
                    <td><input type="number" name="count" value="5" min="1" max="200" style="width:100px"></td>
                </tr>
                <tr>
                    <td><strong>Cooldown (days)</strong><br>
                        <span style="color:#6b7280;font-size:12px">Skip any domain touched more recently than this</span></td>
                    <td><input type="number" name="cooldown_days" value="30" min="0" max="365" style="width:100px"></td>
                </tr>
                <tr>
                    <td><strong>Niches</strong></td>
                    <td>
                        <?php foreach (INFRA_NICHES as $n): if ($n === '') continue; ?>
                            <label style="margin-right:14px;font-weight:400">
                                <input type="checkbox" name="niches[]" value="<?= ih($n) ?>" checked>
                                <?= ih(ucfirst($n)) ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                </tbody>
            </table>
            <div style="margin-top:14px">
                <button class="btn" type="submit">Queue refresh</button>
            </div>
        </form>
    </div>
</div>

<div class="ic-card">
    <h2>Log</h2>
    <div class="body">
        <?php $log = infra_filedate_recent_log(100); ?>
        <?php if (!$log): ?>
            <div class="ic-empty">Nothing queued or run yet.</div>
        <?php else: ?>
            <table>
                <thead><tr><th>Domain</th><th>Niche</th><th>Queued</th><th>Due / Done</th><th>Status</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($log as $row): ?>
                    <tr>
                        <td><code><?= ih($row['domain']) ?></code></td>
                        <td><?= ih($row['niche'] ?: '—') ?></td>
                        <td style="font-size:12px;color:#6b7280"><?= ih($row['queued_at']) ?></td>
                        <td style="font-size:12px;color:#6b7280">
                            <?= ih($row['status'] === 'done' ? $row['done_at'] : $row['due_at']) ?>
                        </td>
                        <td>
                            <?php if ($row['status'] !== 'done'): ?>
                                <span class="badge b-warn">pending</span>
                            <?php elseif ($row['ok'] === 'yes'): ?>
                                <span class="badge b-ok">done</span>
                            <?php else: ?>
                                <span class="badge b-err">failed</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:#6b7280"><?= ih($row['note'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php infra_footer(); ?>
