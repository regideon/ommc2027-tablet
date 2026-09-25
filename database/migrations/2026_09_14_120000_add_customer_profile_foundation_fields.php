<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('person_in_charge_id')->nullable()->after('is_active');
            $table->string('business_landline_number')->nullable()->after('contact_number');
            $table->string('business_mobile_number')->nullable()->after('business_landline_number');
            $table->date('date_established')->nullable()->after('business_mobile_number');
        });

        Schema::table('customer_trade_profiles', function (Blueprint $table): void {
            $table->string('profile_type', 20)->nullable()->index()->after('customer_id');
            $table->json('profile_data')->nullable()->after('profile_type');
        });
    }

    public function down(): void
    {
        Schema::table('customer_trade_profiles', function (Blueprint $table): void {
            $table->dropIndex(['profile_type']);
            $table->dropColumn(['profile_type', 'profile_data']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['person_in_charge_id', 'business_landline_number', 'business_mobile_number', 'date_established']);
        });
    }
};
