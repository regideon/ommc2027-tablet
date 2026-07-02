<?php

namespace Database\Seeders;

use App\Models\SalescallImageCategory;
use Illuminate\Database\Seeder;

class SalescallImageSeeder extends Seeder
{
    public function run(): void
    {
        $exterior = SalescallImageCategory::firstOrCreate(
            ['slug' => 'exterior'],
            ['name' => 'Exterior', 'sort' => 1]
        );

        $interior = SalescallImageCategory::firstOrCreate(
            ['slug' => 'interior'],
            ['name' => 'Interior', 'sort' => 2]
        );

        $exteriorTypes = [
            'Store Facade',
            'Neighbor Stores',
            'Nearby Competitor Outlet',
            'From Direction of Vehicles',
            'From Opposite Road',
        ];

        $interiorTypes = [
            'Battery Displays',
            'ULAB Area',
            'Dummy Batteries and Rack',
            'Other Merchandising Materials',
        ];

        foreach ($exteriorTypes as $i => $name) {
            $exterior->types()->firstOrCreate(
                ['slug' => \Str::slug($name, '_')],
                ['name' => $name, 'sort' => $i + 1]
            );
        }

        foreach ($interiorTypes as $i => $name) {
            $interior->types()->firstOrCreate(
                ['slug' => \Str::slug($name, '_')],
                ['name' => $name, 'sort' => $i + 1]
            );
        }
    }
}
