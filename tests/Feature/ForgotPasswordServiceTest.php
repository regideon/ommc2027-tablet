<?php

use App\Services\ForgotPasswordService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function configureForgotPasswordPortal(): void
{
    config(['sync.server_url' => 'http://portal.test']);
}

test('it requests a reset link from the portal', function () {
    configureForgotPasswordPortal();

    Http::fake([
        'http://portal.test/api/auth/forgot-password' => Http::response([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ], 202),
    ]);

    $result = app(ForgotPasswordService::class)->request('rep@example.test');

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('If an account exists for that email, a password reset link has been sent.');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'http://portal.test/api/auth/forgot-password'
        && $request['email'] === 'rep@example.test');
});

test('it reports a connection failure', function () {
    configureForgotPasswordPortal();

    Http::fake([
        'http://portal.test/api/auth/forgot-password' => fn () => throw new ConnectionException('Offline.'),
    ]);

    $result = app(ForgotPasswordService::class)->request('rep@example.test');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('connection_error')
        ->and($result->message)->toBe('Could not reach Portal. Check your connection and try again.');
});

test('it reports validation failures from the portal', function () {
    configureForgotPasswordPortal();

    Http::fake([
        'http://portal.test/api/auth/forgot-password' => Http::response([
            'message' => 'The email field must be a valid email address.',
        ], 422),
    ]);

    $result = app(ForgotPasswordService::class)->request('not-an-email');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('invalid_email')
        ->and($result->message)->toBe('The email field must be a valid email address.');
});

test('it reports rate limiting from the portal', function () {
    configureForgotPasswordPortal();

    Http::fake([
        'http://portal.test/api/auth/forgot-password' => Http::response([], 429),
    ]);

    $result = app(ForgotPasswordService::class)->request('rep@example.test');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('too_many_requests')
        ->and($result->message)->toBe('Too many requests. Please wait a moment and try again.');
});

test('it reports server failures from the portal', function () {
    configureForgotPasswordPortal();

    Http::fake([
        'http://portal.test/api/auth/forgot-password' => Http::response([], 500),
    ]);

    $result = app(ForgotPasswordService::class)->request('rep@example.test');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('server_error')
        ->and($result->message)->toBe('The Portal could not process the password reset request (500). Please try again.');
});

test('it refuses to send when the portal is not configured', function () {
    config(['sync.server_url' => '']);

    Http::fake();

    $result = app(ForgotPasswordService::class)->request('rep@example.test');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('not_configured')
        ->and($result->message)->toBe('Portal authentication is not configured on this device.');

    Http::assertNothingSent();
});
