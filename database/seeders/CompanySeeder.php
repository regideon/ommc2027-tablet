<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            ['name' => 'OMMC', 'code' => 'OMMC'],
            ['name' => 'LAST MILE', 'code' => 'LAST_MILE'],
            ['name' => 'CAR CLUBS', 'code' => 'CAR_CLUBS'],
            ['name' => 'FLEET', 'code' => 'FLEET'],
        ];

        foreach ($data as $item) {
            Company::firstOrCreate(['name' => $item['name']], $item);
        }
    }
}
