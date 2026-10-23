<?php

namespace App\Filament\Pages\Auth;

use App\Services\ForgotPasswordService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Notifications\Notification;

class ForgotPassword extends RequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $result = app(ForgotPasswordService::class)->request((string) $data['email']);

        $notification = Notification::make()
            ->title($result->success ? 'Password reset requested' : 'Password reset unavailable')
            ->body($result->message);

        if ($result->success) {
            $notification->success();
            $this->form->fill();
        } else {
            $notification->danger();
        }

        $notification->send();
    }
}
