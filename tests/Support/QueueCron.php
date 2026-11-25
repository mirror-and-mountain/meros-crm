<?php

const DB_NAME = 'meros_crm_cron_test';

class WP_Error {
    public function get_error_message(): string { return 'Scheduling failed'; }
}
function add_filter(string $hook, Closure $callback, int $priority = 10): void { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function add_action(string $hook, Closure $callback, int $priority = 10): void { add_filter($hook, $callback, $priority); }
function apply_filters(string $hook, mixed $value): mixed {
    $priorities = $GLOBALS['hooks'][$hook] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as $callback) { $value = $callback($value); }
    }
    return $value;
}
function do_action(string $hook): void {
    foreach ($GLOBALS['hooks'][$hook] ?? [] as $callbacks) {
        foreach ($callbacks as $callback) { $callback(); }
    }
}
function wp_next_scheduled(string $hook): int|false { return $GLOBALS['scheduled'][$hook]['timestamp'] ?? false; }
function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wpError = false): bool|WP_Error {
    if (isset($GLOBALS['schedule_result'])) return $GLOBALS['schedule_result'];
    $GLOBALS['schedule_calls'] = ($GLOBALS['schedule_calls'] ?? 0) + 1;
    $GLOBALS['scheduled'][$hook] = compact('timestamp', 'recurrence', 'args');
    return true;
}
function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wpError = false): int {
    $count = isset($GLOBALS['scheduled'][$hook]) ? 1 : 0;
    unset($GLOBALS['scheduled'][$hook]);
    return $count;
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
