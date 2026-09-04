<?php

use Illuminate\Database\Schema\Blueprint;
use MM\Meros\Contracts\Features\Data\TableCreator;

return new class extends TableCreator {

    protected function configure(): void {
        $this->required(true);
        $this->description('The meros_crm_accounts table stores CRM account information.');
        
        $this->define(function (Blueprint $table) {
            $table->id();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }
};