<?php

use App\Models\CustomerProfile;
use App\Models\Category;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\SyncService;
use App\Services\TabletS3UploadService;
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

test('tablet s3 upload service is idempotent for repeated salescall image retries', function () {
    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'server_id' => 101,
        'sync_status' => 'pending',
    ]);

    $localPath = omTabletLocalFile(storage_path('app/private/salescall_images/'.str()->uuid().'.jpg'));

    $image = SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => $localPath,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    $service = app(TabletS3UploadService::class);

    $firstKey = $service->ensureSalescallImageUploaded($image);
    $image->update(['s3_key' => $firstKey]);

    $secondKey = $service->ensureSalescallImageUploaded($image->fresh('salescall'));

    expect($secondKey)->toBe($firstKey)
        ->and(Storage::disk('s3')->exists($firstKey))->toBeTrue();
});

test('sync service uploads to s3 before preserving salescall image portal compatibility sync', function () {
    config()->set('sync.server_url', 'https://portal.test');

    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'server_id' => 202,
        'sync_status' => 'pending',
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
        'https://portal.test/api/sync/push/salescall-image' => Http::response(['server_id' => 9001], 200),
    ]);

    $result = app(SyncService::class)->push();

    $image->refresh();

    expect($result->success)->toBeTrue()
        ->and($image->s3_key)->not->toBeNull()
        ->and(Storage::disk('s3')->exists($image->s3_key))->toBeTrue()
        ->and($image->server_id)->toBe(9001)
        ->and($image->sync_status)->toBe('synced')
        ->and(is_file($image->local_path))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.test/api/sync/push/salescall-image');
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
        'https://portal.test/api/sync/push/customer-profile' => Http::response(['server_id' => 9101], 200),
    ]);

    $result = app(SyncService::class)->push();

    $profile->refresh();

    expect($result->success)->toBeTrue()
        ->and($profile->signature_s3_key)->not->toBeNull()
        ->and(Storage::disk('s3')->exists($profile->signature_s3_key))->toBeTrue()
        ->and($profile->server_id)->toBe(9101)
        ->and($profile->sync_status)->toBe('synced')
        ->and(is_file($profile->signature_path))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.test/api/sync/push/customer-profile');
});
