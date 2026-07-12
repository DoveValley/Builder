<?php
// Hook system — thin action/filter dispatcher.
// add_hook()    — register a listener on a named hook
// run_hook()    — fire all listeners (action)
// filter_hook() — fire all listeners, each transforms $value (filter)

$GLOBALS['_hooks'] = [];

function add_hook(string $name, callable $fn, int $priority = 10): void {
    $GLOBALS['_hooks'][$name][] = ['fn' => $fn, 'priority' => $priority];
}

function run_hook(string $name, ...$args): void {
    $hooks = $GLOBALS['_hooks'][$name] ?? [];
    usort($hooks, fn($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($hooks as $h) ($h['fn'])(...$args);
}

function filter_hook(string $name, $value, ...$args) {
    $hooks = $GLOBALS['_hooks'][$name] ?? [];
    usort($hooks, fn($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($hooks as $h) $value = ($h['fn'])($value, ...$args);
    return $value;
}

// route_hook() — routing dispatch: fire listeners in priority order, FIRST non-null
// result wins (short-circuits). Generic; a no-op when no listener is registered.
// Listener signature: fn(...$args): ?array  — return null to pass, or a render
// payload array to claim the request. See page.php's route_request seam.
function route_hook(string $name, ...$args): ?array {
    $hooks = $GLOBALS['_hooks'][$name] ?? [];
    usort($hooks, fn($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($hooks as $h) {
        $res = ($h['fn'])(...$args);
        if ($res !== null) return $res;
    }
    return null;
}
