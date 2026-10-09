<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_reference_memberships', function (Blueprint $table): void {
            $table->string('reference_type', 32);
            $table->unsignedBigInteger('reference_id');
            $table->primary(['reference_type', 'reference_id']);
            $table->index('reference_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_reference_memberships');
    }
};
