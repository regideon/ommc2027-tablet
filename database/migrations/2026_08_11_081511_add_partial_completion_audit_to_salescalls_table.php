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
        Schema::table('salescalls', function (Blueprint $table) {
            $table->dateTime('partially_completed_at')->nullable();
            $table->text('partially_completed_reason')->nullable();
            $table->unsignedBigInteger('partially_completed_by')->nullable();
            $table->dateTime('resumed_at')->nullable();
            $table->unsignedBigInteger('resumed_by')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salescalls', function (Blueprint $table) {
            $table->dropColumn([
                'partially_completed_at',
                'partially_completed_reason',
                'partially_completed_by',
                'resumed_at',
                'resumed_by',
            ]);
        });
    }
};
