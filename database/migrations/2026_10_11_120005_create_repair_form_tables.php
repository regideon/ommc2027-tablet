<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repairs', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();

            $table->text('details');
            $table->string('plate_number');
            $table->string('invoice_number')->nullable();
            $table->decimal('amount', 10, 2);
            $table->text('remarks')->nullable();
            $table->string('sap_doc_number')->nullable();

            $table->unsignedTinyInteger('rsm_status')->index()->default(1);
            $table->unsignedTinyInteger('sps_status')->index()->default(1);
            $table->unsignedTinyInteger('spm_status')->index()->default(1);

            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('repair_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('repair_id')->constrained('repairs')->cascadeOnDelete();
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
