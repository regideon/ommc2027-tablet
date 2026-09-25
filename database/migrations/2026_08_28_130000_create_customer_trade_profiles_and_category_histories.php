<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
        });

        Schema::create('customer_trade_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->unique();
            $table->string('house_number')->nullable();
            $table->string('entry_detail')->nullable();
            $table->json('classifications')->nullable();
            $table->json('ommc_brands')->nullable();
            $table->json('ommc_mcb_brands')->nullable();
            $table->json('tpl_pollux')->nullable();
            $table->json('other_competitor_brands')->nullable();
            $table->json('mcb_competitors')->nullable();
            $table->text('other_competitors_note')->nullable();
            $table->json('working_days')->nullable();
            $table->json('operating_hours')->nullable();
            $table->boolean('motiv_user')->nullable();
            $table->string('delivery_method')->nullable();
            $table->string('ulab')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_category_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedSmallInteger('category_year');
            $table->string('category');
            $table->timestamps();
            $table->unique(['customer_id', 'category_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_category_histories');
        Schema::dropIfExists('customer_trade_profiles');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('company_id'));
    }
};
