<?php
/**
 * Recovery Insurance plugin.
 *
 * Owns ALL of the recovery-insurance site's "different stuff" so the shared factory
 * stays untouched: the nested-URL matrix (states × cities × carriers), its data
 * model, per-page synthesis, and the static-build enumerator. It REUSES the core
 * block library + site-template for rendering — it only decides WHICH blocks a
 * matrix URL shows, never how blocks render.
 *
 * Isolation guarantees (why this can't affect the other sites):
 *   1. The route_request hook is registered ONLY when the active site is the
 *      recovery site — dormant everywhere else.
 *   2. The core seam (page.php) is a no-op when no plugin claims a path, so even the
 *      hook mechanism has zero effect on the other sites.
 *   3. Matrix data lives in the site's own data/recovery/ dir, not in core.
 *
 * URL structure + rationale: see README.md and memory project_recovery_insurance_site.
 */

// Site id this plugin activates for (container: sites/recovery-site).
if (!defined('RECOVERY_SITE_ID')) define('RECOVERY_SITE_ID', 'recovery-site');

register_plugin(
    'recovery',
    'Recovery Insurance',
    'Programmatic recovery-insurance directory: routes + renders the states × cities × carriers matrix '
        . '(/insurance/, /{state}, /{state}/{city}, /{state}/{company}, /{state}/{city}/{company}) using the '
        . 'shared block library. Active only for the recovery site.',
    '&#128657;',   // 🚑
    __DIR__
);

// ── Activate routing ONLY for the recovery site — a true no-op for every other site. ──
if (defined('ACTIVE_SITE_ID') && ACTIVE_SITE_ID === RECOVERY_SITE_ID) {
    require_once __DIR__ . '/router.php';
    require_once __DIR__ . '/pages.php';

    // route_request: return a render payload to claim the URL, or null to pass.
    add_hook('route_request', function (string $path, array $data): ?array {
        $match = recovery_match_route($path);
        if ($match === null) return null;            // not a matrix URL — let core handle it
        return recovery_render_route($match, $data, $path);
    });

    // {company}* tokens for mapped templates. Reads the per-request entity context
    // set by recovery_render_route(); empty (pass-through) on non-matrix pages.
    add_hook('shortcode_tokens', function (array $map): array {
        $c = recovery_ctx();
        if (empty($c)) return $map;
        if (isset($c['company']))      $map['{company}']      = $c['company'];
        if (isset($c['company_slug'])) $map['{company_slug}'] = $c['company_slug'];
        return $map;
    });
}
