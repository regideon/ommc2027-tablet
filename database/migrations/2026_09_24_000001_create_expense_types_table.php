<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('expense_types')->insert([
            ['code' => 'lodging', 'label' => 'Lodging', 'is_enabled' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'communication_expenses', 'label' => 'Communication expenses', 'is_enabled' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ancillary_expenses', 'label' => 'Ancillary expenses: Supplies, parcel, etc', 'is_enabled' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'representation', 'label' => 'Representation', 'is_enabled' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'emergency_expenses', 'label' => 'Emergency expenses: Medical, vehicle repair, etc', 'is_enabled' => true, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'repairs_and_maintenance', 'label' => 'Repairs and Maintenance', 'is_enabled' => true, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'per_diem', 'label' => 'Per Diem', 'is_enabled' => true, 'sort_order' => 7, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'transportation_toll', 'label' => 'Transportation - Toll', 'is_enabled' => true, 'sort_order' => 8, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'transportation_gas', 'label' => 'Transportation - Gas', 'is_enabled' => true, 'sort_order' => 9, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'transportation_parking', 'label' => 'Transportation - Parking', 'is_enabled' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'transportation_commute', 'label' => 'Transportation - Commute', 'is_enabled' => true, 'sort_order' => 11, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'staff_meeting', 'label' => 'Staff Meeting', 'is_enabled' => true, 'sort_order' => 12, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'airfare', 'label' => 'Airfare', 'is_enabled' => true, 'sort_order' => 13, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        // Expense schema is forward-only by project safety policy.
    }
};
