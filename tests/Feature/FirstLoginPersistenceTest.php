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
    expect($user->api_token)->toBe('tok_local_123');
    expect(Hash::check('secret-password', $user->password))->toBeTrue();
    expect($user->hasRole('drm'))->toBeTrue();
});

/**
 * Regression: a reachable-but-broken server (5xx on /api/ping) must not be
 * indistinguishable from being offline. isReachable() reports the HTTP status.
 */
test('isReachable reports the HTTP status when the sync server responds with an error', function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'degraded'], 500),
    ]);

    $sync = app(SyncService::class);

    expect($sync->isReachable())->toBeFalse();
    expect($sync->lastError())->toContain('500');
});

/**
 * Regression: a DNS/connection failure (dead URL, TLS error, timeout) must
 * surface its exception instead of silently becoming "no internet".
 */
test('isReachable reports the underlying connection exception', function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/ping' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host'),
    ]);

    $sync = app(SyncService::class);

    expect($sync->isReachable())->toBeFalse();
    expect($sync->lastError())->toContain('cURL error 6');
});

/**
 * A blank SYNC_SERVER_URL must be reported distinctly from an offline device.
 */
test('isReachable reports an unconfigured sync server', function () {
    config(['sync.server_url' => '']);

    $sync = app(SyncService::class);

    expect($sync->isReachable())->toBeFalse();
    expect($sync->lastError())->toContain('not configured');
});
