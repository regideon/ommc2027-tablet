<?php

namespace App\Filament\Pages;

use App\Support\NativeAppReleaseMetadata;
use App\Services\TrustedLoginService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SettingsPage extends Page
{
    public string $releaseLabel = 'Release 0.0.0 (0)';

    protected string $view = 'filament.pages.settings-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = '';

    protected static ?int $navigationSort = 500;

    public function mount(NativeAppReleaseMetadata $releaseMetadata): void
    {
        $this->releaseLabel = $releaseMetadata->releaseLabel();
    }

    public function logout(): void
    {
        app(TrustedLoginService::class)->clear();
        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect('/app/login', navigate: false);
    }
}
