<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

/**
 * Recovery safety net for the HENRI tablet app.
 *
 * The persistent WebView cookie store is the primary mechanism that keeps a
 * tablet logged in across app restarts. This service is only a fallback for
 * the case where the laravel_session cookie is lost (e.g. app reinstall,
 * OS clearing the WebKit data store) but the device still has a trusted user.
 *
 * A marker is only ever written after a real, successful authentication. It is
 * never derived from "any User row exists". Validation requires:
 *   - the user still exists;
 *   - the user has an api_token;
 *   - the stored SHA-256 hash still matches the current api_token.
 */
class TrustedLoginService
{
    public function markerPath(): string
    {
        return config('trusted-login.marker_path', storage_path('app/private/trusted-login.json'));
    }

    /**
     * Persist a trusted-login marker after a real successful authentication.
     */
    public function mark(User $user): void
    {
        if (blank($user->api_token)) {
            return;
        }

        File::ensureDirectoryExists(dirname($this->markerPath()));

        File::put($this->markerPath(), (string) json_encode([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $user->api_token),
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Restore the previously authenticated local user, generating a fresh
     * session. Returns false when no valid marker exists or validation fails,
     * in which case the marker is removed so no further attempts happen.
     */
    public function restore(): bool
    {
        $marker = $this->readMarker();

        if ($marker === null) {
            return false;
        }

        $user = User::find($marker['user_id'] ?? null);

        if ($user === null || blank($user->api_token)) {
            $this->clear();

            return false;
        }

        if (! hash_equals($marker['token_hash'] ?? '', hash('sha256', $user->api_token))) {
            $this->clear();

            return false;
        }

        Auth::login($user);
        session()->regenerate();

        return true;
    }

    /**
     * Remove the trusted-login marker (called on explicit logout).
     */
    public function clear(): void
    {
        if (File::exists($this->markerPath())) {
            File::delete($this->markerPath());
        }
    }

    /**
     * @return array{user_id: int, token_hash: string, created_at: string}|null
     */
    protected function readMarker(): ?array
    {
        if (! File::exists($this->markerPath())) {
            return null;
        }

        $contents = File::get($this->markerPath());
        $marker = json_decode($contents, true);

        if (! is_array($marker) || ! isset($marker['user_id'], $marker['token_hash'])) {
            $this->clear();

            return null;
        }

        return $marker;
    }
}
