<?php

use App\Filament\Pages\SalescallPage;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Http\Controllers\DispatchEventFromAppController;

uses(RefreshDatabase::class);

test('sales call page persists check-in coordinates from the native LocationReceived livewire event', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);

    Livewire::test(SalescallPage::class)
        ->call('onLocationReceived', true, 14.5995, 120.9842, 5.0, 1_700_000_000_000, 'gps', null, 'checkin-'.$salescall->id);

    $salescall->refresh();

    expect((float) $salescall->latitude_actual_in)->toBe(14.5995)
        ->and((float) $salescall->longitude_actual_in)->toBe(120.9842)
        ->and($salescall->sync_status)->toBe('pending');
});

test('sales call page persists check-out coordinates from the native LocationReceived livewire event', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
        'actual_out' => now(),
    ]);

    Livewire::test(SalescallPage::class)
        ->call('onLocationReceived', true, 14.6, 120.98, 8.0, 1_700_000_000_100, 'gps', null, 'submit-'.$salescall->id);

    $salescall->refresh();

    expect((float) $salescall->latitude_actual_out)->toBe(14.6)
        ->and((float) $salescall->longitude_actual_out)->toBe(120.98);
});

test('failed native location events do not overwrite sales call coordinates', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $salescall = Salescall::create([
        'itinerary_id' => 1,
        'customer_id' => 1,
        'local_uuid' => (string) str()->uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
        'latitude_actual_in' => 1.1,
        'longitude_actual_in' => 2.2,
    ]);

    Livewire::test(SalescallPage::class)
        ->call('onLocationReceived', false, null, null, null, null, null, 'Location permission denied', 'checkin-'.$salescall->id);

    $salescall->refresh();

    expect((float) $salescall->latitude_actual_in)->toBe(1.1)
        ->and((float) $salescall->longitude_actual_in)->toBe(2.2);
});

test('vendor native json event endpoint does not read json bodies via request get', function () {
    $request = Request::create(
        '/_native/api/events',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode([
            'event' => LocationReceived::class,
            'payload' => [
                'success' => true,
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'id' => 'checkin-1',
            ],
        ], JSON_THROW_ON_ERROR),
    );
    $request->headers->set('Content-Type', 'application/json');

    expect($request->get('event'))->toBeNull()
        ->and($request->input('event'))->toBe(LocationReceived::class);

    $response = app(DispatchEventFromAppController::class)($request);

    expect($response->getData(true))->toMatchArray(['success' => false]);
});

test('sales call page listens for native location received like camera events', function () {
    $path = base_path('app/Filament/Pages/SalescallPage.php');
    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain("#[On('native:'.LocationReceived::class)]")
        ->toContain('function onLocationReceived(')
        ->toContain('HandleLocationReceived::class');
});
