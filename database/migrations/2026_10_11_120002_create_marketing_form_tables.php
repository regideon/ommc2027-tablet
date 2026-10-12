<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_programs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('marketings', function (Blueprint $table): void {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->dateTime('submitted_at')->index();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('region_type_id')->constrained('region_types')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('marketing_programs')->cascadeOnDelete();
            $table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();
            $table->dateTime('duration_start_at')->index();
            $table->dateTime('duration_end_at')->index();
            $table->string('objective');
            $table->string('name');
            $table->text('mechanics_description');
            $table->unsignedTinyInteger('sps_status')->default(1);
            $table->unsignedTinyInteger('rac_status')->default(1);
            $table->unsignedTinyInteger('rac_return_count')->default(0);
            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_volumes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_id')->constrained('marketings')->cascadeOnDelete();
            $table->decimal('before_program', 10, 2);
            $table->decimal('during_program', 10, 2);
            $table->decimal('volume', 10, 2);
            $table->timestamps();
        });

        Schema::create('marketing_realizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_id')->constrained('marketings')->cascadeOnDelete();
            $table->unsignedTinyInteger('stage');
            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->index();
            $table->decimal('volume', 10, 2);
            $table->timestamps();
        });

        Schema::create('marketing_mechanics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_id')->constrained('marketings')->cascadeOnDelete();
            $table->dateTime('period')->index();
            $table->decimal('unit_price', 10, 2);
            $table->decimal('sub_total', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Forms schema is forward-only by project safety policy.
    }
};
