<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function rememberMeMarkerPath(): string
{
    return storage_path('framework/testing/remember-me-marker.json');
}

function fakeRememberMePortal(): void
{
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'ok'], 200),
        'portal.test/api/auth/tablet-login' => Http::response([
            'id' => 992,
            'name' => 'Remember Me',
            'email' => 'remember@example.test',
            'password' => Hash::make('portal-password'),
            'api_token' => 'remember-me-token',
            'roles' => [],
            'rsm_id' => null,
        ], 200),
        '*' => Http::response([], 200),
    ]);
}

beforeEach(function (): void {
    config(['trusted-login.marker_path' => rememberMeMarkerPath()]);
    @unlink(rememberMeMarkerPath());
});

afterEach(function (): void {
    @unlink(rememberMeMarkerPath());
});

test('remember me checked writes the trusted marker and queues the recaller cookie', function (): void {
    fakeRememberMePortal();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'remember@example.test',
            'password' => 'portal-password',
            'remember' => true,
        ])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'remember@example.test')->firstOrFail();
    $marker = json_decode((string) file_get_contents(rememberMeMarkerPath()), true);
    $recallerName = auth()->guard()->getRecallerName();
    $recallerCookie = collect(app('cookie')->getQueuedCookies())
        ->first(fn ($cookie): bool => $cookie->getName() === $recallerName);

    expect($marker['remember'] ?? null)->toBeTrue()
        ->and($user->remember_token)->not->toBeNull()
        ->and($recallerCookie)->not->toBeNull()
        ->and($recallerCookie->getExpiresTime())->toBeGreaterThan(time());
});

test('remember me unchecked clears the trusted marker and queues recaller deletion', function (): void {
    fakeRememberMePortal();

    $user = User::factory()->create([
        'email' => 'remember@example.test',
        'api_token' => 'remember-me-token',
        'remember_token' => 'old-recaller-token',
    ]);

    File::ensureDirectoryExists(dirname(rememberMeMarkerPath()));
    File::put(rememberMeMarkerPath(), (string) json_encode([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'remember-me-token'),
        'created_at' => now()->toIso8601String(),
        'remember' => true,
    ], JSON_THROW_ON_ERROR));

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'remember@example.test',
            'password' => 'portal-password',
            'remember' => false,
        ])
        ->call('authenticate')
        ->assertHasNoErrors();

    $recallerName = auth()->guard()->getRecallerName();
    $recallerCookie = collect(app('cookie')->getQueuedCookies())
        ->first(fn ($cookie): bool => $cookie->getName() === $recallerName);

    expect(rememberMeMarkerPath())->not->toBeFile()
        ->and($recallerCookie)->not->toBeNull()
        ->and($recallerCookie->getExpiresTime())->toBeLessThan(time());
});
