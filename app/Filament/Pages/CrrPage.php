<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class CrrPage extends Page
{
    protected string $view = 'filament.pages.crr-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static ?string $navigationLabel = 'CRR';

    protected static ?string $title = '';

    protected static ?int $navigationSort = 300;
}
