<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class CustomerAggregateReconciler
{
    public const COMPLETE_COLLECTIONS = [
        'customers',
        'customer_users',
        'customer_brands',
        'customer_categories',
        'customer_trade_profiles',
        'customer_category_histories',
        'customer_category_events',
    ];

    private const PROTECTED_STATUSES = ['pending', 'failed', 'conflict', 'syncing'];

    public function __construct(private readonly CustomerIdentityResolver $identityResolver) {}

    public function supportsCompleteSnapshot(?array $contract): bool
    {
        return ($contract['contract_version'] ?? null) === 1
            && is_array($contract['capabilities'] ?? null)
            && in_array('customer.aggregate.snapshot.v1', $contract['capabilities'], true)
            && is_array($contract['complete_collections'] ?? null)
            && array_diff(self::COMPLETE_COLLECTIONS, $contract['complete_collections']) === [];
    }

    /**
     * Reconcile one Portal Customer and the collections included in the page.
     * The caller keeps Pull's existing page transaction around this operation.
     *
     * @param  array<string, mixed>  $aggregate
     * @param  array<string, int>  $localUserIdsByEmail
     * @return array{status: string, local_id: int, reason?: string}
     */
    public function reconcile(
        array $aggregate,
        array $localUserIdsByEmail = [],
        ?int $expectedLocalId = null,
        ?string $expectedFingerprint = null,
        bool $requireCompleteContract = false,
    ): array {
        $customers = $aggregate['customers'] ?? null;
        if (! is_array($customers) || count($customers) !== 1 || ! is_array($customers[0])) {
            throw new RuntimeException('Customer aggregate snapshot must contain exactly one Customer.');
        }

        $customer = $customers[0];
        $contract = $aggregate['customer_snapshot_contract'] ?? $aggregate['contract']
            ?? (array_key_exists('contract_version', $aggregate) ? $aggregate : null);
        $complete = $this->supportsCompleteSnapshot(is_array($contract) ? $contract : null);

        if ($requireCompleteContract && ! $complete) {
            throw new RuntimeException('Portal Customer acknowledgment does not satisfy the complete aggregate snapshot contract.');
        }

        foreach (self::COMPLETE_COLLECTIONS as $collection) {
            if (array_key_exists($collection, $aggregate) && ! is_array($aggregate[$collection])) {
                throw new RuntimeException("Portal Customer aggregate collection {$collection} is invalid.");
            }
            if ($complete && ! is_array($aggregate[$collection] ?? null)) {
                throw new RuntimeException("Complete Portal Customer aggregate omitted {$collection}.");
            }
        }
        if ($complete && (! array_key_exists('access_user_emails', $customer)
            || ! is_array($customer['access_user_emails'])
            || ! array_key_exists('person_in_charge_email', $customer))) {
            throw new RuntimeException('Complete Portal Customer snapshot omitted its Access or PIC identity data.');
        }

        return DB::transaction(function () use ($aggregate, $customer, $localUserIdsByEmail, $expectedLocalId, $expectedFingerprint, $complete): array {
            $identity = $this->identityResolver->resolve($customer, $expectedLocalId);
            if ($identity->isConflict() || $identity->localId === null) {
                throw new RuntimeException('Portal Customer identity conflict: '.($identity->reason ?? 'unresolved').'.');
            }

            $localId = $identity->localId;
            $local = DB::table('customers')->where('id', $localId)->lockForUpdate()->first();
            $status = $local->sync_status ?? null;

            if ($expectedFingerprint !== null && $this->fingerprint($localId) !== $expectedFingerprint) {
                $this->markConflict($localId, $customer, 'Customer or related local data changed while Push was in flight. Portal acknowledged the earlier version; local edits were preserved.');

                return ['status' => 'conflict', 'local_id' => $localId, 'reason' => 'local_changes_during_push'];
            }

            if ($status === 'conflict') {
                if ($expectedFingerprint !== null) {
                    $this->markConflict($localId, $customer, $local->sync_error ?: 'Customer requires conflict review.');
                }

                return ['status' => 'preserved_conflict', 'local_id' => $localId, 'reason' => 'existing_conflict'];
            }

            if ($expectedFingerprint === null && in_array($status, self::PROTECTED_STATUSES, true)) {
                if ($this->portalAdvancedBeyondBase($local, $customer)) {
                    $this->markConflict($localId, $customer, 'Portal Customer changed while this Tablet Customer had pending local edits. Local values were preserved.');

                    return ['status' => 'conflict', 'local_id' => $localId, 'reason' => 'portal_and_tablet_changed'];
                }

                return ['status' => 'preserved_local', 'local_id' => $localId, 'reason' => 'pending_local_changes'];
            }

            $portalId = (int) $customer['id'];
            $localUserIdsByEmail = $this->completeUserMap($aggregate, $localUserIdsByEmail);
            $attributes = $this->customerAttributes($customer, $localUserIdsByEmail);

            if ($local === null) {
                $attributes['id'] = $localId;
                $attributes['created_at'] = now();
            }
            $attributes['server_id'] = $portalId;
            $attributes['server_updated_at'] = $customer['updated_at'] ?? null;
            $attributes['sync_status'] = 'synced';
            $attributes['sync_error'] = null;
            $attributes['synced_at'] = now();
            $attributes['updated_at'] = now();
            DB::table('customers')->updateOrInsert(['id' => $localId], $attributes);

            $collections = $this->collectionsForCustomer($aggregate, $portalId);
            $this->applyCollections($localId, $customer, $collections, $localUserIdsByEmail, $complete);

            return ['status' => 'applied', 'local_id' => $localId];
        });
    }

    /**
     * Record a successful legacy acknowledgment without treating it as an
     * aggregate refresh. The sent state is guarded against in-flight edits.
     *
     * @param  array<string, mixed>  $identity
     * @return array{status: string, local_id: int, reason?: string}
     */
    public function acknowledgeWithoutSnapshot(array $identity, int $expectedLocalId, string $expectedFingerprint): array
    {
        return DB::transaction(function () use ($identity, $expectedLocalId, $expectedFingerprint): array {
            $resolution = $this->identityResolver->resolve($identity, $expectedLocalId);
            if ($resolution->isConflict() || $resolution->localId === null) {
                throw new RuntimeException('Portal Customer acknowledgment identity conflict: '.($resolution->reason ?? 'unresolved').'.');
            }

            $localId = $resolution->localId;
            $local = DB::table('customers')->where('id', $localId)->lockForUpdate()->first();
            if (! $local) {
                throw new RuntimeException('Acknowledged Tablet Customer no longer exists.');
            }

            if ($this->fingerprint($localId) !== $expectedFingerprint) {
                $this->markConflict($localId, $identity, 'Customer or related local data changed while Push was in flight. Portal confirmed receipt of an earlier version; local edits were preserved.');

                return ['status' => 'conflict', 'local_id' => $localId, 'reason' => 'local_changes_during_push'];
            }

            if ($local->sync_status === 'conflict') {
                return ['status' => 'preserved_conflict', 'local_id' => $localId, 'reason' => 'existing_conflict'];
            }

            DB::table('customers')->where('id', $localId)->update([
                'server_id' => (int) $identity['id'],
                'server_updated_at' => $identity['updated_at'] ?? null,
                'unique_id' => ($identity['unique_id'] ?? null) ?: $local->unique_id,
                'sync_status' => 'synced',
                'sync_error' => null,
                'synced_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => 'acknowledged_without_snapshot', 'local_id' => $localId];
        });
    }

    /**
     * Keep the acknowledged identity while blocking malformed versioned data.
     *
     * @param  array<string, mixed>  $identity
     * @return array{status: string, local_id: int, reason: string}
     */
    public function rejectAcknowledgmentSnapshot(array $identity, int $expectedLocalId, string $message): array
    {
        return DB::transaction(function () use ($identity, $expectedLocalId, $message): array {
            $resolution = $this->identityResolver->resolve($identity, $expectedLocalId);
            if ($resolution->isConflict() || $resolution->localId === null) {
                throw new RuntimeException('Portal Customer acknowledgment identity conflict: '.($resolution->reason ?? 'unresolved').'.');
            }

            $localId = $resolution->localId;
            DB::table('customers')->where('id', $localId)->lockForUpdate()->first();
            $this->markConflict($localId, $identity, $message);

            return ['status' => 'conflict', 'local_id' => $localId, 'reason' => 'unsupported_snapshot_contract'];
        });
    }

    public function fingerprint(int $localId): string
    {
        $customerColumns = [
            'name', 'unique_id', 'company_id', 'general_category_id', 'competitor_volume',
            'region_specific_id', 'area_cluster_id', 'province_id', 'municipality_id',
            'barangay_id', 'address', 'latitude', 'longitude', 'contact_person',
            'contact_number', 'business_landline_number', 'business_mobile_number',
            'date_established', 'is_active', 'landmass_code', 'person_in_charge_id',
        ];
        $customer = DB::table('customers')->where('id', $localId)->first($customerColumns);
        if (! $customer) {
            throw new RuntimeException('Tablet Customer disappeared during synchronization.');
        }

        $profile = DB::table('customer_trade_profiles')->where('customer_id', $localId)
            ->first(['profile_type', 'profile_data', 'house_number', 'entry_detail', 'classifications', 'ommc_brands', 'ommc_mcb_brands', 'tpl_pollux', 'other_competitor_brands', 'mcb_competitors', 'other_competitors_note', 'working_days', 'operating_hours', 'motiv_user', 'delivery_method', 'ulab']);
        $users = DB::table('customer_user')->where('customer_id', $localId)->join('users', 'users.id', '=', 'customer_user.user_id')->orderBy('users.email')->pluck('users.email')->all();
        $histories = DB::table('customer_category_histories')->where('customer_id', $localId)->orderBy('profile_type')->orderBy('stream')->orderBy('category_year')->get(['profile_type', 'stream', 'category_year', 'category'])->toArray();
        $events = DB::table('customer_category_events')->where('customer_id', $localId)->orderBy('event_key')->get(['profile_type', 'stream', 'category', 'effective_at', 'event_key', 'source', 'supersedes_event_key'])->toArray();
        $brands = DB::table('customer_brands')->where('customer_id', $localId)->orderBy('material_group_id')->orderBy('brand_id')->orderBy('id')->get(['material_group_id', 'brand_id', 'quantity', 'brand_other', 'last_salescall_id', 'last_updated_by'])->toArray();
        $categories = DB::table('customer_categories')->where('customer_id', $localId)->get(['category_id', 'sub_category_id', 'last_salescall_id', 'last_updated_by'])->toArray();

        return hash('sha256', json_encode([
            (array) $customer,
            $profile ? (array) $profile : null,
            $users,
            array_map(fn (object $row): array => (array) $row, $histories),
            array_map(fn (object $row): array => (array) $row, $events),
            array_map(fn (object $row): array => (array) $row, $brands),
            array_map(fn (object $row): array => (array) $row, $categories),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $customer @param array<string, int> $localUserIdsByEmail @return array<string, mixed> */
    private function customerAttributes(array $customer, array $localUserIdsByEmail): array
    {
        if (array_key_exists('person_in_charge_id', $customer) && ! array_key_exists('person_in_charge_email', $customer)) {
            throw new RuntimeException('Portal Customer snapshot omitted the Person in Charge email identity.');
        }

        $attributes = [];
        foreach ([
            'company_id', 'general_category_id', 'region_specific_id', 'area_cluster_id',
            'province_id', 'municipality_id', 'barangay_id', 'name', 'unique_id',
            'contact_person', 'contact_number', 'business_landline_number', 'business_mobile_number',
            'date_established', 'address', 'latitude', 'longitude', 'is_active',
            'competitor_volume', 'landmass_code',
        ] as $field) {
            if (array_key_exists($field, $customer)) {
                $attributes[$field] = $customer[$field];
            }
        }

        if (array_key_exists('local_uuid', $customer) && filled($customer['local_uuid'])) {
            $attributes['local_uuid'] = $customer['local_uuid'];
        }
        if (array_key_exists('person_in_charge_email', $customer)) {
            $email = $customer['person_in_charge_email'];
            $attributes['person_in_charge_id'] = filled($email) ? $this->localUserId($email, $localUserIdsByEmail) : null;
        }

        return $attributes;
    }

    /** @param array<string, mixed> $aggregate @param array<string, int> $localUserIdsByEmail @return array<string, int> */
    private function completeUserMap(array $aggregate, array $localUserIdsByEmail): array
    {
        foreach ($aggregate['customer_users'] ?? [] as $reference) {
            $email = $reference['email'] ?? null;
            if (! is_string($email) || $email === '' || array_key_exists($email, $localUserIdsByEmail)) {
                continue;
            }

            $matches = DB::table('users')->where('email', $email)->pluck('id');
            if ($matches->count() !== 1) {
                throw new RuntimeException('Portal Customer User email does not identify exactly one local User.');
            }
            $localUserIdsByEmail[$email] = (int) $matches->first();
        }

        return $localUserIdsByEmail;
    }

    private function localUserId(string $email, array $localUserIdsByEmail): int
    {
        if (! array_key_exists($email, $localUserIdsByEmail)) {
            throw new RuntimeException('Portal Customer User email is unresolved on this Tablet.');
        }

        return $localUserIdsByEmail[$email];
    }

    private function portalAdvancedBeyondBase(object $local, array $customer): bool
    {
        if (blank($customer['updated_at'] ?? null)) {
            return false;
        }

        if (blank($local->server_updated_at ?? null)) {
            // A matching Portal UUID with no known synchronized base cannot
            // prove that the dirty local aggregate is unchanged remotely.
            return true;
        }

        return Carbon::parse($customer['updated_at'])->gt(Carbon::parse($local->server_updated_at));
    }

    private function markConflict(int $localId, array $customer, string $message): void
    {
        DB::table('customers')->where('id', $localId)->update([
            'server_id' => (int) $customer['id'],
            'sync_status' => 'conflict',
            'sync_error' => $message,
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function collectionsForCustomer(array $aggregate, int $portalId): array
    {
        $collections = [];
        foreach (array_slice(self::COMPLETE_COLLECTIONS, 1) as $name) {
            $rows = $aggregate[$name] ?? [];
            $collections[$name] = $name === 'customer_users'
                ? $rows
                : array_values(array_filter($rows, fn (array $row): bool => (int) ($row['customer_id'] ?? 0) === $portalId));
        }

        return $collections;
    }

    private function applyCollections(int $localId, array $customer, array $collections, array $localUserIdsByEmail, bool $complete): void
    {
        if ($complete && array_key_exists('access_user_emails', $customer)) {
            $accessEmails = $customer['access_user_emails'];
            if (! is_array($accessEmails)) {
                throw new RuntimeException('Portal Customer Access snapshot is invalid.');
            }
            $userIds = [];
            foreach (array_unique($accessEmails) as $email) {
                $userId = $this->localUserId($email, $localUserIdsByEmail);
                $userIds[] = $userId;
            }
            DB::table('customer_user')->where('customer_id', $localId)->whereNotIn('user_id', $userIds ?: [-1])->delete();
            foreach ($userIds as $userId) {
                DB::table('customer_user')->updateOrInsert(
                    ['customer_id' => $localId, 'user_id' => $userId],
                    ['updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        $profiles = $collections['customer_trade_profiles'] ?? [];
        if (count($profiles) > 1) {
            throw new RuntimeException('Portal Customer profile collection contains multiple profiles.');
        }
        foreach ($profiles as $profile) {
            $attributes = [];
            foreach ([
                'profile_type', 'house_number', 'entry_detail', 'other_competitors_note',
                'motiv_user', 'delivery_method', 'ulab',
            ] as $field) {
                if (array_key_exists($field, $profile)) {
                    $attributes[$field] = $profile[$field];
                }
            }
            foreach (['profile_data', 'classifications', 'ommc_brands', 'ommc_mcb_brands', 'tpl_pollux', 'other_competitor_brands', 'mcb_competitors', 'working_days', 'operating_hours'] as $field) {
                if (array_key_exists($field, $profile)) {
                    $attributes[$field] = is_array($profile[$field]) ? json_encode($profile[$field], JSON_THROW_ON_ERROR) : $profile[$field];
                }
            }
            $attributes['updated_at'] = now();
            DB::table('customer_trade_profiles')->updateOrInsert(['customer_id' => $localId], $attributes + ['created_at' => now()]);
        }
        if ($complete && $profiles === []) {
            DB::table('customer_trade_profiles')->where('customer_id', $localId)->delete();
        }

        $tabletHistoryKeys = [];
        $tabletEvents = DB::table('customer_category_events')
            ->where('customer_id', $localId)->where('source', 'tablet')
            ->get(['profile_type', 'stream', 'effective_at']);
        foreach ($tabletEvents as $event) {
            $tabletHistoryKeys[$this->historyKey(
                $event->profile_type,
                $event->stream,
                (int) Carbon::parse($event->effective_at)->utc()->format('Y'),
            )] = true;
        }

        $historyIds = [];
        foreach ($collections['customer_category_histories'] ?? [] as $history) {
            $query = DB::table('customer_category_histories')->where('customer_id', $localId)->where('category_year', $history['category_year']);
            $query = ($history['profile_type'] ?? null) === null ? $query->whereNull('profile_type') : $query->where('profile_type', $history['profile_type']);
            $query = ($history['stream'] ?? null) === null ? $query->whereNull('stream') : $query->where('stream', $history['stream']);
            $existing = $query->first();
            if ($existing && isset($tabletHistoryKeys[$this->historyKey($history['profile_type'] ?? null, $history['stream'] ?? null, (int) $history['category_year'])])) {
                $historyIds[] = (int) $existing->id;

                continue;
            }
            $values = ['category' => $history['category'], 'updated_at' => now()];
            if ($existing) {
                DB::table('customer_category_histories')->where('id', $existing->id)->update($values);
                $historyIds[] = (int) $existing->id;
            } else {
                $historyIds[] = (int) DB::table('customer_category_histories')->insertGetId([
                    'customer_id' => $localId,
                    'profile_type' => $history['profile_type'] ?? null,
                    'stream' => $history['stream'] ?? null,
                    'category_year' => $history['category_year'],
                    ...$values,
                    'created_at' => now(),
                ]);
            }
        }
        if ($complete) {
            $stale = DB::table('customer_category_histories')->where('customer_id', $localId);
            $localHistoryIds = DB::table('customer_category_histories')
                ->where('customer_id', $localId)
                ->get(['id', 'profile_type', 'stream', 'category_year'])
                ->filter(fn (object $history): bool => isset($tabletHistoryKeys[$this->historyKey(
                    $history->profile_type,
                    $history->stream,
                    (int) $history->category_year,
                )]))
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $historyIds = array_values(array_unique([...$historyIds, ...$localHistoryIds]));
            if ($historyIds === []) {
                $stale->delete();
            } else {
                $stale->whereNotIn('id', $historyIds)->delete();
            }
        }

        $eventKeys = [];
        foreach ($collections['customer_category_events'] ?? [] as $event) {
            $existing = DB::table('customer_category_events')->where('event_key', $event['event_key'])->first();
            $eventAttributes = [
                'customer_id' => $localId,
                'profile_type' => $event['profile_type'],
                'stream' => $event['stream'],
                'category' => $event['category'],
                'effective_at' => $event['effective_at'],
                'event_key' => $event['event_key'],
                'source' => $event['source'],
                'supersedes_event_key' => $event['supersedes_event_key'] ?? null,
                'updated_at' => now(),
            ];
            if ($existing) {
                $sameEffectiveAt = Carbon::parse($existing->effective_at)->utc()->equalTo(Carbon::parse($eventAttributes['effective_at'])->utc());
                if ((int) $existing->customer_id !== $localId || $existing->profile_type !== $eventAttributes['profile_type']
                    || $existing->stream !== $eventAttributes['stream'] || $existing->category !== $eventAttributes['category']
                    || ! $sameEffectiveAt || $existing->source !== $eventAttributes['source']
                    || $existing->supersedes_event_key !== $eventAttributes['supersedes_event_key']) {
                    throw new RuntimeException('Portal Customer category event conflicts with an existing event key.');
                }
                $eventKeys[] = $event['event_key'];

                continue;
            }
            $eventAttributes['supersedes_event_id'] = filled($event['supersedes_event_key'] ?? null)
                ? DB::table('customer_category_events')->where('event_key', $event['supersedes_event_key'])->value('id')
                : null;
            DB::table('customer_category_events')->insert(['created_at' => now(), ...$eventAttributes]);
            $eventKeys[] = $event['event_key'];
        }
        if ($complete) {
            $staleEvents = DB::table('customer_category_events')->where('customer_id', $localId)->where('source', '!=', 'tablet');
            if ($eventKeys === []) {
                $staleEvents->delete();
            } else {
                $staleEvents->whereNotIn('event_key', $eventKeys)->delete();
            }
        }

        if (! $this->hasPendingSalescallBrands($localId)) {
            $this->applyBrandCollection($localId, $collections['customer_brands'] ?? [], $complete);
        }
        if (! $this->hasPendingSalescallCategories($localId)) {
            $this->applyCategoryCollection($localId, $collections['customer_categories'] ?? [], $complete);
        }
    }

    private function hasPendingSalescallBrands(int $customerId): bool
    {
        return DB::table('salescall_brands')->where('customer_id', $customerId)->whereIn('sync_status', ['pending', 'failed', 'syncing', 'conflict'])->exists();
    }

    private function historyKey(?string $profileType, ?string $stream, int $year): string
    {
        return json_encode([$profileType, $stream, $year], JSON_THROW_ON_ERROR);
    }

    private function hasPendingSalescallCategories(int $customerId): bool
    {
        return DB::table('salescall_categories')->where('customer_id', $customerId)->whereIn('sync_status', ['pending', 'failed', 'syncing', 'conflict'])->exists();
    }

    /** @param array<string, mixed> $row */
    private function brandReferencesResolve(array $row): bool
    {
        return isset($row['material_group_id'], $row['brand_id'])
            && DB::table('material_groups')->where('id', $row['material_group_id'])->exists()
            && DB::table('brands')->where('id', $row['brand_id'])->exists();
    }

    /** @param array<string, mixed> $row */
    private function categoryReferencesResolve(array $row): bool
    {
        return isset($row['category_id'], $row['sub_category_id'])
            && DB::table('categories')->where('id', $row['category_id'])->exists()
            && DB::table('sub_categories')->where('id', $row['sub_category_id'])->exists();
    }

    private function applyBrandCollection(int $customerId, array $rows, bool $complete): void
    {
        $retainedIds = [];
        foreach ($rows as $row) {
            $query = DB::table('customer_brands')->where('customer_id', $customerId)
                ->where('material_group_id', $row['material_group_id'])->where('brand_id', $row['brand_id']);
            $query = ($row['last_salescall_id'] ?? null) === null ? $query->whereNull('last_salescall_id') : $query->where('last_salescall_id', $row['last_salescall_id']);
            $existing = $query->first();

            // The Portal reference sync does not guarantee every referenced
            // Brand or Material Group is available locally (disabled Brands are
            // not served, and a reference may arrive after the Customer). Keep
            // any existing local link and skip the unwritable row instead of
            // aborting the whole page transaction.
            if (! $this->brandReferencesResolve($row)) {
                if ($existing) {
                    $retainedIds[] = (int) $existing->id;
                }
                Log::warning('sync:customer-brands:unresolved-reference', [
                    'customer_id' => $customerId,
                    'material_group_id' => $row['material_group_id'] ?? null,
                    'brand_id' => $row['brand_id'] ?? null,
                ]);

                continue;
            }

            $values = [
                'customer_id' => $customerId,
                'material_group_id' => $row['material_group_id'],
                'brand_id' => $row['brand_id'],
                'quantity' => $row['quantity'] ?? null,
                'brand_other' => $row['brand_other'] ?? null,
                'last_salescall_id' => $row['last_salescall_id'] ?? null,
                'last_updated_by' => $row['last_updated_by'] ?? null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('customer_brands')->where('id', $existing->id)->update($values);
                $retainedIds[] = (int) $existing->id;
            } else {
                $retainedIds[] = (int) DB::table('customer_brands')->insertGetId(['created_at' => now(), ...$values]);
            }
        }

        if ($complete) {
            $stale = DB::table('customer_brands')->where('customer_id', $customerId);
            if ($retainedIds === []) {
                $stale->delete();
            } else {
                $stale->whereNotIn('id', $retainedIds)->delete();
            }
        }
    }

    private function applyCategoryCollection(int $customerId, array $rows, bool $complete): void
    {
        if (count($rows) > 1) {
            throw new RuntimeException('Portal Customer category collection contains multiple rows for one Customer.');
        }

        if ($rows === []) {
            if ($complete) {
                DB::table('customer_categories')->where('customer_id', $customerId)->delete();
            }

            return;
        }

        $row = $rows[0];

        // See applyBrandCollection(): keep any existing local link and skip an
        // unwritable reference instead of aborting the whole page transaction.
        if (! $this->categoryReferencesResolve($row)) {
            Log::warning('sync:customer-categories:unresolved-reference', [
                'customer_id' => $customerId,
                'category_id' => $row['category_id'] ?? null,
                'sub_category_id' => $row['sub_category_id'] ?? null,
            ]);

            return;
        }

        DB::table('customer_categories')->updateOrInsert(
            ['customer_id' => $customerId],
            [
                'category_id' => $row['category_id'],
                'sub_category_id' => $row['sub_category_id'],
                'last_salescall_id' => $row['last_salescall_id'] ?? null,
                'last_updated_by' => $row['last_updated_by'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}
