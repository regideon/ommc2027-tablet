<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a locally edited rep base location that has not been pushed to the
     * portal yet. While this is true, an incoming pull must not overwrite the
     * local coordinates, and the regular push loop sends them once.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'base_location_pending')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('base_location_pending')->default(false)->after('base_end_longitude');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'base_location_pending')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('base_location_pending');
        });
    }
};
