<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barangays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('municipality_id');
            $table->string('psgc_code', 20)->nullable()->unique();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['municipality_id', 'code']);
            $table->index(['municipality_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangays');
    }
};
