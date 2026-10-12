<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();

            $table->string('name');

            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_check_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_check_id')->constrained('trade_checks')->cascadeOnDelete();
            $table->unsignedTinyInteger('category');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('trade_check_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('trade_check_groups')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('type');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_image_required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('trade_check_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_check_id')->constrained('trade_checks')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('trade_check_items')->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();

            $table->unsignedTinyInteger('rsm_status')->index()->default(1);
            $table->unsignedTinyInteger('tdh_status')->index()->default(1);

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_check_answer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_check_id')->constrained('trade_checks')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('trade_check_items')->cascadeOnDelete();
            $table->string('answer')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_check_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('trade_check_id')->constrained('trade_checks')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('trade_check_items')->cascadeOnDelete();
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
