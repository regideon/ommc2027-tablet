<?php

namespace App\Listeners;

use App\Models\Salescall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Events\Geolocation\LocationReceived;

class HandleLocationReceived
{
    public function handle(LocationReceived $event): void
    {
        $id = $event->id ?? '';

        if (str_starts_with($id, 'checkin-')) {
            $this->handleCheckIn($event, (int) str_replace('checkin-', '', $id));
        } elseif (str_starts_with($id, 'submit-')) {
            $this->handleSubmit($event, (int) str_replace('submit-', '', $id));
        }
    }

    private function handleCheckIn(LocationReceived $event, int $salescallId): void
    {
        if (!$event->success) {
            Log::warning('NativePHP GPS failed on check-in: ' . $event->error);
            return;
        }

        Salescall::where('id', $salescallId)->update([
            'actual_in'           => now(),
            'latitude_actual_in'  => $event->latitude,
            'longitude_actual_in' => $event->longitude,
            'sync_status'         => 'pending',
        ]);
    }

    private function handleSubmit(LocationReceived $event, int $salescallId): void
    {
        $pending = Cache::pull('pending_submit_' . $salescallId);

        if (! $pending) {
            Log::warning('NativePHP GPS submit fired but no pending data found for salescall #' . $salescallId);
            return;
        }

        Salescall::where('id', $salescallId)->update([
            'actual_out'           => now(),
            'latitude_actual_out'  => $event->success ? $event->latitude : null,
            'longitude_actual_out' => $event->success ? $event->longitude : null,
            'collection_amount'    => $pending['collection_amount'],
            'remarks'              => $pending['remarks'],
            'concerns'             => $pending['concerns'],
            'material_group_id'    => $pending['material_group_id'] ?? null,
            'brand_id'             => $pending['brand_id'] ?? null,
            'brand_other'          => $pending['brand_other'] ?? null,
            'sync_status'          => 'pending',
            'sync_attempts'        => 0,
        ]);
    }
}
