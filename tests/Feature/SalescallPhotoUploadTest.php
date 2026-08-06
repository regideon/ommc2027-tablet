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

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

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
