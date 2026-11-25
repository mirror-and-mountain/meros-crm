<?php

namespace MM\Meros\Crm\Tests\Integration;

use MM\Meros\Crm\App\Integrations\Salesforce;
use MM\Meros\Crm\MerosCrm;
use MM\Meros\Facades\Data\Integrations;
use MM\Meros\Testing\PackageTestCase;

/**
 * Verifies that enabled CRM boot registers Salesforce and queue services and installs package tables.
 */
final class PackageBootTest extends PackageTestCase
{
    protected function packageClass(): string { return MerosCrm::class; }
    protected function packageHandle(): string { return 'meros_crm'; }

    public function test_enabled_package_registers_salesforce_and_queue_services(): void
    {
        $this->package();
        $this->assertInstanceOf(Salesforce::class, Integrations::connect('salesforce'));
        $this->assertSame('meros_crm_jobs', $this->app['config']->get('queue.connections.meros_crm.table'));
        $this->assertSame('database', $this->app['config']->get('session.driver'));
    }

    public function test_package_table_migrations_install_on_wordpress(): void
    {
        $this->installProviderTables($this->package());
        $this->assertTrue($this->app['db']->connection()->getSchemaBuilder()->hasTable('meros_crm_jobs'));
    }
}
