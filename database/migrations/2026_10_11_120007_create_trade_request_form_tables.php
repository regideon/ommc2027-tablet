<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('trade_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('trade_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();
            $table->foreignId('product_id')->constrained('trade_products')->cascadeOnDelete();
            $table->unsignedTinyInteger('rsm_status')->index()->default(1);
            $table->unsignedTinyInteger('tdh_status')->index()->default(1);
            $table->unsignedTinyInteger('tds_status')->index()->default(1);
            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_request_id')->constrained('trade_requests')->cascadeOnDelete();
            $table->foreignId('trade_type_id')->constrained('trade_types')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedTinyInteger('size')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('trade_request_id')->constrained('trade_requests')->cascadeOnDelete();
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
