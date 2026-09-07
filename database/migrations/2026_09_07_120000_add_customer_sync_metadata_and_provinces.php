<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->uuid('local_uuid')->nullable()->unique()->after('id');
            $table->unsignedBigInteger('server_id')->nullable()->index()->after('local_uuid');
            $table->string('sync_status', 20)->default('synced')->index()->after('is_active');
            $table->unsignedTinyInteger('sync_attempts')->default(0)->after('sync_status');
            $table->text('sync_error')->nullable()->after('sync_attempts');
            $table->timestamp('synced_at')->nullable()->after('sync_error');
        });

        Schema::create('provinces', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('region_specific_id')->nullable();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['region_specific_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provinces');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['local_uuid']);
            $table->dropIndex(['server_id']);
            $table->dropIndex(['sync_status']);
            $table->dropColumn(['local_uuid', 'server_id', 'sync_status', 'sync_attempts', 'sync_error', 'synced_at']);
        });
    }
};
