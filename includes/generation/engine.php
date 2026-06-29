<?php
// City page generation engine.
// Called by admin/generate.php (and in future by CLI or cron).
// Requires config.php + functions.php to already be loaded.

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
// Reads all faq_two_col blocks, builds a FAQPage entity, and merges it into
// $page['seo']['schema']. Shortcodes in Q&A text are left unresolved —
// resolve_shortcodes() handles them at render time.

function _gen_inject_faq_schema(array &$page): void {
    $pairs = [];
    foreach ($page['content_blocks'] ?? [] as $block) {
        if (($block['type'] ?? '') !== 'faq_two_col') continue;
        foreach ($block['fq_items'] ?? [] as $item) {
            $q = trim(html_entity_decode(strip_tags($item['question'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $a = trim(html_entity_decode(strip_tags($item['answer']   ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($q && $a) {
                $pairs[] = [
                    '@type'          => 'Question',
                    'name'           => $q,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
                ];
            }
        }
    }

    if (empty($pairs)) return;

    $faqPage  = ['@type' => 'FAQPage', 'mainEntity' => $pairs];
    $existing = trim($page['seo']['schema'] ?? '');

    if ($existing === '') {
        $schema = ['@context' => 'https://schema.org', '@graph' => [$faqPage]];
    } else {
        $decoded = json_decode($existing, true);
        if ($decoded === null) {
            // Template schema is invalid JSON — create fresh graph with FAQPage only
            $schema = ['@context' => 'https://schema.org', '@graph' => [$faqPage]];
        } elseif (isset($decoded['@graph'])) {
            // @graph present — replace existing FAQPage or append
            $decoded['@graph'] = array_values(
                array_filter($decoded['@graph'], fn($e) => ($e['@type'] ?? '') !== 'FAQPage')
            );
            $decoded['@graph'][] = $faqPage;
            $schema = $decoded;
        } else {
            // Single schema object — wrap both in a @graph
            unset($decoded['@context']);
            $schema = ['@context' => 'https://schema.org', '@graph' => [$decoded, $faqPage]];
        }
    }

    $page['seo']['schema'] = json_encode(
        $schema,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}

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

    // ── Template × city loop ─────────────────────────────────────────────────
    foreach ($templates as $tpl) {
        $tplVersion = _gen_template_version($tpl);

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
                'content_blocks'   => $tpl['content_blocks'] ?? [],
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

            // ── Inject FAQPage schema from faq_two_col blocks ────────────────
            // Runs after locked-block restoration so AI-generated FAQ content
            // is present in $page['content_blocks'].
            _gen_inject_faq_schema($page);

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
        'errors'          => $errors,
        'duration_ms'     => $durationMs,
        'dry_run'         => $dryRun,
        'log_entry'       => $logEntry,
    ];
}
