<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * First-time login: when the sync server is reachable, refreshToken() must
 * persist the account (hashed password + api_token) into the local SQLite
 * database so that every later login works fully offline.
 */
test('first login through a reachable sync server stores the account locally for offline login', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $hashedPassword = Hash::make('secret-password');

    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'ok'], 200),
        'portal.test/api/auth/tablet-login' => Http::response([
            'name' => 'Diana DRM',
            'last_name' => 'Surnamed',
            'email' => 'drm@example.com',
            'password' => $hashedPassword,
            'api_token' => 'tok_local_123',
            'roles' => ['drm'],
            'rsm_id' => null,
        ], 200),
    ]);

    $sync = app(SyncService::class);

    expect($sync->isReachable())->toBeTrue();

    $result = $sync->refreshToken('drm@example.com', 'secret-password');

    expect($result->success)->toBeTrue($result->message);

    $user = User::where('email', 'drm@example.com')->firstOrFail();
    expect($user->name)->toBe('Diana DRM');
    expect($user->last_name)->toBe('Surnamed');
    expect($user->fullName())->toBe('Diana DRM Surnamed');
    expect($user->api_token)->toBe('tok_local_123');
    expect(Hash::check('secret-password', $user->password))->toBeTrue();
    expect($user->hasRole('drm'))->toBeTrue();
});

test('login reference Pull persists last_name separately while preserving the matched local User identity', function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create([
        'name' => 'Jordan',
        'email' => 'jordan.surname@example.test',
        'api_token' => 'old-token',
        'server_id' => 871,
    ]);
    $localId = $user->id;
    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'ok'], 200),
        'portal.test/api/auth/tablet-login' => Http::response([
            'id' => 871,
            'name' => 'Jordan',
            'last_name' => 'Rivera',
            'email' => 'jordan.surname@example.test',
            'password' => Hash::make('secret-password'),
            'api_token' => 'refreshed-token',
            'roles' => [],
            'rsm_id' => null,
        ], 200),
    ]);

    $result = app(SyncService::class)->refreshToken('jordan.surname@example.test', 'secret-password');
    $reloaded = User::query()->where('email', 'jordan.surname@example.test')->firstOrFail();

    expect($result->success)->toBeTrue($result->message)
        ->and($reloaded->id)->toBe($localId)
        ->and($reloaded->name)->toBe('Jordan')
        ->and($reloaded->last_name)->toBe('Rivera')
        ->and($reloaded->email)->toBe('jordan.surname@example.test')
        ->and($reloaded->api_token)->toBe('refreshed-token')
        ->and($reloaded->server_id)->toBe(871)
        ->and($reloaded->fullName())->toBe('Jordan Rivera');
});

test('older login payloads without last_name preserve an existing local surname', function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    $user = User::factory()->create([
        'name' => 'Jordan',
        'last_name' => 'Rivera',
        'email' => 'jordan.surname@example.test',
    ]);
    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'ok'], 200),
        'portal.test/api/auth/tablet-login' => Http::response([
            'id' => $user->server_id,
            'name' => 'Jordan',
            'email' => $user->email,
            'password' => Hash::make('secret-password'),
            'api_token' => 'refreshed-token',
            'roles' => [],
            'rsm_id' => null,
        ], 200),
    ]);

    $result = app(SyncService::class)->refreshToken($user->email, 'secret-password');

    expect($result->success)->toBeTrue($result->message)
        ->and(User::query()->findOrFail($user->id)->last_name)->toBe('Rivera');
});

test('fullName does not append a surname that is already part of name', function (): void {
    $user = User::factory()->create(['name' => 'Jordan Rivera']);
    $user->last_name = 'Rivera';

    expect($user->fullName())->toBe('Jordan Rivera');
});

/**
 * Login uses response presence to avoid mistaking Portal HTTP errors for offline mode.
 */
test('isPortalResponsive treats an HTTP error response as reachable', function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'degraded'], 500),
    ]);

    $sync = app(SyncService::class);

    expect($sync->isReachable())->toBeFalse()
        ->and($sync->isPortalResponsive())->toBeTrue();
});

/**
 * A connection failure means Portal could not be reached and permits offline login.
 */
test('isReachable returns false when the sync server connection fails', function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/ping' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host'),
    ]);

    $sync = app(SyncService::class);

    expect($sync->isReachable())->toBeFalse()
        ->and($sync->isPortalResponsive())->toBeFalse();
});

/**
 * A blank SYNC_SERVER_URL must be reported distinctly from an offline device.
 */
test('isReachable reports an unconfigured sync server', function () {
    config(['sync.server_url' => '']);

    $sync = app(SyncService::class);

    expect($sync->isReachable())->toBeFalse()
        ->and($sync->isPortalResponsive())->toBeFalse();
});
