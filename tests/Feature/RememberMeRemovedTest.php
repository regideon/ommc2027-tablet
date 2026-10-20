<?php

use App\Filament\Pages\Auth\Login;
use Livewire\Livewire;

test('login form does not show a remember me checkbox', function (): void {
    Livewire::test(Login::class)
        ->assertDontSee('Remember me')
        ->assertDontSeeHtml('id="form.remember"');
});
