<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\MaterialGroup;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallImage;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedRegionTypeImageStatuses(): void
{
    DB::table('salescall_statuses')->insert([
        ['id' => 1, 'name' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'In Progress', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 4, 'name' => 'Completed', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 8, 'name' => 'Partially Completed', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 9, 'name' => 'Cancelled', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function makeRegionTypeSalescall(User $user): Salescall
{
    $customer = Customer::create(['name' => 'Region Type Customer', 'is_active' => true]);
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);

    $salescall = Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);

    $materialGroup = MaterialGroup::create(['name' => 'Paint']);
    $brand = Brand::create([
        'material_group_id' => $materialGroup->id,
        'name' => 'Boysen',
        'enabled' => true,
    ]);

    SalescallBrand::create([
        'salescall_id' => $salescall->id,
        'customer_id' => $customer->id,
        'material_group_id' => $materialGroup->id,
        'brand_id' => $brand->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);

    return $salescall;
}

test('pull syncs region types and links the logged-in user to their region type', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['email' => 'rep@example.com', 'api_token' => 'rep-token']);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/*' => Http::response([
            'region_types' => [
                ['id' => 5, 'name' => 'IB Team', 'sort' => 8, 'is_image_required' => true],
                ['id' => 6, 'name' => 'Metro Manila', 'sort' => 1, 'is_image_required' => false],
            ],
            'users' => [
                ['id' => 1, 'email' => 'rep@example.com', 'region_type_id' => 5],
            ],
        ], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and((bool) RegionType::find(5)->is_image_required)->toBeTrue()
        ->and((bool) RegionType::find(6)->is_image_required)->toBeFalse()
        ->and($user->fresh()->region_type_id)->toBe(5);
});

test('tablet login stores the region type id on the user', function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/auth/tablet-login' => Http::response([
            'name' => 'Diana DRM',
            'email' => 'drm@example.com',
            'password' => Hash::make('secret-password'),
            'api_token' => 'tok_local_123',
            'roles' => ['drm'],
            'region_type_id' => 7,
        ], 200),
    ]);

    $result = app(SyncService::class)->refreshToken('drm@example.com', 'secret-password');

    expect($result->success)->toBeTrue($result->message)
        ->and(User::where('email', 'drm@example.com')->value('region_type_id'))->toBe(7);
});

test('completing a visit requires an image when the region type requires one', function () {
    seedRegionTypeImageStatuses();

    $type = RegionType::create(['name' => 'IB Team', 'sort' => 1, 'is_image_required' => true]);
    $user = User::factory()->create(['region_type_id' => $type->id]);
    $this->actingAs($user);

    $salescall = makeRegionTypeSalescall($user);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertNotDispatched('finish-done');

    expect($salescall->fresh()->status)->not->toBe('completed');

    SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => '/tmp/photo.jpg',
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
    ]);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'completed');

    expect($salescall->fresh()->status)->toBe('completed');
});

test('completing a visit without an image still works when the region type does not require one', function () {
    seedRegionTypeImageStatuses();

    $type = RegionType::create(['name' => 'Metro Manila', 'sort' => 1, 'is_image_required' => false]);
    $user = User::factory()->create(['region_type_id' => $type->id]);
    $this->actingAs($user);

    $salescall = makeRegionTypeSalescall($user);

    Livewire::test(SalescallPage::class)
        ->call('initiateFinish', $salescall->id, 'completed')
        ->assertDispatched('finish-done', salescallId: $salescall->id, outcome: 'completed');

    expect($salescall->fresh()->status)->toBe('completed');
});
