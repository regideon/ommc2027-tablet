<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attachment ownership moves from item to group. Old item-level rows
        // are intentionally discarded rather than backfilled.
        if (Schema::hasTable('trade_check_attachments')) {
            foreach (DB::table('trade_check_attachments')->get() as $attachment) {
                if ($attachment->local_path && is_file($attachment->local_path)) {
                    @unlink($attachment->local_path);
                }

                $extension = strtolower(pathinfo((string) $attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
                $previewPath = public_path("trade_check_attachments/{$attachment->local_uuid}.{$extension}");

                if (is_file($previewPath)) {
                    @unlink($previewPath);
                }
            }
        }

        Schema::dropIfExists('trade_check_attachments');

        Schema::create('trade_check_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('trade_check_id')->constrained('trade_checks')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('trade_check_groups')->cascadeOnDelete();
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

        Schema::table('trade_check_groups', function (Blueprint $table): void {
            $table->boolean('is_image_required')->default(false);
        });

        Schema::table('trade_check_items', function (Blueprint $table): void {
            $table->dropColumn('is_image_required');
        });
    }

    public function down(): void
    {
        // Forward-only: discarded item-level attachment rows cannot be
        // restored, so this migration intentionally has no reverse.
    }
};
