<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('province_id')->nullable()->after('region_specific_id')->index();
            $table->unsignedBigInteger('barangay_id')->nullable()->after('municipality_id')->index();
            $table->unsignedBigInteger('area_cluster_id')->nullable()->after('region_specific_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['province_id', 'barangay_id', 'area_cluster_id']);
        });
    }
};
