<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('first login persists the rep base location locally', function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'ok'], 200),
        'portal.test/api/auth/tablet-login' => Http::response([
            'name' => 'Diana DRM',
            'email' => 'drm@example.com',
            'password' => Hash::make('secret-password'),
            'api_token' => 'tok_local_123',
            'roles' => ['drm'],
            'rsm_id' => null,
            'base_start_latitude' => 14.5,
            'base_start_longitude' => 121.0,
            'base_end_latitude' => 15.0,
            'base_end_longitude' => 121.5,
        ], 200),
    ]);

    $result = app(SyncService::class)->refreshToken('drm@example.com', 'secret-password');

    expect($result->success)->toBeTrue($result->message);

    $user = User::where('email', 'drm@example.com')->firstOrFail();

    expect((float) $user->base_start_latitude)->toBe(14.5)
        ->and((float) $user->base_start_longitude)->toBe(121.0)
        ->and((float) $user->base_end_latitude)->toBe(15.0)
        ->and((float) $user->base_end_longitude)->toBe(121.5);
});

test('pull refreshes the rep base location from the users payload', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create([
        'email' => 'drm@example.com',
        'api_token' => 'test-token',
    ]);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/*' => Http::response([
            'users' => [
                [
                    'id' => 99,
                    'name' => 'Diana DRM',
                    'email' => 'drm@example.com',
                    'rsm_id' => null,
                    'base_start_latitude' => 7.1,
                    'base_start_longitude' => 125.6,
                    'base_end_latitude' => 7.2,
                    'base_end_longitude' => 125.7,
                ],
            ],
        ], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message);

    $user->refresh();

    expect((float) $user->base_start_latitude)->toBe(7.1)
        ->and((float) $user->base_start_longitude)->toBe(125.6)
        ->and((float) $user->base_end_latitude)->toBe(7.2)
        ->and((float) $user->base_end_longitude)->toBe(125.7);
});
