<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\SalescallImageCategory;
use App\Models\SalescallImageType;
use App\Models\User;
use App\Support\NativeMediaPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;
use Native\Mobile\PendingMediaPicker;
use Native\Mobile\PendingPhotoCapture;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->testPublicPath = sys_get_temp_dir().'/ommc-test-public-'.uniqid();
    app()->usePublicPath($this->testPublicPath);
});

afterEach(function () {
    if (is_dir($this->testPublicPath)) {
        app('files')->deleteDirectory($this->testPublicPath);
    }
});

function omPhotoContext(string $categorySlug = 'store', string $typeSlugA = 'store_facade', string $typeSlugB = 'neighbor_stores'): array
{
    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    $category = SalescallImageCategory::create([
        'name' => 'Store',
        'slug' => $categorySlug,
        'sort' => 1,
    ]);

    $typeA = SalescallImageType::create([
        'salescall_image_category_id' => $category->id,
        'name' => 'Store Facade',
        'slug' => $typeSlugA,
        'sort' => 1,
    ]);

    $typeB = SalescallImageType::create([
        'salescall_image_category_id' => $category->id,
        'name' => 'Neighbor Store',
        'slug' => $typeSlugB,
        'sort' => 2,
    ]);

    return [$salescall, $category, $typeA, $typeB];
}

function omTempPhoto(string $contents, ?string $directory = null): string
{
    if ($directory !== null) {
        @mkdir($directory, 0777, true);

        $path = $directory.'/captured_photo_1.jpg';
    } else {
        $path = tempnam(sys_get_temp_dir(), 'salescall-photo-');
    }

    file_put_contents($path, $contents);

    return $path;
}

test('native media path resolves string paths and file urls', function () {
    expect(NativeMediaPath::resolve('/tmp/photo.jpg'))->toBe('/tmp/photo.jpg');
    expect(NativeMediaPath::resolve('file:///tmp/my%20photo.jpg'))->toBe('/tmp/my photo.jpg');
});

test('native media path resolves gallery file objects', function () {
    expect(NativeMediaPath::resolve([
        'path' => '/tmp/Gallery/gallery_selected_1.jpg',
        'mimeType' => 'image/jpeg',
        'extension' => 'jpg',
        'type' => 'image',
    ]))->toBe('/tmp/Gallery/gallery_selected_1.jpg');

    expect(NativeMediaPath::resolve(['mimeType' => 'image/jpeg']))->toBeNull();
    expect(NativeMediaPath::resolve(null))->toBeNull();
});

test('sales call page saves a gallery selection from a file object payload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    $category = SalescallImageCategory::create([
        'name' => 'Store',
        'slug' => 'store',
        'sort' => 1,
    ]);

    $type = SalescallImageType::create([
        'salescall_image_category_id' => $category->id,
        'name' => 'Facade',
        'slug' => 'facade',
        'sort' => 1,
    ]);

    $source = tempnam(sys_get_temp_dir(), 'salescall-photo-');
    file_put_contents($source, 'fake-image-bytes');

    Livewire::test(SalescallPage::class)
        ->set('pendingPhotoSalescallId', $salescall->id)
        ->set('pendingPhotoTypeId', $type->id)
        ->call('onMediaSelected', true, [
            [
                'path' => $source,
                'mimeType' => 'image/jpeg',
                'extension' => 'jpg',
                'type' => 'image',
            ],
        ], 1)
        ->assertSet('pendingPhotoSalescallId', null)
        ->assertSet('pendingPhotoTypeId', null);

    $image = SalescallImage::first();
    expect($image)->not->toBeNull();
    expect($image->salescall_id)->toBe($salescall->id);
    expect($image->salescall_image_type_id)->toBe($type->id);
    expect(is_file($image->local_path))->toBeTrue();

    @unlink($source);
});

test('native Livewire gallery event reaches the photo listener and persists the selected image', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $type] = omPhotoContext();
    $captureId = 'gallery-livewire-event';
    $source = omTempPhoto('fake-image-bytes');

    Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            $captureId => ['salescall_id' => $salescall->id, 'type_id' => $type->id],
        ])
        ->dispatch('native:Native\\Mobile\\Events\\Gallery\\MediaSelected',
            success: true,
            files: [['path' => $source]],
            count: 1,
            cancelled: false,
            id: $captureId,
        );

    expect(SalescallImage::count())->toBe(1);

    $image = SalescallImage::first();
    expect($image->salescall_id)->toBe($salescall->id)
        ->and($image->salescall_image_type_id)->toBe($type->id)
        ->and(is_file($image->local_path))->toBeTrue();

    @unlink($source);
});

test('native Livewire camera event reaches the photo listener and refreshes preview state', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $type] = omPhotoContext();
    $captureId = 'camera-livewire-event';
    $source = omTempPhoto('fake-camera-image-bytes');

    $component = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            $captureId => ['salescall_id' => $salescall->id, 'type_id' => $type->id],
        ])
        ->dispatch('native:'.PhotoTaken::class,
            path: $source,
            mimeType: 'image/jpeg',
            id: $captureId,
        );

    $image = SalescallImage::first();
    expect($image)->not->toBeNull()
        ->and($image->salescall_id)->toBe($salescall->id)
        ->and($image->salescall_image_type_id)->toBe($type->id)
        ->and(is_file($image->local_path))->toBeTrue()
        ->and($component->get('callPhotos'))->toHaveCount(1)
        ->and($component->get('hasPhoto'))->toBeTrue();

    @unlink($source);
});

test('duplicate native gallery callbacks do not persist the photo twice', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $type] = omPhotoContext();
    $captureId = 'gallery-duplicate-event';
    $source = omTempPhoto('fake-image-bytes');
    Log::spy();

    $component = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            $captureId => ['salescall_id' => $salescall->id, 'type_id' => $type->id],
        ]);

    foreach ([1, 2] as $_) {
        $component->dispatch('native:'.MediaSelected::class,
            success: true,
            files: [['path' => $source]],
            count: 1,
            cancelled: false,
            id: $captureId,
        );
    }

    expect(SalescallImage::count())->toBe(1);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'capture_context_unmatched'
        && $context['discarded_without_persistence'] === true
        && $context['source'] === 'gallery'
    );

    @unlink($source);
});

test('sales call page ignores cancelled gallery picks without notifications errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->set('pendingPhotoSalescallId', 10)
        ->set('pendingPhotoTypeId', 20)
        ->call('onMediaSelected', false, [], 0, null, true)
        ->assertSet('pendingPhotoSalescallId', null)
        ->assertSet('pendingPhotoTypeId', null);

    expect(SalescallImage::count())->toBe(0);
});

test('sales call page does not save when temporary file is missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
    ]);

    $category = SalescallImageCategory::create([
        'name' => 'Store',
        'slug' => 'store-missing',
        'sort' => 1,
    ]);

    $type = SalescallImageType::create([
        'salescall_image_category_id' => $category->id,
        'name' => 'Facade',
        'slug' => 'facade-missing',
        'sort' => 1,
    ]);

    Livewire::test(SalescallPage::class)
        ->set('pendingPhotoSalescallId', $salescall->id)
        ->set('pendingPhotoTypeId', $type->id)
        ->call('onMediaSelected', true, [
            ['path' => '/tmp/does-not-exist-'.uniqid().'.jpg'],
        ], 1);

    expect(SalescallImage::count())->toBe(0);
});

test('takePhoto registers the native capture id before starting the camera', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA] = omPhotoContext();

    $capture = Mockery::mock(PendingPhotoCapture::class);
    $capture->shouldReceive('getId')->andReturn('capture-abc');
    // NativePHP Mobile 3.3.6 iOS returns `{}` for successful async bridge
    // calls, but its PendingPhotoCapture parser reports that as false.
    $capture->shouldReceive('start')->andReturn(false);
    Camera::shouldReceive('getPhoto')->andReturn($capture);
    Log::spy();

    Livewire::test(SalescallPage::class)
        ->call('takePhoto', $salescall->id, $typeA->id)
        ->assertSet('pendingPhoto', [
            'capture-abc' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
        ]);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'native_operation_invoked'
        && $context['source'] === 'camera'
        && $context['capture_correlation'] === substr(hash('sha256', 'capture-abc'), 0, 16)
        && isset($context['action_attempt_id'])
    );

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'native_operation_start_result'
        && $context['start_reported_success'] === false
    );
});

test('pickFromGallery registers the native picker id before starting the picker', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA] = omPhotoContext();

    $picker = Mockery::mock(PendingMediaPicker::class);
    $picker->shouldReceive('single')->andReturnSelf();
    $picker->shouldReceive('getId')->andReturn('picker-def');
    // Gallery uses the same 3.3.6 result parser as Camera.
    $picker->shouldReceive('start')->andReturn(false);
    Camera::shouldReceive('pickImages')->andReturn($picker);
    Log::spy();

    Livewire::test(SalescallPage::class)
        ->call('pickFromGallery', $salescall->id, $typeA->id)
        ->assertSet('pendingPhoto', [
            'picker-def' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
        ]);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'native_operation_invoked'
        && $context['source'] === 'gallery'
        && $context['capture_correlation'] === substr(hash('sha256', 'picker-def'), 0, 16)
        && isset($context['action_attempt_id'])
    );

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'native_operation_start_result'
        && $context['start_reported_success'] === false
    );
});

test('a then b then c keeps all records and never overwrites across photo types', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA, $typeB] = omPhotoContext();
    $srcA = omTempPhoto('aaa');
    $srcB = omTempPhoto('bbb');
    $srcC = omTempPhoto('ccc');

    $test = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
            'capture-b' => ['salescall_id' => $salescall->id, 'type_id' => $typeB->id],
            'capture-c' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
        ])
        ->call('onPhotoTaken', $srcA, 'image/jpeg', 'capture-a')
        ->call('onPhotoTaken', $srcB, 'image/jpeg', 'capture-b')
        ->call('onPhotoTaken', $srcC, 'image/jpeg', 'capture-c');

    expect(SalescallImage::count())->toBe(3);

    $images = SalescallImage::orderBy('id')->get();
    expect($images[0]->salescall_image_type_id)->toBe($typeA->id);
    expect($images[1]->salescall_image_type_id)->toBe($typeB->id);
    expect($images[2]->salescall_image_type_id)->toBe($typeA->id);

    expect($images[0]->local_uuid)->not->toBe($images[1]->local_uuid);
    expect($images[1]->local_uuid)->not->toBe($images[2]->local_uuid);
    expect($images[0]->local_path)->not->toBe($images[2]->local_path);

    $test->assertSet('pendingPhoto', []);
});

test('callbacks arriving out of order persist to the correct photo type', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA, $typeB] = omPhotoContext();
    $srcA = omTempPhoto('aaa');
    $srcB = omTempPhoto('bbb');

    Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
            'capture-b' => ['salescall_id' => $salescall->id, 'type_id' => $typeB->id],
        ])
        ->call('onPhotoTaken', $srcB, 'image/jpeg', 'capture-b')
        ->call('onPhotoTaken', $srcA, 'image/jpeg', 'capture-a');

    $images = SalescallImage::orderBy('id')->get();
    expect($images)->toHaveCount(2);
    expect($images[0]->salescall_image_type_id)->toBe($typeB->id);
    expect($images[1]->salescall_image_type_id)->toBe($typeA->id);
});

test('same source basename for two photo types still yields distinct preview mirrors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA, $typeB] = omPhotoContext();
    $dirA = sys_get_temp_dir().'/ommc-src-a-'.uniqid();
    $dirB = sys_get_temp_dir().'/ommc-src-b-'.uniqid();
    $srcA = omTempPhoto('aaa', $dirA);
    $srcB = omTempPhoto('bbb', $dirB);

    expect(basename($srcA))->toBe(basename($srcB));

    $test = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
            'capture-b' => ['salescall_id' => $salescall->id, 'type_id' => $typeB->id],
        ])
        ->call('onPhotoTaken', $srcA, 'image/jpeg', 'capture-a')
        ->call('onPhotoTaken', $srcB, 'image/jpeg', 'capture-b');

    $urls = $test->get('callPhotos');
    expect($urls)->toHaveCount(2);
    expect($urls[0]['url'])->toStartWith('/_assets/salescall_images/');
    expect($urls[1]['url'])->toStartWith('/_assets/salescall_images/');
    expect($urls[0]['url'])->not->toBe($urls[1]['url']);

    app('files')->deleteDirectory($dirA);
    app('files')->deleteDirectory($dirB);
});

test('preview mirrors use the persisted local uuid and remain distinct per photo', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA, $typeB] = omPhotoContext();
    $srcA = omTempPhoto('aaa');
    $srcB = omTempPhoto('bbb');

    Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
            'capture-b' => ['salescall_id' => $salescall->id, 'type_id' => $typeB->id],
        ])
        ->call('onPhotoTaken', $srcA, 'image/jpeg', 'capture-a')
        ->call('onPhotoTaken', $srcB, 'image/jpeg', 'capture-b');

    $images = SalescallImage::orderBy('id')->get();
    foreach ($images as $image) {
        $mirror = $this->testPublicPath.'/salescall_images/'.$image->local_uuid.'.jpg';
        expect(is_file($mirror))->toBeTrue();
        expect($image->local_path)->not->toBe($mirror);
    }

    expect($this->testPublicPath.'/salescall_images/'.$images[0]->local_uuid.'.jpg')
        ->not->toBe($this->testPublicPath.'/salescall_images/'.$images[1]->local_uuid.'.jpg');
});

test('deleting one photo deletes only its own preview mirror', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA, $typeB] = omPhotoContext();
    $srcA = omTempPhoto('aaa');
    $srcB = omTempPhoto('bbb');

    Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
            'capture-b' => ['salescall_id' => $salescall->id, 'type_id' => $typeB->id],
        ])
        ->call('onPhotoTaken', $srcA, 'image/jpeg', 'capture-a')
        ->call('onPhotoTaken', $srcB, 'image/jpeg', 'capture-b');

    $images = SalescallImage::orderBy('id')->get();

    $mirrorA = $this->testPublicPath.'/salescall_images/'.$images[0]->local_uuid.'.jpg';
    $mirrorB = $this->testPublicPath.'/salescall_images/'.$images[1]->local_uuid.'.jpg';
    expect(is_file($mirrorA))->toBeTrue();
    expect(is_file($mirrorB))->toBeTrue();

    Livewire::test(SalescallPage::class)
        ->call('deleteImage', $images[0]->id);

    expect(is_file($mirrorA))->toBeFalse();
    expect(is_file($mirrorB))->toBeTrue();
    expect(SalescallImage::count())->toBe(1);
});

test('a missing preview mirror self-heals on the next photo load (restart survival)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA] = omPhotoContext();
    $srcA = omTempPhoto('aaa');

    $test = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
        ])
        ->call('onPhotoTaken', $srcA, 'image/jpeg', 'capture-a');

    $image = SalescallImage::first();
    $mirror = $this->testPublicPath.'/salescall_images/'.$image->local_uuid.'.jpg';
    expect(is_file($mirror))->toBeTrue();
    expect($test->get('callPhotos')[0]['url'])->toStartWith('/_assets/salescall_images/');

    @unlink($mirror);
    expect(is_file($mirror))->toBeFalse();

    $test->call('loadPhotos', $salescall->id);

    expect(is_file($mirror))->toBeTrue();
    expect($test->get('callPhotos')[0]['url'])->toStartWith('/_assets/salescall_images/');
});

test('a photo without a mirror still falls back to the route url', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA] = omPhotoContext();
    $srcA = omTempPhoto('aaa');

    $test = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
        ])
        ->call('onPhotoTaken', $srcA, 'image/jpeg', 'capture-a');

    $image = SalescallImage::first();
    $mirror = $this->testPublicPath.'/salescall_images/'.$image->local_uuid.'.jpg';
    expect(is_file($mirror))->toBeTrue();

    @unlink($image->local_path);
    @unlink($mirror);

    $test->call('loadPhotos', $salescall->id);

    expect($test->get('callPhotos')[0]['url'])->toBe('/salescall-image/'.$image->id);
});

test('legacy photo taken event without an id still saves via pending props', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA] = omPhotoContext();
    $srcA = omTempPhoto('aaa');

    Livewire::test(SalescallPage::class)
        ->set('pendingPhotoSalescallId', $salescall->id)
        ->set('pendingPhotoTypeId', $typeA->id)
        ->call('onPhotoTaken', $srcA, 'image/jpeg', null)
        ->assertSet('pendingPhotoSalescallId', null)
        ->assertSet('pendingPhotoTypeId', null);

    $image = SalescallImage::first();
    expect($image)->not->toBeNull();
    expect($image->salescall_id)->toBe($salescall->id);
    expect($image->salescall_image_type_id)->toBe($typeA->id);
});

test('cancelling one capture clears only that captures pending context', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $test = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => 1, 'type_id' => 2],
            'capture-b' => ['salescall_id' => 1, 'type_id' => 3],
        ])
        ->call('onPhotoCancelled', true, 'capture-a');

    $test->assertSet('pendingPhoto', [
        'capture-b' => ['salescall_id' => 1, 'type_id' => 3],
    ]);
});

test('an uncorrelated camera cancel clears all pending contexts and is diagnosed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Log::spy();

    $test = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => 1, 'type_id' => 2],
            'capture-b' => ['salescall_id' => 1, 'type_id' => 3],
        ])
        ->set('pendingPhotoSalescallId', 1)
        ->set('pendingPhotoTypeId', 2)
        ->call('onPhotoCancelled', true, null);

    $test->assertSet('pendingPhoto', [])
        ->assertSet('pendingPhotoSalescallId', null)
        ->assertSet('pendingPhotoTypeId', null);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'uncorrelated_event_cleared_pending_contexts'
        && $context['cleared_capture_count'] === 2
    );
});

test('a denied camera permission clears only the correlated capture context', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $test = Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-a' => ['salescall_id' => 1, 'type_id' => 2],
            'capture-b' => ['salescall_id' => 1, 'type_id' => 3],
        ])
        ->call('onCameraPermissionDenied', 'photo', 'capture-b');

    $test->assertSet('pendingPhoto', [
        'capture-a' => ['salescall_id' => 1, 'type_id' => 2],
    ]);
});

test('a matched camera capture is diagnosed and persisted with a redacted correlation id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $type] = omPhotoContext();
    $source = omTempPhoto('camera-image-bytes');
    $correlation = substr(hash('sha256', 'capture-matched'), 0, 16);
    Log::spy();

    Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-matched' => ['salescall_id' => $salescall->id, 'type_id' => $type->id],
        ])
        ->call('onPhotoTaken', $source, 'image/jpeg', 'capture-matched');

    expect(SalescallImage::count())->toBe(1);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'capture_context_matched'
        && $context['source'] === 'camera'
        && $context['capture_correlation'] === $correlation
        && $context['match_method'] === 'capture_id'
    );

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'salescall_image_persisted'
        && $context['sync_status'] === 'pending'
        && ! array_key_exists('path', $context)
    );

    @unlink($source);
});

test('gallery events use the same matched diagnostic contract and persist selected files', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $type] = omPhotoContext();
    $source = omTempPhoto('gallery-image-bytes');
    $correlation = substr(hash('sha256', 'picker-matched'), 0, 16);
    Log::spy();

    Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'picker-matched' => ['salescall_id' => $salescall->id, 'type_id' => $type->id],
        ])
        ->call('onMediaSelected', true, [['path' => $source]], 1, null, false, 'picker-matched');

    expect(SalescallImage::count())->toBe(1);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'capture_context_matched'
        && $context['source'] === 'gallery'
        && $context['capture_correlation'] === $correlation
        && $context['match_method'] === 'capture_id'
    );

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'photo_preview_refreshed'
        && $context['source'] === 'gallery'
        && $context['capture_correlation'] === $correlation
    );

    @unlink($source);
});

test('an unmatched capture id is logged as discarded without persistence', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $source = omTempPhoto('unmatched-image-bytes');
    Log::spy();

    Livewire::test(SalescallPage::class)
        ->call('onPhotoTaken', $source, 'image/jpeg', 'capture-unmatched');

    expect(SalescallImage::count())->toBe(0);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'capture_context_unmatched'
        && $context['discarded_without_persistence'] === true
        && $context['source'] === 'camera'
    );

    @unlink($source);
});

test('a capture event without an id or legacy pending context is diagnosed as missing context', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $source = omTempPhoto('missing-context-bytes');
    Log::spy();

    Livewire::test(SalescallPage::class)
        ->call('onPhotoTaken', $source, 'image/jpeg', null);

    expect(SalescallImage::count())->toBe(0);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'capture_context_missing'
        && $context['source'] === 'camera'
    );

    @unlink($source);
});

test('camera cancellation and native capture failure have distinct diagnostics', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Log::spy();

    Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'capture-cancelled' => ['salescall_id' => 1, 'type_id' => 1],
            'capture-failed' => ['salescall_id' => 1, 'type_id' => 1],
        ])
        ->call('onPhotoCancelled', true, 'capture-cancelled')
        ->call('onPhotoCancelled', false, 'capture-failed');

    expect(SalescallImage::count())->toBe(0);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'photo_capture_cancelled'
        && $context['source'] === 'camera'
    );

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'photo_capture_failed'
        && $context['failure'] === 'native_capture_failed'
    );
});

test('a sales call image persistence exception is logged and rethrown', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $type] = omPhotoContext();
    $source = omTempPhoto('persistence-failure-bytes');
    Log::spy();
    SalescallImage::creating(function (): void {
        throw new RuntimeException('diagnostic test failure');
    });

    try {
        expect(fn () => Livewire::test(SalescallPage::class)
            ->set('pendingPhoto', [
                'capture-db-failure' => ['salescall_id' => $salescall->id, 'type_id' => $type->id],
            ])
            ->call('onPhotoTaken', $source, 'image/jpeg', 'capture-db-failure'))
            ->toThrow(RuntimeException::class);
    } finally {
        SalescallImage::flushEventListeners();
        @unlink($source);
    }

    expect(SalescallImage::count())->toBe(0);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'salescall_image_persistence_failed'
        && $context['source'] === 'camera'
        && $context['exception_class'] === RuntimeException::class
    );
});
