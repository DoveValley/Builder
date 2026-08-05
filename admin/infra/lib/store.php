<?php
/**
 * infra/lib/store.php — read config registries + fleet state.
 * Self-contained: no factory dependencies. Config = JSON registries (small,
 * human-edited); fleet state will later be SQLite (state/fleet.db).
 */

function infra_base_dir(): string { return dirname(__DIR__); }              // .../admin/infra
function infra_config_path(string $name): string { return infra_base_dir() . '/config/' . $name; }

function infra_load_json(string $path, array $default = []): array
{
    if (!is_file($path)) return $default;
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

/** @return array list of server registry entries */
function infra_servers(): array
{
    $cfg = infra_load_json(infra_config_path('servers.json'), []);
    return $cfg['servers'] ?? [];
}

/** @return array list of Cloudflare account registry entries */
function infra_cf_accounts(): array
{
    $cfg = infra_load_json(infra_config_path('cloudflare.json'), []);
    return $cfg['accounts'] ?? [];
}

/**
 * Registrable-looking domain name. Lives here (not provision.php) so the domain
 * loader can validate without pulling in the Plesk/Cloudflare clients.
 */
function infra_valid_domain(string $d): bool
{
    return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', strtolower(trim($d)));
}

/**
 * Write a config registry back to disk atomically, 0600. Used by the registrar
 * admin UI; keeps the same gitignored JSON files the rest of the console reads.
 */
function infra_save_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return rename($tmp, $path);
}
