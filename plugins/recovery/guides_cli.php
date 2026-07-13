<?php
/**
 * Recovery plugin — guides hub generator (CLI, background).
 *
 *   MULTISITE_SITE_BASE=/var/www/homepage-builder-new/sites/recovery-site \
 *   php plugins/recovery/guides_cli.php [--refresh]
 *
 * AI-drafts informational articles (grounded by the niche brief, YMYL-guarded) and
 * stores them as published blog posts in site.json → the /blog guides hub. Adds a fixed
 * real-sources block (no fabricated URLs) + an internal CTA to /insurance/. Idempotent.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';
if (ACTIVE_SITE_ID !== 'recovery-site') { fwrite(STDERR, "Set MULTISITE_SITE_BASE.\n"); exit(2); }
require_once __DIR__ . '/enrich.php';

$refresh = in_array('--refresh', $argv, true);
$file = ACTIVE_SITE_DIR . '/data/site.json';
$log  = fopen('/tmp/guides.log', 'w');

$topics = [
    ['does-insurance-cover-rehab',              'Does Health Insurance Cover Rehab?',                                  'Coverage',  'whether and how health insurance covers drug and alcohol rehab'],
    ['how-to-verify-rehab-benefits',            'How to Verify Your Insurance Benefits for Rehab',                     'Coverage',  'how to check, step by step, what a health plan covers for addiction treatment'],
    ['levels-of-care-explained',                'Inpatient vs. Outpatient vs. PHP vs. IOP: Levels of Care Explained',  'Treatment', 'the levels of addiction treatment care (detox, inpatient/residential, PHP, IOP, outpatient) and how they differ'],
    ['mental-health-parity-law',                'What Is the Mental Health Parity Law?',                               'Coverage',  'the Mental Health Parity and Addiction Equity Act and what it means for coverage'],
    ['how-much-does-rehab-cost-with-insurance', 'How Much Does Rehab Cost With Insurance?',                            'Costs',     'what affects out-of-pocket rehab costs with insurance (deductibles, copays, coinsurance, network)'],
    ['does-insurance-cover-detox',              'Does Insurance Cover Detox?',                                         'Treatment', 'insurance coverage for medically supervised detox'],
    ['in-network-vs-out-of-network-rehab',      'In-Network vs. Out-of-Network Rehab: What It Means for You',          'Coverage',  'the difference between in-network and out-of-network treatment and how it affects cost'],
    ['does-medicaid-cover-addiction-treatment', 'Does Medicaid Cover Addiction Treatment?',                            'Coverage',  'Medicaid coverage for addiction and substance-use treatment'],
    ['medication-assisted-treatment-coverage',  'What Is Medication-Assisted Treatment (MAT) and Is It Covered?',      'Treatment', 'what medication-assisted treatment (MAT) is and how insurance typically covers it'],
    ['how-to-pay-for-rehab-without-insurance',  'How to Pay for Rehab Without Insurance',                              'Costs',     'options for paying for treatment without insurance (Medicaid, sliding scale, financing, free/state programs)'],
];

$SOURCES = '<p>For authoritative information and free help, see:</p><ul>'
    . '<li><a href="https://findtreatment.gov" target="_blank" rel="noopener nofollow">FindTreatment.gov</a> — SAMHSA’s national treatment locator</li>'
    . '<li><a href="https://www.samhsa.gov/find-help/national-helpline" target="_blank" rel="noopener nofollow">SAMHSA National Helpline</a> — 1-800-662-4357, free and confidential, 24/7</li>'
    . '<li><a href="https://www.dol.gov/agencies/ebsa/laws-and-regulations/laws/mental-health-parity" target="_blank" rel="noopener nofollow">Mental Health Parity and Addiction Equity Act</a> (U.S. Dept. of Labor / HHS)</li>'
    . '<li><a href="https://988lifeline.org" target="_blank" rel="noopener nofollow">988 Suicide &amp; Crisis Lifeline</a></li>'
    . '</ul><p><em>{business} is a free informational and referral service, not a treatment provider or insurer. Coverage varies by plan — always verify your own benefits.</em></p>';

function guide_prompt($title, $focus) {
    return recovery_ai_brief() . "\n\n"
        . "Write a helpful, accurate informational article for {business}'s free resource hub, titled \"$title\".\n"
        . "The reader wants to understand $focus. Plain language, compassionate, genuinely useful.\n"
        . "Do NOT invent statistics, prices, facility names, or success rates. NEVER state any specific dollar amounts or specific percentages (no copay, coinsurance, or deductible figures like \$25 or 20%) — describe what affects cost qualitatively instead. Explain general patterns and tell readers to verify their own benefits. Mention 988 where relevant. No coverage or admission guarantees.\n\n"
        . "Respond with ONLY a JSON object:\n"
        . "{\n  \"excerpt\": \"1-2 sentence summary (~30 words)\",\n"
        . "  \"intro_html\": \"1-2 paragraphs wrapped in <p></p>\",\n"
        . "  \"sections\": [ {\"heading\":\"short H2\", \"html\":\"1-3 <p> paragraphs; a <ul> list is fine\"}, (4 to 5 sections) ],\n"
        . "  \"faq\": [ {\"q\":\"question\", \"a\":\"1-3 sentence answer\"}, (3 items) ]\n}";
}

$d = json_decode(file_get_contents($file), true);
if (!isset($d['posts']) || !is_array($d['posts'])) $d['posts'] = [];
$ok = 0; $fail = 0;
foreach ($topics as [$slug, $title, $tag, $focus]) {
    $pid = 'post_' . str_replace('-', '', $slug);
    if (!$refresh && isset($d['posts'][$pid])) continue;
    $ai = null;
    for ($t = 0; $t < 4 && !$ai; $t++) {
        $txt = recovery_ai_call(guide_prompt($title, $focus), 2000);
        if ($txt === '__RATE__') { sleep(20 * ($t + 1)); continue; }
        if ($txt === null) { sleep(3); continue; }
        $ai = recovery_ai_json($txt);
    }
    if (!$ai || empty($ai['intro_html']) || empty($ai['sections'])) { $fail++; fwrite($log, "FAIL $slug\n"); fflush($log); continue; }

    $blocks = [['type'=>'text','heading_level'=>'p','text'=>$ai['intro_html'],'photo'=>'','photo_ratio'=>'landscape','photo_position'=>'center','photo_alt'=>'']];
    foreach ($ai['sections'] as $s) {
        if (empty($s['heading']) || empty($s['html'])) continue;
        $blocks[] = ['type'=>'text','heading_level'=>'h2','heading_text'=>$s['heading'],'text'=>$s['html'],'photo'=>'','photo_ratio'=>'landscape','photo_position'=>'center','photo_alt'=>''];
    }
    $items = [];
    foreach (($ai['faq'] ?? []) as $q) if (!empty($q['q']) && !empty($q['a'])) $items[] = ['question'=>$q['q'],'answer'=>$q['a']];
    if ($items) $blocks[] = ['type'=>'faq_two_col','fq_heading'=>'Frequently Asked Questions','fq_bg_color'=>'#ffffff','fq_item_bg'=>'#f1f5f9','fq_head_color'=>'header','fq_icon_bg'=>'accent','fq_items'=>$items];
    $blocks[] = ['type'=>'cta_banner','anchor'=>'closing_cta','cb_text'=>'Not sure what your plan covers? Get a free, confidential benefits check.','cb_subtext'=>'','cb_btn_text'=>'Check Your Coverage','cb_btn_url'=>'/insurance/','cb_bg'=>'header','cb_text_color'=>'#ffffff','cb_padding'=>'normal'];
    $blocks[] = ['type'=>'text','heading_level'=>'h2','heading_text'=>'Sources & Help','text'=>$GLOBALS['SOURCES'],'photo'=>'','photo_ratio'=>'landscape','photo_position'=>'center','photo_alt'=>''];

    $d['posts'][$pid] = [
        'title'=>$title,'slug'=>$slug,'status'=>'published','published_at'=>date('Y-m-d'),'updated_at'=>date('Y-m-d'),
        'author'=>'{business} Editorial Team','tag'=>$tag,'excerpt'=>$ai['excerpt'] ?? '','featured_image'=>'','featured_image_alt'=>'',
        'content_blocks'=>$blocks,
        'seo'=>['seo_title'=>"$title | {business}",'meta_description'=>$ai['excerpt'] ?? '','primary_keyword'=>$title,'secondary_keywords'=>'','meta_keywords'=>'','og_title'=>'','og_description'=>'','og_image'=>'','schema'=>'','canonical_url'=>'','service_name'=>'','service_type'=>'','service_area'=>'','service_description'=>'','bc_label'=>'','bc_mid_label'=>'Guides','bc_mid_url'=>'/blog'],
    ];
    $ok++;
    file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fwrite($log, "OK   $slug\n"); fflush($log);
    usleep(700000);
}
exec('chown -R www-data:www-data ' . escapeshellarg(ACTIVE_SITE_DIR . '/data'));
fwrite($log, "\nDONE ok=$ok fail=$fail\n"); fclose($log);
