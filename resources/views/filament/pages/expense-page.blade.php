<x-filament-panels::page>
    @if($expenseLoadError !== '')
        <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ $expenseLoadError }}
        </div>
    @endif

    @include('filament.pages.expense-reader', ['context' => 'global'])
</x-filament-panels::page>
