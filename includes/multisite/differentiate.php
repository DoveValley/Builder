<?php
/**
 * Per-site differentiation (Phase 5, §11).
 *
 * Runs after identity injection, on the ephemeral working site. Makes each output
 * a genuinely distinct entity rather than a Granite clone with Granite's schema:
 *
 *   Tier 2 (structured data / identity):
 *     - rewrite the master's identity (business name, domain/URL, tel, phone,
 *       email) to this site's everywhere in the JSON — including the JSON-LD
 *       schema, which otherwise keeps pointing @id/url at the master's domain
 *     - strip fabricated aggregateRating from schema (carrying the master's rating
 *       to every site invents reviews — never do that, §9)
 *     - inject geo (lat/lng) + NAP into local_business for a distinct LocalBusiness
 *   Tier 4 (technical footprint):
 *     - per-site analytics (theme.analytics_head) from analytics_id, or none —
 *       never share one tag across sites
 *
 * $masterIdentity is the master's own site_vars captured BEFORE injection
 * (business, website, tel, phone, email) — the "from" side of the rewrite.
 */

/**
 * Recursively rewrite master identity → this site's in every string value.
 *
 * Each $rule is [regex, replacement]. Unlike a raw strtr (which mangles any value that
 * merely CONTAINS an identity substring — a phone inside a longer number, a business
 * name inside a word), these patterns are boundary-anchored per identity type:
 *   - phone/tel: not flanked by digits           (?<!\d)…(?!\d)
 *   - business:  word boundaries, case-insensitive
 *   - url/email: not flanked by domain/email chars
 * The replacement is applied literally (callback) so a `$`/`\` in a business name or
 * domain can't be mis-read as a regex backreference.
 */
function ms_deep_replace($val, array $rules) {
    if (is_array($val)) { foreach ($val as $k => $v) $val[$k] = ms_deep_replace($v, $rules); return $val; }
    if (is_string($val) && $val !== '') {
        foreach ($rules as [$pat, $rep]) {
            $val = preg_replace_callback($pat, static fn() => $rep, $val);
        }
    }
    return $val;
}

/** Build boundary-anchored [regex, replacement] rules for the master→site identity rewrite. */
function ms_build_identity_rules(array $masterIdentity, array $params, string $website, string $domain): array {
    $rules = [];
    $q = static fn(string $s): string => preg_quote($s, '/');

    // URL (with scheme) before bare domain so the longer match wins.
    $mWebsite = rtrim($masterIdentity['website'] ?? '', '/');
    if ($mWebsite !== '' && $website !== '') {
        $rules[] = ['/(?<![\w.\-])' . $q($mWebsite) . '(?![\w\-])/i', $website];
        $mDomain = preg_replace('#^https?://#i', '', $mWebsite);
        if ($mDomain !== '' && $domain !== '') {
            // Left: exclude word chars/@/- so we never rewrite a longer label ("notkaty.com")
            // or an email host, but ALLOW a leading '.' so real subdomains still rewrite.
            $rules[] = ['/(?<![\w@\-])' . $q($mDomain) . '(?![\w\-])/i', $domain];
        }
    }
    // Email — flanked by non-email characters.
    $mEmail = $masterIdentity['email'] ?? ''; $toEmail = $params['email'] ?? '';
    if ($mEmail !== '' && $toEmail !== '' && $mEmail !== $toEmail) {
        $rules[] = ['/(?<![\w.+\-])' . $q($mEmail) . '(?![\w.+\-])/i', $toEmail];
    }
    // tel (E.164) then display phone — not part of a longer number.
    $mTel = $masterIdentity['tel'] ?? ''; $toTel = $params['tel'] ?? '';
    if ($mTel !== '' && $toTel !== '' && $mTel !== $toTel) {
        $rules[] = ['/(?<![\d+])' . $q($mTel) . '(?!\d)/', $toTel];
    }
    $mPhone = $masterIdentity['phone'] ?? ''; $toPhone = $params['phone'] ?? '';
    if ($mPhone !== '' && $toPhone !== '' && $mPhone !== $toPhone) {
        $rules[] = ['/(?<!\d)' . $q($mPhone) . '(?!\d)/', $toPhone];
    }
    // Business name — word-boundary, case-insensitive (one rule covers UPPER/lower/Title
    // mentions); replaced with the canonical new name.
    $mBiz = $masterIdentity['business'] ?? ''; $toBiz = $params['business'] ?? '';
    if ($mBiz !== '' && $toBiz !== '' && $mBiz !== $toBiz) {
        $rules[] = ['/(?<![\w])' . $q($mBiz) . '(?![\w])/iu', $toBiz];
    }
    return $rules;
}

/** Recursively remove a key wherever it appears. */
function ms_strip_key($val, string $key) {
    if (!is_array($val)) return $val;
    unset($val[$key]);
    foreach ($val as $k => $v) $val[$k] = ms_strip_key($v, $key);
    return $val;
}

/** Strip a key inside a JSON string field (e.g. seo.schema JSON-LD); returns rewritten JSON or original. */
function ms_strip_key_in_json_field(string $json, string $key): string {
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return $json;
    $decoded = ms_strip_key($decoded, $key);
    $out = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $out === false ? $json : $out;
}

/** GA4 gtag.js snippet for a measurement id. */
function ms_ga4_snippet(string $id): string {
    $id = htmlspecialchars($id, ENT_QUOTES);
    return "<!-- Google tag (gtag.js) -->\n"
         . "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>\n"
         . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
         . "gtag('js',new Date());gtag('config','{$id}');</script>";
}

/** Google Search Console verification <meta> tag for a token, or '' if blank. */
function ms_gsc_meta(string $token): string {
    $token = trim($token);
    return $token === '' ? '' : '<meta name="google-site-verification" content="' . htmlspecialchars($token, ENT_QUOTES) . '">';
}

function ms_differentiate_working_dir(string $workingDir, array $params, array $masterIdentity): void {
    $sf = $workingDir . '/data/site.json';
    if (!file_exists($sf)) return;
    $data = json_decode(file_get_contents($sf), true);
    if (!is_array($data)) return;

    $domain  = preg_replace('#^https?://#i', '', rtrim($params['domain'] ?? '', '/'));
    $website = $domain !== '' ? 'https://' . $domain : '';

    // ── 1. Rewrite master identity → this site's, everywhere (boundary-anchored) ─
    // (Distinct brand *phrasings* — e.g. "Granite PM Training" — and the logo file are
    //  master-authoring / Tier-3 concerns, not identity-string rewrites.)
    $rules = ms_build_identity_rules($masterIdentity, $params, $website, $domain);
    if ($rules) $data = ms_deep_replace($data, $rules);

    // ── 2. Strip fabricated aggregateRating from all rendered schema (seo.schema) ─
    $stripSchema = function (array &$seo) {
        if (!empty($seo['schema']) && is_string($seo['schema'])) {
            $seo['schema'] = ms_strip_key_in_json_field($seo['schema'], 'aggregateRating');
        }
    };
    if (isset($data['seo'])) $stripSchema($data['seo']);
    foreach (($data['pages'] ?? []) as &$pg) { if (isset($pg['seo'])) $stripSchema($pg['seo']); }
    unset($pg);

    // ── 3. LocalBusiness: geo + NAP; clear fabricated rating (never invent) ───
    $lb = $data['local_business'] ?? [];
    if (!empty($params['business'])) $lb['lb_name'] = $params['business'];
    if ($website !== '')             $lb['lb_url']  = $website;
    foreach ([['lat','lb_lat'], ['lng','lb_lng'], ['address','lb_address'], ['city','lb_city'], ['SS','lb_state'], ['zip','lb_zip'], ['phone','lb_phone']] as [$pk, $lk]) {
        if (($params[$pk] ?? '') !== '') $lb[$lk] = $params[$pk];
    }
    $lb['lb_rating'] = $params['rating'] ?? '';           // blank unless the row supplies a real one
    $lb['lb_review_count'] = $params['review_count'] ?? '';
    $data['local_business'] = $lb;

    // geo also into site_vars so {lat}/{lng} shortcodes resolve
    if (($params['lat'] ?? '') !== '') $data['site_vars']['lat'] = $params['lat'];
    if (($params['lng'] ?? '') !== '') $data['site_vars']['lng'] = $params['lng'];

    // ── 3b. Emit a real LocalBusiness JSON-LD node (the Tier-2 distinct-entity signal) ──
    // The master schema has no such node. Inject one whenever the row supplies real
    // local data — geo, a street address, or a rating. Each part is included only if
    // present; the rating is never fabricated (both rating + review_count required).
    $addr = [];
    foreach ([['address','streetAddress'], ['city','addressLocality'], ['SS','addressRegion'], ['zip','postalCode']] as [$pk, $ak]) {
        if (($params[$pk] ?? '') !== '') $addr[$ak] = $params[$pk];
    }
    $hasGeo    = ($params['lat'] ?? '') !== '' && ($params['lng'] ?? '') !== '';
    $hasRating = ($params['rating'] ?? '') !== '' && ($params['review_count'] ?? '') !== '';
    if ($website !== '' && ($hasGeo || $addr || $hasRating)) {
        $lbNode = ['@type' => 'LocalBusiness', '@id' => $website . '/#localbusiness',
                   'name' => $params['business'] ?? '', 'url' => $website];
        if (($params['tel'] ?? '') !== '') $lbNode['telephone'] = $params['tel'];
        if ($addr)    $lbNode['address'] = array_merge(['@type' => 'PostalAddress'], $addr);
        if ($hasGeo)  $lbNode['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $params['lat'], 'longitude' => $params['lng']];
        if ($hasRating) $lbNode['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => (string)$params['rating'],
            'reviewCount' => (string)$params['review_count'],
            'bestRating'  => '5', 'worstRating' => '1',
        ];

        $schema = json_decode($data['seo']['schema'] ?? '', true);
        if (is_array($schema) && isset($schema['@graph']) && is_array($schema['@graph'])) {
            $schema['@graph'][] = $lbNode;
        } elseif (is_array($schema)) {
            $schema = ['@context' => 'https://schema.org', '@graph' => [$schema, $lbNode]];
        } else {
            $schema = ['@context' => 'https://schema.org', '@graph' => [$lbNode]];
        }
        $data['seo']['schema'] = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ── 4. Analytics isolation — per-site tag or none (never shared) ──────────
    $aid = trim($params['analytics_id'] ?? '');
    $data['theme']['analytics_head'] = $aid !== '' ? ms_ga4_snippet($aid) : '';

    // ── 4b. Search Console verification — per-site meta tag or none ────────────
    $data['theme']['head_extra'] = ms_gsc_meta($params['gsc_verification'] ?? '');

    $tmp = $sf . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $sf);
}
