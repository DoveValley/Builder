<?php
/**
 * infra/views/filedate.php — "File Dates/Names" tab.
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
    <h2>How file dates are handled</h2>
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

<hr style="margin:36px 0;border:none;border-top:2px solid #e5e7eb">

<div class="ic-card">
    <h2>How file names are handled</h2>
    <div class="body">
        <ul style="margin:0;padding-left:18px;line-height:1.8">
            <li><strong>Anything a person uploads or picks</strong> — a direct admin upload, a Pic
                Drop photo (Real or AI-generated), a media library upload, a logo/favicon upload —
                always gets a brand-new, random file name. Replacing one of these never overwrites
                the old file; it creates a new file and the old one is cleaned up once nothing
                references it anymore.</li>
            <li><strong>Anything the system generates automatically</strong> — a chart image, a
                service-area map, a city photo, a batch-generated AI photo, or a logo/favicon
                created from a brand/color preset — always reuses the exact same file name every
                time that specific image is regenerated. The old file is simply overwritten in
                place; it never gets a new name.</li>
            <li>Because those auto-generated files keep the same name forever, the system tacks a
                small "version marker" onto the end of the web address every time the file actually
                changes (for example, <code>...photo.webp?v=12345</code>). That forces browsers,
                Google, and social platforms to fetch the new version instead of continuing to show
                an old cached copy.</li>
            <li>That version marker is applied everywhere one of these images is shown — on the
                page itself, the site logo/favicon, charts and maps, and (fixed 2026-10-17) the
                social-media preview image and the business logo used in Google's structured data.
                Before that fix, those last two could keep showing a stale photo or logo
                indefinitely in Facebook/Twitter/Google previews after a real update.</li>
            <li>File names themselves have very little direct effect on Google ranking — the
                descriptive text attached to an image (alt text) and the surrounding page content
                matter far more. Where practical, the auto-generated file names already include
                useful descriptive text (like the city name) rather than a meaningless code.</li>
            <li>One leftover from before this system existed was found and cleaned up: an old,
                no-longer-live test file had a hard-coded logo web address from a previous client.
                It was never reachable by any real visitor and has been reset to a blank default.</li>
        </ul>
    </div>
</div>

<?php infra_footer(); ?>
