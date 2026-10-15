<?php

namespace App\Services\Sync\Push;

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerAggregateReconciler;
use App\Services\CustomerIdentityResolver;
use App\Services\Sync\SyncClient;
use App\Services\Sync\SyncFailureRecorder;
use App\Services\SyncResult;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CustomerPushService
{
    private readonly string $serverUrl;

    private readonly int $timeout;

    public function __construct(
        private readonly SyncClient $client,
        private readonly SyncFailureRecorder $recorder,
    ) {
        $this->serverUrl = rtrim((string) config('sync.server_url', ''), '/');
        $this->timeout = (int) config('sync.timeout', 15);
    }

    public function pushCustomer(int $customerId): SyncResult
    {
        $this->releaseStaleCustomerPushClaims();
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $customer = $this->pendingCustomerPushQuery()->whereKey($customerId)->first();

        if (! $customer) {
            return SyncResult::ok('Customer has no pending changes.');
        }

        return $this->pushCustomerRecords($this->client->forUser($user), collect([$customer]));
    }

    public function pushPendingCustomers(): SyncResult
    {
        $this->releaseStaleCustomerPushClaims();
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $customers = $this->pendingCustomerPushQuery()->get();

        return $this->pushCustomerRecords($this->client->forUser($user), $customers);
    }

    public function retryExhaustedCustomer(int $customerId): SyncResult
    {
        $this->releaseStaleCustomerPushClaims();
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();
        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $customer = Customer::with(['tradeProfile', 'categoryHistories', 'categoryEvents', 'users', 'personInCharge'])->find($customerId);
        if (! $customer || $customer->sync_status !== 'failed' || (int) $customer->sync_attempts < 3) {
            return SyncResult::fail('Only an exhausted failed Customer can be retried here.', 'not_retryable');
        }

        $readyKey = 'customer.manual_retry_ready.'.$customer->id;
        $readyHash = json_decode((string) DB::table('sync_states')->where('key', $readyKey)->value('value'), true);
        $payload = CustomerPushPayload::fromCustomer($customer)->toArray();
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if (! is_string($readyHash) || ! hash_equals($readyHash, $fingerprint)) {
            return SyncResult::fail('Open this Customer, correct and save at least one value, then retry.', 'correction_required');
        }

        return $this->pushCustomerRecords($this->client->forUser($user), collect([$customer]), true);
    }

    public function customerPayloadFingerprint(Customer $customer): string
    {
        $customer->loadMissing(['tradeProfile', 'categoryHistories', 'categoryEvents', 'users', 'personInCharge']);

        return CustomerPushPayload::fromCustomer($customer)->fingerprint();
    }

    public function hasPendingCustomerPushWork(): bool
    {
        return $this->pendingCustomerPushQuery()->exists();
    }

    private function pendingCustomerPushQuery(): Builder
    {
        return Customer::with(['tradeProfile', 'categoryHistories', 'categoryEvents'])
            ->where(function (Builder $query): void {
                $query->where('sync_status', 'pending')
                    ->orWhere(fn ($retry) => $retry->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
            });
    }

    private function releaseStaleCustomerPushClaims(): void
    {
        $cutoff = now()->subSeconds(max($this->timeout * 4, 120));
        $staleClaims = Customer::query()->where('sync_status', 'syncing')->where('updated_at', '<', $cutoff)->get(['id', 'sync_attempts']);

        foreach ($staleClaims as $claim) {
            Customer::query()->whereKey($claim->id)
                ->where('sync_status', 'syncing')
                ->where('updated_at', '<', $cutoff)
                ->update([
                    'sync_status' => 'failed',
                    'sync_attempts' => min((int) $claim->sync_attempts + 1, 255),
                    'sync_error' => 'A previous push ended without a recorded response. Review Portal by Customer Code before retrying.',
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  Collection<int, Customer>  $customers
     */
    private function pushCustomerRecords(PendingRequest $client, Collection $customers, bool $allowExhausted = false): SyncResult
    {
        $pushed = 0;
        $failed = 0;
        $retryable = 0;
        $failureReasons = [];

        foreach ($customers as $customer) {
            $result = $this->pushCustomerRecord($client, $customer, $allowExhausted);

            if ($result->errorCode === 'token_expired') {
                return SyncResult::fail(
                    $result->message,
                    'token_expired',
                    $pushed + $result->syncedCount,
                    $failed + $result->failedCount,
                    $retryable + $result->retryableCount,
                    array_values(array_unique([...$failureReasons, ...$result->failureReasons])),
                );
            }

            $pushed += $result->syncedCount;
            $failed += $result->failedCount;
            $retryable += $result->retryableCount;
            $failureReasons = array_values(array_unique([...$failureReasons, ...$result->failureReasons]));

            // A single-Customer action can show the exact Portal response in its UI.
            if ($customers->count() === 1 && $result->failedCount > 0) {
                return $result;
            }
        }

        return SyncResult::aggregate($pushed, $failed, $retryable, $failureReasons);
    }

    private function pushCustomerRecord(PendingRequest $client, Customer $customer, bool $allowExhausted = false): SyncResult
    {
        $originalStatus = $customer->sync_status;
        $claim = Customer::query()->whereKey($customer->id)
            ->where('sync_status', $customer->sync_status)
            ->where('sync_attempts', $customer->sync_attempts)
            ->where(fn (Builder $query) => $query->where('sync_status', 'pending')
                ->orWhere(fn (Builder $retry) => $retry->where('sync_status', 'failed')->where('sync_attempts', '<', 3))
                ->when($allowExhausted, fn (Builder $retry) => $retry->orWhere(fn (Builder $exhausted) => $exhausted->where('sync_status', 'failed')->where('sync_attempts', '>=', 3))))
            ->update(['sync_status' => 'syncing', 'updated_at' => now()]);

        if ($claim !== 1) {
            return SyncResult::ok('Customer push was already claimed or is no longer eligible.');
        }
        $customer->sync_status = 'syncing';
        // The claim was written with a query builder, so keep Eloquent's
        // original value aligned with the database before later transitions.
        $customer->syncOriginalAttribute('sync_status');

        try {
            $payload = CustomerPushPayload::fromCustomer($customer)->toArray();
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $aggregateFingerprint = app(CustomerAggregateReconciler::class)->fingerprint((int) $customer->id);

            $response = $client->post("{$this->serverUrl}/api/customers/sync", $payload);

            if ($response->status() === 401) {
                $customer->update(['sync_status' => $originalStatus]);

                return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
            }

            if ($this->supersededByLocalEdit($customer)) {
                return SyncResult::ok('A local edit superseded this push result; the record stays pending.');
            }

            if ($response->successful() && filter_var($response->json('server_id'), FILTER_VALIDATE_INT) && (int) $response->json('server_id') > 0) {
                return $this->handleCustomerPushAcknowledgment($customer, $response->json(), $aggregateFingerprint, $allowExhausted);
            }

            if ($response->status() === 409 && $response->json('code') === 'customer_conflict') {
                $customer->update(['sync_status' => 'conflict', 'sync_error' => $response->json('message', 'Customer changed on Portal.')]);

                return SyncResult::fail('Customer changed on the server and needs review.', 'customer_conflict', 0, 1, 0, ['customer_conflict']);
            }

            $portalError = $this->customerPortalError($response);
            $previousError = $customer->sync_error;
            $this->recorder->recordItemFailure($customer, 'portal_rejected', $portalError, [
                'stage' => 'customer:portal',
                'endpoint' => '/api/customers/sync',
                'http_status' => $response->status(),
            ]);
            $this->recorder->recordCustomerPushErrorHistory($customer, $previousError);
            $this->recorder->rememberFailedCustomerPayload($customer, $fingerprint);

            $retryable = (int) $customer->sync_attempts < 3 ? 1 : 0;

            return SyncResult::fail($portalError, 'push_failed', 0, 1, $retryable, ['portal_rejected']);
        } catch (Throwable $exception) {
            $previousError = $customer->sync_error;
            $this->recorder->recordUnexpectedItemFailure($customer, $exception, 'customer:unexpected');
            $this->recorder->recordCustomerPushErrorHistory($customer, $previousError);
            if (isset($fingerprint)) {
                $this->recorder->rememberFailedCustomerPayload($customer, $fingerprint);
            }

            $retryable = (int) $customer->sync_attempts < 3 ? 1 : 0;

            return SyncResult::fail($retryable ? 'Customer push failed and will retry on the next sync.' : 'Customer push failed and reached the automatic retry limit. Correct and save the Customer before using Retry Customer.', 'push_failed', 0, 1, $retryable, ['unexpected_sync_error']);
        }
    }

    /**
     * @param  array<string, mixed>  $acknowledgment
     */
    private function handleCustomerPushAcknowledgment(Customer $customer, array $acknowledgment, string $aggregateFingerprint, bool $allowExhausted): SyncResult
    {
        $acknowledgedIdentity = [
            'id' => $acknowledgment['server_id'],
            'local_uuid' => $acknowledgment['local_uuid'] ?? null,
            'unique_id' => $acknowledgment['unique_id'] ?? null,
            'company_id' => $customer->company_id,
            'updated_at' => $acknowledgment['updated_at'] ?? null,
        ];
        $identity = app(CustomerIdentityResolver::class)->resolve($acknowledgedIdentity, (int) $customer->id);

        if ($identity->isConflict()) {
            $customer->update([
                'sync_status' => 'conflict',
                'sync_error' => 'Portal acknowledged a Customer identity that conflicts with this Tablet row ('.$identity->reason.'). Review is required.',
            ]);

            return SyncResult::fail('Customer identity conflict requires review.', 'customer_identity_conflict', 0, 1, 0, ['customer_identity_conflict']);
        }

        $reconciler = app(CustomerAggregateReconciler::class);
        $snapshotProvided = array_key_exists('customer_snapshot', $acknowledgment);
        $snapshot = $acknowledgment['customer_snapshot'] ?? null;
        $snapshotCapabilityAvailable = is_array($snapshot)
            && $reconciler->supportsCompleteSnapshot($snapshot);

        if (! $snapshotProvided) {
            Log::notice('sync:customer:acknowledged_without_snapshot_capability', [
                'local_customer_id' => $customer->id,
                'portal_customer_id' => (int) $acknowledgment['server_id'],
            ]);
        }

        if (! $snapshotProvided) {
            $outcome = $reconciler->acknowledgeWithoutSnapshot($acknowledgedIdentity, (int) $customer->id, $aggregateFingerprint);
        } elseif (! $snapshotCapabilityAvailable
            || ! isset($snapshot['customers'][0]['id'])
            || (int) $snapshot['customers'][0]['id'] !== (int) $acknowledgment['server_id']
            || ($snapshot['customers'][0]['local_uuid'] ?? null) !== ($acknowledgment['local_uuid'] ?? null)) {
            $outcome = $reconciler->rejectAcknowledgmentSnapshot(
                $acknowledgedIdentity,
                (int) $customer->id,
                'Portal acknowledged this Customer with an invalid or incomplete aggregate snapshot. Local data was preserved for review.',
            );
        } else {
            $outcome = $reconciler->reconcile(
                $snapshot,
                [],
                (int) $customer->id,
                $aggregateFingerprint,
                requireCompleteContract: true,
            );
        }

        if (! in_array($outcome['status'], ['applied', 'acknowledged_without_snapshot'], true)) {
            return SyncResult::fail(
                'Customer delivery was acknowledged, but local changes were preserved and require conflict review.',
                'customer_conflict',
                0,
                1,
                0,
                ['customer_conflict'],
            );
        }

        DB::table('customers')->where('id', $customer->id)->update([
            'customer_code_reservation_token' => null,
            'sync_attempts' => $allowExhausted ? $customer->sync_attempts : 0,
            'sync_error' => $allowExhausted ? $customer->sync_error : null,
        ]);
        DB::table('sync_states')->whereIn('key', [
            'customer.manual_retry_ready.'.$customer->id,
            'customer.last_failed_payload.'.$customer->id,
        ])->delete();

        $snapshotOutcome = $snapshotCapabilityAvailable
            ? 'customer_snapshot_reconciled'
            : 'customer_snapshot_unsupported';

        return SyncResult::ok(
            $snapshotCapabilityAvailable
                ? 'Customer delivery acknowledged and authoritative aggregate reconciled.'
                : 'Customer delivery acknowledged; Portal does not support the authoritative aggregate snapshot contract, so aggregate refresh is not claimed.',
            1,
            0,
            0,
            [$snapshotOutcome],
        );
    }

    /** Return a concise, human-readable Portal response for the Create Customer notification. */
    private function customerPortalError(Response $response): string
    {
        $status = $response->status();
        $payload = $response->json();
        $details = [];

        if (is_array($payload)) {
            if (is_string($payload['message'] ?? null) && trim($payload['message']) !== '') {
                $details[] = trim(strip_tags($payload['message']));
            }

            foreach (($payload['errors'] ?? []) as $field => $messages) {
                foreach ((array) $messages as $message) {
                    if (is_string($message) && trim($message) !== '') {
                        $details[] = trim((string) $field).': '.trim(strip_tags($message));
                    }
                }
            }
        }

        $detail = implode(' ', array_unique($details));
        if ($detail === '') {
            $detail = 'The Portal did not provide a readable error message.';
        }

        return mb_substr("Portal returned HTTP {$status}: {$detail}", 0, 300);
    }

    private function supersededByLocalEdit(Customer $customer): bool
    {
        return DB::table('customers')->where('id', $customer->id)->value('sync_status') !== 'syncing';
    }
}
