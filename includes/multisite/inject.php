<?php
/**
 * Inject a params row's factual identity into a cloned working site (Phase 0d).
 *
 * Only site_vars + the header business name — the master supplies all structure
 * and content. AI copy (Phase 2) and per-site favicon/theme/images (Phase 5) are
 * layered in by later steps; this is deliberately just the factual identity.
 *
 * Requires slugify() (includes/helpers.php, loaded via functions.php).
 */
function inject_params_into_working_dir(string $workingDir, array $params): void {
    $file = $workingDir . '/data/site.json';
    if (!file_exists($file)) {
        throw new RuntimeException("Working site.json not found: {$file}");
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException("Working site.json is not valid JSON: {$file}");
    }

    $sv = $data['site_vars'] ?? [];

    // Direct site_vars mappings — only overwrite when the param is a non-empty string.
    foreach (['business', 'phone', 'tel', 'email', 'city', 'state', 'SS', 'zip', 'address'] as $k) {
        if (isset($params[$k]) && is_string($params[$k]) && $params[$k] !== '') {
            $sv[$k] = $params[$k];
        }
    }

    // Derived fields.
    if (!empty($params['domain'])) {
        // website is always https://{bare-domain}
        $sv['website'] = 'https://' . preg_replace('#^https?://#i', '', rtrim($params['domain'], '/'));
    }
    if (!empty($params['city'])) {
        $sv['city_slug'] = function_exists('slugify') ? slugify($params['city']) : strtolower(trim($params['city']));
    }

    $data['site_vars'] = $sv;

    // Header business name mirrors the business.
    if (!empty($params['business'])) {
        $data['header']['site_name'] = $params['business'];
    }

    $tmp = $file . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($tmp, $json) === false) {
        @unlink($tmp);
        throw new RuntimeException("Failed to write injected site.json");
    }
    rename($tmp, $file);
}
