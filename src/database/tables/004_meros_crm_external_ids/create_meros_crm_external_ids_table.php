<?php

use Illuminate\Database\Schema\Blueprint;
use MM\Meros\Contracts\Features\Data\TableCreator;

return new class extends TableCreator {

    protected function configure(): void {
        $this->required(true);
        $this->dependsOn('meros_crm_accounts');
        $this->dependsOn('meros_crm_contacts');
        $this->description('The meros_crm_external_ids table stores external IDs for CRM accounts and contacts.');
        
        $this->define(function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('meros_crm_accounts')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('meros_crm_contacts')->cascadeOnDelete();
            $table->string('integration_id')->nullable();
            $table->string('external_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }
};