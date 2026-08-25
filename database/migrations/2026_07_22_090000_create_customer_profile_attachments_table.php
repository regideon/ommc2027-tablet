<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profile_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('salescall_id');
            $table->string('local_path');          // absolute path on device
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
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
        Schema::dropIfExists('customer_profile_attachments');
    }
};
