<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class ForgotPasswordService
{
    public const GENERIC_MESSAGE = 'If an account exists for that email, a password reset link has been sent.';

    public function request(string $email): SyncResult
    {
        $serverUrl = rtrim((string) config('sync.server_url', ''), '/');

        if ($serverUrl === '') {
            return SyncResult::fail('Portal authentication is not configured on this device.', 'not_configured');
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('sync.timeout', 15))
                ->post("{$serverUrl}/api/auth/forgot-password", ['email' => $email]);
        } catch (ConnectionException) {
            return SyncResult::fail('Could not reach Portal. Check your connection and try again.', 'connection_error');
        } catch (Throwable) {
            return SyncResult::fail('Could not complete the password reset request.', 'request_error');
        }

        if ($response->status() === 422) {
            $message = $response->json('message');

            return SyncResult::fail(
                is_string($message) && $message !== '' ? $message : 'Enter a valid email address.',
                'invalid_email',
                httpStatus: 422,
            );
        }

        if ($response->status() === 429) {
            return SyncResult::fail(
                'Too many requests. Please wait a moment and try again.',
                'too_many_requests',
                httpStatus: 429,
            );
        }

        if ($response->failed()) {
            return SyncResult::fail(
                "The Portal could not process the password reset request ({$response->status()}). Please try again.",
                'server_error',
                httpStatus: $response->status(),
            );
        }

        return SyncResult::ok(self::GENERIC_MESSAGE);
    }
}
