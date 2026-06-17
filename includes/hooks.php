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
