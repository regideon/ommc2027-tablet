<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lookup tables pulled from server
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10);
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('region_specifics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('region_id');
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('region_specific_id')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('name');
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('itinerary_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('salescall_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('salescall_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('itineraries', function (Blueprint $table) {
            $table->id();
            $table->date('date_month')->nullable();
            $table->date('date_year')->nullable();
            $table->string('remarks')->nullable();
            $table->unsignedSmallInteger('itinerary_status_id')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            // Sync fields
            $table->string('local_uuid', 36)->nullable()->unique();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('sync_status', 20)->default('pending');

            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('salescalls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('itinerary_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedSmallInteger('salescall_type_id')->default(1);
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->decimal('latitude_actual_in', 10, 6)->nullable();
            $table->decimal('longitude_actual_in', 10, 6)->nullable();
            $table->decimal('latitude_actual_out', 10, 6)->nullable();
            $table->decimal('longitude_actual_out', 10, 6)->nullable();
            $table->dateTime('actual_in')->nullable();
            $table->dateTime('actual_out')->nullable();

            $table->unsignedBigInteger('material_group_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('brand_other')->nullable();

            $table->decimal('collection_amount', 12, 4)->nullable();
            $table->string('collection_remarks')->nullable();
            $table->string('remarks')->nullable();
            $table->string('concerns')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            // Sync fields
            $table->string('local_uuid', 36)->nullable()->unique();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->timestamp('synced_at')->nullable();

            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salescalls');
        Schema::dropIfExists('itineraries');
        Schema::dropIfExists('salescall_types');
        Schema::dropIfExists('salescall_statuses');
        Schema::dropIfExists('itinerary_statuses');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('municipalities');
        Schema::dropIfExists('region_specifics');
        Schema::dropIfExists('regions');
    }
};
