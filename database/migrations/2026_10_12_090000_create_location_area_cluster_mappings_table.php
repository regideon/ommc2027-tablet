<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_area_cluster_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('location_level', 20);
            $table->unsignedBigInteger('location_id');
            $table->string('decision', 32);
            $table->unsignedBigInteger('area_cluster_id')->nullable();
            $table->timestamps();
            $table->unique(['location_level', 'location_id'], 'location_area_cluster_mapping_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_area_cluster_mappings');
    }
};
