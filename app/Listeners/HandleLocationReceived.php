<?php

namespace App\Listeners;

use App\Models\Salescall;
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
        if (! $event->success) {
            Log::warning('NativePHP GPS failed on check-in: '.$event->error);

            return;
        }

        Salescall::where('id', $salescallId)->update([
            'actual_in' => now(),
            'latitude_actual_in' => $event->latitude,
            'longitude_actual_in' => $event->longitude,
            'sync_status' => 'pending',
        ]);
    }

    /**
     * The outcome (actual_out, salescall_status_id, outcome_reason) is written
     * synchronously by SalescallPage::initiateFinish() when the button is tapped.
     * This listener only backfills the exit GPS coordinates once available, so a
     * slow or failed GPS fix never blocks the visit from being marked finished.
     */
    private function handleSubmit(LocationReceived $event, int $salescallId): void
    {
        if (! $event->success) {
            Log::warning('NativePHP GPS failed on visit finish: '.$event->error);

            return;
        }

        Salescall::where('id', $salescallId)->update([
            'latitude_actual_out' => $event->latitude,
            'longitude_actual_out' => $event->longitude,
        ]);
    }
}
