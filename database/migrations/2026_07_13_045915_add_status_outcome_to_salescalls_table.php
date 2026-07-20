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
            $table->unsignedBigInteger('salescall_status_id')->nullable()->after('actual_out');
            $table->text('outcome_reason')->nullable()->after('salescall_status_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salescalls', function (Blueprint $table) {
            $table->dropColumn(['salescall_status_id', 'outcome_reason']);
        });
    }
};
