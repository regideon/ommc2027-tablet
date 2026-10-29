<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Approved reset: existing local Giveaway data is discarded so the
        // masterdata and per-giveaway photo/signature structure starts clean.
        if (Schema::hasTable('giveaway_signatures')) {
            foreach (DB::table('giveaway_signatures')->get() as $signature) {
                if ($signature->local_path && is_file($signature->local_path)) {
                    @unlink($signature->local_path);
                }

                $extension = strtolower(pathinfo((string) $signature->local_path, PATHINFO_EXTENSION)) ?: 'bin';
                $previewPath = public_path("giveaway_signatures/{$signature->local_uuid}.{$extension}");

                if (is_file($previewPath)) {
                    @unlink($previewPath);
                }
            }
        }

        DB::table('giveaways')->delete();

        Schema::dropIfExists('giveaway_photos');
        Schema::dropIfExists('giveaway_signatures');
        Schema::dropIfExists('giveaway_answer_items');
        Schema::dropIfExists('giveaway_answers');
        Schema::dropIfExists('giveaway_items');

        Schema::create('giveaway_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->unsignedTinyInteger('category')->index();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('giveaway_submission_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
            $table->foreignId('giveaway_item_id')->constrained('giveaway_items')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('giveaway_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('giveaway_submission_items')->cascadeOnDelete();
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();
            $table->foreignId('submitted_by')->constrained('users');
            $table->unsignedTinyInteger('rsm_status')->default(1);
            $table->timestamps();
        });

        Schema::create('giveaway_answer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('giveaway_submission_items')->cascadeOnDelete();
            $table->string('answer')->nullable();
            $table->timestamps();
        });

        Schema::create('giveaway_signatures', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
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

        Schema::create('giveaway_photos', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('giveaway_id')->constrained('giveaways')->cascadeOnDelete();
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
        // Forward-only: the discarded local Giveaway data cannot be restored.
    }
};
