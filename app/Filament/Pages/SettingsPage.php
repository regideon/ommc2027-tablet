<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SettingsPage extends Page
{
    protected string $view = 'filament.pages.settings-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $title = '';
    protected static ?int $navigationSort = 500;

    public function logout(): void
    {
        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        redirect('/app/login');
    }
}
