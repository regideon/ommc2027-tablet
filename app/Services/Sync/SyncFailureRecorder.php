<?php

namespace App\Services\Sync;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFailureRecorder
{
    public function recordUnexpectedItemFailure(Model $model, Throwable $e, string $stage): void
    {
        $this->recordThrowableFailure($model, 'unexpected_sync_error', $e, [
            'stage' => $stage,
        ]);
    }

    public function recordThrowableFailure(Model $model, string $classification, Throwable $e, array $context = []): void
    {
        $message = $this->formatSyncError($classification, $e->getMessage());

        $this->recordItemFailure($model, $classification, $message, [
            ...$context,
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
        ]);
    }

    public function recordItemFailure(Model $model, string $classification, string $error, array $context = []): void
    {
        $formattedError = $this->formatSyncError($classification, $error);

        try {
            $this->markFailed($model, $formattedError);
        } catch (Throwable $markFailedError) {
            Log::error('sync:item:mark-failed-error', [
                'classification' => $classification,
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'original_error' => $formattedError,
                'mark_failed_exception_class' => $markFailedError::class,
                'mark_failed_exception_message' => $markFailedError->getMessage(),
            ]);
        }

        Log::warning('sync:item:failed', [
            'classification' => $classification,
            'model' => $model::class,
            'model_id' => $model->getKey(),
            'local_uuid' => $model->local_uuid ?? null,
            'error' => $formattedError,
            ...$context,
        ]);
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    public function recordCollectionFailure(Collection $models, string $classification, string $error, array $context = []): void
    {
        $models->each(fn (Model $model) => $this->recordItemFailure($model, $classification, $error, $context));
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    public function recordCollectionUnexpectedFailure(Collection $models, Throwable $e, string $stage, array $context = []): void
    {
        $models->each(fn (Model $model) => $this->recordThrowableFailure($model, 'unexpected_sync_error', $e, [
            'stage' => $stage,
            ...$context,
        ]));
    }

    public function rememberFailedCustomerPayload(Customer $customer, string $fingerprint): void
    {
        DB::table('sync_states')->updateOrInsert(
            ['key' => 'customer.last_failed_payload.'.$customer->id],
            ['value' => json_encode($fingerprint), 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('sync_states')->where('key', 'customer.manual_retry_ready.'.$customer->id)->delete();
    }

    public function recordCustomerPushErrorHistory(Customer $customer, ?string $previousError): void
    {
        $key = 'customer.push_error_history.'.$customer->id;
        $history = json_decode((string) DB::table('sync_states')->where('key', $key)->value('value'), true);
        $history = is_array($history) ? $history : [];

        foreach ([
            ['attempt' => max(0, (int) $customer->sync_attempts - 1), 'message' => $previousError],
            ['attempt' => (int) $customer->sync_attempts, 'message' => $customer->sync_error],
        ] as $entry) {
            if (is_string($entry['message']) && $entry['message'] !== '' && ($history === [] || ($history[array_key_last($history)]['message'] ?? null) !== $entry['message'])) {
                $history[] = $entry;
            }
        }

        $history = array_slice($history, -10);
        DB::table('sync_states')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($history), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function markFailed(Model $model, string $error): void
    {
        $model->update([
            'sync_status' => 'failed',
            'sync_attempts' => ($model->sync_attempts ?? 0) + 1,
            'sync_error' => $error,
        ]);
    }

    private function formatSyncError(string $classification, string $detail): string
    {
        if (str_starts_with($detail, $classification.'|')) {
            $detail = substr($detail, strlen($classification) + 1) ?: $detail;
        }

        return $classification.': '.$this->trimSyncError($detail);
    }

    private function trimSyncError(string $error): string
    {
        return mb_substr(trim($error), 0, 300);
    }
}
