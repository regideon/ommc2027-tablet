<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use BackedEnum;
use Filament\Support\Icons\Heroicon;


class ExpensePage extends Page
{
    protected string $view = 'filament.pages.expense-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;
    protected static ?string $navigationLabel = 'Expenses';
    protected static ?string $title = '';
    protected static ?int $navigationSort = 200;
}
