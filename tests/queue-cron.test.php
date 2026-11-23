<?php

require __DIR__ . '/../../../autoload.php';
require __DIR__ . '/../src/app/Jobs/InvokeSalesforceFlow.php';
require __DIR__ . '/../src/ServiceProvider.php';

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use MM\Meros\Crm\ServiceProvider;

const DB_NAME = 'meros_crm_cron_test';

class WP_Error {
    public function get_error_message(): string { return 'Scheduling failed'; }
}

function config(string|array $key): mixed {
    $config = Container::getInstance()->make('config');
    if (is_array($key)) {
        $config->set($key);
        return null;
    }
    return $config->get($key);
}

function add_filter(string $hook, Closure $callback, int $priority = 10): void {
    $GLOBALS['hooks'][$hook][$priority][] = $callback;
}

function add_action(string $hook, Closure $callback, int $priority = 10): void {
    add_filter($hook, $callback, $priority);
}

function apply_filters(string $hook, mixed $value): mixed {
    $priorities = $GLOBALS['hooks'][$hook] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = $callback($value);
        }
    }
    return $value;
}

function do_action(string $hook): void {
    foreach ($GLOBALS['hooks'][$hook] ?? [] as $callbacks) {
        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}

function wp_next_scheduled(string $hook): int|false {
    return $GLOBALS['scheduled'][$hook]['timestamp'] ?? false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wpError = false): bool|WP_Error {
    if (isset($GLOBALS['schedule_result'])) {
        return $GLOBALS['schedule_result'];
    }
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

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectFailure(Closure $callback, string $message): void {
    try {
        $callback();
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), $message), 'Unexpected failure: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

$container = new Container();
Container::setInstance($container);
Facade::setFacadeApplication($container);
$container->instance('config', new Repository());
$provider = new ServiceProvider($container);
(new ReflectionMethod($provider, 'beforeBoot'))->invoke($provider);

$capsule = new Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wp_']);
$container->instance('db', $capsule->getDatabaseManager());
$schema = $capsule->getConnection()->getSchemaBuilder();
$artisan = new class {
    public array $calls = [];
    public ?Closure $onCall = null;
    public int $status = 0;
    public function call(string $command, array $arguments): int {
        $this->calls[] = [$command, $arguments];
        if ($this->onCall !== null) {
            ($this->onCall)();
        }
        return $this->status;
    }
};
Artisan::swap($artisan);
$logger = new class {
    public array $errors = [];
    public function error(string $message, array $context): void { $this->errors[] = $message; }
};
Log::swap($logger);
$wpdb = new class {
    public string $prefix = 'wp_test_';
    public string $last_error = '';
    public bool $locked = false;
    public bool $failAcquire = false;
    public bool $failRelease = false;
    public int $releases = 0;
    public array $queries = [];
    public function prepare(string $sql, string $lock): string {
        return str_replace('%s', "'{$lock}'", $sql);
    }
    public function get_var(string $sql): ?string {
        $this->queries[] = $sql;
        if (str_contains($sql, 'GET_LOCK')) {
            if ($this->failAcquire) {
                $this->last_error = 'Lock query failed';
                return null;
            }
            if ($this->locked) {
                return '0';
            }
            $this->locked = true;
            return '1';
        }
        $this->locked = false;
        $this->releases++;
        return $this->failRelease ? null : '1';
    }
};

$schedules = apply_filters('cron_schedules', ['hourly' => ['interval' => 3600]]);
check($schedules['meros_crm_every_minute']['interval'] === 60 && isset($schedules['hourly']), 'Cron must add its minute interval without replacing existing schedules.');
do_action('init');
do_action('init');
check($GLOBALS['schedule_calls'] === 1 && $GLOBALS['scheduled']['meros_crm_process_queue']['recurrence'] === 'meros_crm_every_minute', 'Repeated requests must not create duplicate scheduled events.');
check(wp_next_scheduled('meros_crm_process_queue') >= time(), 'The first tick must be scheduled in the future.');

do_action('meros_crm_process_queue');
check($artisan->calls === [] && $wpdb->queries === [], 'An uninstalled optional table must not start a worker or acquire a lock.');
$schema->create('meros_crm_jobs', function (Blueprint $table): void { $table->id(); });
$artisan->onCall = function () use ($wpdb): void {
    check($wpdb->locked, 'The worker must run while holding its lock.');
    do_action('meros_crm_process_queue');
};
do_action('meros_crm_process_queue');
check(count($artisan->calls) === 1, 'An overlapping cron drain must not start another worker.');
check($artisan->calls[0] === ['queue:work', [
    'connection' => 'meros_crm', '--queue' => 'meros_crm',
    '--stop-when-empty' => true, '--max-time' => 20, '--sleep' => 0,
    '--tries' => 1, '--timeout' => 60,
]], 'Cron must use bounded worker options on the dedicated CRM queue.');
check(!$wpdb->locked && $wpdb->releases === 1, 'The lock must be released after a successful drain.');

$artisan->onCall = function (): never { throw new RuntimeException('Worker failed'); };
expectFailure(fn () => do_action('meros_crm_process_queue'), 'Worker failed');
check(!$wpdb->locked && $wpdb->releases === 2, 'The lock must be released when the worker throws.');
$artisan->onCall = null;
$artisan->status = 1;
expectFailure(fn () => do_action('meros_crm_process_queue'), 'status 1');
check(!$wpdb->locked, 'A nonzero worker exit must release the lock and surface its failure.');
$artisan->status = 0;
$wpdb->failAcquire = true;
$count = count($artisan->calls);
expectFailure(fn () => do_action('meros_crm_process_queue'), 'Lock query failed');
check(count($artisan->calls) === $count, 'A lock query error must not run an unprotected worker.');
$wpdb->failAcquire = false;
$wpdb->failRelease = true;
do_action('meros_crm_process_queue');
check(count($logger->errors) === 1, 'A lock release failure must be logged explicitly.');
$wpdb->failRelease = false;

add_filter('meros_crm_queue_cron_enabled', fn (bool $enabled): bool => false);
do_action('init');
check(wp_next_scheduled('meros_crm_process_queue') === false, 'Opting out must remove the scheduled event.');
$count = count($artisan->calls);
do_action('meros_crm_process_queue');
check(count($artisan->calls) === $count, 'A stale cron tick must respect the opt-out.');
unset($GLOBALS['hooks']['meros_crm_queue_cron_enabled']);
foreach ([new WP_Error(), false] as $result) {
    $GLOBALS['schedule_result'] = $result;
    expectFailure(fn () => do_action('init'), 'Unable to update the Meros CRM queue schedule');
}

echo "CRM queue cron tests passed.\n";
