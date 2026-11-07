<?php
// City page generation engine.
// Called by admin/generate.php (and in future by CLI or cron).
// Requires config.php + functions.php to already be loaded.

// Reused, non-destructive image-differentiation core (multisite-shared). We only call
// ms_process_blocks_images() — never the orchestrator/prune (those delete uploads).
require_once __DIR__ . '/../multisite/image_overlay.php';
// openai_images_edit()/openai_images_estimate_cost() — "AI unique photo per landing
// page" option below reuses this one module, same as every other AI-photo path.
require_once __DIR__ . '/../openai_images.php';

// ── Step loader ───────────────────────────────────────────────────────────────

function _gen_step_file(string $stepName): string {
    return __DIR__ . '/steps/' . preg_replace('/[^a-z0-9_]/', '', $stepName) . '.php';
}

function _gen_load_step_meta(string $stepName): array {
    $file = _gen_step_file($stepName);
    if (!file_exists($file)) return ['has_cost' => false, 'description' => $stepName . ' (unknown)'];
    require_once $file;
    $metaFn = 'step_' . $stepName . '_meta';
    return function_exists($metaFn) ? $metaFn() : ['has_cost' => false, 'description' => $stepName];
}

function _gen_run_step(string $stepName, array $page, array $city, array $options): array {
    $file = _gen_step_file($stepName);
    if (!file_exists($file)) {
        throw new RuntimeException("Step file not found: $stepName");
    }
    require_once $file;
    $fn = 'step_' . $stepName;
    if (!function_exists($fn)) {
        throw new RuntimeException("Step function not found: step_$stepName");
    }
    return $fn($page, $city, $options);
}

// ── Slug pattern resolver ─────────────────────────────────────────────────────

function _gen_resolve_slug(string $pattern, array $city): string {
    // Deterministic city+SS derivation — the reliable slug (same basis as the row id).
    $ss      = strtolower($city['SS'] ?? '');
    $derived = trim(preg_replace('/[^a-z0-9-]+/', '-',
                    strtolower(preg_replace('/[^a-z0-9]+/i', '-', ($city['city'] ?? '') . '-' . ($city['SS'] ?? '')))), '-');
    // Trust a stored city_slug only if it looks like it belongs to this city
    // (a slug ending in the state suffix). Otherwise fall back to $derived — this
    // keeps garbage/placeholder text that slugifies to a valid-but-wrong slug
    // (e.g. "in-add-a-city-section") out of page URLs, for single-site AND fleet
    // builds (this resolver is the shared chokepoint). Mirrors cities_save.php.
    $stored  = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)($city['city_slug'] ?? ''))), '-');
    $ssSuf   = '-' . $ss;
    $citySlug = ($stored !== '' && ($ss === '' || substr($stored, -strlen($ssSuf)) === $ssSuf)) ? $stored : $derived;

    $vars = [
        '{city}'      => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $city['city'] ?? '')),
        '{SS}'        => $ss,
        '{state}'     => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $city['state'] ?? '')),
        '{city_slug}' => $citySlug,
        '{zip}'       => $city['zip'] ?? '',
    ];
    $slug = str_replace(array_keys($vars), array_values($vars), $pattern);
    $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug));
    return trim($slug, '-');
}

// ── Template version fingerprint ──────────────────────────────────────────────

function _gen_template_version(array $tpl): string {
    return md5(json_encode($tpl['content_blocks'] ?? []) . json_encode($tpl['seo'] ?? []) . ($tpl['slug_pattern'] ?? ''));
}

// ── FAQPage schema injection ──────────────────────────────────────────────
// Called after locked-block restoration so AI-generated FAQ content is present.
// Resolves schema shortcodes at generation time using merged site + city vars
// so the stored JSON is fully self-contained (no {tokens} remain).
function _gen_resolve_schema_shortcodes(string $schema, array $vars): string {
    if (trim($schema) === '') return $schema;
    $website      = rtrim($vars['website'] ?? '', '/');
    $city         = $vars['city']      ?? '';
    $SS           = $vars['SS']        ?? '';
    $city_slug    = $vars['city_slug'] ?? '';
    $city_state   = ($city && $SS) ? "$city, $SS" : $city . $SS;
    $replacements = [
        '{website}'         => $website,
        '{business_domain}' => parse_url($website, PHP_URL_HOST) ?: $website,
        '{business}'        => $vars['business']  ?? '',
        '{city}'            => $city,
        '{state}'           => $vars['state']     ?? '',
        '{SS}'              => $SS,
        '{city_slug}'       => $city_slug,
        '{city_state}'      => $city_state,
        '{phone}'           => $vars['phone']     ?? '',
        '{tel}'             => ($vars['tel'] ?? '') !== ''
            ? $vars['tel']
            : (($d = preg_replace('/\D/', '', $vars['phone'] ?? '')) !== ''
                ? '+' . (strlen($d) === 10 ? '1' . $d : $d)
                : ''),
        '{zip}'             => $vars['zip']        ?? '',
        '{address}'         => $vars['address']   ?? '',
        '{rating}'          => $vars['rating']     ?? '',
        '{review_count}'    => $vars['review_count'] ?? '',
    ];
    return str_replace(array_keys($replacements), array_values($replacements), $schema);
}

// FAQPage schema is no longer injected at structure time — it's derived from the
// page's current FAQ blocks at render (schema_apply_faqpage in includes/schema.php),
// so it can never be stale relative to AI-filled FAQ content.

// ── Main generation function ──────────────────────────────────────────────────
//
// $options keys:
//   template_ids   array|null  — limit to these template IDs
//   city_ids       array|null  — limit to these city IDs
//   tag_filter     string      — limit cities to those with this tag
//   confirmed_cost bool        — user confirmed costly step warning
//   force_locked   bool        — overwrite locked blocks too
//   dry_run        bool        — simulate; no files written
//   ai_photo       bool        — AI unique photo per landing page (real OpenAI cost)
//   ai_photo_quality string    — 'low'|'medium'|'high', default 'low'
//
// Returns array:
//   success, pages_written, pages_skipped, pages_backed_up,
//   errors[], run_id, duration_ms, dry_run,
//   cost_warning (bool), cost_steps[], estimated_pages, message

/**
 * "AI unique photo per landing page" — takes this page's own hero photo (whatever
 * is currently set, same field everyone already edits via Pic Drop) and asks
 * OpenAI to swap the person's hair/face, so a page gets a genuinely distinct file
 * instead of sharing the one photo every other page under the same template uses.
 * Same prompt family as tools/capture_leaf_hero_prompts.php's fleet-wide version —
 * protects clothing/pose explicitly, and doesn't assume a person is present.
 *
 * Cached by template + city + source photo path, written next to the source photo
 * itself (no separate registry file) — ticking this again on a later regenerate
 * only ever pays for a city that doesn't already have a cached variant, same
 * discipline as the fleet's ms_generate_ai_images_for_domain().
 *
 * Runs one call at a time, in the same per-city loop every other step already
 * runs in — fine for a template-or-city-scoped run, but an unfiltered "Generate
 * All" across hundreds of cities will be slow (each edit call is a real,
 * several-second API round trip). Scope the run (template_ids/city_ids) for a
 * first pass on a large site rather than running it fully unfiltered.
 *
 * @return array{generated:bool,cost:float,error:?string}
 */
function _gen_ai_hero_photo(array &$blocks, string $tplId, string $cityId, string $quality): array {
    $none = ['generated' => false, 'cost' => 0.0, 'error' => null];
    if ($cityId === '') return $none;

    $field = null; $blockIdx = null;
    foreach ($blocks as $i => $b) {
        if (!is_array($b) || strncmp($b['type'] ?? '', 'hero', 4) !== 0) continue;
        foreach ($b as $k => $v) {
            if ($k === '' || $k[0] === '_' || stripos($k, 'bg') !== false) continue;
            if (is_string($v) && preg_match('/\.(jpe?g|png|webp)$/i', $v) && stripos($v, 'uploads') !== false) {
                $field = $k; $blockIdx = $i; break 2;
            }
        }
    }
    if ($field === null) return $none; // no hero photo on this page — nothing to do

    // Always edit from the TRUE original, never a previously-generated variant —
    // same convention ms_stamp_blocks() already uses (_<field>_orig), for the
    // identical reason: editing an edit drifts further from the real source every
    // run, AND the field's current value would change on every single run, which
    // breaks the cache signature below outright. Confirmed live: without this, a
    // second run on an already-done page regenerated (and re-billed) instead of
    // hitting cache, because $srcRel had become the first run's own AI output.
    $origKey = '_' . $field . '_orig';
    $srcRel  = (isset($blocks[$blockIdx][$origKey]) && is_string($blocks[$blockIdx][$origKey]) && $blocks[$blockIdx][$origKey] !== '')
        ? $blocks[$blockIdx][$origKey] : (string) $blocks[$blockIdx][$field];
    if ($srcRel === '' || str_contains($srcRel, '{')) return $none; // empty, or a locked per-city token

    // Same brand-asset exemption ms_vary_raw_image_refs() already uses — a fixed
    // logo/badge/icon isn't a photo of a scene, so "keep the same scene, swap the
    // person's hair/face" has nothing real to act on. Confirmed live: pointed at
    // granitepmacademy's PMI trust badge, the edit call didn't produce a subtle
    // variation at all — it hallucinated an entirely unrelated "technician in a
    // server room" scene, since there was no real photographic scene to preserve.
    $srcName = strtolower(basename($srcRel));
    foreach (['logo', 'icon', 'favicon', 'badge', 'sprite'] as $brandWord) {
        if (str_contains($srcName, $brandWord)) return $none;
    }

    // BASE_DIR, not ACTIVE_SITE_DIR — same project-root-relative convention the
    // sibling image-diff call above already documents (and the same doubled-prefix
    // bug class this project has hit before if that convention isn't followed).
    $srcPath = BASE_DIR . '/' . ltrim($srcRel, '/');
    if (!is_file($srcPath)) return ['generated' => false, 'cost' => 0.0, 'error' => "AI hero photo: source missing ($srcRel)"];

    $sig      = substr(md5($srcRel . '|' . $tplId . '|' . $cityId), 0, 10);
    $destRel  = trim(dirname($srcRel), '.') . '/ai_hero_' . $sig . '.webp';
    $destPath = BASE_DIR . '/' . ltrim($destRel, '/');

    if (is_file($destPath)) {
        $blocks[$blockIdx][$field]   = $destRel; // cache hit — no new cost
        $blocks[$blockIdx][$origKey] = $srcRel;
        return $none;
    }

    [$w, $h] = @getimagesize($srcPath) ?: [0, 0];
    $size = ($w > $h) ? '1536x1024' : (($h > $w) ? '1024x1536' : '1024x1024');

    // Confirmed live across several real edits, on a two-person photo:
    // - Softer wording ("change hair and face", proven fine on single-person
    //   technician shots elsewhere in this project) left both people essentially
    //   unchanged at both Low and Medium quality — a strong "keep everything the
    //   same" instruction reads as license to barely touch anything unless the one
    //   requested change is equally explicit.
    // - "Different face shape/features/hair/age" alone (no mention of skin tone)
    //   only changed hair color, not the face — not enough per Scott's own review.
    // - Adding "different skin tone" fixed the face but changed apparent ethnicity —
    //   this project has a standing rule against demographic specification in these
    //   prompts (see the "Clothing only, no demographic specifics" note on the
    //   Pic Drop reference prompts, includes/picdrop.php), so that's not usable.
    // - Explicitly constraining "keep the same general skin tone and ethnicity,
    //   vary the face and hair only" alongside the stronger feature list gets real
    //   facial differentiation (face shape, features, hair, age) without steering
    //   ethnicity — confirmed on two different real edits of the same source photo.
    // "any/every/each person" (not "one or a second person") so this scales to
    // however many people are actually in a given reference photo, not just two.
    $prompt = 'Same picture — keep the exact same scene, composition, equipment, setting, clothing, '
        . 'and pose. If any people are visible in the photo, replace every one of them with a '
        . 'completely different-looking individual: a different face shape, different facial features '
        . '(eyes, nose, mouth, jawline), different hair color and style, and a different apparent age '
        . 'for each person — each one must be unmistakably a different person, not the same person '
        . 'with new hair. Keep each person\'s own general skin tone and ethnicity as in the reference '
        . 'photo — vary the face and hair only, not anyone\'s apparent race. Keep everyone\'s exact '
        . 'pose, clothing, and position unchanged. If no person is visible in the photo, leave the '
        . 'subject and scene as-is and make only a small, believable variation (e.g. a slightly '
        . 'different angle or pose of the same subject) so it reads as a distinct photo rather than an '
        . 'identical copy — do not add a person. Bright, natural daylight. No visible text or logos.';

    $r = openai_images_edit($prompt, $srcPath, ['size' => $size, 'quality' => $quality, 'output_format' => 'webp']);
    if (!$r['ok']) return ['generated' => false, 'cost' => 0.0, 'error' => 'AI hero photo failed: ' . $r['error']];

    if (!is_dir(dirname($destPath))) @mkdir(dirname($destPath), 0775, true);
    if (file_put_contents($destPath, $r['bytes']) === false) {
        return ['generated' => false, 'cost' => 0.0, 'error' => 'AI hero photo: could not write ' . $destRel];
    }
    if (function_exists('img_write_mobile_variant')) img_write_mobile_variant($destPath);

    $blocks[$blockIdx][$field]   = $destRel;
    $blocks[$blockIdx][$origKey] = $srcRel;
    return ['generated' => true, 'cost' => openai_images_estimate_cost($size, $quality), 'error' => null];
}

function generate_city_pages(array $options = []): array {
    $templateIds   = $options['template_ids']   ?? null;
    $cityIds       = $options['city_ids']        ?? null;
    $tagFilter     = trim($options['tag_filter'] ?? '');
    $confirmedCost = (bool)($options['confirmed_cost'] ?? false);
    $forceLocked   = (bool)($options['force_locked']   ?? false);
    $dryRun        = (bool)($options['dry_run']        ?? false);
    // "AI unique photo per landing page" — opt-in, real OpenAI cost, gated through
    // the same cost_warning flow every other paid step already uses (below).
    $aiPhoto        = (bool)($options['ai_photo'] ?? false);
    $aiPhotoQuality = in_array($options['ai_photo_quality'] ?? '', ['low', 'medium', 'high'], true)
        ? $options['ai_photo_quality'] : 'low';

    $startedAt = date('c');
    $startMs   = (int)(microtime(true) * 1000);
    $runId     = bin2hex(random_bytes(8));

    // ── Load data ─────────────────────────────────────────────────────────────
    $templates = [];
    if (file_exists(TEMPLATES_FILE)) {
        $raw = json_decode(file_get_contents(TEMPLATES_FILE), true);
        $templates = is_array($raw) ? array_values($raw) : [];
    }

    $cities = [];
    if (file_exists(CITIES_FILE)) {
        $raw = json_decode(file_get_contents(CITIES_FILE), true);
        $cities = is_array($raw) ? array_values($raw) : [];
    }

    // ── Apply filters ─────────────────────────────────────────────────────────
    if ($templateIds !== null) {
        $templates = array_values(array_filter($templates, fn($t) => in_array($t['id'], $templateIds, true)));
    }
    if ($cityIds !== null) {
        $cities = array_values(array_filter($cities, fn($c) => in_array($c['id'], $cityIds, true)));
    } elseif ($tagFilter !== '') {
        $cities = array_values(array_filter($cities, fn($c) => in_array($tagFilter, $c['tags'] ?? [], true)));
    }

    if (empty($templates)) {
        return ['success' => false, 'message' => 'No templates match the requested filters.'];
    }
    if (empty($cities)) {
        return ['success' => false, 'message' => 'No cities match the requested filters.'];
    }

    // ── Cost check ────────────────────────────────────────────────────────────
    $costSteps = [];
    foreach ($templates as $tpl) {
        foreach ($tpl['generation_steps'] ?? [['step' => 'city_vars']] as $stepDef) {
            $name = $stepDef['step'] ?? '';
            if ($name === '' || isset($costSteps[$name])) continue;
            $meta = _gen_load_step_meta($name);
            if (!empty($meta['has_cost'])) {
                $costSteps[$name] = $meta['description'] ?? $name;
            }
        }
    }
    // Not a registered template step (it's a run-time checkbox, same model as the
    // existing "Per-city images" option below) — folded into the same $costSteps
    // list by hand so it rides the one existing cost-confirmation flow for free.
    if ($aiPhoto) {
        $costSteps['ai_hero_photo'] = 'AI unique photo per landing page (OpenAI, ' . $aiPhotoQuality . ' quality)';
    }
    if (!empty($costSteps) && !$confirmedCost) {
        $estimated = count($templates) * count($cities);
        return [
            'success'         => false,
            'cost_warning'    => true,
            'cost_steps'      => array_keys($costSteps),
            'cost_descriptions' => array_values($costSteps),
            'estimated_pages' => $estimated,
            'message'         => 'This run includes steps that make external API calls (' . implode(', ', array_keys($costSteps)) . '). '
                                . "It will process $estimated pages. Confirm to proceed.",
        ];
    }

    // ── Load site vars (for schema shortcode resolution) ─────────────────────
    $siteData = load_data();
    $siteVars = $siteData['site_vars'] ?? [];

    // ── Load existing page index ──────────────────────────────────────────────
    $pageIndex = [];
    if (file_exists(PAGE_INDEX_FILE)) {
        $raw = json_decode(file_get_contents(PAGE_INDEX_FILE), true);
        $pageIndex = is_array($raw) ? $raw : [];
    }

    $written  = 0;
    $skipped  = 0;
    $backedUp = 0;
    $errors   = [];

    // ── Per-city image differentiation (opt-in) ──────────────────────────────
    // Reuses the multisite core: bakes {keyword} + "City, ST" onto the hero and
    // (in 'full' mode) byte-perturbs + city-renames every content photo, so each
    // city page gets distinct image files instead of sharing the template's. Runs
    // as www-data into sites/{id}/uploads/; non-destructive (keeps originals),
    // seed-deterministic per city, no-ops without ImageMagick or on a dry run.
    $imgDiff   = in_array($options['image_diff'] ?? '', ['hero', 'full'], true) ? $options['image_diff'] : '';
    $imgCanRun = $imgDiff !== '' && empty($options['dry_run']) && function_exists('ms_convert_bin') && ms_convert_bin() !== null;
    // Same per-master-then-global resolution the multisite build and the Gen-Image
    // tab use, through the one helper that owns that order (image_overlay.php).
    $imgStyle  = $imgCanRun ? ms_image_settings_read(ACTIVE_SITE_DIR, 'hero_style.json') : [];
    $imgStamped = 0; $imgVaried = 0;

    // ── AI unique photo per landing page (opt-in) ────────────────────────────
    $aiPhotoCanRun    = $aiPhoto && empty($options['dry_run']) && function_exists('openai_images_edit');
    $aiPhotoGenerated = 0; $aiPhotoCost = 0.0;

    // ── Per-city layout variation (opt-in) ───────────────────────────────────
    // Reorders each city page's blocks (hero pinned first, closing block pinned
    // last, a couple of middle swaps) via the same ms_variant/layout helpers the
    // multisite build uses per domain — here keyed per city — to cut the intra-site
    // "identical block order on 40 pages" template footprint.
    $varyLayout   = !empty($options['vary_layout']) && empty($options['dry_run']);
    $layoutVaried = 0;
    // Same per-master-then-global resolution the multisite build and the
    // Gen-Image tab use, through the one helper that owns that order
    // (image_overlay.php) — set on the Gen-Mod tab, defaults to 4 if untouched.
    $layoutSettings = ms_layout_variation_settings($varyLayout ? ms_image_settings_read(ACTIVE_SITE_DIR, 'layout_variation.json') : []);

    // ── Template × city loop ─────────────────────────────────────────────────
    foreach ($templates as $tpl) {
        $tplVersion = _gen_template_version($tpl);

        // Layout choices for this template (opt-in): give the blocks ids + build the
        // reordering set once; each city picks one deterministically below.
        $tplBlocksIded = $tpl['content_blocks'] ?? [];
        $layoutChoices = [];
        if ($varyLayout) {
            $tplBlocksIded = ensure_block_ids($tplBlocksIded);
            $layoutChoices = layout_generate_variants($tplBlocksIded, $layoutSettings['variant_count']);
        }

        foreach ($cities as $city) {
            $tplId  = $tpl['id']  ?? '';
            $cityId = $city['id'] ?? '';
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $tplId) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $cityId)) {
                $errors[] = "Skipped: unsafe template/city ID '{$tplId}'/'{$cityId}'";
                $skipped++;
                continue;
            }
            $filename = $tplId . '_' . $cityId . '.json';
            $pageFile = PAGES_DIR . $filename;

            // Build base page — steps can modify any field
            $page = [
                'template_id'      => $tpl['id'],
                'city_id'          => $city['id'],
                'slug'             => _gen_resolve_slug($tpl['slug_pattern'] ?? '', $city),
                'title'            => $tpl['title'] ?? '',
                'city_vars'        => $city,
                'content_blocks'   => $varyLayout ? $tplBlocksIded : ($tpl['content_blocks'] ?? []),
                'seo'              => $tpl['seo']             ?? [],
                'locked_blocks'    => [],
                'generated_at'     => date('c'),
                'template_version' => $tplVersion,
            ];

            // Load existing page — needed for locked_blocks, backup, and old-slug cleanup
            $existingPage = null;
            $existingSlug = '';
            if (file_exists($pageFile)) {
                $raw = json_decode(file_get_contents($pageFile), true);
                if (is_array($raw)) {
                    $existingPage = $raw;
                    $existingSlug = $raw['slug'] ?? '';
                    $page['locked_blocks'] = $raw['locked_blocks'] ?? [];
                }
            }

            // ── Run steps ────────────────────────────────────────────────────
            $stepErrors = [];
            $stepsToRun = $tpl['generation_steps'] ?? [['step' => 'city_vars']];

            foreach ($stepsToRun as $stepDef) {
                $stepName = $stepDef['step']    ?? '';
                $stepOpts = $stepDef['options'] ?? [];
                if ($stepName === '') continue;

                try {
                    $page = _gen_run_step($stepName, $page, $city, $stepOpts);
                } catch (Throwable $e) {
                    // Step failed — log error, keep page as-is, continue
                    $stepErrors[] = ['step' => $stepName, 'error' => $e->getMessage()];
                }
            }

            if (!empty($stepErrors)) {
                $errors[] = [
                    'template_id' => $tpl['id'],
                    'city_id'     => $city['id'],
                    'step_errors' => $stepErrors,
                    'note'        => 'Page written with template defaults for failed steps.',
                ];
            }

            // ── Restore locked blocks ─────────────────────────────────────────
            // Two lock sources are merged:
            // 1. Explicit locked_blocks index array (set by admin or future tooling)
            // 2. Blocks with _ai_locked:true set by generate.py after AI generation
            // Both are skipped when $forceLocked is true.
            if (!$forceLocked && $existingPage !== null) {
                // Build a set of indexes to preserve from explicit locked_blocks list
                $preserveIndexes = [];
                foreach ($page['locked_blocks'] as $blockIdx) {
                    $preserveIndexes[(int)$blockIdx] = true;
                }
                // Also preserve any block the AI has already filled (_ai_locked:true)
                foreach ($existingPage['content_blocks'] as $blockIdx => $existingBlock) {
                    if (!empty($existingBlock['_ai_locked'])) {
                        $preserveIndexes[$blockIdx] = true;
                    }
                }
                // Restore preserved blocks from the existing page
                foreach ($preserveIndexes as $blockIdx => $_) {
                    if (isset($existingPage['content_blocks'][$blockIdx], $page['content_blocks'][$blockIdx])) {
                        $restored = $existingPage['content_blocks'][$blockIdx];
                        // The freshly-generated block may carry an id (assigned by
                        // ensure_block_ids() above, when vary_layout is on) that the
                        // stored copy predates and therefore lacks. Discarding it here
                        // silently un-places the block for layout_apply(), which shoves
                        // any id-less block to the end regardless of the chosen variant —
                        // carry the fresh id forward instead of losing it.
                        if (empty($restored['id']) && !empty($page['content_blocks'][$blockIdx]['id'])) {
                            $restored['id'] = $page['content_blocks'][$blockIdx]['id'];
                        }
                        $page['content_blocks'][$blockIdx] = $restored;
                    }
                }
            }

            // The H1 is FORCED to the page's own primary keyword, not left to whatever
            // static text a template's hero block happens to carry. Runs AFTER the
            // locked-block restore above, and deliberately overrides even a restored,
            // _ai_locked hero block — that lock exists to protect the AI-written
            // hs_subtext from being clobbered by a re-run, not to let the heading (a
            // mechanical, keyword-driven field, not authored content) go stale. A
            // hand-typed heading can agree with seo_title/primary_keyword on the day
            // someone writes it and silently drift the next time either is edited alone;
            // this closes that off by construction instead of relying on an editor's
            // care every time (see feedback_seo_primary_keyword_focus). Everything else
            // on the block — subtext, photo, the lock flag itself — is untouched.
            $headingField = block_heading_field($page['content_blocks'][0]['type'] ?? '');
            $primaryKw    = trim((string) ($page['seo']['primary_keyword'] ?? ''));
            if ($primaryKw !== '' && $headingField !== null) {
                $page['content_blocks'][0][$headingField] = '{primary_keyword} in {city_state}';
            }

            // ── FAQPage schema is derived at RENDER time ─────────────────────
            // (schema_apply_faqpage in includes/schema.php), from the page's current
            // FAQ blocks — so it's never stale relative to AI-filled FAQ content.
            // No structure-time injection here on purpose.

            // ── Resolve SEO shortcodes + set canonical at generation time ────────
            $cityVarsFiltered = array_filter($city, fn($v) => is_array($v) || ($v !== '' && $v !== null));
            $mergedVars       = array_merge($siteVars, $cityVarsFiltered);

            // Canonical URL — set from website + slug if template left it empty
            if (empty(trim($page['seo']['canonical_url'] ?? ''))) {
                $website = rtrim($siteVars['website'] ?? '', '/');
                $page['seo']['canonical_url'] = $website . '/' . ltrim($page['slug'], '/') . '/';
            } else {
                $page['seo']['canonical_url'] = _gen_resolve_schema_shortcodes($page['seo']['canonical_url'], $mergedVars);
            }

            // meta_keywords — resolve any remaining shortcodes
            if (!empty($page['seo']['meta_keywords'])) {
                $page['seo']['meta_keywords'] = _gen_resolve_schema_shortcodes($page['seo']['meta_keywords'], $mergedVars);
            }

            // Schema — fully resolve all tokens so the stored JSON is self-contained
            if (!empty($page['seo']['schema'])) {
                $page['seo']['schema'] = _gen_resolve_schema_shortcodes($page['seo']['schema'], $mergedVars);
            }

            // ── Per-city image differentiation (opt-in, reused multisite core) ──
            if ($imgCanRun && !empty($page['content_blocks'])) {
                $ir = ms_process_blocks_images($page['content_blocks'], [
                    // BASE_DIR, not ACTIVE_SITE_DIR: this site's own photo fields are
                    // stored project-root-relative ("sites/{id}/uploads/...", the same
                    // form $pathPrefix resolves at render time), but ms_stamp_blocks()
                    // resolves a block's path as $site_dir . '/' . $path — passing
                    // ACTIVE_SITE_DIR here doubled the "sites/{id}/" segment, so the
                    // source file was never found and every stamp silently no-op'd.
                    'site_dir'         => BASE_DIR,
                    'seed'             => $tplId . '_' . $cityId,
                    'city'             => $city['city'] ?? '',
                    'ss'               => $city['SS'] ?? '',
                    'keyword'          => trim((string)($page['seo']['primary_keyword'] ?? '')),
                    'master_city_slug' => '',
                    'style'            => $imgStyle,
                    'page_key'         => $tplId . '_' . $cityId,
                    'stamp_hero'       => true,
                    'vary_images'      => $imgDiff === 'full',
                ]);
                $imgStamped += count($ir['stamped'] ?? []);
                $imgVaried  += (int)($ir['varied'] ?? 0);
            }

            // ── AI unique photo per landing page (opt-in, real OpenAI cost) ───
            if ($aiPhotoCanRun && !empty($page['content_blocks'])) {
                $apr = _gen_ai_hero_photo($page['content_blocks'], $tplId, $cityId, $aiPhotoQuality);
                if ($apr['generated']) { $aiPhotoGenerated++; $aiPhotoCost += $apr['cost']; }
                if ($apr['error'] !== null) {
                    $errors[] = [
                        'template_id' => $tplId, 'city_id' => $cityId,
                        'step_errors' => [['step' => 'ai_hero_photo', 'error' => $apr['error']]],
                        'note'        => 'AI hero photo skipped; page written with its existing photo.',
                    ];
                }
            }

            // ── Per-city layout variation (opt-in) — reorder blocks per city ──
            if ($varyLayout && $layoutChoices) {
                $lkey = $city['city_slug'] ?? ($tplId . '_' . $cityId);
                $pick = ms_variant($lkey, 1 + count($layoutChoices), 'citylayout');   // 0 = natural
                if ($pick > 0 && isset($layoutChoices[$pick - 1])) {
                    $page['content_blocks'] = layout_apply($page['content_blocks'], $layoutChoices[$pick - 1]);
                    $layoutVaried++;
                }
            }

            if ($dryRun) {
                $written++;
                continue;
            }

            // ── Backup existing file (one-version rollback) ───────────────────
            if (file_exists($pageFile)) {
                if (!copy($pageFile, $pageFile . '.bak')) {
                    $errors[] = [
                        'template_id' => $tpl['id'],
                        'city_id'     => $city['id'],
                        'error'       => 'Could not back up ' . $filename . '; skipping write to preserve original.',
                    ];
                    $skipped++;
                    continue;
                }
                $backedUp++;
            }

            // ── Write page file (atomic: tmp → rename) ───────────────────────
            $pageJson = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pageTmp  = $pageFile . '.tmp.' . getmypid();
            $ok       = file_put_contents($pageTmp, $pageJson) !== false && rename($pageTmp, $pageFile);
            if (!$ok) {
                @unlink($pageTmp);
                $errors[] = [
                    'template_id' => $tpl['id'],
                    'city_id'     => $city['id'],
                    'error'       => 'Could not write page file: ' . $filename,
                ];
                $skipped++;
            } else {
                if ($page['slug'] !== '') {
                    // Remove the old slug entry if this page's slug changed
                    if ($existingSlug !== '' && $existingSlug !== $page['slug']) {
                        unset($pageIndex[$existingSlug]);
                    }
                    // Guard against slug collision with a different page
                    if (isset($pageIndex[$page['slug']]) && $pageIndex[$page['slug']] !== $filename) {
                        $errors[] = [
                            'template_id' => $tpl['id'],
                            'city_id'     => $city['id'],
                            'error'       => 'Slug collision: /' . $page['slug'] . ' already used by ' . $pageIndex[$page['slug']] . '; this page will not be indexed.',
                        ];
                    } else {
                        $pageIndex[$page['slug']] = $filename;
                    }
                }
                $written++;
            }
        }
    }

    // ── Rebuild page-index.json (atomic) ─────────────────────────────────────
    if (!$dryRun) {
        $idxJson = json_encode($pageIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $idxTmp  = PAGE_INDEX_FILE . '.tmp.' . getmypid();
        if (file_put_contents($idxTmp, $idxJson) !== false) {
            rename($idxTmp, PAGE_INDEX_FILE);
        } else {
            @unlink($idxTmp);
        }
    }

    // ── Write generation log (keep last 50 runs) ──────────────────────────────
    $finishedAt = date('c');
    $durationMs = (int)(microtime(true) * 1000) - $startMs;

    $logEntry = [
        'run_id'          => $runId,
        'started_at'      => $startedAt,
        'finished_at'     => $finishedAt,
        'duration_ms'     => $durationMs,
        'dry_run'         => $dryRun,
        'options'         => [
            'template_ids' => $templateIds,
            'city_ids'     => $cityIds,
            'tag_filter'   => $tagFilter,
            'force_locked' => $forceLocked,
        ],
        'pages_written'   => $written,
        'pages_skipped'   => $skipped,
        'pages_backed_up' => $backedUp,
        'errors'          => $errors,
    ];

    if (!$dryRun) {
        $log = [];
        if (file_exists(STRUCTURE_LOG_FILE)) {
            $raw = json_decode(file_get_contents(STRUCTURE_LOG_FILE), true);
            $log = is_array($raw) ? $raw : [];
        }
        $log[] = $logEntry;
        if (count($log) > 50) $log = array_slice($log, -50);
        $logJson = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $logTmp  = STRUCTURE_LOG_FILE . '.tmp.' . getmypid();
        if (file_put_contents($logTmp, $logJson) !== false) {
            rename($logTmp, STRUCTURE_LOG_FILE);
        } else {
            @unlink($logTmp);
        }
    }

    return [
        'success'         => true,
        'run_id'          => $runId,
        'pages_written'   => $written,
        'pages_skipped'   => $skipped,
        'pages_backed_up' => $backedUp,
        'images'          => ['mode' => $imgDiff, 'stamped' => $imgStamped, 'varied' => $imgVaried],
        'ai_photo'        => ['enabled' => $aiPhoto, 'quality' => $aiPhotoQuality, 'generated' => $aiPhotoGenerated, 'cost' => round($aiPhotoCost, 4)],
        'layout'          => ['enabled' => $varyLayout, 'varied' => $layoutVaried],
        'errors'          => $errors,
        'duration_ms'     => $durationMs,
        'dry_run'         => $dryRun,
        'log_entry'       => $logEntry,
    ];
}

/**
 * generate_city_pages(), guarded against a concurrent run for the same site.
 *
 * generate_city_pages() runs in-process (no subprocess, unlike generate.py), so unlike
 * research/content-fill it can't be spotted via a `ps` scan from another request — a
 * flock() on a dedicated lock file is the only thing that's atomic across two separate
 * PHP requests racing to call it (a double-click, two tabs, or an admin edit racing a
 * CLI/batch build of the same site). flock is released by the OS the moment the file
 * handle closes, including on a crash or PHP's own timeout kill, so there is no stale-
 * lock state to clean up or get wrong, unlike a pid/timestamp lock file would need.
 *
 * Call this everywhere generate_city_pages() is called from a request/process that
 * could overlap another (admin/generate.php, multisite/generate_landing.php) — never
 * call generate_city_pages() directly from a new call site.
 */
function generate_city_pages_locked(array $options = []): array {
    $lockFh = @fopen(GENERATION_LOCK_FILE, 'c+');
    // $lockFh === false (couldn't open the lock file, e.g. missing data dir) fails
    // OPEN on purpose, same philosophy as generate_py_in_flight(): a missed detection
    // costs the old racy behavior, a false "can't acquire" costs a generator that
    // never runs again.
    if ($lockFh && !flock($lockFh, LOCK_EX | LOCK_NB)) {
        // Reading doesn't need the lock (flock is advisory, write-vs-write only) — best-
        // effort only, so a torn read mid-write from the holder just drops the detail.
        $info = json_decode((string) stream_get_contents($lockFh), true);
        fclose($lockFh);
        $msg = 'Another generation run is already in progress for this site.';
        if (is_array($info) && !empty($info['started_at'])) {
            $secs = max(0, time() - strtotime($info['started_at']));
            $ago  = $secs >= 60 ? intdiv($secs, 60) . ' min' : $secs . ' sec';
            $msg .= ' Started ' . $ago . ' ago' . (!empty($info['pid']) ? ' (pid ' . (int) $info['pid'] . ')' : '') . '.';
        }
        $msg .= ' Wait for it to finish before starting another — two at once would race on the same page files.';
        return ['success' => false, 'message' => $msg];
    }
    if ($lockFh) {
        ftruncate($lockFh, 0);
        rewind($lockFh);
        fwrite($lockFh, json_encode(['pid' => getmypid(), 'started_at' => date('c')]));
        fflush($lockFh);
    }
    try {
        return generate_city_pages($options);
    } finally {
        if ($lockFh) {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }
}
