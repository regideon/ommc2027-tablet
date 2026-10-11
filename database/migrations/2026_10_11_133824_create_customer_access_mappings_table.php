<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_access_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 32);
            $table->string('mapping_key', 100);
            $table->boolean('is_complete')->default(false);
            $table->json('user_references');
            $table->timestamps();
            $table->unique(['scope', 'mapping_key'], 'customer_access_mapping_scope_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_access_mappings');
    }
};
