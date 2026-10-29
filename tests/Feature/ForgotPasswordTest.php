<?php

use App\Filament\Pages\Auth\ForgotPassword;
use App\Filament\Pages\Auth\Login;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('login page links to the forgot password page', function (): void {
    Livewire::test(Login::class)
        ->assertSee('Forgot password?')
        ->assertSeeHtml('/app/forgot-password');
});

test('the forgot password page is publicly accessible', function (): void {
    $this->get('/app/forgot-password')
        ->assertOk()
        ->assertSee('Forgot password?');
});

test('a user can request a password reset link from the forgot password page', function (): void {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'http://portal.test/api/auth/forgot-password' => Http::response([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ], 202),
    ]);

    Livewire::test(ForgotPassword::class)
        ->fillForm(['email' => 'rep@example.test'])
        ->call('request')
        ->assertHasNoFormErrors()
        ->assertNotified('Password reset requested');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://portal.test/api/auth/forgot-password'
        && $request['email'] === 'rep@example.test');
});

test('the forgot password page reports connection failures', function (): void {
    config(['sync.server_url' => 'http://portal.test']);

    Http::fake([
        'http://portal.test/api/auth/forgot-password' => fn () => throw new ConnectionException('Offline.'),
    ]);

    Livewire::test(ForgotPassword::class)
        ->fillForm(['email' => 'rep@example.test'])
        ->call('request')
        ->assertNotified('Password reset unavailable');
});
