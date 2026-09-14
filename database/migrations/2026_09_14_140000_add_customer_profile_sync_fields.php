<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('server_updated_at')->nullable()->after('synced_at');
        });

        Schema::table('customer_category_histories', function (Blueprint $table): void {
            $table->string('profile_type', 20)->nullable()->after('customer_id');
            $table->string('stream', 20)->nullable()->after('profile_type');
            $table->dropUnique('customer_category_histories_customer_id_category_year_unique');
            $table->unique(['customer_id', 'profile_type', 'stream', 'category_year'], 'customer_category_histories_stream_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_category_histories', function (Blueprint $table): void {
            $table->dropUnique('customer_category_histories_stream_unique');
            $table->unique(['customer_id', 'category_year'], 'customer_category_histories_customer_id_category_year_unique');
            $table->dropColumn(['profile_type', 'stream']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('server_updated_at');
        });
    }
};
