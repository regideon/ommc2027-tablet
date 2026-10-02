<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_clusters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('region_specific_id');
            $table->string('code', 100);
            $table->string('name', 255);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['region_specific_id', 'code']);
            $table->index(['region_specific_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_clusters');
    }
};
