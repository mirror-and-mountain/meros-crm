<?php

namespace MM\Meros\Crm\Tests\Integration;

use MM\Meros\Crm\App\Integrations\Salesforce;
use MM\Meros\Crm\MerosCrm;
use MM\Meros\Facades\Data\Integrations;
use MM\Meros\Testing\PackageTestCase;

/**
 * Verifies that a disabled CRM package is discovered without configuring its services.
 */
final class DisabledPackageTest extends PackageTestCase
{
    protected function packageClass(): string { return MerosCrm::class; }
    protected function packageHandle(): string { return 'meros_crm'; }
    protected function packageEnabled(): bool { return false; }

    public function test_disabled_package_is_discovered_without_configuring_services(): void
    {
        $this->package();
        $this->assertFalse(Integrations::all()->contains(fn ($integration) => $integration instanceof Salesforce));
        $this->assertNull($this->app['config']->get('queue.connections.meros_crm'));
    }
}
