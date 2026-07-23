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
