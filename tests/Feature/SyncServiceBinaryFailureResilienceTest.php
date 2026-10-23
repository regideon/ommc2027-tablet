<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Category;
use App\Models\CustomerProfile;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\SyncResult;
use App\Services\SyncService;
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

test('salescall image portal failure remains retryable and keeps the local file', function () {
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
        'https://portal.test/api/salescalls/push-image' => Http::response(['message' => 'temporary upstream failure'], 500),
    ]);

    $result = app(SyncService::class)->push();
    $image->refresh();

    expect($result->success)->toBeFalse()
        ->and($result->failedCount)->toBe(1)
        ->and($result->retryableCount)->toBe(1)
        ->and($result->failureReasons)->toContain('portal_upload_failed')
        ->and($image->sync_status)->toBe('failed')
        ->and($image->sync_attempts)->toBe(1)
        ->and($image->s3_key)->toBeNull()
        ->and($image->sync_error)->toContain('portal_upload_failed')
        ->and(is_file($image->local_path))->toBeTrue();
});

test('salescall image push does not attempt an s3 upload', function () {
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

    Http::fake([
        'https://portal.test/api/salescalls/push-image' => Http::response(['server_id' => 9301], 200),
    ]);

    $result = app(SyncService::class)->push();
    $image->refresh();

    expect($result->success)->toBeTrue()
        ->and($image->sync_status)->toBe('synced')
        ->and($image->server_id)->toBe(9301)
        ->and($image->s3_key)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty()
        ->and(is_file($image->local_path))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.test/api/salescalls/push-image'
        && $request->isMultipart());
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
        'https://portal.test/api/customer-profiles/push' => Http::response(['message' => 'validation failed'], 422),
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
        'https://portal.test/api/salescalls/push-image' => Http::response(['server_id' => 9002], 200),
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

test('a repeated salescall image retry keeps one local file and eventually syncs', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = omBinarySalescall($user, 505);
    $salescall->update(['sync_status' => 'synced']);
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

    $second = app(SyncService::class)->push();
    $image->refresh();

    expect($first->success)->toBeFalse()
        ->and($image->s3_key)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty()
        ->and(is_file($localPath))->toBeTrue()
        ->and($second->success)->toBeTrue()
        ->and($image->sync_status)->toBe('synced')
        ->and($image->server_id)->toBe(9300)
        ->and($image->s3_key)->toBeNull()
        ->and(is_file($image->local_path))->toBeTrue();
});

test('an exhausted failed salescall image is retried until it reaches the portal', function () {
    $user = User::factory()->create(['api_token' => 'tablet-token']);
    $this->actingAs($user);

    $salescall = omBinarySalescall($user, 506);
    $salescall->update(['sync_status' => 'synced']);
    $localPath = omBinaryLocalFile(storage_path('app/private/salescall_images/'.str()->uuid().'.jpg'));

    $image = SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => $localPath,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'failed',
        'sync_attempts' => 3,
        'sync_error' => 's3_upload_failed: Failed to upload file to S3.',
    ]);

    Http::fake([
        'https://portal.test/api/salescalls/push-image' => Http::response(['server_id' => 9302], 200),
    ]);

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();

    $result = app(SyncService::class)->push();
    $image->refresh();

    expect($result->success)->toBeTrue()
        ->and($image->sync_status)->toBe('synced')
        ->and($image->server_id)->toBe(9302)
        ->and($image->s3_key)->toBeNull()
        ->and($image->local_path)->toBe($localPath)
        ->and(is_file($localPath))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.test/api/salescalls/push-image');
});

test('top level Livewire sync action reports failure when the sync service throws', function () {
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
        ->assertDispatched('sync-done', fn (string $event, array $params): bool => ($params['success'] ?? null) === false);
});
