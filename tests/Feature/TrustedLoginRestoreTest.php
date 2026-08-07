<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\SettingsPage;
use App\Models\User;
use App\Services\TrustedLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function markerPath(): string
{
    return storage_path('framework/testing/trusted-login.json');
}

beforeEach(function () {
    config(['trusted-login.marker_path' => markerPath()]);
    config(['app.env' => 'local']);
    @unlink(markerPath());
});

/*
|--------------------------------------------------------------------------
| Scenario A — Normal restart
|--------------------------------------------------------------------------
| The persistent WebView cookie is present, so Laravel restores the session
| directly. TrustedLoginService must NOT be consulted.
*/

test('A: an existing authenticated session does not consult TrustedLoginService', function () {
    $user = User::factory()->create(['api_token' => 'tok_a']);

    $service = Mockery::mock(TrustedLoginService::class);
    $service->shouldReceive('restore')->never();
    app()->instance(TrustedLoginService::class, $service);

    $this->actingAs($user)->get('/app')->assertStatus(200);
});

test('A: an existing authenticated session reaches the dashboard without a marker', function () {
    $user = User::factory()->create(['api_token' => 'tok_a']);

    $response = $this->actingAs($user)->get('/app');

    $response->assertStatus(200);
    expect(markerPath())->not->toBeFile();
});

/*
|--------------------------------------------------------------------------
| Scenario B — Cookie-loss recovery
|--------------------------------------------------------------------------
| laravel_session is gone (cookie-loss) but a valid trusted marker remains.
| A protected route must restore the local trusted user, generate a new
| session, and land on the dashboard.
*/

test('B: a valid trusted marker restores the local user on a protected route', function () {
    $user = User::factory()->create(['api_token' => 'tok_b']);
    app(TrustedLoginService::class)->mark($user);

    $response = $this->get('/app');

    $response->assertStatus(200);
    expect(auth()->id())->toBe($user->id);
    expect(auth()->user()->api_token)->toBe('tok_b');
});

test('B: restore authenticates the user and sets a session cookie', function () {
    $user = User::factory()->create(['api_token' => 'tok_b']);
    app(TrustedLoginService::class)->mark($user);

    $response = $this->get('/app');

    $response->assertStatus(200);
    expect(auth()->id())->toBe($user->id);
    $cookies = $response->headers->getCookies();
    expect($cookies)->not->toBeEmpty();
    expect(array_map(fn ($cookie) => $cookie->getName(), $cookies))->toContain(config('session.cookie'));
});

/*
|--------------------------------------------------------------------------
| Scenario C — Explicit logout
|--------------------------------------------------------------------------
| Logout clears the marker, logs the user out, invalidates the session and
| regenerates the CSRF token. A cold restart afterwards must land on Login.
*/

test('C: explicit logout clears the marker, invalidates the session and lands on Login on restart', function () {
    $user = User::factory()->create(['api_token' => 'tok_c']);
    app(TrustedLoginService::class)->mark($user);
    expect(markerPath())->toBeFile();

    Livewire::actingAs($user)->test(SettingsPage::class)->call('logout');

    expect(markerPath())->not->toBeFile();
    expect(auth()->guest())->toBeTrue();

    $this->get('/app')->assertRedirect('/app/login');
    expect(auth()->guest())->toBeTrue();
});

test('C: replaying the pre-logout session cookie cannot restore the authenticated session', function () {
    $user = User::factory()->create(['api_token' => 'tok_c']);
    $sessionKey = 'login_web_'.sha1('web');

    $this->withSession([$sessionKey => $user->id]);
    $oldSessionId = session()->getId();
    expect($oldSessionId)->not->toBeEmpty();

    Livewire::actingAs($user)->test(SettingsPage::class)->call('logout');

    expect(session()->getId())->not->toBe($oldSessionId);
    expect(auth()->guest())->toBeTrue();

    $this->withCookie(config('session.cookie'), $oldSessionId)->get('/app')->assertRedirect('/app/login');
    expect(auth()->guest())->toBeTrue();
});

test('C: login writes the marker, logout clears it, full lifecycle', function () {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'portal.test/api/ping' => Http::response(['status' => 'ok'], 200),
        'portal.test/api/auth/tablet-login' => Http::response([
            'name' => 'Diana DRM',
            'email' => 'drm@example.com',
            'password' => bcrypt('secret-password'),
            'api_token' => 'tok_c2',
            'roles' => ['drm'],
            'rsm_id' => null,
        ], 200),
        'portal.test/api/sync/pull' => Http::response(['success' => true, 'data' => []], 200),
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'drm@example.com', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->not->toBeNull();
    expect(markerPath())->toBeFile();

    Livewire::actingAs(auth()->user())->test(SettingsPage::class)->call('logout');

    expect(markerPath())->not->toBeFile();
    expect(auth()->guest())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Scenario D — Invalid recovery marker
|--------------------------------------------------------------------------
| A changed api_token, a deleted user, or a malformed marker must be removed
| and never restore a session.
*/

test('D: a marker with a changed api_token is removed and does not restore', function () {
    $user = User::factory()->create(['api_token' => 'old_token']);
    app(TrustedLoginService::class)->mark($user);

    $user->update(['api_token' => 'rotated_token']);

    $this->get('/app')->assertRedirect('/app/login');
    expect(auth()->guest())->toBeTrue();
    expect(markerPath())->not->toBeFile();
});

test('D: a marker pointing at a deleted user is removed and does not restore', function () {
    $user = User::factory()->create(['api_token' => 'tok_d']);
    app(TrustedLoginService::class)->mark($user);

    $user->delete();

    $this->get('/app')->assertRedirect('/app/login');
    expect(auth()->guest())->toBeTrue();
    expect(markerPath())->not->toBeFile();
});

test('D: a user without an api_token is never marked and cannot be restored', function () {
    $user = User::factory()->create(['api_token' => null]);
    app(TrustedLoginService::class)->mark($user);

    expect(markerPath())->not->toBeFile();

    $this->get('/app')->assertRedirect('/app/login');
    expect(auth()->guest())->toBeTrue();
});

test('D: a malformed marker file is removed and does not restore', function () {
    User::factory()->create(['api_token' => 'tok_d2']);

    File::ensureDirectoryExists(dirname(markerPath()));
    File::put(markerPath(), '{not valid json');

    $this->get('/app')->assertRedirect('/app/login');
    expect(auth()->guest())->toBeTrue();
    expect(markerPath())->not->toBeFile();
});

test('D: a marker missing required fields is removed and does not restore', function () {
    File::ensureDirectoryExists(dirname(markerPath()));
    File::put(markerPath(), json_encode(['user_id' => 1]));

    $this->get('/app')->assertRedirect('/app/login');
    expect(auth()->guest())->toBeTrue();
    expect(markerPath())->not->toBeFile();
});

/*
|--------------------------------------------------------------------------
| Never-authenticated baseline
|--------------------------------------------------------------------------
| With no session and no marker, a protected route redirects to Login.
*/

test('a never-authenticated device cannot auto-login', function () {
    $this->get('/app')->assertRedirect('/app/login');
    expect(auth()->guest())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Offline restart
|--------------------------------------------------------------------------
| Restore depends only on local state (marker + local User row), so it works
| fully offline — the same as the offline first-login rule.
*/

test('offline restart restores the trusted user without any network', function () {
    config(['sync.server_url' => '']);
    $user = User::factory()->create(['api_token' => 'tok_off']);

    Http::fake();
    app(TrustedLoginService::class)->mark($user);

    $this->get('/app')->assertStatus(200);
    expect(auth()->id())->toBe($user->id);
});
