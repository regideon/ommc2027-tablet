<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\SyncService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Auth\Pages\Login
{
    public function authenticate(): ?LoginResponse
    {
        $data     = $this->form->getState();
        $email    = $data['email'];
        $password = $data['password'];
        $remember = $data['remember'] ?? false;
        $sync     = app(SyncService::class);

        $user = User::where('email', $email)->first();

        if ($user) {
            if (! Hash::check($password, $user->password)) {
                $this->throwFailureValidationException();
            }

            Filament::auth()->login($user, $remember);
            session()->regenerate();

            if ($sync->isReachable()) {
                $sync->refreshToken($email, $password);
                $sync->pull();
            }

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

        $sync->pull();

        return app(LoginResponse::class);
    }
}
