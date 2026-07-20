<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('salescall_categories')->where('approval_status', 'pending_drm')->update(['approval_status' => 'initial_approval']);
        DB::table('salescall_categories')->where('approval_status', 'pending_rsm')->update(['approval_status' => 'final_approval']);
    }

    public function down(): void
    {
        DB::table('salescall_categories')->where('approval_status', 'initial_approval')->update(['approval_status' => 'pending_drm']);
        DB::table('salescall_categories')->where('approval_status', 'final_approval')->update(['approval_status' => 'pending_rsm']);
    }
};
