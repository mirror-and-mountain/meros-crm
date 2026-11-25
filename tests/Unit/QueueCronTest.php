<?php

namespace MM\Meros\Crm\Tests\Unit;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use MM\Meros\Crm\ServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use WP_Error;

/**
 * Tests queue cron scheduling, opt-out cleanup, worker locking, bounded execution and failure handling.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class QueueCronTest extends TestCase {
    private object $schema;
    private object $artisan;
    private object $logger;
    private object $wpdb;

    protected function setUp(): void {
        require_once dirname(__DIR__) . '/Support/QueueCron.php';
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository());
        $provider = new ServiceProvider($container);
        (new ReflectionMethod($provider, 'beforeBoot'))->invoke($provider);
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wp_']);
        $container->instance('db', $capsule->getDatabaseManager());
        $this->schema = $capsule->getConnection()->getSchemaBuilder();
        $this->artisan = new class {
            public array $calls = [];
            public ?Closure $onCall = null;
            public int $status = 0;
            public function call(string $command, array $arguments): int {
                $this->calls[] = [$command, $arguments];
                if ($this->onCall !== null) ($this->onCall)();
                return $this->status;
            }
        };
        Artisan::swap($this->artisan);
        $this->logger = new class {
            public array $errors = [];
            public function error(string $message, array $context): void { $this->errors[] = $message; }
        };
        Log::swap($this->logger);
        $this->wpdb = $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_test_';
            public string $last_error = '';
            public bool $locked = false;
            public bool $failAcquire = false;
            public bool $failRelease = false;
            public int $releases = 0;
            public array $queries = [];
            public function prepare(string $sql, string $lock): string { return str_replace('%s', "'{$lock}'", $sql); }
            public function get_var(string $sql): ?string {
                $this->queries[] = $sql;
                if (str_contains($sql, 'GET_LOCK')) {
                    if ($this->failAcquire) { $this->last_error = 'Lock query failed'; return null; }
                    if ($this->locked) return '0';
                    $this->locked = true;
                    return '1';
                }
                $this->locked = false;
                $this->releases++;
                return $this->failRelease ? null : '1';
            }
        };
    }

    private function assertFailure(Closure $callback, string $message): void {
        try {
            $callback();
            self::fail('Expected failure: ' . $message);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function testSchedulingIsIdempotentAndOptOutClearsExistingEvent(): void {
        $schedules = apply_filters('cron_schedules', ['hourly' => ['interval' => 3600]]);
        self::assertSame(60, $schedules['meros_crm_every_minute']['interval']);
        self::assertArrayHasKey('hourly', $schedules);
        do_action('init');
        do_action('init');
        self::assertSame(1, $GLOBALS['schedule_calls']);
        self::assertSame('meros_crm_every_minute', $GLOBALS['scheduled']['meros_crm_process_queue']['recurrence']);
        self::assertGreaterThanOrEqual(time(), wp_next_scheduled('meros_crm_process_queue'));
        $this->schema->create('meros_crm_jobs', function (Blueprint $table): void { $table->id(); });
        add_filter('meros_crm_queue_cron_enabled', fn (bool $enabled): bool => false);
        do_action('init');
        self::assertFalse(wp_next_scheduled('meros_crm_process_queue'));
        do_action('meros_crm_process_queue');
        self::assertSame([], $this->artisan->calls);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testSchedulingFailuresAreReported(): void {
        foreach ([new WP_Error(), false] as $result) {
            $GLOBALS['schedule_result'] = $result;
            $this->assertFailure(fn () => do_action('init'), 'Unable to update the Meros CRM queue schedule');
        }
    }

    public function testWorkerRequiresTableAndLockAndUsesBoundedOptions(): void {
        do_action('meros_crm_process_queue');
        self::assertSame([], $this->artisan->calls);
        self::assertSame([], $this->wpdb->queries);
        $this->schema->create('meros_crm_jobs', function (Blueprint $table): void { $table->id(); });
        $wpdb = $this->wpdb;
        $this->artisan->onCall = function () use ($wpdb): void {
            self::assertTrue($wpdb->locked);
            do_action('meros_crm_process_queue');
        };
        do_action('meros_crm_process_queue');
        self::assertCount(1, $this->artisan->calls);
        self::assertSame(['queue:work', [
            'connection' => 'meros_crm', '--queue' => 'meros_crm',
            '--stop-when-empty' => true, '--max-time' => 20, '--sleep' => 0,
            '--tries' => 1, '--timeout' => 60,
        ]], $this->artisan->calls[0]);
        self::assertFalse($wpdb->locked);
        self::assertSame(1, $wpdb->releases);
    }

    public function testWorkerAndLockFailuresAreHandled(): void {
        $this->schema->create('meros_crm_jobs', function (Blueprint $table): void { $table->id(); });
        $this->artisan->onCall = function (): never { throw new RuntimeException('Worker failed'); };
        $this->assertFailure(fn () => do_action('meros_crm_process_queue'), 'Worker failed');
        self::assertFalse($this->wpdb->locked);
        self::assertSame(1, $this->wpdb->releases);
        $this->artisan->onCall = null;
        $this->artisan->status = 1;
        $this->assertFailure(fn () => do_action('meros_crm_process_queue'), 'status 1');
        self::assertFalse($this->wpdb->locked);
        $this->artisan->status = 0;
        $this->wpdb->failAcquire = true;
        $count = count($this->artisan->calls);
        $this->assertFailure(fn () => do_action('meros_crm_process_queue'), 'Lock query failed');
        self::assertCount($count, $this->artisan->calls);
        $this->wpdb->failAcquire = false;
        $this->wpdb->failRelease = true;
        do_action('meros_crm_process_queue');
        self::assertCount(1, $this->logger->errors);
    }
}
