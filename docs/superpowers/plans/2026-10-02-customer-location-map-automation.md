# Customer Location Map Automation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Leaflet map the only Customer location input in both the portal admin and the tablet, so the pin sets latitude/longitude/address and the physical/commercial hierarchy is resolved best-effort from the coordinates.

**Architecture:** Add a shared `PhilippineAddressResolver` service (one per repo) that reverse-geocodes with Nominatim and matches address components against locally available reference rows, fail-closed. Both customer forms drop their manual location controls and expose a `resolveLocation()` Livewire method called by the map JS; resolved values are persisted and synced. The portal's customer push becomes defensive so a partial tablet push cannot null reconciled values.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5.7, Livewire 4, Pest 4, Leaflet 1.9.4, OpenStreetMap Nominatim.

**Spec:** `docs/superpowers/specs/2026-10-02-customer-location-map-automation-design.md`

## Global Constraints

- **No commit or push.** This repository's workflow (`docs/agent-workflow.md`) forbids commit/push without separate explicit Human terminal authorization. Every task ends at a validation checkpoint, never `git commit`.
- Preserve unrelated worktree changes. The portal has unrelated in-progress work on `4-be-ai-schedule-policy`; all portal tasks run in an isolated worktree on branch `1-tablet-customer-module-location`.
- Fail-closed: a component that does not match exactly one reference row resolves to `null`. Never guess.
- Offline/geocode failure keeps existing values and shows exactly: `Internet connection required for location.`
- Run `vendor/bin/pint --dirty --format agent` after PHP edits, and `php artisan view:cache` after Blade edits.
- Test commands: `php artisan test --compact <path>`.

## Review Focus

1. Offline or timeout while pinning must not erase existing latitude/longitude/address/hierarchy — it must keep them and show the offline message.
2. A Nominatim component absent from the reference DB (common) must resolve to `null`, not throw and not pick a wrong row.
3. A tablet customer push whose location fields are unknown/older must not null the portal's reconciled `province_id`, `area_cluster_id`, or `barangay_id`.
4. Ambiguous names (e.g., a barangay name appearing in several municipalities) must resolve to `null`, never an arbitrary row.
5. Removing the manual controls must not break save/validation or the customer-code reservation flow (location remains nullable).

---

## Task 1: Tablet reference schema and models

**Files:**
- Create: `database/migrations/2026_10_02_120000_create_barangays_table.php`
- Create: `database/migrations/2026_10_02_120001_create_area_clusters_table.php`
- Create: `database/migrations/2026_10_02_120002_add_location_reference_ids_to_customers_table.php`
- Create: `app/Models/Barangay.php`
- Create: `app/Models/AreaCluster.php`
- Modify: `app/Models/Customer.php`
- Test: `tests/Feature/CustomerLocationReferenceSchemaTest.php`

**Interfaces:**
- Produces: tables `barangays` (`municipality_id`, `psgc_code`, `code`, `name`, `enabled`), `area_clusters` (`region_specific_id`, `code`, `name`, `enabled`); columns `customers.province_id`, `customers.barangay_id`, `customers.area_cluster_id`; models `App\Models\Barangay`, `App\Models\AreaCluster`; `Customer::province()/barangay()/areaCluster()` relations.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CustomerLocationReferenceSchemaTest.php`:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('tablet has the customer location reference schema', function () {
    expect(Schema::hasTable('barangays'))->toBeTrue()
        ->and(Schema::hasTable('area_clusters'))->toBeTrue()
        ->and(Schema::hasColumns('customers', ['province_id', 'barangay_id', 'area_cluster_id']))->toBeTrue()
        ->and(Schema::hasColumns('barangays', ['municipality_id', 'psgc_code', 'code', 'name', 'enabled']))->toBeTrue()
        ->and(Schema::hasColumns('area_clusters', ['region_specific_id', 'code', 'name', 'enabled']))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerLocationReferenceSchemaTest.php`
Expected: FAIL (tables/columns missing).

- [ ] **Step 3: Write the migrations and models**

`database/migrations/2026_10_02_120000_create_barangays_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barangays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('municipality_id');
            $table->string('psgc_code', 20)->nullable()->unique();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['municipality_id', 'code']);
            $table->index(['municipality_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangays');
    }
};
```

`database/migrations/2026_10_02_120001_create_area_clusters_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_clusters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('region_specific_id');
            $table->string('code', 100);
            $table->string('name', 255);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['region_specific_id', 'code']);
            $table->index(['region_specific_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_clusters');
    }
};
```

`database/migrations/2026_10_02_120002_add_location_reference_ids_to_customers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('province_id')->nullable()->after('region_specific_id')->index();
            $table->unsignedBigInteger('barangay_id')->nullable()->after('municipality_id')->index();
            $table->unsignedBigInteger('area_cluster_id')->nullable()->after('region_specific_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['province_id', 'barangay_id', 'area_cluster_id']);
        });
    }
};
```

`app/Models/Barangay.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barangay extends Model
{
    protected $guarded = ['id'];

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
```

`app/Models/AreaCluster.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AreaCluster extends Model
{
    protected $guarded = ['id'];

    public function regionSpecific(): BelongsTo
    {
        return $this->belongsTo(RegionSpecific::class);
    }
}
```

Add to `app/Models/Customer.php` (after the `municipality()` relation):

```php
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function areaCluster(): BelongsTo
    {
        return $this->belongsTo(AreaCluster::class);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/CustomerLocationReferenceSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Checkpoint (no commit)**

Run: `vendor/bin/pint --dirty --format agent`. Confirm only the new files changed with `git status --short`.

---

## Task 2: `PhilippineAddressResolver` in both repositories

**Files:**
- Create: `app/Services/PhilippineAddressResolver.php` (in BOTH `ommc2027-tablet` and `ommc2027`)
- Test: `tests/Feature/PhilippineAddressResolverTest.php` (in BOTH repositories)

**Interfaces:**
- Consumes: reference tables `regions`, `region_specifics`, `provinces`, `municipalities`, `barangays`, `area_clusters`; models `Region`, `Province`, `Municipality`, `RegionSpecific`, `Barangay`, `AreaCluster`.
- Produces: `resolve(float $latitude, float $longitude): array` returning keys `ok` (bool), `reason` (`?string`, `unreachable` on failure), `region_id`, `province_id`, `municipality_id`, `barangay_id`, `region_specific_id`, `area_cluster_id` (`?int`), `address` (`?string`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/PhilippineAddressResolverTest.php` (identical in both repositories):

```php
<?php

use App\Services\PhilippineAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function seedResolverGeography(): void
{
    $now = now();

    DB::table('regions')->insert(['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'psgc_code' => '0305400000', 'name' => 'Pampanga', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'psgc_code' => '0305401000', 'region_id' => 1, 'province_id' => 1, 'name' => 'City of Angeles', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 1, 'psgc_code' => '0305401001', 'municipality_id' => 1, 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
}

test('resolver maps reverse geocode components to reference ids', function () {
    seedResolverGeography();

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas St, Pulung Maragul, Angeles, Pampanga, Central Luzon',
            'address' => [
                'house_number' => '123',
                'road' => 'Roxas Street',
                'suburb' => 'Pulung Maragul',
                'city' => 'Angeles',
                'county' => 'Pampanga',
                'state' => 'Central Luzon',
            ],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeTrue()
        ->and($result['region_id'])->toBe(1)
        ->and($result['province_id'])->toBe(1)
        ->and($result['municipality_id'])->toBe(1)
        ->and($result['barangay_id'])->toBe(1)
        ->and($result['region_specific_id'])->toBe(1)
        ->and($result['area_cluster_id'])->toBe(1)
        ->and($result['address'])->toBe('123 Roxas Street');
});

test('resolver fails closed when the geocoder is unreachable', function () {
    seedResolverGeography();
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('unreachable')
        ->and($result['region_id'])->toBeNull()
        ->and($result['address'])->toBeNull();
});

test('resolver returns null for an unknown component instead of guessing', function () {
    seedResolverGeography();

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'Somewhere',
            'address' => ['state' => 'Central Luzon', 'county' => 'Pampanga', 'city' => 'Atlantis'],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeTrue()
        ->and($result['region_id'])->toBe(1)
        ->and($result['municipality_id'])->toBeNull()
        ->and($result['barangay_id'])->toBeNull()
        ->and($result['address'])->toBe('Somewhere');
});

test('resolver returns null when a component name is ambiguous', function () {
    seedResolverGeography();

    DB::table('barangays')->insert(['id' => 2, 'municipality_id' => 1, 'code' => 'pulungmaragul-duplicate', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'Somewhere',
            'address' => ['suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    $result = app(PhilippineAddressResolver::class)->resolve(15.1456, 120.5887);

    expect($result['ok'])->toBeTrue()->and($result['barangay_id'])->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (in each repo): `php artisan test --compact tests/Feature/PhilippineAddressResolverTest.php`
Expected: FAIL ("Class App\Services\PhilippineAddressResolver not found").

- [ ] **Step 3: Implement the resolver**

`app/Services/PhilippineAddressResolver.php` (identical in both repositories):

```php
<?php

namespace App\Services;

use App\Models\AreaCluster;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Region;
use App\Models\RegionSpecific;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PhilippineAddressResolver
{
    /**
     * @return array{ok: bool, reason: ?string, region_id: ?int, province_id: ?int, municipality_id: ?int, barangay_id: ?int, region_specific_id: ?int, area_cluster_id: ?int, address: ?string}
     */
    public function resolve(float $latitude, float $longitude): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('app.name', 'OMMC').' customer-location',
                'Accept' => 'application/json',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/reverse', [
                'format' => 'jsonv2',
                'lat' => $latitude,
                'lon' => $longitude,
                'zoom' => 18,
                'addressdetails' => 1,
            ]);
        } catch (Throwable) {
            return $this->failure('unreachable');
        }

        if (! $response->successful()) {
            return $this->failure('unreachable');
        }

        $components = (array) $response->json('address', []);
        $display = trim((string) $response->json('display_name', ''));

        $stateTokens = [$components['state'] ?? null, $components['region'] ?? null];

        $regionId = $this->match(Region::class, $stateTokens);
        $provinceId = $this->match(Province::class, [$components['county'] ?? null, $components['state_district'] ?? null], ['enabled' => true]);
        $municipalityId = $this->match(Municipality::class, [
            $components['city'] ?? null,
            $components['municipality'] ?? null,
            $components['town'] ?? null,
            $components['city_district'] ?? null,
        ], $provinceId
            ? ['enabled' => true, 'province_id' => $provinceId]
            : ($regionId ? ['enabled' => true, 'region_id' => $regionId] : ['enabled' => true]));
        $barangayId = $municipalityId
            ? $this->match(Barangay::class, [
                $components['suburb'] ?? null,
                $components['village'] ?? null,
                $components['neighbourhood'] ?? null,
                $components['quarter'] ?? null,
                $components['hamlet'] ?? null,
            ], ['enabled' => true, 'municipality_id' => $municipalityId])
            : null;
        $regionSpecificId = $this->match(RegionSpecific::class, $stateTokens, $regionId ? ['region_id' => $regionId] : []);
        $areaClusterId = $regionSpecificId ? $this->uniqueAreaClusterId($regionSpecificId) : null;

        $street = trim(((string) ($components['house_number'] ?? '')).' '.((string) ($components['road'] ?? '')));
        $address = $street !== '' ? $street : $display;

        return [
            'ok' => true,
            'reason' => null,
            'region_id' => $regionId,
            'province_id' => $provinceId,
            'municipality_id' => $municipalityId,
            'barangay_id' => $barangayId,
            'region_specific_id' => $regionSpecificId,
            'area_cluster_id' => $areaClusterId,
            'address' => $address !== '' ? Str::limit($address, 500, '') : null,
        ];
    }

    /**
     * @return array{ok: false, reason: string, region_id: null, province_id: null, municipality_id: null, barangay_id: null, region_specific_id: null, area_cluster_id: null, address: null}
     */
    private function failure(string $reason): array
    {
        return [
            'ok' => false, 'reason' => $reason, 'region_id' => null, 'province_id' => null,
            'municipality_id' => null, 'barangay_id' => null, 'region_specific_id' => null,
            'area_cluster_id' => null, 'address' => null,
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, mixed>  $values
     * @param  array<string, mixed>  $constraints
     */
    private function match(string $model, array $values, array $constraints = []): ?int
    {
        $tokens = $this->tokens($values);

        if ($tokens === []) {
            return null;
        }

        $query = $model::query();
        foreach ($constraints as $column => $value) {
            $query->where($column, $value);
        }

        $ids = [];
        foreach ($query->get(['id', 'name']) as $row) {
            if (array_intersect($this->candidateNames($row->name), $tokens) !== []) {
                $ids[] = (int) $row->id;
            }
        }

        $ids = array_values(array_unique($ids));

        return count($ids) === 1 ? $ids[0] : null;
    }

    private function uniqueAreaClusterId(int $regionSpecificId): ?int
    {
        $ids = AreaCluster::query()
            ->where('region_specific_id', $regionSpecificId)
            ->where('enabled', true)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function tokens(array $values): array
    {
        $tokens = [];

        foreach ($values as $value) {
            if (is_string($value) && ($normalized = $this->normalize($value)) !== '') {
                $tokens[] = $normalized;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return list<string>
     */
    private function candidateNames(?string $name): array
    {
        $name = (string) $name;
        $candidates = [$this->normalize($name)];

        if (preg_match('/\(([^)]+)\)/', $name, $match) === 1) {
            $candidates[] = $this->normalize($match[1]);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii($value);
        $value = Str::lower($value);
        $value = preg_replace('/\b(?:city of|municipality of|barangay|brgy\.?|city)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run (in each repo): `php artisan test --compact tests/Feature/PhilippineAddressResolverTest.php`
Expected: 3 passed.

- [ ] **Step 5: Checkpoint (no commit)**

Run (in each repo): `vendor/bin/pint app/Services/PhilippineAddressResolver.php tests/Feature/PhilippineAddressResolverTest.php --format agent`.

---

## Task 3: Portal defensive customer push

**Files:**
- Create: `app/Services/CustomerLocationPayloadService.php`
- Modify: `app/Http/Controllers/Api/SyncController.php:632-653`
- Test: `tests/Feature/CustomerLocationPayloadServiceTest.php`

**Interfaces:**
- Produces: `CustomerLocationPayloadService::present(array $data): array` returns only the location keys present in `$data`, preserving explicit `null` values and omitting absent keys.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CustomerLocationPayloadServiceTest.php`:

```php
<?php

use App\Services\CustomerLocationPayloadService;

test('present keeps explicit location keys and omits absent ones', function () {
    $present = CustomerLocationPayloadService::present([
        'name' => 'Shop',
        'latitude' => 14.5,
        'region_specific_id' => null,
        'barangay_id' => 9,
    ]);

    expect($present)->toBe([
        'latitude' => 14.5,
        'region_specific_id' => null,
        'barangay_id' => 9,
    ])->and(array_key_exists('province_id', $present))->toBeFalse()
        ->and(array_key_exists('area_cluster_id', $present))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerLocationPayloadServiceTest.php`
Expected: FAIL ("Class not found").

- [ ] **Step 3: Implement the service and use it**

`app/Services/CustomerLocationPayloadService.php`:

```php
<?php

namespace App\Services;

class CustomerLocationPayloadService
{
    public const LOCATION_FIELDS = [
        'region_specific_id',
        'area_cluster_id',
        'province_id',
        'municipality_id',
        'barangay_id',
        'address',
        'latitude',
        'longitude',
    ];

    /**
     * Only the location fields present in the incoming payload, so a partial
     * tablet push cannot null reconciled portal values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function present(array $data): array
    {
        return array_intersect_key($data, array_flip(self::LOCATION_FIELDS));
    }
}
```

In `app/Http/Controllers/Api/SyncController.php`, add `use App\Services\CustomerLocationPayloadService;` and replace the location lines in the `fill([...])` block (currently lines 638–645) so they are merged from `present()`:

```php
            $customer->fill([
                'name' => $data['name'],
                'unique_id' => $data['unique_id'] ?? null,
                'company_id' => $data['company_id'],
                'general_category_id' => $data['general_category_id'] ?? null,
                'competitor_volume' => $data['competitor_volume'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'business_landline_number' => $data['business_landline_number'] ?? null,
                'business_mobile_number' => $data['business_mobile_number'] ?? null,
                'date_established' => $data['date_established'] ?? null,
                'person_in_charge_id' => $data['person_in_charge_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                ...CustomerLocationPayloadService::present($data),
            ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/CustomerLocationPayloadServiceTest.php tests/Feature/TabletBaseLocationPushTest.php`
Expected: PASS.

- [ ] **Step 5: Checkpoint (no commit)**

Run: `vendor/bin/pint app/Services/CustomerLocationPayloadService.php app/Http/Controllers/Api/SyncController.php tests/Feature/CustomerLocationPayloadServiceTest.php --format agent`.

---

## Task 4: Portal map-driven Customer form

**Files:**
- Modify: `app/Filament/Resources/Customers/Schemas/CustomerForm.php:94,100-184`
- Create: `app/Filament/Resources/Customers/Concerns/ResolvesCustomerLocation.php`
- Modify: `app/Filament/Resources/Customers/Pages/CreateCustomer.php`
- Modify: `app/Filament/Resources/Customers/Pages/EditCustomer.php`
- Modify: `resources/views/filament/schemas/components/customer-location-map.blade.php`
- Test: `tests/Feature/CustomerLocationMapFormTest.php`

**Interfaces:**
- Consumes: `App\Services\PhilippineAddressResolver::resolve()`.
- Produces: public property `$locationError` and public method `resolveLocation(): void` on both Create and Edit pages; the map JS calls `$wire.resolveLocation()` after setting coordinates.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CustomerLocationMapFormTest.php`:

```php
<?php

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Models\User;
use App\Services\PhilippineAddressResolver;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasColumn('users', 'deleted_at')) {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }
});

function seedPortalLocationFixtures(): User
{
    $now = now();

    DB::table('regions')->insert(['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Pampanga', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'City of Angeles', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

    return User::factory()->create();
}

test('resolveLocation fills persisted location state from coordinates', function () {
    $user = seedPortalLocationFixtures();
    $this->actingAs($user);
    Filament::setCurrentPanel('ommcpanel');

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas St, Angeles, Pampanga',
            'address' => ['house_number' => '123', 'road' => 'Roxas Street', 'suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['latitude' => 15.1456, 'longitude' => 120.5887])
        ->call('resolveLocation')
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'region_specific_id' => 1,
            'province_id' => 1,
            'municipality_id' => 1,
            'barangay_id' => 1,
            'area_cluster_id' => 1,
        ]);
});

test('resolveLocation shows the offline message and keeps existing values on failure', function () {
    $user = seedPortalLocationFixtures();
    $this->actingAs($user);
    Filament::setCurrentPanel('ommcpanel');

    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['latitude' => 15.1456, 'longitude' => 120.5887, 'province_id' => 1])
        ->call('resolveLocation')
        ->assertSet('locationError', 'Internet connection required for location.')
        ->assertFormSet(['province_id' => 1]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerLocationMapFormTest.php`
Expected: FAIL (method `resolveLocation` not found / form state missing).

- [ ] **Step 3: Implement the trait, page methods, form, and map hook**

`app/Filament/Resources/Customers/Concerns/ResolvesCustomerLocation.php`:

```php
<?php

namespace App\Filament\Resources\Customers\Concerns;

use App\Services\PhilippineAddressResolver;

trait ResolvesCustomerLocation
{
    public ?string $locationError = null;

    public function resolveLocation(): void
    {
        $latitude = is_numeric(data_get($this->data, 'latitude')) ? (float) data_get($this->data, 'latitude') : null;
        $longitude = is_numeric(data_get($this->data, 'longitude')) ? (float) data_get($this->data, 'longitude') : null;

        if ($latitude === null || $longitude === null) {
            return;
        }

        $result = app(PhilippineAddressResolver::class)->resolve($latitude, $longitude);

        if (! $result['ok']) {
            $this->locationError = 'Internet connection required for location.';

            return;
        }

        $this->locationError = null;

        foreach (['region_specific_id', 'province_id', 'municipality_id', 'barangay_id', 'area_cluster_id', 'address'] as $field) {
            if ($result[$field] !== null) {
                data_set($this->data, $field, $result[$field]);
            }
        }
    }
}
```

Add `use App\Filament\Resources\Customers\Concerns\ResolvesCustomerLocation;` and `use ResolvesCustomerLocation;` to both `CreateCustomer` and `EditCustomer`.

In `CustomerForm.php`, delete the `Textarea::make('address')` line (line 94) — its state is preserved by `Hidden::make('address')` in the new Location section — and replace the whole `Section::make('Location')` body (lines 100–184) with:

```php
            Section::make('Location')->columns(12)->columnSpanFull()->schema([
                View::make('filament.schemas.components.customer-location-map')->columnSpanFull(),
                Placeholder::make('region_display')->label('Region')
                    ->content(fn (Get $get): string => Municipality::find($get('municipality_id'))?->region?->name ?? 'Unavailable')->columnSpan(4),
                Placeholder::make('region_specific_display')->label('Specific Region')
                    ->content(fn (Get $get): string => RegionSpecific::whereKey($get('region_specific_id'))->value('name') ?? 'Unavailable')->columnSpan(4),
                Placeholder::make('area_cluster_display')->label('Area Cluster')
                    ->content(fn (Get $get): string => AreaCluster::whereKey($get('area_cluster_id'))->value('name') ?? 'Unavailable')->columnSpan(4),
                Placeholder::make('province_display')->label('Province')
                    ->content(fn (Get $get): string => Province::whereKey($get('province_id'))->value('name') ?? 'Not applicable')->columnSpan(4),
                Placeholder::make('municipality_display')->label('City / Municipality')
                    ->content(fn (Get $get): string => Municipality::whereKey($get('municipality_id'))->value('name') ?? 'Unavailable')->columnSpan(4),
                Placeholder::make('barangay_display')->label('Barangay')
                    ->content(fn (Get $get): string => Barangay::whereKey($get('barangay_id'))->value('name') ?? 'Unavailable')->columnSpan(4),
                Placeholder::make('address_display')->label('Address')
                    ->content(fn (Get $get): string => (string) ($get('address') ?: '—'))->columnSpanFull(),
                TextInput::make('latitude')->label('Latitude')->extraInputAttributes(['data-customer-coordinate' => 'latitude'])->disabled()->dehydrated()->columnSpan(3),
                TextInput::make('longitude')->label('Longitude')->extraInputAttributes(['data-customer-coordinate' => 'longitude'])->disabled()->dehydrated()->columnSpan(3),
                Hidden::make('region_specific_id'),
                Hidden::make('area_cluster_id'),
                Hidden::make('province_id'),
                Hidden::make('municipality_id'),
                Hidden::make('barangay_id'),
                Hidden::make('address'),
            ]),
```

Add `use Filament\Forms\Components\Hidden;` to the imports. `Select` remains used by the Customer Information and profile sections, so keep its import.

In `resources/views/filament/schemas/components/customer-location-map.blade.php`, inside `setInputs()` add a resolver call at the end:

```javascript
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    });

                    this.$wire.resolveLocation?.();
                },
```

And below the existing `<p x-text="message">` add:

```blade
    <p x-show="$wire.locationError" x-text="$wire.locationError" class="text-sm text-danger-600"></p>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/CustomerLocationMapFormTest.php tests/Feature/PhilippineAddressResolverTest.php tests/Feature/CustomerLocationPayloadServiceTest.php`
Expected: PASS. Then `php artisan view:cache`.

- [ ] **Step 5: Checkpoint (no commit)**

Run: `vendor/bin/pint --dirty --format agent`.

---

## Task 5: Tablet sync pull for reference tables and customer location FKs

**Files:**
- Modify: `app/Services/SyncService.php` (reference loops after line 293; customer upsert at lines 308–335)
- Test: `tests/Feature/CustomerLocationSyncTest.php`

**Interfaces:**
- Consumes: pull payload keys `barangays[]`, `area_clusters[]`, and customer keys `province_id`, `barangay_id`, `area_cluster_id`.
- Produces: pulled rows persisted in `barangays`, `area_clusters`, and on `customers`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CustomerLocationSyncTest.php`:

```php
<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('pull stores location reference tables and customer location foreign keys', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull' => Http::response([
            'companies' => [],
            'general_categories' => [],
            'regions' => [['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)']],
            'region_specifics' => [['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1]],
            'provinces' => [['id' => 7, 'region_id' => 1, 'name' => 'Pampanga', 'enabled' => true]],
            'municipalities' => [['id' => 9, 'region_id' => 1, 'province_id' => 7, 'name' => 'City of Angeles', 'enabled' => true]],
            'barangays' => [['id' => 55, 'municipality_id' => 9, 'psgc_code' => '0305401001', 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true]],
            'area_clusters' => [['id' => 61, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true]],
            'customers' => [[
                'id' => 99,
                'company_id' => null,
                'name' => 'Synced Customer',
                'region_specific_id' => 1,
                'province_id' => 7,
                'municipality_id' => 9,
                'barangay_id' => 55,
                'area_cluster_id' => 61,
                'address' => '123 Roxas Street',
                'latitude' => 15.1456,
                'longitude' => 120.5887,
                'is_active' => true,
            ]],
            'customer_trade_profiles' => [], 'customer_category_histories' => [], 'customer_category_events' => [],
            'salescall_statuses' => [], 'salescall_types' => [], 'material_groups' => [], 'brands' => [],
            'categories' => [], 'sub_categories' => [], 'sub_sub_categories' => [], 'salescall_image_categories' => [],
            'salescall_image_types' => [], 'itineraries' => [], 'customer_brands' => [], 'customer_categories' => [], 'customer_notes' => [],
        ], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('barangays')->where('id', 55)->value('name'))->toBe('Barangay Pulung Maragul')
        ->and(DB::table('area_clusters')->where('id', 61)->value('name'))->toBe('GMA - Area 1');

    $customer = Customer::findOrFail(99);
    expect($customer->province_id)->toBe(7)
        ->and($customer->barangay_id)->toBe(55)
        ->and($customer->area_cluster_id)->toBe(61);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerLocationSyncTest.php`
Expected: FAIL (barangays/area_clusters empty, FKs null).

- [ ] **Step 3: Implement the pull changes**

In `app/Services/SyncService.php`, after the `municipalities` loop (line 293), add:

```php
            foreach ($data['barangays'] ?? [] as $barangay) {
                DB::table('barangays')->updateOrInsert(
                    ['id' => $barangay['id']],
                    [
                        'municipality_id' => $barangay['municipality_id'],
                        'psgc_code' => $barangay['psgc_code'] ?? null,
                        'code' => $barangay['code'] ?? null,
                        'name' => $barangay['name'],
                        'enabled' => $barangay['enabled'] ?? true,
                        'updated_at' => now(),
                    ]
                );
            }

            foreach ($data['area_clusters'] ?? [] as $areaCluster) {
                DB::table('area_clusters')->updateOrInsert(
                    ['id' => $areaCluster['id']],
                    [
                        'region_specific_id' => $areaCluster['region_specific_id'],
                        'code' => $areaCluster['code'] ?? null,
                        'name' => $areaCluster['name'],
                        'enabled' => $areaCluster['enabled'] ?? true,
                        'updated_at' => now(),
                    ]
                );
            }
```

In the customer upsert array (lines 308–335), add after `'municipality_id' => $customer['municipality_id'] ?? null,`:

```php
                        'province_id' => $customer['province_id'] ?? null,
                        'barangay_id' => $customer['barangay_id'] ?? null,
                        'area_cluster_id' => $customer['area_cluster_id'] ?? null,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/CustomerLocationSyncTest.php tests/Feature/CustomerOperationalSyncTest.php`
Expected: PASS.

- [ ] **Step 5: Checkpoint (no commit)**

Run: `vendor/bin/pint app/Services/SyncService.php tests/Feature/CustomerLocationSyncTest.php --format agent`.

---

## Task 6: Tablet sync push for customer location FKs

**Files:**
- Modify: `app/Services/SyncService.php` (push payload at lines 707–750)
- Test: `tests/Feature/CustomerLocationPushTest.php`

**Interfaces:**
- Consumes: local customer columns `province_id`, `barangay_id`, `area_cluster_id`.
- Produces: `POST /api/sync/push/customer` payload including those keys.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CustomerLocationPushTest.php`:

```php
<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('push sends the customer location foreign keys', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    $customerId = DB::table('customers')->insertGetId([
        'local_uuid' => (string) Str::uuid(),
        'name' => 'Offline Shop',
        'sync_status' => 'pending',
        'sync_attempts' => 0,
        'province_id' => 7,
        'municipality_id' => 9,
        'barangay_id' => 55,
        'area_cluster_id' => 61,
        'address' => '123 Roxas Street',
        'latitude' => 15.1456,
        'longitude' => 120.5887,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake([
        'portal.test/api/sync/push/customer' => Http::response(['server_id' => 501, 'updated_at' => now()->toISOString()], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/api/sync/push/customer')
            && $request['province_id'] === 7
            && $request['municipality_id'] === 9
            && $request['barangay_id'] === 55
            && $request['area_cluster_id'] === 61;
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerLocationPushTest.php`
Expected: FAIL (payload keys missing).

- [ ] **Step 3: Implement the push change**

In the `client->post(...)` array in `app/Services/SyncService.php`, after `'municipality_id' => $customer->municipality_id,` add:

```php
                        'province_id' => $customer->province_id,
                        'barangay_id' => $customer->barangay_id,
                        'area_cluster_id' => $customer->area_cluster_id,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/CustomerLocationPushTest.php`
Expected: PASS.

- [ ] **Step 5: Checkpoint (no commit)**

Run: `vendor/bin/pint app/Services/SyncService.php tests/Feature/CustomerLocationPushTest.php --format agent`.

---

## Task 7: Tablet map-driven Customer form

**Files:**
- Modify: `app/Filament/Pages/CustomerCreatePage.php`
- Modify: `resources/views/filament/pages/customer-create-page.blade.php`
- Test: `tests/Feature/CustomerLocationMapTest.php` (replaces assertions in `tests/Feature/CustomerLocationPickerTest.php`)

**Interfaces:**
- Consumes: `App\Services\PhilippineAddressResolver::resolve()`.
- Produces: public properties `province_id`, `barangay_id`, `area_cluster_id`, `locationError`; public method `resolveLocation(): void`; read-only resolved display methods `resolvedRegionName()`, `resolvedRegionSpecificName()`, `resolvedAreaClusterName()`, `resolvedProvinceName()`, `resolvedMunicipalityName()`, `resolvedBarangayName()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CustomerLocationMapTest.php`:

```php
<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedTabletLocationFixtures(): User
{
    $now = now();

    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'R3', 'psgc_code' => '0300000000', 'name' => 'Region III (Central Luzon)', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Region III (Central Luzon)', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 1, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Pampanga', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 1, 'region_id' => 1, 'province_id' => 1, 'name' => 'City of Angeles', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 1, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

    return User::factory()->create();
}

test('tablet customer form has no manual location controls', function () {
    $this->actingAs(seedTabletLocationFixtures());

    Livewire::test(CustomerCreatePage::class)
        ->assertOk()
        ->assertDontSee('Select region')
        ->assertDontSee('Select specific region')
        ->assertDontSee('No province / independent locality')
        ->assertDontSee('Select municipality')
        ->assertDontSee('data-location-address', false)
        ->assertSee('Pick on Map')
        ->assertSee('data-location-coordinate="latitude"', false);
});

test('tablet resolveLocation fills coordinates, address and hierarchy', function () {
    $this->actingAs(seedTabletLocationFixtures());

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => '123 Roxas St, Angeles, Pampanga',
            'address' => ['house_number' => '123', 'road' => 'Roxas Street', 'suburb' => 'Pulung Maragul', 'city' => 'Angeles', 'county' => 'Pampanga', 'state' => 'Central Luzon'],
        ], 200),
    ]);

    Livewire::test(CustomerCreatePage::class)
        ->set('latitude', '15.1456000')
        ->set('longitude', '120.5887000')
        ->call('resolveLocation')
        ->assertSet('address', '123 Roxas Street')
        ->assertSet('province_id', 1)
        ->assertSet('municipality_id', 1)
        ->assertSet('barangay_id', 1)
        ->assertSet('area_cluster_id', 1)
        ->assertSet('region_specific_id', 1);
});

test('tablet resolveLocation reports offline and keeps existing values', function () {
    $this->actingAs(seedTabletLocationFixtures());

    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);

    Livewire::test(CustomerCreatePage::class)
        ->set('latitude', '15.1456000')
        ->set('longitude', '120.5887000')
        ->set('address', 'Existing Street')
        ->call('resolveLocation')
        ->assertSet('locationError', 'Internet connection required for location.')
        ->assertSet('address', 'Existing Street');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerLocationMapTest.php`
Expected: FAIL (manual controls still present, method missing).

- [ ] **Step 3: Implement the page and Blade changes**

In `app/Filament/Pages/CustomerCreatePage.php`:
- Add imports `use App\Models\AreaCluster;`, `use App\Models\Barangay;`, `use App\Services\PhilippineAddressResolver;`.
- Add properties after `public ?int $municipality_id = null;`:

```php
    public ?int $barangay_id = null;
    public ?int $area_cluster_id = null;
    public ?string $locationError = null;
```

- Add methods:

```php
    public function resolveLocation(): void
    {
        $latitude = is_numeric($this->latitude) ? (float) $this->latitude : null;
        $longitude = is_numeric($this->longitude) ? (float) $this->longitude : null;

        if ($latitude === null || $longitude === null) {
            return;
        }

        $result = app(PhilippineAddressResolver::class)->resolve($latitude, $longitude);

        if (! $result['ok']) {
            $this->locationError = 'Internet connection required for location.';

            return;
        }

        $this->locationError = null;

        foreach (['region_specific_id', 'province_id', 'municipality_id', 'barangay_id', 'area_cluster_id', 'address'] as $field) {
            if ($result[$field] !== null) {
                $this->{$field} = $result[$field];
            }
        }
    }

    public function resolvedRegionName(): ?string
    {
        return $this->municipality_id
            ? Municipality::find($this->municipality_id)?->region?->name
            : null;
    }

    public function resolvedRegionSpecificName(): ?string
    {
        return $this->region_specific_id
            ? DB::table('region_specifics')->where('id', $this->region_specific_id)->value('name')
            : null;
    }

    public function resolvedAreaClusterName(): ?string
    {
        return $this->area_cluster_id
            ? AreaCluster::whereKey($this->area_cluster_id)->value('name')
            : null;
    }

    public function resolvedProvinceName(): ?string
    {
        return $this->province_id ? Province::whereKey($this->province_id)->value('name') : null;
    }

    public function resolvedMunicipalityName(): ?string
    {
        return $this->municipality_id ? Municipality::whereKey($this->municipality_id)->value('name') : null;
    }

    public function resolvedBarangayName(): ?string
    {
        return $this->barangay_id ? Barangay::whereKey($this->barangay_id)->value('name') : null;
    }
```

- In `getViewData()`, `$provinces` and `$municipalities` are still used by nothing after Blade removal; keep `regions`/`regionSpecifics` only if referenced. Leave `getViewData()` unchanged to avoid touching filters.

- In `saveCustomer()`, add to the `$customer->fill([...])` array after `'municipality_id' => $this->municipality_id,`:

```php
                'province_id' => $this->province_id,
                'barangay_id' => $this->barangay_id,
                'area_cluster_id' => $this->area_cluster_id,
```

- Add validation rules in both `saveCustomer()` validate arrays (`CustomerCreatePage` and `CustomerEditPage`) next to `'municipality_id'`:

```php
            'barangay_id' => 'nullable|exists:barangays,id',
            'area_cluster_id' => 'nullable|exists:area_clusters,id',
```

In `resources/views/filament/pages/customer-create-page.blade.php`:
- Delete the Address textarea line (line 13).
- Replace the Location card grid body (lines 24–44) with read-only displays:

```blade
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div><label class="fi-fo-field-wrp-label">Region</label><x-filament::input :value="$this->resolvedRegionName()" readonly placeholder="Unavailable" /></div>
                <div><label class="fi-fo-field-wrp-label">Specific Region</label><x-filament::input :value="$this->resolvedRegionSpecificName()" readonly placeholder="Unavailable" /></div>
                <div><label class="fi-fo-field-wrp-label">Area Cluster</label><x-filament::input :value="$this->resolvedAreaClusterName()" readonly placeholder="Unavailable" /></div>
                <div><label class="fi-fo-field-wrp-label">Province</label><x-filament::input :value="$this->resolvedProvinceName()" readonly placeholder="Unavailable" /></div>
                <div><label class="fi-fo-field-wrp-label">City / Municipality</label><x-filament::input :value="$this->resolvedMunicipalityName()" readonly placeholder="Unavailable" /></div>
                <div><label class="fi-fo-field-wrp-label">Barangay</label><x-filament::input :value="$this->resolvedBarangayName()" readonly placeholder="Unavailable" /></div>
                <div><label class="fi-fo-field-wrp-label">Latitude</label><x-filament::input type="number" step="any" wire:model="latitude" data-location-coordinate="latitude" readonly /></div>
                <div><label class="fi-fo-field-wrp-label">Longitude</label><x-filament::input type="number" step="any" wire:model="longitude" data-location-coordinate="longitude" readonly /></div>
                <div class="md:col-span-3 flex flex-wrap items-center gap-3">
                    <button type="button" x-on:click="openPicker()" class="fi-btn fi-btn-size-md fi-btn-color-gray inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold shadow-sm ring-1 ring-inset ring-gray-950/10">
                        <span class="material-symbols-outlined text-lg">location_on</span>
                        Pick on Map
                    </button>
                    <span class="text-xs text-[#737685]">Pin the exact customer location on the map; the address is filled automatically.</span>
                </div>
                @if($locationError)
                    <div class="md:col-span-3 text-sm text-danger-600">{{ $locationError }}</div>
                @endif
            </div>
```

- In the Blade `<script>`, in `setCoordinates()` after the two `setInput` calls add:

```javascript
                    if (this.$wire?.resolveLocation) {
                        this.$wire.resolveLocation();
                    }
```

- In `confirmLocation()`, remove the address-writing branch so it only closes:

```javascript
                confirmLocation() {
                    this.closePicker();
                },
```

- Update `tests/Feature/CustomerLocationPickerTest.php`: remove the `data-location-address="address"` assertion (line 35) and replace the assertions for region/province/municipality names with `assertDontSee('Select specific region')`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/CustomerLocationMapTest.php tests/Feature/CustomerLocationPickerTest.php`
Expected: PASS. Then run `php artisan view:cache`.

- [ ] **Step 5: Checkpoint (no commit)**

Run: `vendor/bin/pint app/Filament/Pages/CustomerCreatePage.php app/Filament/Pages/CustomerEditPage.php --format agent`.

---

## Task 8: Tablet location persistence and display

**Files:**
- Modify: `app/Services/CustomerProfileFormService.php` (hydrate lines 78–102; saveAggregate lines 130–138)
- Modify: `app/Filament/Pages/CustomerPage.php` (view detail lines 117–144)
- Test: `tests/Feature/CustomerLocationPersistenceTest.php`

**Interfaces:**
- Consumes: customer columns `province_id`, `barangay_id`, `area_cluster_id`.
- Produces: hydrate/save carry the three fields; the customer detail view displays direct Province, Barangay and Area Cluster.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CustomerLocationPersistenceTest.php`:

```php
<?php

use App\Filament\Pages\CustomerEditPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerProfileFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedLocationPersistenceFixtures(): Customer
{
    $now = now();

    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('regions')->insert(['id' => 1, 'code' => 'R3', 'name' => 'Region III', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('region_specifics')->insert(['id' => 1, 'region_id' => 1, 'name' => 'Region III', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('provinces')->insert(['id' => 7, 'region_id' => 1, 'region_specific_id' => 1, 'name' => 'Pampanga', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('municipalities')->insert(['id' => 9, 'region_id' => 1, 'province_id' => 7, 'name' => 'City of Angeles', 'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('barangays')->insert(['id' => 55, 'municipality_id' => 9, 'code' => 'pulungmaragul', 'name' => 'Barangay Pulung Maragul', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('area_clusters')->insert(['id' => 61, 'region_specific_id' => 1, 'code' => 'gmaarea1', 'name' => 'GMA - Area 1', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

    return Customer::create([
        'name' => 'Shop',
        'company_id' => 1,
        'region_specific_id' => 1,
        'province_id' => 7,
        'municipality_id' => 9,
        'barangay_id' => 55,
        'area_cluster_id' => 61,
        'is_active' => true,
        'sync_status' => 'synced',
    ]);
}

test('hydrate exposes direct province, barangay and area cluster', function () {
    $customer = seedLocationPersistenceFixtures();

    $state = CustomerProfileFormService::hydrate($customer);

    expect($state['province_id'])->toBe(7)
        ->and($state['barangay_id'])->toBe(55)
        ->and($state['area_cluster_id'])->toBe(61);
});

test('edit save persists province, barangay and area cluster', function () {
    $customer = seedLocationPersistenceFixtures();
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CustomerEditPage::class, ['customerId' => $customer->id])
        ->set('province_id', 7)
        ->set('barangay_id', 55)
        ->set('area_cluster_id', 61)
        ->call('saveCustomer');

    $customer->refresh();
    expect($customer->province_id)->toBe(7)
        ->and($customer->barangay_id)->toBe(55)
        ->and($customer->area_cluster_id)->toBe(61);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerLocationPersistenceTest.php`
Expected: FAIL (hydrate returns province from municipality only; saveAggregate drops the fields).

- [ ] **Step 3: Implement persistence and display**

In `app/Services/CustomerProfileFormService.php` `hydrate()` return array, replace the `province_id` line and add the new keys:

```php
            'province_id' => $customer->province_id ?? $customer->municipality?->province_id,
            'barangay_id' => $customer->barangay_id,
            'area_cluster_id' => $customer->area_cluster_id,
```

In `saveAggregate()`, add the three keys to the `Arr::only($state, [...])` list:

```php
            'province_id', 'barangay_id', 'area_cluster_id',
```

In `CustomerEditPage::saveCustomer()` validate array, add (next to `municipality_id`):

```php
            'barangay_id' => 'nullable|exists:barangays,id',
            'area_cluster_id' => 'nullable|exists:area_clusters,id',
```

In `CustomerEditPage::saveCustomer()` `saveAggregate([...])` array, add after `'municipality_id' => $this->municipality_id,`:

```php
                'province_id' => $this->province_id,
                'barangay_id' => $this->barangay_id,
                'area_cluster_id' => $this->area_cluster_id,
```

In `app/Filament/Pages/CustomerPage.php` (`customerDetail['customer']`), update the province line and add barangay/area cluster:

```php
                'province' => $customer->province?->name ?? $customer->municipality?->province?->name,
                'barangay' => $customer->barangay?->name,
                'area_cluster' => $customer->areaCluster?->name,
```

In the customer view Blade (`resources/views/filament/pages/customer-page.blade.php`), after the `Specific Region` line (line 79) add:

```blade
                            <p><span class="font-bold">Barangay:</span> {{ $customerDetail['customer']['barangay'] ?? 'Unavailable' }}</p>
                            <p><span class="font-bold">Area Cluster:</span> {{ $customerDetail['customer']['area_cluster'] ?? 'Unavailable' }}</p>
```

The existing `Province` line (line 77) now renders the direct Province because `CustomerPage` prefers `$customer->province?->name`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/CustomerLocationPersistenceTest.php tests/Feature/CustomerLocationMapTest.php tests/Feature/CustomerLocationSyncTest.php tests/Feature/CustomerLocationPushTest.php tests/Feature/PhilippineAddressResolverTest.php`
Expected: PASS. Then `php artisan view:cache`.

- [ ] **Step 5: Checkpoint (no commit)**

Run: `vendor/bin/pint --dirty --format agent`. Report the final `git status --short` for both repositories. Set the Work note to `READY_FOR_TERMINAL_REVIEW` only after manual acceptance.

---

## Spec coverage check

| Spec requirement | Task |
|---|---|
| Remove manual location controls + Address textarea in tablet | 7 |
| Remove manual location controls + Address textarea in portal admin | 4 |
| Map pin sets latitude/longitude | 4, 7 |
| Nominatim reverse geocode auto-fill | 2 |
| Best-effort hierarchy; Area Cluster only when unambiguous | 2 |
| Persist new fields (tablet schema) | 1, 8 |
| Sync pull reference tables + customer FKs | 5 |
| Sync push new FKs | 6 |
| Portal push cannot null reconciled values | 3 |
| Offline message + never erase | 2, 4, 7 |
| Read-only resolved display | 4, 7, 8 |
| Customer view shows resolved fields | 8 |
