<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giveaways', function (Blueprint $table): void {
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

        Schema::create('giveaway_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
            $table->unsignedTinyInteger('category')->index();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('giveaway_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('giveaway_items')->cascadeOnDelete();
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();
            $table->foreignId('submitted_by')->constrained('users');
            $table->unsignedTinyInteger('rsm_status')->default(1);
            $table->timestamps();
        });

        Schema::create('giveaway_answer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('giveaway_items')->cascadeOnDelete();
            $table->string('answer')->nullable();
            $table->timestamps();
        });

        Schema::create('giveaway_signatures', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
            $table->foreignId('answer_id')->constrained('giveaway_answers')->cascadeOnDelete();
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();
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
