<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profile_attachments', function (Blueprint $table) {
            $table->string('s3_key')->nullable()->after('local_path');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profile_attachments', function (Blueprint $table) {
            $table->dropColumn('s3_key');
        });
    }
};
