<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror the portal's per-rep itinerary base location so the tablet can hold
     * the same coordinates locally. Kept nullable; older devices that sync
     * before the portal populates them simply stay null.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'base_start_latitude')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('base_start_latitude', 10, 6)->nullable()->after('rsm_id');
            $table->decimal('base_start_longitude', 10, 6)->nullable()->after('base_start_latitude');
            $table->decimal('base_end_latitude', 10, 6)->nullable()->after('base_start_longitude');
            $table->decimal('base_end_longitude', 10, 6)->nullable()->after('base_end_latitude');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'base_start_latitude')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'base_start_latitude',
                'base_start_longitude',
                'base_end_latitude',
                'base_end_longitude',
            ]);
        });
    }
};
