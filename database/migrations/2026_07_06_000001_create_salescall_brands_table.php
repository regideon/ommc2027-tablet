<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salescall_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salescall_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_group_id')->constrained();
            $table->foreignId('brand_id')->constrained();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('brand_other')->nullable();

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
        Schema::dropIfExists('salescall_brands');
    }
};
