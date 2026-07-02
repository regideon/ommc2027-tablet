<?php

namespace Database\Seeders;

use App\Models\GeneralCategory;
use Illuminate\Database\Seeder;

class GeneralCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            ['name' => 'Mixed Outlet', 'priority_visit' => 1, 'duration_per_visit' => 25, 'sort' => 1],
            ['name' => 'Competitor Dealer', 'priority_visit' => 2, 'duration_per_visit' => 25, 'sort' => 2],
            ['name' => 'Exclusive Dealer', 'priority_visit' => 3, 'duration_per_visit' => 15, 'sort' => 3],
            ['name' => 'Exclusive Distributor', 'priority_visit' => 4, 'duration_per_visit' => 120, 'sort' => 4],
        ];

        foreach ($data as $item) {
            GeneralCategory::firstOrCreate(['name' => $item['name']], $item);
        }
    }
}
