<?php

namespace MM\Meros\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;

use MM\Meros\Support\Migration;
use MM\Meros\Support\SchemaManager;

return new class extends Migration {

    public function up(string $installer): void {
        SchemaManager::create('meros_crm_contacts', $installer, function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }


    public function down(string $installer): void {
        SchemaManager::dropIfExists('meros_crm_contacts', $installer);
    }
};