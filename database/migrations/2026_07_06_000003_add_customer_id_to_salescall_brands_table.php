<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salescall_brands', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained();
        });

        DB::statement('UPDATE salescall_brands SET customer_id = (SELECT customer_id FROM salescalls WHERE salescalls.id = salescall_brands.salescall_id)');
    }

    public function down(): void
    {
        Schema::table('salescall_brands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
