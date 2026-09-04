<?php 

namespace MM\Meros\Crm\Facades;

use Illuminate\Support\Facades\Facade;
use MM\Meros\Crm\App\Integrations\Salesforce as SFIntegration;

class Salesforce extends Facade {
    protected static function getFacadeAccessor() {
        return SFIntegration::class;
    }
}