<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'customer_code_reservation_token')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->uuid('customer_code_reservation_token')->nullable()->after('unique_id');
            });
        }

        if (! Schema::hasColumn('customers', 'province_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->unsignedBigInteger('province_id')->nullable()->after('region_specific_id');
                $table->index('province_id');
            });
        }

        if (! Schema::hasColumn('customers', 'barangay_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->unsignedBigInteger('barangay_id')->nullable()->after('municipality_id');
                $table->index('barangay_id');
            });
        }

        if (! Schema::hasColumn('customers', 'area_cluster_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->unsignedBigInteger('area_cluster_id')->nullable()->after('region_specific_id');
                $table->index('area_cluster_id');
            });
        }

        if (! Schema::hasTable('area_clusters')) {
            Schema::create('area_clusters', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('region_specific_id')->nullable();
                $table->string('name');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                $table->index(['region_specific_id', 'enabled']);
            });
        }

        if (! Schema::hasTable('barangays')) {
            Schema::create('barangays', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('municipality_id');
                $table->string('name');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                $table->index(['municipality_id', 'enabled']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('barangays');
        Schema::dropIfExists('area_clusters');

        if (Schema::hasColumn('customers', 'customer_code_reservation_token')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('customer_code_reservation_token');
            });
        }

        foreach (['area_cluster_id', 'barangay_id', 'province_id'] as $column) {
            if (Schema::hasColumn('customers', $column)) {
                Schema::table('customers', function (Blueprint $table) use ($column): void {
                    $table->dropIndex(['customers_'.$column.'_index']);
                    $table->dropColumn($column);
                });
            }
        }
    }
};
