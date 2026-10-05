<?php

use App\Filament\Pages\Auth\Profile;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a user defaults to no pending base location sync', function () {
    $user = User::factory()->create();

    expect($user->fresh()->base_location_pending)->toBeFalse();
});

test('profile page renders the base location map modal', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->assertOk()
        ->assertSee('Base Location')
        ->assertSee('data-base-coordinate="base_start_latitude"', false)
        ->assertSee('data-base-coordinate="base_start_longitude"', false)
        ->assertSee('data-base-coordinate="base_end_latitude"', false)
        ->assertSee('data-base-coordinate="base_end_longitude"', false)
        ->assertSee('Pick on Map')
        ->assertSee('base-location-map', false)
        ->assertSee('openPicker()', false)
        ->assertSee('closePicker()', false)
        ->assertSee('showPicker', false)
        ->assertSee('Use Current Location')
        ->assertSee("closest('form')", false)
        ->assertSee('window.baseLocationPicker', false);
});

test('saving the profile persists the base location and marks it pending', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->fillForm([
            'base_start_latitude' => 14.5,
            'base_start_longitude' => 121.0,
            'base_end_latitude' => 15.0,
            'base_end_longitude' => 121.5,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect((float) $user->base_start_latitude)->toBe(14.5)
        ->and((float) $user->base_start_longitude)->toBe(121.0)
        ->and((float) $user->base_end_latitude)->toBe(15.0)
        ->and((float) $user->base_end_longitude)->toBe(121.5)
        ->and($user->base_location_pending)->toBeTrue();
});

test('push sends a pending base location to the portal and clears the flag', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create([
        'api_token' => 'test-token',
        'base_start_latitude' => 14.5,
        'base_start_longitude' => 121.0,
        'base_location_pending' => true,
    ]);
    $this->actingAs($user);

    Http::fake();

    app(SyncService::class)->push();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://portal.test/api/sync/push/base-location'
            && (float) $request['base_start_latitude'] === 14.5
            && (float) $request['base_start_longitude'] === 121.0;
    });

    expect($user->refresh()->base_location_pending)->toBeFalse();
});

test('pull does not overwrite a locally pending base location', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create([
        'email' => 'drm@example.com',
        'api_token' => 'test-token',
        'base_start_latitude' => 14.5,
        'base_start_longitude' => 121.0,
        'base_location_pending' => true,
    ]);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/*' => Http::response([
            'users' => [[
                'id' => 99,
                'email' => 'drm@example.com',
                'base_start_latitude' => 7.1,
                'base_start_longitude' => 125.6,
            ]],
        ], 200),
    ]);

    app(SyncService::class)->pull();

    $user->refresh();

    expect((float) $user->base_start_latitude)->toBe(14.5)
        ->and((float) $user->base_start_longitude)->toBe(121.0);
});

test('pull overwrites the base location when there is nothing pending', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create([
        'email' => 'drm@example.com',
        'api_token' => 'test-token',
        'base_start_latitude' => 14.5,
        'base_start_longitude' => 121.0,
        'base_location_pending' => false,
    ]);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/*' => Http::response([
            'users' => [[
                'id' => 99,
                'email' => 'drm@example.com',
                'base_start_latitude' => 7.1,
                'base_start_longitude' => 125.6,
            ]],
        ], 200),
    ]);

    app(SyncService::class)->pull();

    expect((float) $user->refresh()->base_start_latitude)->toBe(7.1);
});
