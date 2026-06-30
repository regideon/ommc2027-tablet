<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salescall_image_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('salescall_image_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('salescall_image_category_id');
            $table->string('name');
            $table->string('slug');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('salescall_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('salescall_id');
            $table->unsignedBigInteger('salescall_image_type_id');
            $table->string('local_path');          // absolute path on device
            $table->text('notes')->nullable();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salescall_images');
        Schema::dropIfExists('salescall_image_types');
        Schema::dropIfExists('salescall_image_categories');
    }
};
