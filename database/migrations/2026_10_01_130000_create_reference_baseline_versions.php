<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_baseline_versions', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('version');
            $table->timestamp('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_baseline_versions');
    }
};
