<?php

namespace App\Filament\Pages;

use App\Services\ExpenseReadService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ExpensePage extends Page
{
    protected string $view = 'filament.pages.expense-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $navigationLabel = 'Expenses';

    protected static ?string $title = '';

    protected static ?int $navigationSort = 200;

    public array $expenses = [];

    public string $expenseLoadError = '';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(ExpenseReadService $expenseReadService): void
    {
        $this->loadExpenses($expenseReadService);
    }

    public function loadExpenses(ExpenseReadService $expenseReadService): void
    {
        try {
            $this->expenses = $expenseReadService->forCurrentUser()->all();
            $this->expenseLoadError = $expenseReadService->globalReadFailed()
                ? 'Some Expenses could not be loaded. Refresh to try again.'
                : '';
        } catch (Throwable $exception) {
            report($exception);
            $this->expenses = [];
            $this->expenseLoadError = 'Expenses could not be loaded. Refresh to try again.';
        }
    }
}
