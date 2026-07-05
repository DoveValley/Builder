<?php
// City page generation engine.
// Called by admin/generate.php (and in future by CLI or cron).
// Requires config.php + functions.php to already be loaded.

// Reused, non-destructive image-differentiation core (multisite-shared). We only call
// ms_process_blocks_images() — never the orchestrator/prune (those delete uploads).
require_once __DIR__ . '/../multisite/image_overlay.php';

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
    $vars = [
        '{city}'      => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $city['city'] ?? '')),
        '{SS}'        => strtolower($city['SS'] ?? ''),
        '{state}'     => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $city['state'] ?? '')),
        '{city_slug}' => $city['city_slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '-', ($city['city'] ?? '') . '-' . ($city['SS'] ?? ''))),
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
        '{tel}'             => $vars['tel']        ?? '',
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
//
// Returns array:
//   success, pages_written, pages_skipped, pages_backed_up,
//   errors[], run_id, duration_ms, dry_run,
//   cost_warning (bool), cost_steps[], estimated_pages, message

function generate_city_pages(array $options = []): array {
    $templateIds   = $options['template_ids']   ?? null;
    $cityIds       = $options['city_ids']        ?? null;
    $tagFilter     = trim($options['tag_filter'] ?? '');
    $confirmedCost = (bool)($options['confirmed_cost'] ?? false);
    $forceLocked   = (bool)($options['force_locked']   ?? false);
    $dryRun        = (bool)($options['dry_run']        ?? false);

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
    $imgStyle  = [];
    if ($imgCanRun) {
        foreach ([ACTIVE_SITE_DIR . '/multisite/hero_style.json', BASE_DIR . '/multisite/hero_style.json'] as $sf) {
            if (is_file($sf)) { $imgStyle = json_decode((string)file_get_contents($sf), true) ?: []; break; }
        }
    }
    $imgStamped = 0; $imgVaried = 0;

    // ── Per-city layout variation (opt-in) ───────────────────────────────────
    // Reorders each city page's blocks (hero pinned first, closing block pinned
    // last, a couple of middle swaps) via the same ms_variant/layout helpers the
    // multisite build uses per domain — here keyed per city — to cut the intra-site
    // "identical block order on 40 pages" template footprint.
    $varyLayout   = !empty($options['vary_layout']) && empty($options['dry_run']);
    $layoutVaried = 0;

    // ── Template × city loop ─────────────────────────────────────────────────
    foreach ($templates as $tpl) {
        $tplVersion = _gen_template_version($tpl);

        // Layout choices for this template (opt-in): give the blocks ids + build the
        // reordering set once; each city picks one deterministically below.
        $tplBlocksIded = $tpl['content_blocks'] ?? [];
        $layoutChoices = [];
        if ($varyLayout) {
            $tplBlocksIded = ensure_block_ids($tplBlocksIded);
            $layoutChoices = layout_generate_variants($tplBlocksIded, 4);
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
                        $page['content_blocks'][$blockIdx] = $existingPage['content_blocks'][$blockIdx];
                    }
                }
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
                    'site_dir'         => ACTIVE_SITE_DIR,
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
        'layout'          => ['enabled' => $varyLayout, 'varied' => $layoutVaried],
        'errors'          => $errors,
        'duration_ms'     => $durationMs,
        'dry_run'         => $dryRun,
        'log_entry'       => $logEntry,
    ];
}
