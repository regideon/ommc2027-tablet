<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->string('local_path');
            $table->string('storage_key')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('server_id');
            $table->index('expense_id');
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        // Expense schema is forward-only by project safety policy.
    }
};
