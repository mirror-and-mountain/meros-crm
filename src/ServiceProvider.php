<?php

namespace MM\Meros\Crm;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MM\Meros\App\Providers\PackageServiceProvider;
use MM\Meros\Crm\App\Jobs\InvokeSalesforceFlow;
use MM\Meros\Crm\App\Models\PortalUser;

class ServiceProvider extends PackageServiceProvider {
    private const QUEUE_CRON_HOOK = 'meros_crm_process_queue';

    protected function init(): void {
        $this->setPackageClass(MerosCrm::class);
    }

    protected function beforeBoot(): void {
        $configPath = dirname(__DIR__) . '/config';
        $this->publishesConfig($configPath, 'meros-crm-');
        $this->mergePackageConfig(
            $configPath . '/integrations.php',
            'integrations',
            'meros-crm-integrations.php'
        );

        $this->configurePortalAuthProvider();
        $this->configureQueue();
        $this->configureQueueCron();
    }

    /**
     * Configures the queue connection for the InvokeSalesforceFlow job.
     *
     * @return void
     */
    private function configureQueue(): void {
        config([
            'queue.connections.' . InvokeSalesforceFlow::CONNECTION => [
                'driver'       => 'database',
                'connection'   => null,
                'table'        => 'meros_crm_jobs',
                'queue'        => 'meros_crm',
                'retry_after'  => 90,
                'after_commit' => true,
            ],
        ]);
    }

    /**
     * Configures the cron schedule for processing the Meros CRM queue.
     *
     * @return void
     */
    private function configureQueueCron(): void {
        add_filter('cron_schedules', function (array $schedules): array {
            $schedules['meros_crm_every_minute'] = [
                'interval' => 60,
                'display' => 'Every Minute (Meros CRM)',
            ];
            return $schedules;
        });

        add_action('init', function (): void {
            if (!apply_filters('meros_crm_queue_cron_enabled', true)) {
                $result = wp_clear_scheduled_hook(self::QUEUE_CRON_HOOK, [], true);
            } elseif (wp_next_scheduled(self::QUEUE_CRON_HOOK) === false) {
                $result = wp_schedule_event(time() + 60, 'meros_crm_every_minute', self::QUEUE_CRON_HOOK, [], true);
            } else {
                return;
            }

            if (is_wp_error($result)) {
                throw new \RuntimeException('Unable to update the Meros CRM queue schedule: ' . $result->get_error_message());
            }
            if ($result === false) {
                throw new \RuntimeException('Unable to update the Meros CRM queue schedule.');
            }
        });

        add_action(self::QUEUE_CRON_HOOK, function (): void {
            $this->runQueueWorker();
        });
    }

    /**
     * Runs the Meros CRM queue worker to process queued jobs.
     *
     * @return void
     */
    private function runQueueWorker(): void {
        if (!apply_filters('meros_crm_queue_cron_enabled', true)) {
            return;
        }

        $connection = config('queue.connections.' . InvokeSalesforceFlow::CONNECTION);
        if (!Schema::connection($connection['connection'])->hasTable($connection['table'])) {
            return;
        }

        global $wpdb;

        // A connection-scoped lock survives long jobs and is released if PHP terminates.
        $lockName = 'meros_crm_queue_' . md5(DB_NAME . ':' . $wpdb->prefix);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName));
        if ((string) $acquired === '0') {
            return;
        }
        if ((string) $acquired !== '1') {
            throw new \RuntimeException('Unable to acquire the Meros CRM queue lock: ' . $wpdb->last_error);
        }

        try {
            $status = Artisan::call('queue:work', [
                'connection' => InvokeSalesforceFlow::CONNECTION,
                '--queue' => $connection['queue'],
                '--stop-when-empty' => true,
                '--max-time' => 20,
                '--sleep' => 0,
                '--tries' => 1,
                '--timeout' => 60,
            ]);
            if ($status !== 0) {
                throw new \RuntimeException("The Meros CRM queue worker exited with status {$status}.");
            }
        } finally {
            $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            if ((string) $released !== '1') {
                Log::error('Unable to release the Meros CRM queue lock.', ['error' => $wpdb->last_error]);
            }
        }
    }

    /**
     * Configures the portal authentication provider.
     *
     * @return void
     */
    private function configurePortalAuthProvider(): void {
        config([
            'auth.defaults.guard' => 'portal',

            'auth.guards.portal' => [
                'driver'   => 'session',
                'provider' => 'portals',
            ],
            'auth.providers.portals' => [
                'driver' => 'eloquent',
                'model'  => PortalUser::class,
            ],

            'auth.passwords.portal_users' => [
                'provider' => 'portals',
                'table'    => 'meros_crm_portal_password_reset_tokens',
                'expire'   => 60,
                'throttle' => 60,
            ],

            'session.driver' => 'database',
            'session.table'  => 'meros_crm_portal_sessions',
        ]);
    }
}
