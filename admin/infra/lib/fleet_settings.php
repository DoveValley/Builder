<?php
/**
 * infra/lib/fleet_settings.php — the few fleet-wide defaults an operator sets once.
 *
 *   golive_time        'HH:MM' (INFRA_TZ) a scheduled domain goes live at, unless the
 *                      schedule says otherwise. Default 09:00.
 *   cf_max_per_account how many domains one Cloudflare account may hold before placement
 *                      moves on to the box's next account. Default 20.
 *
 * Stored in config/fleet_settings.json. Every reader goes through infra_fleet_settings(),
 * which clamps, so a hand-edited or half-written file can't produce nonsense.
 */
require_once __DIR__ . '/store.php';

function infra_fleet_settings_defaults(): array
{
    return ['golive_time' => '09:00', 'cf_max_per_account' => 20];
}

function infra_fleet_settings_clean(array $raw): array
{
    $d = infra_fleet_settings_defaults();
    $t = trim((string) ($raw['golive_time'] ?? ''));
    $n = $raw['cf_max_per_account'] ?? null;
    return [
        'golive_time'        => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : $d['golive_time'],
        'cf_max_per_account' => is_numeric($n) ? max(1, min(1000, (int) $n)) : $d['cf_max_per_account'],
    ];
}

function infra_fleet_settings(): array
{
    return infra_fleet_settings_clean(infra_load_json(infra_config_path('fleet_settings.json'), []));
}

/** Merge $changes over the current settings, clamp, save. @return array the saved settings */
function infra_fleet_settings_save(array $changes): array
{
    $s = infra_fleet_settings_clean(array_merge(infra_fleet_settings(), array_filter($changes, fn($v) => $v !== '' && $v !== null)));
    infra_save_json(infra_config_path('fleet_settings.json'), $s);
    return $s;
}
