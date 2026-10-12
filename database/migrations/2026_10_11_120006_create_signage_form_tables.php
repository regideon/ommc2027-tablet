<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signage_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('signage_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('signages', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();
            $table->foreignId('product_id')->constrained('signage_products')->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('signage_types')->cascadeOnDelete();
            $table->unsignedTinyInteger('category')->index();
            $table->string('name');
            $table->unsignedTinyInteger('rsm_status')->index()->default(1);
            $table->unsignedTinyInteger('tdh_status')->index()->default(1);
            $table->unsignedTinyInteger('tds_status')->index()->default(1);
            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('signage_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('signage_id')->constrained('signages')->cascadeOnDelete();
            $table->unsignedTinyInteger('type')->index();
            $table->string('local_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Forms schema is forward-only by project safety policy.
    }
};
