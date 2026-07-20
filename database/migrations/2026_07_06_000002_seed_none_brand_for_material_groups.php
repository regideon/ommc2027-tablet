<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (range(1, 5) as $materialGroupId) {
            if (! DB::table('material_groups')->where('id', $materialGroupId)->exists()) {
                continue;
            }

            $exists = DB::table('brands')
                ->where('material_group_id', $materialGroupId)
                ->where('name', 'None')
                ->exists();

            if (! $exists) {
                DB::table('brands')->insert([
                    'material_group_id' => $materialGroupId,
                    'name' => 'None',
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('brands')->where('name', 'None')->whereIn('material_group_id', range(1, 5))->delete();
    }
};
