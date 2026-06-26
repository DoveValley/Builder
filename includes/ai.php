<?php
// AI block registry — load, retrieve, and save ai_block_types.json

function ai_load_registry(): array {
    if (!defined('AI_REGISTRY_FILE') || !file_exists(AI_REGISTRY_FILE)) return [];
    $raw = json_decode(file_get_contents(AI_REGISTRY_FILE), true);
    return is_array($raw) ? $raw : [];
}

function ai_get_block_type(string $type_id): ?array {
    $registry = ai_load_registry();
    return $registry[$type_id] ?? null;
}

function ai_save_registry(array $registry): bool {
    if (!defined('AI_REGISTRY_FILE')) return false;
    $dir = dirname(AI_REGISTRY_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $content = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = AI_REGISTRY_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false) return false;
    return rename($tmp, AI_REGISTRY_FILE);
}

function ai_valid_type_id(string $id): bool {
    return (bool) preg_match('/^[a-z][a-z0-9_]{1,59}$/', $id);
}
