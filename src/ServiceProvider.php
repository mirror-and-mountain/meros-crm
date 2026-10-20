<?php

namespace MM\Meros\Crm;

use MM\Meros\App\Providers\PackageServiceProvider;
use MM\Meros\Crm\App\Models\PortalUser;

class ServiceProvider extends PackageServiceProvider {
    protected function init(): void {
        $this->setPackageClass(MerosCrm::class);
    }

    protected function beforeBoot(): void {
        $this->configurePortalAuthProvider();
    }

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
