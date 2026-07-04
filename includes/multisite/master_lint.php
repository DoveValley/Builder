<?php
/**
 * Master pre-flight lint (multisite item 1f, as a guardrail rather than a manual pass).
 *
 * Flags authoring mistakes that WON'T localize on a clone:
 *   - the master's own city / state / SS / zip typed as LITERAL text (should be
 *     {city} / {state} / {SS} / {zip} shortcodes), and
 *   - external URLs pointing at the master's own domain with a path (the domain is
 *     rewritten per clone but the path breaks — e.g. a logo at the master domain).
 *
 * It deliberately does NOT flag things the pipeline auto-handles: site_vars and
 * local_business (overwritten by inject/differentiate), the business name and
 * phone/website/domain/email (rewritten at clone time), or image filenames
 * (renamed by the 4c image pass). Advisory only — a human reviews each hit, since
 * some are legitimate (e.g. a governing-law clause that really is the home state).
 */

function ms_lint_master(string $masterId): array {
    $dir  = BASE_DIR . '/sites/' . $masterId;
    $site = json_decode((string)@file_get_contents($dir . '/data/site.json'), true);
    if (!is_array($site)) return ['master' => $masterId, 'error' => "No site.json for {$masterId}", 'findings' => []];

    $v = $site['site_vars'] ?? [];
    $city = trim($v['city'] ?? ''); $state = trim($v['state'] ?? ''); $ss = trim($v['SS'] ?? '');
    $zip  = trim($v['zip'] ?? '');  $biz   = trim($v['business'] ?? '');
    $domain = preg_replace('#^https?://#i', '', rtrim($v['website'] ?? '', '/'));

    // Geo needles. City/state case-insensitive; SS uppercase-only (a case-insensitive
    // 2-letter match is too noisy); zip digit-bounded.
    $geo = [];
    if ($city !== '')  $geo[] = ['re' => '/(?<![\w])' . preg_quote($city, '/')  . '(?![\w])/iu', 'lit' => $city,  'fix' => '{city}'];
    if ($state !== '') $geo[] = ['re' => '/(?<![\w])' . preg_quote($state, '/') . '(?![\w])/iu', 'lit' => $state, 'fix' => '{state}'];
    if ($ss !== '')    $geo[] = ['re' => '/(?<![\w])' . preg_quote($ss, '/')    . '(?![\w])/u',  'lit' => $ss,    'fix' => '{SS}'];
    if ($zip !== '')   $geo[] = ['re' => '/(?<!\d)'   . preg_quote($zip, '/')   . '(?!\d)/',     'lit' => $zip,   'fix' => '{zip}'];

    // Scan the AUTHORED sources only: site.json (home + core pages + posts) and
    // templates.json (landing templates). data/pages/*.json are regenerated outputs
    // (their city_vars / resolved slugs are rebuilt per deploy) — scanning them is
    // just noise, so they're excluded.
    $files = ['site.json' => $dir . '/data/site.json'];
    if (is_file($dir . '/data/templates.json')) $files['templates.json'] = $dir . '/data/templates.json';

    $findings = [];
    foreach ($files as $label => $file) {
        $data = json_decode((string)@file_get_contents($file), true);
        if (is_array($data)) _ms_lint_walk($data, '', $label, $geo, $biz, $domain, $findings);
    }
    return [
        'master'   => $masterId,
        'identity' => compact('city', 'state', 'ss', 'zip', 'domain', 'biz'),
        'findings' => $findings,
    ];
}

function _ms_lint_walk($node, string $path, string $file, array $geo, string $biz, string $domain, array &$out): void {
    if (!is_array($node)) return;
    static $skipTop = ['site_vars' => 1, 'local_business' => 1];   // auto-overwritten at clone time
    foreach ($node as $k => $val) {
        $p = $path === '' ? (string)$k : $path . '.' . $k;
        if (is_array($val)) { _ms_lint_walk($val, $p, $file, $geo, $biz, $domain, $out); continue; }
        if (!is_string($val) || $val === '') continue;
        if (isset($skipTop[explode('.', $p)[0]])) continue;

        // external master-domain URL with a path (rewrite fixes the domain, not the path)
        if ($domain !== '' && preg_match('#https?://' . preg_quote($domain, '#') . '/\S+#i', $val)) {
            $out[] = ['file' => $file, 'path' => $p, 'type' => 'master-url', 'lit' => $domain,
                      'fix' => 'point at an uploads/ asset (not the master domain)', 'excerpt' => _ms_lint_excerpt($val)];
        }

        // geo literals — strip upload paths + the business name first (both auto-handled)
        $test = preg_replace('#uploads/[^\s"\'<>]+#i', ' ', $val);
        if ($biz !== '') $test = str_ireplace($biz, ' ', $test);
        foreach ($geo as $g) {
            if (preg_match($g['re'], $test)) {
                $out[] = ['file' => $file, 'path' => $p, 'type' => 'geo', 'lit' => $g['lit'],
                          'fix' => $g['fix'], 'excerpt' => _ms_lint_excerpt($val)];
            }
        }
    }
}

function _ms_lint_excerpt(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)));
    return mb_substr($s, 0, 100);
}
