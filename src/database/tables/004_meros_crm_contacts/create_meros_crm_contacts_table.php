<?php

use Illuminate\Database\Schema\Blueprint;
use MM\Meros\Contracts\Features\Data\TableCreator;

return new class extends TableCreator {
    
    protected function configure(): void {
        $this->required(false);
        $this->description('The meros_crm_contacts table stores CRM contact information.');
        
        $this->define(function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('primary_email')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }
};