<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function portalTabletLoginPayload(array $overrides = []): array
{
    return array_replace([
        'id' => 810,
        'name' => 'Portal Representative',
        'last_name' => 'Example',
        'email' => 'rep@example.test',
        'password' => Hash::make('portal-password'),
        'api_token' => 'tablet-test-token',
        'roles' => ['drm'],
        'rsm_id' => null,
        'region_type_id' => null,
        'base_start_latitude' => null,
        'base_start_longitude' => null,
        'base_end_latitude' => null,
        'base_end_longitude' => null,
    ], $overrides);
}

function fakePortalReachableLogin(array|int $loginResponse): void
{
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'ok'], 200),
        'portal.test/api/auth/tablet-login' => is_array($loginResponse)
            ? Http::response($loginResponse, 200)
            : Http::response(['error' => 'Authentication failed.'], $loginResponse),
        '*' => Http::response([], 200),
    ]);
}

test('online login authenticates at Portal before replacing a stale cached password hash', function (): void {
    fakePortalReachableLogin(portalTabletLoginPayload());
    $local = User::factory()->create([
        'name' => 'Old Local Name',
        'email' => 'rep@example.test',
        'password' => Hash::make('old-password'),
    ]);
    Role::firstOrCreate(['name' => 'rsm', 'guard_name' => 'web']);
    $local->assignRole('rsm');

    $localId = $local->id;

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'portal-password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $reconciled = User::query()->findOrFail($localId);

    expect(auth()->id())->toBe($localId)
        ->and($reconciled->name)->toBe('Portal Representative')
        ->and($reconciled->server_id)->toBe(810)
        ->and($reconciled->api_token)->toBe('tablet-test-token')
        ->and(Hash::check('portal-password', $reconciled->password))->toBeTrue()
        ->and($reconciled->password)->not->toBe('portal-password')
        ->and($reconciled->hasRole('drm'))->toBeTrue()
        ->and($reconciled->hasRole('rsm'))->toBeFalse();

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/auth/tablet-login')
        && $request['email'] === 'rep@example.test'
        && $request['password'] === 'portal-password');
});

test('online login creates a local identity for a Portal user missing from SQLite', function (): void {
    fakePortalReachableLogin(portalTabletLoginPayload());

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'portal-password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'rep@example.test')->firstOrFail();

    expect(auth()->id())->toBe($user->id)
        ->and(Hash::check('portal-password', $user->password))->toBeTrue()
        ->and($user->api_token)->toBe('tablet-test-token')
        ->and($user->hasRole('drm'))->toBeTrue();
});

test('online authentication rejection never falls back to a valid cached password', function (int $status): void {
    fakePortalReachableLogin($status);
    User::factory()->create([
        'email' => 'rep@example.test',
        'password' => Hash::make('portal-password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'portal-password'])
        ->call('authenticate')
        ->assertHasErrors('data.email');

    expect(auth()->guest())->toBeTrue();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/auth/tablet-login'));
})->with([401, 403, 422, 500, 503]);

test('an HTTP error from the Portal ping still attempts online authentication', function (int $status): void {
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        'portal.test/api/ping' => Http::response(['error' => 'Portal error.'], $status),
        'portal.test/api/auth/tablet-login' => Http::response(['error' => 'Invalid credentials.'], 401),
    ]);
    User::factory()->create([
        'email' => 'rep@example.test',
        'password' => Hash::make('portal-password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'portal-password'])
        ->call('authenticate')
        ->assertHasErrors('data.email');

    expect(auth()->guest())->toBeTrue();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/auth/tablet-login'));
})->with([401, 403, 422, 500]);

test('offline login still verifies valid cached credentials locally', function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        'portal.test/api/ping' => fn () => throw new ConnectionException('Network unavailable.'),
    ]);
    $user = User::factory()->create([
        'email' => 'rep@example.test',
        'password' => Hash::make('offline-password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'offline-password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($user->id);
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/api/auth/tablet-login'));
});

test('offline login rejects an invalid cached password', function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        'portal.test/api/ping' => fn () => throw new ConnectionException('Network unavailable.'),
    ]);
    User::factory()->create([
        'email' => 'rep@example.test',
        'password' => Hash::make('offline-password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasErrors('data.email');

    expect(auth()->guest())->toBeTrue();
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/api/auth/tablet-login'));
});

test('an unconfigured Portal URL does not enable cached offline authentication', function (): void {
    config(['sync.server_url' => '']);
    User::factory()->create([
        'email' => 'rep@example.test',
        'password' => Hash::make('offline-password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'offline-password'])
        ->call('authenticate')
        ->assertHasErrors('data.email');

    expect(auth()->guest())->toBeTrue();
});

test('Portal persistence failure does not establish a local session', function (): void {
    fakePortalReachableLogin(portalTabletLoginPayload());
    User::saving(function (): never {
        throw new RuntimeException('Disposable local persistence failure.');
    });

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'portal-password'])
        ->call('authenticate')
        ->assertHasErrors('data.email');

    expect(auth()->guest())->toBeTrue()
        ->and(User::query()->where('email', 'rep@example.test')->exists())->toBeFalse();
});

test('Portal connection loss during credential verification may use a valid cached account', function (): void {
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'ok'], 200),
        'portal.test/api/auth/tablet-login' => fn () => throw new ConnectionException('Network unavailable.'),
    ]);
    $user = User::factory()->create([
        'email' => 'rep@example.test',
        'password' => Hash::make('offline-password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rep@example.test', 'password' => 'offline-password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($user->id);
});
