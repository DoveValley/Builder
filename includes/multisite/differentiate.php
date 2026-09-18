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
     - declare areaServed (City in State) so service-area businesses with no
       storefront still signal their market when the street address is blank
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

/**
 * @param bool $skipTags Skip the analytics + Search Console tags only.
 *
 * Nothing else here is optional, and deliberately so. The identity rules above rewrite
 * the MASTER's own website, domain, email, phone and business name out of every clone
 * — skip that and each generated site ships carrying the master's brand and contact
 * details in its copy. Clearing a fabricated rating is the same kind of guard. Those
 * are safety nets, not features, so they have no switch.
 */
/**
 * $structureSkip: ['home'=>bool,'landing'=>bool] — the section-order on/off switches from the
 * batch card. Absent/empty means both vary, which is the old behaviour.
 *
 * $rotation: ['home_top'=>int,'home_bottom'=>int,'landing_top'=>int,'landing_bottom'=>int] —
 * how many leading/trailing blocks stay fixed before the domain-hash rotation picks an ordering
 * for whatever's left (see layout_rotate_blocks()). Missing keys default to 1/1 — the hero stays
 * first, the closing block stays last — the same pin behaviour this mechanism always had.
 *
 * Only two scopes: the homepage's own blocks, and each landing page. Privacy/Terms/disclaimer/
 * Contact Us are never in scope — they already get their own per-domain wording variance (see
 * 'ai.legal_reword') and, since the 2026-09 merge to 2 blocks, are too short to have a movable
 * middle anyway. About Us (the only other page under data['pages']) is a single AI-generated
 * block with nothing to rotate either, so "core pages" were dropped from scope entirely rather
 * than kept as a bucket with nothing real in it.
 */
function ms_differentiate_working_dir(string $workingDir, array $params, array $masterIdentity, bool $skipTags = false, array $structureSkip = [], array $rotation = []): void {
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

    // ── 3b. Enrich the business's JSON-LD node with real per-domain geo/address/rating
    //       (the Tier-2 distinct-entity signal) ──────────────────────────────────
    // The master schema's own #localbusiness node has no geo/address/rating. Merge
    // real local data into it whenever the row supplies some — geo, a street
    // address, or a rating — never fabricated (rating requires both rating +
    // review_count). This used to APPEND a second node at the same @id instead of
    // merging into the existing one — two entities sharing one @id, which is what
    // broke the schema every real multisite domain shipped (found via a real
    // review of baileyrestoration.com's live schema, not a hypothetical).
    $addr = [];
    foreach ([['address','streetAddress'], ['city','addressLocality'], ['SS','addressRegion'], ['zip','postalCode']] as [$pk, $ak]) {
        if (($params[$pk] ?? '') !== '') $addr[$ak] = $params[$pk];
    }
    $hasGeo    = ($params['lat'] ?? '') !== '' && ($params['lng'] ?? '') !== '';
    $hasRating = ($params['rating'] ?? '') !== '' && ($params['review_count'] ?? '') !== '';
    if ($website !== '' && ($hasGeo || $addr || $hasRating)) {
        // Deliberately no '@type' here — an Organization (a referral/advertising
        // network, not the crew doing the work) merges into whatever type the
        // master's own node already declares rather than asserting a possibly
        // conflicting one of its own.
        $lbFields = ['@id' => $website . '/#localbusiness',
                     'name' => $params['business'] ?? '', 'url' => $website];
        if (($params['tel'] ?? '') !== '') $lbFields['telephone'] = $params['tel'];
        if ($addr)    $lbFields['address'] = array_merge(['@type' => 'PostalAddress'], $addr);
        if ($hasGeo)  $lbFields['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $params['lat'], 'longitude' => $params['lng']];
        // areaServed — the "we serve this area" signal for service-area businesses with
        // no storefront (leave the street address blank; this still declares the market).
        if (($params['city'] ?? '') !== '') {
            $area = ['@type' => 'City', 'name' => $params['city']];
            if (($params['SS'] ?? '') !== '') $area['containedInPlace'] = ['@type' => 'AdministrativeArea', 'name' => $params['SS']];
            $lbFields['areaServed'] = $area;
        }
        if ($hasRating) $lbFields['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => (string)$params['rating'],
            'reviewCount' => (string)$params['review_count'],
            'bestRating'  => '5', 'worstRating' => '1',
        ];

        // Match by the "#localbusiness" @id SUFFIX, the convention every schema prompt in
        // this codebase follows (see schema_apply_sameas() in includes/schema.php) — NOT
        // an exact string match against $lbFields['@id']. The master's own node still
        // carries the unresolved "{website}" shortcode at this pipeline stage (shortcodes
        // resolve later, at render time), so comparing against the already-resolved
        // $website URL here would never match, and silently fall through to appending a
        // second node again — confirmed by actually running this against a real row
        // before trusting the fix, not just reading the code.
        $isLbNode = fn($n) => is_array($n) && str_ends_with((string) ($n['@id'] ?? ''), '#localbusiness');
        $schema = json_decode($data['seo']['schema'] ?? '', true);
        if (is_array($schema) && isset($schema['@graph']) && is_array($schema['@graph'])) {
            $merged = false;
            foreach ($schema['@graph'] as &$node) {
                if ($isLbNode($node)) {
                    $node = array_merge($node, $lbFields);   // same entity, richer data — not a second one
                    $merged = true;
                    break;
                }
            }
            unset($node);
            if (!$merged) $schema['@graph'][] = array_merge(['@type' => 'Organization'], $lbFields);
        } elseif (is_array($schema) && $isLbNode($schema)) {
            $schema = array_merge($schema, $lbFields);
        } elseif (is_array($schema)) {
            $schema = ['@context' => 'https://schema.org',
                       '@graph' => [$schema, array_merge(['@type' => 'Organization'], $lbFields)]];
        } else {
            $schema = ['@context' => 'https://schema.org',
                       '@graph' => [array_merge(['@type' => 'Organization'], $lbFields)]];
        }
        $data['seo']['schema'] = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ── 4. Analytics isolation — per-site tag or none (never shared) ──────────
    // Only the real per-site TAGS are cleared when skipped, not left alone: the clone
    // inherits the master's tags, so "leave it as it was" would send this site's traffic
    // to the master's property — the one thing per-site isolation exists to prevent.
    // theme.head_extra is NOT a tracking tag — it's the site's own free-text "Custom head
    // code" field (arbitrary CSS/HTML authored per-site in the admin), so it must survive
    // the clone untouched; it used to get clobbered here because the GSC meta tag was
    // written into this same field, silently destroying any custom CSS on every generate.
    if ($skipTags) {
        $data['theme']['analytics_head'] = '';
        $data['theme']['gsc_meta']       = '';
    } else {
        $aid = trim($params['analytics_id'] ?? '');
        $data['theme']['analytics_head'] = $aid !== '' ? ms_ga4_snippet($aid) : '';

        // ── 4b. Search Console verification — per-site meta tag or none ────────
        $data['theme']['gsc_meta'] = ms_gsc_meta($params['gsc_verification'] ?? '');
    }

    // ── 5. Section-order rotation — one ordering per domain, computed live ──
    // Computed fresh from whatever blocks the page currently has — nothing pre-authored or
    // saved. layout_rotate_blocks() leaves the top/bottom pin counts alone and rotates
    // whatever's between them; ms_variant()'s domain hash then picks one ordering (0 =
    // natural), so the same domain always lands on the same choice, forever, until the
    // blocks themselves change.
    if (function_exists('layout_rotate_blocks') && empty($structureSkip['home'])) {
        $data['content_blocks'] = layout_rotate_blocks(
            $data['content_blocks'] ?? [], $domain,
            (int) ($rotation['home_top'] ?? 1), (int) ($rotation['home_bottom'] ?? 1),
            'rotate_home'
        );
    }

    $tmp = $sf . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $sf);

    // ── 5b. Landing pages ────────────────────────────────────────────────────
    // Live in their own files, so section 5 above never sees them — and landing pages are
    // where the keywords and most of the traffic are. Same function, same per-domain hash,
    // its own top/bottom pin counts.
    if (function_exists('layout_rotate_blocks') && empty($structureSkip['landing'])) {
        $lTop    = (int) ($rotation['landing_top'] ?? 1);
        $lBottom = (int) ($rotation['landing_bottom'] ?? 1);
        foreach (glob($workingDir . '/data/pages/*.json') ?: [] as $pf) {
            $pg = json_decode((string) @file_get_contents($pf), true);
            if (!is_array($pg) || empty($pg['content_blocks'])) continue;
            $pg['content_blocks'] = layout_rotate_blocks($pg['content_blocks'], $domain, $lTop, $lBottom, 'rotate_landing');
            $t = $pf . '.tmp.' . getmypid();
            if (@file_put_contents($t, json_encode($pg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
                @rename($t, $pf);
            } else {
                @unlink($t);
            }
        }
    }
}
