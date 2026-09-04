<?php

namespace MM\Meros\Crm;

use MM\Meros\App\Providers\PackageServiceProvider;

class ServiceProvider extends PackageServiceProvider {
    protected function init(): void {
        $this->setPackageClass(MerosCrm::class);
    }
}
