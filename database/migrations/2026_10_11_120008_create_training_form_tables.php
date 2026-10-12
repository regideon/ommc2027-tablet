<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('training_positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('trainings', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('training_topics')->nullOnDelete();
            $table->string('other_topic')->nullable();
            $table->dateTime('preferred_at')->index();
            $table->unsignedInteger('omcc_volume')->nullable();
            $table->unsignedInteger('competitor_volume')->nullable();
            $table->unsignedTinyInteger('rsm_status')->index()->default(1);
            $table->unsignedTinyInteger('tdh_status')->index()->default(1);
            $table->unsignedTinyInteger('tds_status')->index()->default(1);
            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('training_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('position_id')->constrained('training_positions')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('training_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('training_id')->constrained('trainings')->cascadeOnDelete();
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
