<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crr_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('crrs', function (Blueprint $table) {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();

            $table->foreignId('crr_program_id')->constrained('crr_programs')->cascadeOnDelete();
            $table->string('date_implemented')->nullable();
            $table->decimal('amount', 10, 2);
            $table->text('details')->nullable();
            $table->text('remarks')->nullable();
            $table->string('sap_doc_number')->nullable();

            $table->unsignedTinyInteger('rsm_status')->default(1);
            $table->unsignedTinyInteger('sps_status')->default(1);
            $table->unsignedTinyInteger('spm_status')->default(1);

            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crr_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('crr_id')->constrained('crrs')->cascadeOnDelete();
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
