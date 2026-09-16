<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table): void {
            $table->string('psgc_code', 20)->nullable()->unique()->after('code');
        });

        Schema::table('provinces', function (Blueprint $table): void {
            $table->unsignedBigInteger('region_id')->nullable()->after('id');
            $table->string('psgc_code', 20)->nullable()->unique()->after('region_id');
            $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
            $table->index(['region_id', 'enabled']);
        });

        Schema::table('municipalities', function (Blueprint $table): void {
            $table->string('psgc_code', 20)->nullable()->unique()->after('id');
            $table->string('locality_type', 40)->nullable()->after('province_id');
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table): void {
            $table->dropUnique(['psgc_code']);
            $table->dropColumn(['psgc_code', 'locality_type']);
        });

        Schema::table('provinces', function (Blueprint $table): void {
            $table->dropForeign(['region_id']);
            $table->dropUnique(['psgc_code']);
            $table->dropIndex(['region_id', 'enabled']);
            $table->dropColumn(['region_id', 'psgc_code']);
        });

        Schema::table('regions', function (Blueprint $table): void {
            $table->dropUnique(['psgc_code']);
            $table->dropColumn('psgc_code');
        });
    }
};
