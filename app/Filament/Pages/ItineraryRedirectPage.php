<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Native\Mobile\Facades\Browser;

class ItineraryRedirectPage extends Page
{
    protected string $view = 'filament.pages.itinerary-redirect-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'My Itineraries';

    protected static ?string $title = '';

    protected static ?int $navigationSort = 475;

    public static function canAccess(): bool
    {
        return false;

        return auth()->user()?->hasAnyRole(['drm_approver', 'rsm_approver', 'admin']) ?? false;
    }

    public function mount(): void
    {
        $url = rtrim(config('services.sync.url', ''), '/').'/ommcpanel/my-itineraries';

        if (function_exists('nativephp_call')) {
            Browser::open($url);
            $this->redirect(SalescallPage::getUrl());

            return;
        }

        $this->redirect($url);
    }
}
