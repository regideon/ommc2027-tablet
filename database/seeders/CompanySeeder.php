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
            ['name' => 'OE', 'code' => 'OE'],
            ['name' => 'IB', 'code' => 'IB'],
        ];

        foreach ($data as $item) {
            $company = Company::firstOrNew(['name' => $item['name']]);
            if (blank($company->code)) {
                $company->code = $item['code'];
            }
            $company->save();
        }
    }
}
