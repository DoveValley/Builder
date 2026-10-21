<?php
/**
 * The thresholds behind the fleet audit boxes — one card, on the Site Manager,
 * alongside them.
 *
 * ⚠ ONLY POLICY LIVES HERE. Every field is a judgement a reasonable person could make
 * differently, and whose right value changes as the fleet matures. The things that are
 * NOT here are not here on purpose — the Googlebot IP ranges, the -1 ≠ 0 conventions,
 * rank depth 100, the retry counts. A wrong value for any of those is a silently wrong
 * answer rather than a different opinion, so they stay in code where they can be
 * reasoned about with their evidence beside them. See infra/lib/audit_settings.php.
 *
 * ⚠ AND THE THING TO KEEP IN MIND WHILE EDITING: changing a threshold changes what a
 * number MEANS. Move "settling-in period" from 21 days to 40 and a third of the fleet
 * stops being counted as a problem without one site improving. That is why each audit
 * box prints the thresholds in force next to its verdict, and why this card says so
 * out loud rather than presenting itself as a neutral preferences screen.
 */

require_once __DIR__ . '/infra/lib/audit_settings.php';
$fas = infra_audit_settings();
$fasCsrf = $csrf ?? ($csrfToken ?? ($_SESSION['csrf_token'] ?? ''));
?>
<div id="fas" class="card" style="margin:0 0 16px;padding:12px 14px;border-left:3px solid #7c3aed">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <strong style="font-size:.92rem">Fleet audit settings</strong>
        <a href="#" onclick="fasInfo();return false;" title="What these change, and what deliberately is not here"
           style="text-decoration:none;color:#94a3b8;font-size:1.05rem">&#9432;</a>
        <span style="font-size:.8rem;color:#64748b">The judgement calls behind the boxes above. Thresholds only &mdash;
            the measurement rules stay in code.</span>
        <span id="fas-msg" style="font-size:.8rem;margin-left:auto;color:#16a34a"></span>
        <button type="button" class="btn-sm-outline" id="fas-save" onclick="fasSave()">Save</button>
    </div>

    <table style="width:100%;border-collapse:collapse;font-size:.82rem;margin-top:10px">
        <tbody>
        <?php
        /* label, key, type, suffix, affects, help */
        $fields = [
            ['Settling-in period', 'too_new_days', 'number', 'days', 'index · rank',
             'Under this age, "not indexed" and "not ranking" show as <em>too new to judge</em> rather than as failures. Measured on 2026-10-21: ~21-day sites were 26&ndash;81% indexed while 7-day sites had a single page each. Raising this hides more sites from the counts.'],
            ['Stale-crawl warning', 'stale_crawl_days', 'number', 'days', 'crawl',
             'How long Googlebot can stay away before the crawl box flags the site.'],
            ['Index drop needs', 'index_drop_sweeps', 'number', 'sweeps', 'index',
             'How many consecutive sweeps must agree before a fall is called a real loss. Google&rsquo;s <code>site:</code> count returned <strong>62, then 10, then nothing</strong> for one query ninety seconds apart, so 1 would alarm constantly on noise. Clamped to a minimum of 2.'],
            ['Rank keyword depth', 'rank_tiers', 'tier', '', 'rank',
             'How far down the keyword tiers a rank sweep pays for. The homepage term is always included. <strong>This drives the bill directly</strong>: high-1 is 567 keywords (~$6.18), high-2 roughly doubles it.'],
            ['Probe concurrency', 'probe_concurrency', 'number', 'parallel', 'index · rank',
             'How many DataForSEO probes run at once. More is not faster past a point &mdash; it throttles, and the retries cost the time back. 2 was no better than 4 in testing.'],
            ['Cost per probe', 'cost_per_probe', 'cost', 'USD', 'index · rank',
             'Used for the estimate and the confirmation prompt before a paid sweep. Measured 2026-10-21: the balance moved $70.3400 &rarr; $70.2206 over 11 probes. <strong>If this is wrong you are being misinformed about the spend</strong>, so re-measure rather than guess.'],
            ['Crawl log tail', 'crawl_tail_lines', 'number', 'lines', 'crawl',
             'Lines of access log pulled per domain. Bailey&rsquo;s entire log was 3,320 lines covering 3.5 days; a busier site holds far less, so a bigger tail is the safer error.'],
        ];
        foreach ($fields as [$label, $key, $type, $suffix, $affects, $help]): ?>
            <tr style="border-top:1px solid #f1f5f9">
                <td style="padding:7px 10px 7px 0;width:190px;vertical-align:top">
                    <strong><?= $label ?></strong><br>
                    <span style="color:#a78bfa;font-size:.72rem"><?= $affects ?></span>
                </td>
                <td style="padding:7px 10px 7px 0;width:150px;vertical-align:top;white-space:nowrap">
                    <?php if ($type === 'tier'): ?>
                        <select name="<?= $key ?>" class="fas-f" style="padding:4px 6px">
                            <?php foreach (['high-1' => 'high-1 only', 'high-2' => 'through high-2', 'high-3' => 'through high-3'] as $v => $t): ?>
                                <option value="<?= $v ?>" <?= $fas[$key] === $v ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'cost'): ?>
                        <input name="<?= $key ?>" class="fas-f" type="number" step="0.0001" min="0" max="1"
                               value="<?= h((string) $fas[$key]) ?>" style="width:90px;padding:4px 6px">
                        <span style="color:#94a3b8"><?= $suffix ?></span>
                    <?php else: ?>
                        <input name="<?= $key ?>" class="fas-f" type="number"
                               value="<?= (int) $fas[$key] ?>" style="width:80px;padding:4px 6px">
                        <span style="color:#94a3b8"><?= $suffix ?></span>
                    <?php endif; ?>
                </td>
                <td style="padding:7px 0;color:#64748b;line-height:1.5"><?= $help ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin:8px 0 0;font-size:.75rem;color:#94a3b8">
        Values are clamped on save and on every read, so a bad number cannot reach a sweep &mdash;
        out-of-range entries snap back to the nearest allowed value.
    </p>
</div>

<div id="fas-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;
     align-items:center;justify-content:center" onclick="fasInfoClose(event)">
    <div style="background:#fff;border-radius:8px;padding:18px 22px;max-width:720px;width:92%;max-height:84vh;
         overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2);line-height:1.6;font-size:.86rem;color:#334155">
        <h3 style="margin:0 0 10px;font-size:1.05rem;color:#1e3a5f">Fleet audit settings</h3>
        <p style="margin:0 0 14px">
            These are the <strong>judgement calls</strong> behind the audit boxes &mdash; thresholds whose right
            value is a matter of taste and of how mature the fleet is, not of fact.
        </p>
        <h4 style="margin:0 0 4px;font-size:.9rem">Changing a threshold changes what a number means</h4>
        <p style="margin:0 0 14px">
            Raise the settling-in period from 21 to 40 days and a third of the fleet stops counting as a problem
            without one site having improved. Lengthen the stale-crawl warning and the crawl box goes green while
            Google stays away just as long. That is not a reason to avoid tuning them &mdash; it is a reason the
            boxes print the thresholds in force beside their verdicts, so a green light can always be read
            together with the bar it had to clear.
        </p>
        <h4 style="margin:0 0 4px;font-size:.9rem">What is deliberately not here</h4>
        <ul style="margin:0 0 14px 20px">
            <li><strong>Googlebot IP verification.</strong> 30 of 78 requests claiming to be Googlebot on one site
                were impostors probing for <code>.env</code> files. Trusting the user-agent is not a setting.</li>
            <li><strong>&minus;1 is not 0.</strong> &ldquo;Could not measure&rdquo; and &ldquo;measured zero&rdquo;
                stay separate everywhere, and nothing can switch that off.</li>
            <li><strong>Rank depth 100.</strong> A site at #72 would read as &ldquo;not ranking&rdquo; at depth 20.</li>
            <li><strong>Retry and backoff counts</strong>, and the crawl cron against the ~3.5-day log window &mdash;
                that one is a hard constraint, not a preference.</li>
        </ul>
        <p style="margin:0 0 14px;color:#64748b">
            Stored in <code>admin/infra/config/audit_settings.json</code>, clamped on every read, so a hand-edited
            file cannot push a sweep outside its safe range either.
        </p>
        <div style="text-align:right"><button type="button" class="btn-sm-outline" onclick="fasInfoClose()">Close</button></div>
    </div>
</div>

<script>
var FAS_CSRF = <?= json_encode($fasCsrf) ?>;
function fasInfo() { document.getElementById('fas-modal-bg').style.display = 'flex'; }
function fasInfoClose(e) {
    if (e && e.type === 'click' && e.target && e.target.id !== 'fas-modal-bg') return;
    document.getElementById('fas-modal-bg').style.display = 'none';
}
function fasSave() {
    var btn = document.getElementById('fas-save'), msg = document.getElementById('fas-msg');
    btn.disabled = true; msg.style.color = '#64748b'; msg.textContent = 'Saving…';
    var fd = new FormData();
    fd.append('csrf_token', FAS_CSRF);
    document.querySelectorAll('.fas-f').forEach(function (el) { fd.append(el.name, el.value); });
    fetch('fleet_audit_api.php?action=settings_save', {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.error) { msg.style.color = '#dc2626'; msg.textContent = d.error; return; }
            // Write the CLAMPED values back into the fields. Showing what was typed
            // when the stored value differs would be the form quietly lying about
            // what the sweeps will actually use.
            document.querySelectorAll('.fas-f').forEach(function (el) {
                if (d.saved && d.saved[el.name] !== undefined) el.value = d.saved[el.name];
            });
            msg.style.color = '#16a34a'; msg.textContent = 'Saved';
            setTimeout(function () { msg.textContent = ''; }, 2500);
        })
        .catch(function (e) { btn.disabled = false; msg.style.color = '#dc2626'; msg.textContent = 'Failed: ' + e; });
}
</script>
