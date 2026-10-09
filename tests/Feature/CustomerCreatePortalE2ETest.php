<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Models\Customer;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('Tablet Add Customer submits and round-trips through an isolated Portal API', function () {
    $portalUrl = env('CUSTOMER_CONTRACT_PORTAL_URL');
    if (! is_string($portalUrl) || $portalUrl === '') {
        $this->markTestSkipped('Set CUSTOMER_CONTRACT_PORTAL_URL to an isolated disposable Portal API.');
    }

    config(['sync.server_url' => rtrim($portalUrl, '/')]);

    $now = now();
    $actor = User::factory()->create(['email' => 'tablet-actor@contract.test', 'api_token' => 'tablet-contract-e2e-token']);
    $accessUser = User::factory()->create(['email' => 'tablet-access@contract.test']);
    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'R1', 'psgc_code' => '010000000', 'name' => 'Region 1', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Specific 1', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'AC1', 'name' => 'Cluster 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Province 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'Municipality 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'code' => 'B1', 'name' => 'Barangay 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('general_categories')->insert(['id' => 1, 'name' => 'Mixed Outlet', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);

    $this->actingAs($actor);
    $readPortal = fn (): array => Http::withToken('tablet-contract-e2e-token')
        ->get(rtrim($portalUrl, '/').'/api/sync/pull/customers?limit=1000')
        ->throw()
        ->json();
    $snapshot = function (array $payload): array {
        $profiles = collect($payload['customer_trade_profiles'] ?? [])->keyBy('customer_id');
        $histories = collect($payload['customer_category_histories'] ?? [])->groupBy('customer_id');

        return collect($payload['customers'] ?? [])->mapWithKeys(function (array $customer) use ($profiles, $histories): array {
            $id = $customer['id'];

            return [$id => [
                'customer' => collect($customer)->except(['updated_at'])->all(),
                'profile' => $profiles->get($id),
                'histories' => ($histories->get($id) ?? collect())->sortBy(['profile_type', 'stream', 'category_year'])->values()->all(),
            ]];
        })->all();
    };
    $beforeExistingCustomers = $snapshot($readPortal());

    $page = Livewire::test(CustomerCreatePage::class)
        ->set('company_id', 1)
        ->set([
            'name' => 'Isolated E2E Customer',
            'general_category_id' => 1,
            'competitor_volume' => 2,
            'region_specific_id' => 1,
            'physical_region_id' => 1,
            'province_id' => 1,
            'municipality_id' => 1,
            'barangay_id' => 1,
            'area_cluster_id' => 1,
            'person_in_charge_id' => $accessUser->id,
            'access_user_ids' => [$actor->id, $accessUser->id],
            'address' => '1 Test Road',
            'latitude' => '14.6500',
            'longitude' => '121.0500',
            'contact_person' => 'Contract Contact',
            'business_landline_number' => '02-8000000',
            'business_mobile_number' => '09170000000',
            'date_established' => '2018-01-01',
            'is_active' => true,
            'trade' => [
                'house_number' => '1',
                'entry_detail' => 'AB',
                'classifications' => ['Battery Specialist'],
                'ommc_brands' => ['Motolite'],
                'ommc_mcb_brands' => ['Motolite MCB'],
                'tpl_pollux' => ['Amaron'],
                'other_competitor_brands' => ['3K'],
                'mcb_competitors' => ['Bosch'],
                'other_competitors_note' => 'E2E competitor note',
                'working_days' => ['Monday', 'Tuesday'],
                'motiv_user' => false,
                'delivery_method' => 'resq_hub',
                'ulab' => 'GRC',
            ],
            'active' => [
                'conversion_program' => 'For Conversion',
                'ulab' => 'GRC',
                'delivery_type' => 'no',
                'operating_hours' => ['start' => '08:00', 'end' => '17:00'],
                'owner' => [
                    'name' => 'E2E Owner',
                    'birthday' => '1970-01-01',
                    'nickname' => 'Owner',
                    'successor_name' => 'E2E Successor',
                    'successor_birthday' => '2000-01-01',
                    'relationship' => 'Child',
                    'generation' => '2nd Gen',
                    'hobbies' => 'Reading',
                ],
            ],
            'categories' => ['ab' => array_fill_keys(range(2018, 2026), 'AB Loyal')],
        ]);

    $page->call('saveCustomer')->assertRedirect();

    $local = Customer::query()->where('name', 'Isolated E2E Customer')->firstOrFail();
    expect($local->sync_status)->toBe('synced')
        ->and($local->server_id)->not->toBeNull()
        ->and($local->unique_id)->toStartWith('OMMC')
        ->and($local->general_category_id)->toBe(1)
        ->and($local->competitor_volume)->toBe(2)
        ->and($local->region_specific_id)->toBe(1)
        ->and($local->area_cluster_id)->toBe(1)
        ->and($local->province_id)->toBe(1)
        ->and($local->municipality_id)->toBe(1)
        ->and($local->barangay_id)->toBe(1)
        ->and($local->contact_person)->toBe('Contract Contact')
        ->and($local->business_landline_number)->toBe('02-8000000')
        ->and($local->business_mobile_number)->toBe('09170000000')
        ->and(DB::table('customer_category_histories')->where('customer_id', $local->id)->count())->toBe(9);

    $portalPull = $readPortal();
    $afterExistingCustomers = $snapshot($portalPull);
    foreach ($beforeExistingCustomers as $id => $beforeState) {
        expect($afterExistingCustomers[$id] ?? null)->toBe($beforeState);
    }
    $portalCustomer = collect($portalPull['customers'])->firstWhere('id', $local->server_id);
    expect($portalCustomer['general_category_id'])->toBe(1)
        ->and($portalCustomer['region_specific_id'])->toBe(1)
        ->and($portalCustomer['area_cluster_id'])->toBe(1)
        ->and($portalCustomer['province_id'])->toBe(1)
        ->and($portalCustomer['municipality_id'])->toBe(1)
        ->and($portalCustomer['barangay_id'])->toBe(1)
        ->and($portalCustomer['competitor_volume'])->toBe(2)
        ->and($portalCustomer['unique_id'])->toBe($local->unique_id)
        ->and($portalCustomer['contact_person'])->toBe('Contract Contact')
        ->and($portalCustomer['business_landline_number'])->toBe('02-8000000')
        ->and($portalCustomer['business_mobile_number'])->toBe('09170000000')
        ->and($portalCustomer['person_in_charge_email'])->toBe($accessUser->email)
        ->and(collect($portalCustomer['access_user_emails'])->sort()->values()->all())->toBe(collect([$actor->email, $accessUser->email])->sort()->values()->all())
        ->and(collect($portalPull['customer_category_histories'])->where('customer_id', $local->server_id)->where('stream', 'ab')->pluck('category_year')->sort()->values()->all())
        ->toBe(range(2018, 2026));

    $portalProfile = collect($portalPull['customer_trade_profiles'])->firstWhere('customer_id', $local->server_id);
    expect($portalProfile['entry_detail'])->toBe('AB')
        ->and($portalProfile['classifications'])->toBe(['Battery Specialist'])
        ->and($portalProfile['ommc_brands'])->toBe(['Motolite'])
        ->and($portalProfile['ommc_mcb_brands'])->toBe(['Motolite MCB'])
        ->and($portalProfile['tpl_pollux'])->toBe(['Amaron'])
        ->and($portalProfile['other_competitor_brands'])->toBe(['3K'])
        ->and($portalProfile['mcb_competitors'])->toBe(['Bosch'])
        ->and($portalProfile['profile_data']['active']['conversion_program'])->toBe('For Conversion')
        ->and($portalProfile['profile_data']['active']['owner']['name'])->toBe('E2E Owner');

    do {
        $pullStep = app(SyncService::class)->pullCustomersStep();
    } while ($pullStep['success'] && ! $pullStep['done']);

    expect($pullStep['success'])->toBeTrue($pullStep['message'])
        ->and(DB::table('customer_user')->where('customer_id', $local->id)->count())->toBe(2)
        ->and(DB::table('customers')->where('id', $local->id)->value('person_in_charge_id'))->toBe($accessUser->id)
        ->and(DB::table('customer_category_histories')->where('customer_id', $local->id)->count())->toBe(9);
});
