<?php
/**
 * infra/lib/map_meta.php — storage for Map-tab-only, per-server fields (today:
 * which way a dot's leader line points). Deliberately its OWN file, map_meta.json
 * — NEVER hestia.json, which holds real Hestia credentials and is what the rest
 * of the console (Servers tab, every sweep) depends on.
 *
 * This split exists because of a real incident: testing a version of this feature
 * that wrote into hestia.json, a stray `cp` run as root reset that file's owner
 * away from www-data, and Apache could no longer read it — the Servers tab showed
 * zero servers even though nothing was actually lost. Keeping the Map tab's own
 * fields in a separate, non-credential file means the worst this feature can ever
 * do to itself is lose its own cosmetic preferences — it structurally cannot take
 * the rest of the console down with it.
 */
require_once __DIR__ . '/store.php';

function infra_map_meta_path(): string { return infra_config_path('map_meta.json'); }

/** @return array<string,array> server id => its stored fields */
function infra_map_meta_all(): array
{
    $d = infra_load_json(infra_map_meta_path(), []);
    return is_array($d) ? $d : [];
}

function infra_map_meta_get(string $id): array
{
    $all = infra_map_meta_all();
    return is_array($all[$id] ?? null) ? $all[$id] : [];
}

/** Merge $fields into this id's stored object. Never replaces the whole record,
 *  and never touches any id but this one — same contract as the fields this
 *  feature writes elsewhere. */
function infra_map_meta_save(string $id, array $fields): bool
{
    $all = infra_map_meta_all();
    $all[$id] = array_merge(is_array($all[$id] ?? null) ? $all[$id] : [], $fields);
    return infra_save_json(infra_map_meta_path(), $all);
}
