<?php

use Illuminate\Database\Schema\Blueprint;
use MM\Meros\Contracts\Features\Data\TableCreator;

return new class extends TableCreator {

    protected function configure(): void {
        $this->required(true);
        $this->dependsOn('meros_crm_accounts');
        $this->dependsOn('meros_crm_contacts');
        $this->description('The meros_crm_account_contacts table stores CRM account contact relationships.');
        
        $this->define(function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('meros_crm_accounts')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('meros_crm_contacts')->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }
};