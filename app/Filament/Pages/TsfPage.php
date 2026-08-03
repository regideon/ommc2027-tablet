<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class TsfPage extends Page
{
    protected string $view = 'filament.pages.tsf-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocument;

    protected static ?string $navigationLabel = 'TSF';

    protected static ?string $title = '';

    protected static ?int $navigationSort = 400;

    protected static bool $shouldRegisterNavigation = false; 
}
