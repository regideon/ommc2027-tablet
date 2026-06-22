<?php

namespace App\Filament\Pages;

use App\Models\Salescall;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Native\Mobile\Facades\Geolocation;
use App\Services\SyncService;
use Filament\Notifications\Notification;
use App\Models\Brand;
use App\Models\MaterialGroup;

class SalescallPage extends Page
{
    protected string $view = 'filament.pages.salescall-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;
    protected static ?string $navigationLabel = 'Sales Calls';
    protected static ?string $title = '';
    protected static ?int $navigationSort = 100;

    public ?int $pendingCheckInId = null;

    public ?int $preselectedId = null;

    public function mount(): void
    {
        $this->preselectedId = (int) request()->get('call') ?: null;

        if (function_exists('nativephp_call')) {
            Geolocation::requestPermissions()->get();
        }
    }


    public function initiateCheckIn(int $salescallId): void
    {
        $this->pendingCheckInId = $salescallId;

        // Persist check-in time immediately so refresh doesn't lose the state
        Salescall::findOrFail($salescallId)->update([
            'actual_in'   => now(),
            'sync_status' => 'pending',
        ]);

        if (function_exists('nativephp_call')) {
            Geolocation::getCurrentPosition()
                ->fineAccuracy()
                ->id('checkin-' . $salescallId)
                ->get();
        } else {
            $this->dispatch('use-browser-geolocation', salescallId: $salescallId);
        }
    }

    public function initiateSubmit(
        int $salescallId,
        ?float $collectionAmount,
        ?string $remarks,
        ?string $concerns,
        ?int $materialGroupId,
        ?int $brandId,
        ?string $brandOther,
        ?int $categoryId,
        ?int $subCategoryId,
        ?int $subSubCategoryId,
        bool $isOnline = false
    ): void {
        if (function_exists('nativephp_call')) {
            Cache::put('pending_submit_' . $salescallId, [
                'collection_amount' => $collectionAmount,
                'remarks'           => $remarks,
                'concerns'          => $concerns,
                'material_group_id' => $materialGroupId,
                'brand_id'          => $brandId,
                'brand_other'       => $brandOther,
                'is_online'         => $isOnline,

                'category_id'         => $categoryId,
                'sub_category_id'     => $subCategoryId,
                'sub_sub_category_id' => $subSubCategoryId,
            ], now()->addMinutes(5));

            Geolocation::getCurrentPosition()
                ->fineAccuracy()
                ->id('submit-' . $salescallId)
                ->get();
        } else {
            $this->dispatch(
                'get-submit-location',
                salescallId: $salescallId,
                collectionAmount: $collectionAmount,
                remarks: $remarks,
                concerns: $concerns,
                materialGroupId: $materialGroupId,
                brandId: $brandId,
                brandOther: $brandOther,
                isOnline: $isOnline,
            );
        }
    }

    public function submitSalesCall(int $salescallId, ?float $collectionAmount, ?string $remarks, ?string $concerns, ?int $materialGroupId, ?int $brandId, ?string $brandOther, float $lat = 0, float $lng = 0, bool $isOnline = false): void
    {
        Salescall::findOrFail($salescallId)->update([
            'actual_out'           => now(),
            'latitude_actual_out'  => $lat ?: null,
            'longitude_actual_out' => $lng ?: null,
            'collection_amount'    => $collectionAmount,
            'remarks'              => $remarks ?: null,
            'concerns'             => $concerns ?: null,
            'material_group_id'    => $materialGroupId,
            'brand_id'             => $brandId,
            'brand_other'          => $brandOther ?: null,
            'sync_status'          => 'pending',
            'sync_attempts'        => 0,
        ]);

        if ($isOnline) {
            $this->runSync();
        }
    }


    #[On('nativephp-checkin-complete')]
    public function onCheckinComplete(int $salescallId): void
    {
        $this->dispatch('checkin-done', salescallId: $salescallId);
    }

    public function checkIn(int $salescallId, float $lat, float $lng, bool $isOnline = false): void
    {
        // actual_in already set — only update GPS coords now
        Salescall::findOrFail($salescallId)->update([
            'latitude_actual_in'  => $lat ?: null,
            'longitude_actual_in' => $lng ?: null,
            'sync_status'         => 'pending',
        ]);

        if ($isOnline) {
            $this->runSync();
        }
    }



    public function syncNow(): void
    {
        $this->runSync();
    }

    public function pullNow(): void
    {
        $result = app(SyncService::class)->pull();

        if ($result->success) {
            $this->dispatch('pull-done');
        } else {
            $this->dispatch('pull-failed');
            Notification::make()->title($result->message)->danger()->send();
        }
    }

    private function runSync(): void
    {
        $result = app(SyncService::class)->push();

        $this->dispatch('sync-done');

        if (! $result->success) {
            Notification::make()->title($result->message)->danger()->send();
        }
    }

    protected function getViewData(): array
    {
        $weekStart  = Carbon::now()->startOfWeek();
        $weekEnd    = Carbon::now()->endOfWeek();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();

        $calls = Salescall::with('customer')
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('actual_in', [$monthStart, $monthEnd])
                    ->orWhereBetween('created_at', [$monthStart, $monthEnd]);
            })
            ->orderByRaw('COALESCE(actual_in, created_at) ASC')
            ->get()
            ->values()
            ->map(function (Salescall $call) use ($weekStart, $weekEnd) {
                $visitDate = $call->visit_date;

                $filterGroup = match (true) {
                    $visitDate->isToday()                     => 'today',
                    $visitDate->between($weekStart, $weekEnd) => 'week',
                    default                                   => 'month',
                };

                return [
                    'id'           => $call->id,
                    'seq'          => $call->id,
                    'local_uuid'   => $call->local_uuid,
                    'name'         => $call->customer->name ?? '—',
                    'location'     => $call->customer->address ?? '',
                    'lat'          => $call->customer->latitude ?? null,
                    'lng'          => $call->customer->longitude ?? null,
                    'time'         => $visitDate->format('h:i A'),
                    'date_label'   => $visitDate->format('D, M j'),
                    'status'       => $call->status,
                    'sync_status'  => $call->sync_status,
                    'filter_group' => $filterGroup,
                ];
            });


        return [
            'callsJson'          => $calls->toJson(),
            'firstId'            => $calls->first()['id'] ?? null,
            'materialGroupsJson' => MaterialGroup::orderBy('name')->get(['id', 'name'])->toJson(),
            'brandsJson'         => Brand::where('enabled', true)->orderBy('name')->get(['id', 'material_group_id', 'name'])->toJson(),
            'preselectedId'      => $this->preselectedId,
        ];
    }
}
