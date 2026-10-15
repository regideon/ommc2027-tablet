<?php

use App\Filament\Pages\SalescallPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Http\Controllers\DispatchEventFromAppController;

uses(RefreshDatabase::class);

test('sales call page serves the capture-photo wizard island in its HTML', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(SalescallPage::class)
        ->assertOk()
        ->assertSee('salescall-photo-wizard', false)
        ->assertSee('wire:ignore', false)
        ->assertSee('photoStep === 3', false)
        ->assertSee('Capture Photo', false)
        ->assertSee('browser-camera-input', false)
        ->assertSee('browser-gallery-input', false)
        ->assertSee('Take Photo', false)
        ->assertSee('Gallery', false)
        ->assertSee('selectPhotoType', false)
        ->assertSee('nativephp-ios', false);
});

test('photo capture diagnostics keep a component id across hydration and change it after remount', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(SalescallPage::class);
    $componentId = $component->get('photoDiagnosticsComponentId');

    $component->call('loadPhotos', null)
        ->assertSet('photoDiagnosticsComponentId', $componentId);

    $remountedComponent = Livewire::test(SalescallPage::class);

    expect($remountedComponent->get('photoDiagnosticsComponentId'))->not->toBe($componentId);
});

test('camera and gallery native event payloads match their PHP event constructors', function () {
    $events = [
        [PhotoTaken::class, [
            'path' => '/tmp/capture.jpg',
            'mimeType' => 'image/jpeg',
            'id' => 'camera-id',
        ]],
        [MediaSelected::class, [
            'success' => true,
            'files' => [['path' => '/tmp/gallery.jpg']],
            'count' => 1,
            'error' => null,
            'cancelled' => false,
            'id' => 'gallery-id',
        ]],
        [PhotoCancelled::class, [
            'cancelled' => true,
            'id' => 'camera-cancelled-id',
        ]],
        [PhotoCancelled::class, [
            'cancelled' => false,
            'id' => 'camera-failure-id',
        ]],
        [PermissionDenied::class, [
            'action' => 'photo',
            'id' => 'camera-permission-id',
        ]],
    ];

    foreach ($events as [$eventClass, $payload]) {
        $request = Request::create('/_native/api/events', 'POST', [
            'event' => $eventClass,
            'payload' => $payload,
        ]);

        $response = app(DispatchEventFromAppController::class)($request);

        expect($response->getStatusCode())->toBe(200);
    }
});

test('camera native failure event only sends supported PhotoCancelled fields', function () {
    $swift = file_get_contents(base_path('packages/ommc2027/camera/resources/ios/CameraFunctions.swift'));
    $photoDelegate = str($swift)->after('final class CameraPhotoDelegate')->toString();

    expect($photoDelegate)
        ->toContain('["cancelled": false]')
        ->toContain('["cancelled": true]')
        ->not->toContain('"failure":')
        ->and(substr_count($photoDelegate, '["cancelled": false]'))->toBe(3);
});

test('native Livewire camera cancellation and operational failure reach the correlated handler', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Log::spy();

    Livewire::test(SalescallPage::class)
        ->set('pendingPhoto', [
            'camera-cancelled-event' => ['salescall_id' => 10, 'type_id' => 20],
            'camera-failed-event' => ['salescall_id' => 10, 'type_id' => 20],
        ])
        ->dispatch('native:'.PhotoCancelled::class, cancelled: true, id: 'camera-cancelled-event')
        ->dispatch('native:'.PhotoCancelled::class, cancelled: false, id: 'camera-failed-event');

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'photo_capture_cancelled'
        && $context['source'] === 'camera'
    );

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'salescall_photo_diagnostic'
        && $context['event'] === 'photo_capture_failed'
        && $context['failure'] === 'native_capture_failed'
    );
});

test('camera success and gallery selection source payloads use their NativePHP constructor fields', function () {
    $swift = file_get_contents(base_path('packages/ommc2027/camera/resources/ios/CameraFunctions.swift'));

    expect($swift)
        ->toContain('"path": fileURL.path')
        ->toContain('"mimeType": "image/jpeg"')
        ->toContain('"success": success')
        ->toContain('"files": processedFiles')
        ->toContain('"count": processedFiles.count')
        ->toContain('payload["error"] = "Failed to import selected media"')
        ->toContain('payload["id"] = id');
});

test('NativePHP 3.3.6 iOS success data is unwrapped while pending photo wrappers expect a status envelope', function () {
    $photoWrapper = file_get_contents(base_path('vendor/nativephp/mobile/src/PendingPhotoCapture.php'));
    $galleryWrapper = file_get_contents(base_path('vendor/nativephp/mobile/src/PendingMediaPicker.php'));
    $iosBridge = file_get_contents(base_path('vendor/nativephp/mobile/resources/xcode/NativePHP/Bridge/BridgeRouter.swift'));

    expect($photoWrapper)
        ->toContain("isset(\$decoded['status']) && \$decoded['status'] === 'success'")
        ->and($galleryWrapper)->toContain("isset(\$decoded['status']) && \$decoded['status'] === 'success'")
        ->and($iosBridge)->toContain('static func success(data: [String: Any] = [:]) -> [String: Any]')
        ->and($iosBridge)->toContain('return data');
});
