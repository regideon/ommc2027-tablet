<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\SyncService;
use App\Services\TrustedLoginService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Native\Mobile\Facades\Geolocation;

class Login extends \Filament\Auth\Pages\Login
{
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $email = $data['email'];
        $password = $data['password'];
        $remember = (bool) ($data['remember'] ?? false);
        $sync = app(SyncService::class);

        $user = User::where('email', $email)->first();

        if (! $sync->isConfigured()) {
            throw ValidationException::withMessages([
                'data.email' => 'Portal authentication is not configured on this device.',
            ]);
        }

        if ($sync->isPortalResponsive()) {
            $tokenResult = $sync->refreshToken($email, $password);

            if ($tokenResult->success) {
                // refreshToken reconciles the Portal identity, roles, password hash, and token locally.
                $user = User::where('email', $email)->firstOrFail();
                Filament::auth()->login($user, $remember);
                session()->regenerate();

                $pullResult = $sync->pull();

                if (! $pullResult->success) {
                    Notification::make()->title($pullResult->message)->danger()->send();
                }

                $user = User::where('email', $email)->firstOrFail();
                Filament::auth()->login($user, $remember);

                $this->finalizeAuthentication($user, $remember);

                $this->requestDevicePermissions();

                return app(LoginResponse::class);
            }

            if ($tokenResult->errorCode !== 'connection_error') {
                $this->throwFailureValidationException();
            }
        }

        // Only a connection failure permits the previously approved offline path.
        if (! $user) {
            throw ValidationException::withMessages([
                'data.email' => 'No local account found. Connect to the internet for first login.',
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            $this->throwFailureValidationException();
        }

        Filament::auth()->login($user, $remember);
        session()->regenerate();

        $this->finalizeAuthentication($user, $remember);

        $this->requestDevicePermissions();

        return app(LoginResponse::class);
    }

    protected function finalizeAuthentication(User $user, bool $remember): void
    {
        app(TrustedLoginService::class)->mark($user, $remember);

        if ($remember) {
            return;
        }

        /** @var SessionGuard $guard */
        $guard = Filament::auth();

        Cookie::queue(Cookie::forget($guard->getRecallerName()));
    }

    protected function requestDevicePermissions(): void
    {
        if (function_exists('nativephp_call')) {
            Geolocation::requestPermissions()->get();
        }
    }
}
