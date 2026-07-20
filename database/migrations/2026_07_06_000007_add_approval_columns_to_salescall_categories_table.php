<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salescall_categories', function (Blueprint $table) {
            $table->string('approval_status')->default('pending_drm');
            $table->unsignedBigInteger('drm_approved_by')->nullable();
            $table->timestamp('drm_approved_at')->nullable();
            $table->unsignedBigInteger('rsm_approved_by')->nullable();
            $table->timestamp('rsm_approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salescall_categories', function (Blueprint $table) {
            $table->dropColumn([
                'approval_status', 'drm_approved_by', 'drm_approved_at',
                'rsm_approved_by', 'rsm_approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
            ]);
        });
    }
};
