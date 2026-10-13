<?php

use App\Enums\CRRSignage\SignageCategory;
use App\Models\Forms\CRRSignage\Signage;
use App\Models\Forms\CRRSignage\SignageProduct;
use App\Models\Forms\CRRSignage\SignageType;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Forms\TradeCheck\TradeCheck;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function emptyLocationReferenceSnapshot(): array
{
    $sections = array_fill_keys(['regions', 'region_specifics', 'area_clusters', 'provinces', 'municipalities', 'barangays'], []);

    return [
        'reference_contract_version' => 1,
        ...$sections,
        'reference_counts' => array_map('count', $sections),
    ];
}

function attachSignageSubmission(User $user, Salescall $salescall): void
{
    $product = SignageProduct::create(['name' => 'Tarpaulin']);
    $type = SignageType::create(['name' => 'Horizontal']);

    Signage::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'salescall_id' => $salescall->id,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
        'sync_status' => 'pending',
    ]);
}

function attachMarketingSubmission(User $user, Salescall $salescall): void
{
    $program = MarketingProgram::create(['name' => 'Price Support']);
    $region = RegionType::query()->first() ?? RegionType::create(['name' => 'Metro Manila', 'sort' => 99]);
    $role = Role::firstOrCreate(['name' => 'drm', 'guard_name' => 'web']);

    Marketing::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_at' => now(),
        'submitted_by' => $user->id,
        'region_type_id' => $region->id,
        'role_id' => $role->id,
        'program_id' => $program->id,
        'salescall_id' => $salescall->id,
        'duration_start_at' => now(),
        'duration_end_at' => now()->addMonth(),
        'objective' => 'Grow monthly case volume',
        'name' => 'Branch Expansion Support',
        'mechanics_description' => 'Volume rebate per incremental case.',
        'sync_status' => 'pending',
    ]);
}

function attachTradeCheckSubmission(User $user, Salescall $salescall): void
{
    TradeCheck::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'salescall_id' => $salescall->id,
        'name' => 'Trade Check Evaluation',
        'sync_status' => 'pending',
    ]);
}

function attachRequiredSalescallForms(User $user, Salescall $salescall): void
{
    attachSignageSubmission($user, $salescall);
    attachMarketingSubmission($user, $salescall);
    attachTradeCheckSubmission($user, $salescall);
}
