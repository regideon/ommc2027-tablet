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
        if (Schema::hasColumn('salescalls', 'visit_date')) {
            return;
        }

        Schema::table('salescalls', function (Blueprint $table) {
            $table->dateTime('visit_date')->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('salescalls', function (Blueprint $table) {
            $table->dropColumn('visit_date');
        });
    }
};
