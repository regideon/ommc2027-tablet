<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salescall_images', function (Blueprint $table) {
            $table->string('s3_key')->nullable()->after('local_path');
        });

        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('signature_s3_key')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('salescall_images', function (Blueprint $table) {
            $table->dropColumn('s3_key');
        });

        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropColumn('signature_s3_key');
        });
    }
};
