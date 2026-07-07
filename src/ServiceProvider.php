<?php

namespace MM\Meros\Crm;

use MM\Meros\App\Providers\PackageServiceProvider;

class ServiceProvider extends PackageServiceProvider {
    protected string $serviceClass = MerosCrm::class;
}
