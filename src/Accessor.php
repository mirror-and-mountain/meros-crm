<?php 

namespace MM\Meros\Crm;

use Illuminate\Support\Facades\Facade;

class Accessor extends Facade {
    protected static function getFacadeAccessor() {
        return MerosCrm::class;
    }
}