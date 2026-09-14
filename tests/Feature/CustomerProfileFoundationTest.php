<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('tablet profile configuration matches the accepted company and category contract', function () {
    expect(config('customer_trade_form.allowed_profile_types'))->toBe(['outlet', 'fleet', 'oe', 'ib'])
        ->and(config('customer_trade_form.company_profile_map'))->toBe([
            'OMMC' => 'outlet', 'LAST_MILE' => 'outlet', 'CAR_CLUBS' => 'outlet',
            'FLEET' => 'fleet', 'OE' => 'oe', 'IB' => 'ib',
        ])
        ->and(config('customer_trade_form.category_streams.outlet'))->toBe(['years' => range(2018, 2026), 'streams' => ['ab', 'mcb']])
        ->and(config('customer_trade_form.category_streams.fleet.years'))->toBe(range(2018, 2026))
        ->and(config('customer_trade_form.category_streams.oe.years'))->toBe(range(2018, 2025))
        ->and(config('customer_trade_form.category_streams.ib.years'))->toBe(range(2018, 2025))
        ->and(config('customer_trade_form.field_rules.person_in_charge_id.profiles'))->toBe(['outlet']);
});

test('tablet company seeder adds OE and IB idempotently', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\CompanySeeder']);
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\CompanySeeder']);

    expect(Company::whereIn('code', ['OMMC', 'LAST_MILE', 'CAR_CLUBS', 'FLEET', 'OE', 'IB'])->count())->toBe(6)
        ->and(Company::where('code', 'OE')->value('name'))->toBe('OE')
        ->and(Company::where('code', 'IB')->value('name'))->toBe('IB');
});

test('tablet foundation schema preserves legacy customer and trade fields', function () {
    expect(Schema::hasColumns('customers', [
        'person_in_charge_id', 'business_landline_number', 'business_mobile_number', 'date_established',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('customer_trade_profiles', ['profile_type', 'profile_data']))->toBeTrue()
        ->and(Schema::hasColumns('customers', ['contact_number', 'address', 'latitude', 'longitude']))->toBeTrue()
        ->and(Schema::hasColumns('customer_trade_profiles', ['house_number', 'entry_detail', 'operating_hours', 'delivery_method']))->toBeTrue();
});
