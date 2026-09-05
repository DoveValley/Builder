<?php
/**
 * "How this batch generates sites" — the Variant Engine's own explainer, same convention as
 * _batch_explainer.php's six-phase table. Shown in place of the plain "4. Generate sites"
 * card's own text for a variant_engine-flagged master, so every button's job is stated before
 * it's clicked, not left to be discovered by clicking it.
 *
 * Expects: nothing beyond global page vars — purely explanatory, no state read.
 */
?>
<details class="card" open style="background:#f8fafc;border-left:3px solid #0ea5a4;margin-bottom:16px;">
    <summary style="cursor:pointer;font-weight:700;font-size:1.02rem;color:#1e3a5f;">
        How this batch generates sites &mdash; five steps, replacing plain "Generate sites"
    </summary>
    <p class="hint" style="margin:12px 0 4px;">
        This master generates genuinely different sites per row &mdash; different architecture,
        colors, fonts, and voice &mdash; instead of one shared template with different names
        swapped in. That means "Generate sites" becomes five steps instead of one click.
    </p>
    <table class="be-table" style="margin-top:10px;width:100%;border-collapse:collapse;font-size:.88rem;">
        <thead>
            <tr>
                <th style="width:16%;text-align:left;padding:7px 10px;border-bottom:1px solid #e2e8f0;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;">Step</th>
                <th style="width:22%;text-align:left;padding:7px 10px;border-bottom:1px solid #e2e8f0;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;">Click this button</th>
                <th style="width:37%;text-align:left;padding:7px 10px;border-bottom:1px solid #e2e8f0;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;">What it does</th>
                <th style="width:25%;text-align:left;padding:7px 10px;border-bottom:1px solid #e2e8f0;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;">Runs where</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;font-weight:700;">4a &middot; Propose variants</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;"><span style="display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:4px;padding:2px 7px;font-size:.78rem;font-weight:700;">Propose variants</span></td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;color:#475569;">Picks one architecture, type, color, title pattern, research prompt, and voice per row &mdash; spread as evenly as possible across whatever options exist on disk right now. Nothing is generated yet.</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;">This page. Reads the <code>variants/</code> library plus this niche's own voice/research-prompt folders; writes a proposal only.</td>
            </tr>
            <tr>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;font-weight:700;">4b &middot; Review &amp; approve</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;"><span style="display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:4px;padding:2px 7px;font-size:.78rem;font-weight:700;">Reroll</span> per cell / <span style="display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:4px;padding:2px 7px;font-size:.78rem;font-weight:700;">Approve plan</span></td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;color:#475569;">Shows exactly what got picked for every row. Reroll swaps ONE row's ONE dimension to a different option and locks it in &mdash; nothing else in the batch moves. Approve unlocks the next step.</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;">This page. No content is generated until you approve.</td>
            </tr>
            <tr>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;font-weight:700;">4c &middot; Generate content</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;"><span style="display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:4px;padding:2px 7px;font-size:.78rem;font-weight:700;">Generate content</span></td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;color:#475569;">Researches each city with real, cited sources kept internally, then writes hero copy, services, FAQs, guide pages, and legal pages for every approved row. Builds nothing yet &mdash; no images, no HTML.</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;">This page's content pipeline (new Python scripts under <code>variants/pipeline/</code>) &mdash; files only.</td>
            </tr>
            <tr>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;font-weight:700;">4d &middot; Review content</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;"><span style="display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:4px;padding:2px 7px;font-size:.78rem;font-weight:700;">Approve for build</span> per row</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;color:#475569;">Shows the actual generated words for every row, plus a flag if a legal page's required disclosures didn't clearly survive the rewrite. Nothing builds until you approve a row here.</td>
                <td style="padding:11px 10px;border-bottom:1px solid #f1f5f9;color:#64748b;">This page.</td>
            </tr>
            <tr>
                <td style="padding:11px 10px;font-weight:700;">4e &middot; Build</td>
                <td style="padding:11px 10px;"><span style="display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:4px;padding:2px 7px;font-size:.78rem;font-weight:700;">Build</span></td>
                <td style="padding:11px 10px;color:#475569;">Turns approved rows' content into finished static pages using each row's assigned architecture/colors/fonts, runs the image pipeline, and runs a quick SEO check.</td>
                <td style="padding:11px 10px;color:#64748b;">This page's content pipeline &mdash; still just files, same as the plain "Generate sites" step it replaces.</td>
            </tr>
        </tbody>
    </table>
    <p class="hint" style="margin:14px 0 0;">
        Phases 1, 2, 3, 5, and 6 on this page are completely unchanged &mdash; only phase 4 is
        replaced by the five steps above.
    </p>
</details>
