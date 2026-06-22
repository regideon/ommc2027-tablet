<?php

namespace App\Filament\Widgets;

use App\Models\Salescall;
use Carbon\Carbon;
use Filament\Widgets\Widget;

class DashboardSalesrepWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard-salesrep-widget';

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();

        $todayCalls = Salescall::with('customer')
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('actual_in', [$monthStart, $monthEnd])
                    ->orWhereBetween('created_at', [$monthStart, $monthEnd]);
            })
            ->orderByRaw('COALESCE(actual_in, created_at) ASC')
            ->get()
            ->filter(fn($call) => $call->visit_date->isToday())
            ->values();

        $mapPins = $todayCalls
            ->filter(fn($c) => $c->customer?->latitude && $c->customer?->longitude)
            ->map(fn($c) => [
                'lat'  => (float) $c->customer->latitude,
                'lng'  => (float) $c->customer->longitude,
                'name' => $c->customer->name ?? '—',
                'time' => $c->visit_date->format('h:i A'),
            ])
            ->values();



        $inProgress     = $todayCalls->firstWhere('status', 'in_progress');
        $scheduledCount = $todayCalls->where('status', 'scheduled')->count();
        $completedCount = $todayCalls->where('status', 'completed')->count();
        $totalCount     = $todayCalls->count();

        [$notifTitle, $notifMessage] = match (true) {
            $inProgress !== null => [
                'Currently In Visit',
                "You're currently visiting {$inProgress->customer?->name}. Complete the sales call when you're done.",
            ],
            $completedCount > 0 && $scheduledCount > 0 => [
                'Keep It Up!',
                "You've completed {$completedCount} of {$totalCount} calls today. {$scheduledCount} more to go!",
            ],
            $scheduledCount > 0 && $completedCount === 0 => [
                "Today's Calls",
                "You have {$scheduledCount} call(s) scheduled today. Head out and start your first visit!",
            ],
            $totalCount > 0 && $scheduledCount === 0 => [
                'All Done! 🎉',
                "Excellent work! All {$completedCount} of today's calls are completed. Don't forget to sync.",
            ],
            default => [
                'No Calls Today',
                'No sales calls scheduled for today. Pull the latest data from the server to check for updates.',
            ],
        };




        return [
            'todayCalls'  => $todayCalls,
            'mapPinsJson' => $mapPins->toJson(),

            'notifTitle'   => $notifTitle,
            'notifMessage' => $notifMessage,
        ];
    }
}
