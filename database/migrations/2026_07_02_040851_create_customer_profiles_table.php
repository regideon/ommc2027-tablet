<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salescall_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_category_id')->constrained();
            $table->string('registered_name');
            $table->string('owner_name');
            $table->text('address');
            $table->string('tin')->nullable();
            $table->string('landline')->nullable();
            $table->string('mobile')->nullable();
            $table->string('classification')->nullable();
            $table->string('incentive_type')->nullable();
            $table->date('birthday')->nullable();
            $table->string('gender')->nullable();
            $table->string('marital_status')->nullable();
            $table->json('brand_products')->nullable();
            $table->string('signature_path')->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('local_uuid')->unique();
            $table->string('sync_status')->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
