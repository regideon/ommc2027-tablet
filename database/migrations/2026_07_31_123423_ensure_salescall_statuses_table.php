<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original schema for this lookup table lives in
     * create_tablet_core_tables. On devices whose SQLite database was created
     * while the app still pointed at MySQL the core migration was recorded as
     * already run, so it never re-ran and the table is missing locally. Keep
     * this migration idempotent so it is safe on both fresh installs (table
     * already created by the core migration) and upgrades (table missing).
     */
    public function up(): void
    {
        if (! Schema::hasTable('salescall_statuses')) {
            Schema::create('salescall_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salescall_statuses');
    }
};
