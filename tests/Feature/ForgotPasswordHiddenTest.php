<?php

use App\Filament\Pages\Auth\Login;
use Livewire\Livewire;

test('login page does not show the forgot password link', function (): void {
    Livewire::test(Login::class)
        ->assertDontSee('Forgot password?')
        ->assertDontSeeHtml('/app/password-reset/request');
});
