<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\SalescallImageCategory;
use App\Models\SalescallImageType;
use App\Models\User;
use App\Support\NativeMediaPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
    $capture->shouldReceive('start')->andReturn(true);
    Camera::shouldReceive('getPhoto')->andReturn($capture);

    Livewire::test(SalescallPage::class)
        ->call('takePhoto', $salescall->id, $typeA->id)
        ->assertSet('pendingPhoto', [
            'capture-abc' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
        ]);
});

test('pickFromGallery registers the native picker id before starting the picker', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [$salescall, , $typeA] = omPhotoContext();

    $picker = Mockery::mock(PendingMediaPicker::class);
    $picker->shouldReceive('single')->andReturnSelf();
    $picker->shouldReceive('getId')->andReturn('picker-def');
    $picker->shouldReceive('start')->andReturn(true);
    Camera::shouldReceive('pickImages')->andReturn($picker);

    Livewire::test(SalescallPage::class)
        ->call('pickFromGallery', $salescall->id, $typeA->id)
        ->assertSet('pendingPhoto', [
            'picker-def' => ['salescall_id' => $salescall->id, 'type_id' => $typeA->id],
        ]);
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
