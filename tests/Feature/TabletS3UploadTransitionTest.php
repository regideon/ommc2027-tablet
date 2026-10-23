<?php

use App\Models\Category;
use App\Models\CustomerProfile;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');
});

function omTabletLocalFile(string $path, string $contents = 'binary-test-data'): string
{
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($path, $contents);

    return $path;
}

test('sync service uploads the salescall image to the portal without s3', function () {
    config()->set('sync.server_url', 'https://portal.test');

    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'server_id' => 202,
        'sync_status' => 'synced',
    ]);

    $localPath = omTabletLocalFile(storage_path('app/private/salescall_images/'.str()->uuid().'.jpg'));

    $image = SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => $localPath,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    Http::fake([
        'https://portal.test/api/salescalls/push-image' => Http::response(['server_id' => 9001], 200),
    ]);

    $result = app(SyncService::class)->push();

    $image->refresh();

    expect($result->success)->toBeTrue()
        ->and($image->s3_key)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty()
        ->and($image->server_id)->toBe(9001)
        ->and($image->sync_status)->toBe('synced')
        ->and($image->local_path)->toBe($localPath)
        ->and(is_file($image->local_path))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.test/api/salescalls/push-image'
        && $request->isMultipart());
});

test('sync service uploads signatures to s3 before preserving customer profile portal compatibility sync', function () {
    config()->set('sync.server_url', 'https://portal.test');

    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'server_id' => 303,
        'sync_status' => 'pending',
    ]);

    $category = Category::create([
        'name' => 'Profile Category',
    ]);

    $subCategory = SubCategory::create([
        'category_id' => $category->id,
        'name' => 'Profile Subcategory',
    ]);

    $signaturePath = omTabletLocalFile(storage_path('app/private/customer_profiles/'.str()->uuid().'.png'));

    $profile = CustomerProfile::create([
        'salescall_id' => $salescall->id,
        'sub_category_id' => $subCategory->id,
        'registered_name' => 'Store Name',
        'owner_name' => 'Owner',
        'address' => 'Address',
        'local_uuid' => (string) str()->uuid(),
        'signature_path' => $signaturePath,
        'sync_status' => 'pending',
    ]);

    Http::fake([
        'https://portal.test/api/customer-profiles/push' => Http::response(['server_id' => 9101], 200),
    ]);

    $result = app(SyncService::class)->push();

    $profile->refresh();

    expect($result->success)->toBeTrue()
        ->and($profile->signature_s3_key)->not->toBeNull()
        ->and(Storage::disk('s3')->exists($profile->signature_s3_key))->toBeTrue()
        ->and($profile->server_id)->toBe(9101)
        ->and($profile->sync_status)->toBe('synced')
        ->and(is_file($profile->signature_path))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.test/api/customer-profiles/push');
});
