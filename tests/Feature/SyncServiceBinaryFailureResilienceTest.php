<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Category;
use App\Models\CustomerProfile;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\SyncService;
use App\Services\SyncResult;
use App\Services\TabletS3UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');
    config()->set('sync.server_url', 'https://portal.test');
});

function omBinaryLocalFile(string $path, string $contents = 'binary-test-data'): string
{
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($path, $contents);

    return $path;
}

function omBinarySalescall(User $user, int $serverId = 101): Salescall
{
    return Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'created_by' => $user->id,
        'local_uuid' => (string) str()->uuid(),
        'server_id' => $serverId,
        'sync_status' => 'pending',
    ]);
}

function omBinaryProfileContext(Salescall $salescall): array
{
    $category = Category::create([
        'name' => 'Profile Category',
    ]);

    $subCategory = SubCategory::create([
        'category_id' => $category->id,
        'name' => 'Profile Subcategory',
    ]);

    $profile = CustomerProfile::create([
        'salescall_id' => $salescall->id,
        'sub_category_id' => $subCategory->id,
        'registered_name' => 'Store Name',
        'owner_name' => 'Owner',
        'address' => 'Address',
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    return [$category, $subCategory, $profile];
}

test('image portal failure after s3 success remains retryable and retains s3 key', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = omBinarySalescall($user, 202);
    $localPath = omBinaryLocalFile(storage_path('app/private/salescall_images/'.str()->uuid().'.jpg'));

    $image = SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => $localPath,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    Http::fake([
        'https://portal.test/api/sync/push/salescall-image' => Http::response(['message' => 'temporary upstream failure'], 500),
    ]);

    $result = app(SyncService::class)->push();
    $image->refresh();

    expect($result->success)->toBeFalse()
        ->and($result->failedCount)->toBe(1)
        ->and($result->retryableCount)->toBe(1)
        ->and($result->failureReasons)->toContain('portal_upload_failed')
        ->and($image->sync_status)->toBe('failed')
        ->and($image->sync_attempts)->toBe(1)
        ->and($image->s3_key)->not->toBeNull()
        ->and(Storage::disk('s3')->exists($image->s3_key))->toBeTrue()
        ->and($image->sync_error)->toContain('portal_upload_failed')
        ->and(is_file($image->local_path))->toBeTrue();
});

test('image s3 failure remains retryable and does not call portal', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = omBinarySalescall($user, 203);
    $localPath = omBinaryLocalFile(storage_path('app/private/salescall_images/'.str()->uuid().'.jpg'));

    $image = SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => $localPath,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    app()->instance(TabletS3UploadService::class, new class extends TabletS3UploadService
    {
        public function ensureSalescallImageUploaded(SalescallImage $image): string
        {
            throw new RuntimeException('S3 put failed.');
        }
    });

    Http::fake();

    $result = app(SyncService::class)->push();
    $image->refresh();

    expect($result->success)->toBeFalse()
        ->and($result->failureReasons)->toContain('s3_upload_failed')
        ->and($image->sync_status)->toBe('failed')
        ->and($image->sync_attempts)->toBe(1)
        ->and($image->s3_key)->toBeNull()
        ->and($image->sync_error)->toContain('s3_upload_failed')
        ->and(is_file($image->local_path))->toBeTrue();

    Http::assertNothingSent();
});

test('signature portal failure after s3 success remains retryable and retains s3 key', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = omBinarySalescall($user, 303);
    [, , $profile] = omBinaryProfileContext($salescall);
    $signaturePath = omBinaryLocalFile(storage_path('app/private/customer_profiles/'.str()->uuid().'.png'));

    $profile->update([
        'signature_path' => $signaturePath,
    ]);

    Http::fake([
        'https://portal.test/api/sync/push/customer-profile' => Http::response(['message' => 'validation failed'], 422),
    ]);

    $result = app(SyncService::class)->push();
    $profile->refresh();

    expect($result->success)->toBeFalse()
        ->and($result->failedCount)->toBe(1)
        ->and($result->failureReasons)->toContain('portal_rejected')
        ->and($profile->sync_status)->toBe('failed')
        ->and($profile->signature_s3_key)->not->toBeNull()
        ->and(Storage::disk('s3')->exists($profile->signature_s3_key))->toBeTrue()
        ->and($profile->sync_error)->toContain('portal_rejected')
        ->and(is_file($profile->signature_path))->toBeTrue();
});

test('one failed binary item does not block another successful item and reports partial success', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = omBinarySalescall($user, 404);

    $missingImage = SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => storage_path('app/private/salescall_images/missing-'.str()->uuid().'.jpg'),
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    $goodImage = SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => omBinaryLocalFile(storage_path('app/private/salescall_images/'.str()->uuid().'.jpg')),
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    Http::fake([
        'https://portal.test/api/sync/push/salescall-image' => Http::response(['server_id' => 9002], 200),
    ]);

    $result = app(SyncService::class)->push();

    $missingImage->refresh();
    $goodImage->refresh();

    expect($result->success)->toBeTrue()
        ->and($result->syncedCount)->toBe(1)
        ->and($result->failedCount)->toBe(1)
        ->and($result->retryableCount)->toBe(1)
        ->and($result->message)->toContain('1 item synced. 1 item could not be uploaded and will retry later.')
        ->and($missingImage->sync_status)->toBe('failed')
        ->and($goodImage->sync_status)->toBe('synced')
        ->and($goodImage->server_id)->toBe(9002);
});

test('repeated retry after image s3 success does not duplicate s3 upload', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = omBinarySalescall($user, 505);
    $localPath = omBinaryLocalFile(storage_path('app/private/salescall_images/'.str()->uuid().'.jpg'));

    $image = SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => $localPath,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    Http::fakeSequence()
        ->push(['message' => 'upstream unavailable'], 500)
        ->push(['server_id' => 9300], 200);

    $first = app(SyncService::class)->push();
    $image->refresh();
    $firstKey = $image->s3_key;

    $second = app(SyncService::class)->push();
    $image->refresh();

    expect($first->success)->toBeFalse()
        ->and($firstKey)->not->toBeNull()
        ->and(Storage::disk('s3')->exists($firstKey))->toBeTrue()
        ->and(count(Storage::disk('s3')->allFiles()))->toBe(1)
        ->and($second->success)->toBeTrue()
        ->and($image->sync_status)->toBe('synced')
        ->and($image->s3_key)->toBe($firstKey)
        ->and(count(Storage::disk('s3')->allFiles()))->toBe(1);
});

test('top level livewire sync action always returns normally when sync service throws', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app()->instance(SyncService::class, new class extends SyncService
    {
        public function __construct() {}

        public function push(): SyncResult
        {
            throw new Error('Unexpected binary sync crash');
        }
    });

    Livewire::test(SalescallPage::class)
        ->call('syncNow')
        ->assertDispatched('sync-done');
});
