<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\SyncService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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
        $remember = $data['remember'] ?? false;
        $sync = app(SyncService::class);

        $user = User::where('email', $email)->first();

        if ($user) {
            if (! Hash::check($password, $user->password)) {
                $this->throwFailureValidationException();
            }

            Filament::auth()->login($user, $remember);
            session()->regenerate();

            if ($sync->isReachable()) {
                $sync->refreshToken($email, $password);
                $pullResult = $sync->pull();

                if (! $pullResult->success) {
                    Notification::make()->title($pullResult->message)->danger()->send();
                }

                $user = User::where('email', $email)->firstOrFail();
                Filament::auth()->login($user, $remember);
            }

            $this->requestDevicePermissions();

            return app(LoginResponse::class);
        }

        // No local user — first login requires internet
        if (! $sync->isReachable()) {
            throw ValidationException::withMessages([
                'data.email' => 'No local account found. Connect to the internet for first login.',
            ]);
        }

        $tokenResult = $sync->refreshToken($email, $password);

        if (! $tokenResult->success) {
            $this->throwFailureValidationException();
        }

        $user = User::where('email', $email)->firstOrFail();

        Filament::auth()->login($user, $remember);
        session()->regenerate();

        $pullResult = $sync->pull();

        if (! $pullResult->success) {
            Notification::make()->title($pullResult->message)->danger()->send();
        }

        $user = User::where('email', $email)->firstOrFail();
        Filament::auth()->login($user, $remember);

        $this->requestDevicePermissions();

        return app(LoginResponse::class);
    }

    protected function requestDevicePermissions(): void
    {
        if (function_exists('nativephp_call')) {
            Geolocation::requestPermissions()->get();
        }
    }
}
