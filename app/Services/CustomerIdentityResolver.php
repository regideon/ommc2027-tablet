<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class CustomerIdentityResolver
{
    /**
     * Resolve a Portal Customer to an existing Tablet row without mutating it.
     *
     * @param  array<string, mixed>  $portalCustomer
     */
    public function resolve(array $portalCustomer, ?int $expectedLocalId = null): CustomerIdentityResolution
    {
        $portalId = filter_var($portalCustomer['id'] ?? null, FILTER_VALIDATE_INT);
        if (! $portalId || $portalId < 1) {
            return $this->conflict('invalid_portal_customer_id');
        }

        $localUuid = $portalCustomer['local_uuid'] ?? null;
        $localUuid = is_string($localUuid) && trim($localUuid) !== '' ? trim($localUuid) : null;

        $uuidMatches = $localUuid === null
            ? collect()
            : DB::table('customers')->where('local_uuid', $localUuid)
                ->get(['id', 'local_uuid', 'server_id', 'unique_id', 'company_id']);
        $serverMatches = DB::table('customers')->where('server_id', $portalId)
            ->get(['id', 'local_uuid', 'server_id', 'unique_id', 'company_id']);

        if ($uuidMatches->count() > 1) {
            return $this->conflict('duplicate_local_uuid');
        }

        if ($serverMatches->count() > 1) {
            return $this->conflict('duplicate_server_id');
        }

        $uuidMatch = $uuidMatches->first();
        $serverMatch = $serverMatches->first();

        if ($uuidMatch && $serverMatch && (int) $uuidMatch->id !== (int) $serverMatch->id) {
            return $this->conflict('uuid_and_server_id_resolve_to_different_customers');
        }

        if ($uuidMatch) {
            if ($uuidMatch->server_id !== null && (int) $uuidMatch->server_id !== (int) $portalId) {
                return $this->conflict('local_uuid_has_different_server_id');
            }

            if ($expectedLocalId !== null && (int) $uuidMatch->id !== $expectedLocalId) {
                return $this->conflict('acknowledgment_uuid_does_not_match_pushed_customer');
            }

            return new CustomerIdentityResolution(
                CustomerIdentityResolution::MATCHED,
                (int) $uuidMatch->id,
                'local_uuid',
            );
        }

        if ($serverMatch) {
            if ($localUuid !== null && filled($serverMatch->local_uuid) && $serverMatch->local_uuid !== $localUuid) {
                return $this->conflict('server_id_has_different_local_uuid');
            }

            if (! $this->corroboratesLegacyIdentity($serverMatch, $portalCustomer)) {
                return $this->conflict('legacy_server_id_identity_not_corroborated');
            }

            if ($expectedLocalId !== null && (int) $serverMatch->id !== $expectedLocalId) {
                return $this->conflict('acknowledgment_does_not_match_pushed_customer');
            }

            return new CustomerIdentityResolution(
                CustomerIdentityResolution::MATCHED,
                (int) $serverMatch->id,
                'server_id_legacy',
            );
        }

        if ($expectedLocalId !== null) {
            return $this->conflict('acknowledgment_has_no_matching_local_uuid');
        }

        $sameNumericId = DB::table('customers')->where('id', $portalId)->exists();
        if ($sameNumericId) {
            return $this->conflict('numeric_id_occupied_without_identity_match');
        }

        return new CustomerIdentityResolution(CustomerIdentityResolution::NEW, (int) $portalId, 'unoccupied_portal_id');
    }

    /**
     * Resolve a whole page of Portal Customers against preloaded Tablet rows
     * with the same precedence as resolve(): server_id, then local_uuid, then
     * the occupied numeric id. Used by the pull path, which never passes an
     * expected local id.
     *
     * @param  array<int, array<string, mixed>>  $portalCustomers
     * @return array<int, CustomerIdentityResolution> keyed by input index
     */
    public function resolveMany(array $portalCustomers): array
    {
        $portalIds = [];
        $uuids = [];

        foreach ($portalCustomers as $customer) {
            $portalId = filter_var($customer['id'] ?? null, FILTER_VALIDATE_INT);
            if ($portalId && $portalId > 0) {
                $portalIds[(int) $portalId] = true;
            }

            $uuid = $customer['local_uuid'] ?? null;
            if (is_string($uuid) && trim($uuid) !== '') {
                $uuids[trim($uuid)] = true;
            }
        }

        $rows = collect();
        if ($portalIds !== [] || $uuids !== []) {
            $rows = DB::table('customers')
                ->where(function ($query) use ($portalIds, $uuids): void {
                    if ($portalIds !== []) {
                        $query->whereIn('server_id', array_keys($portalIds));
                    }
                    if ($uuids !== []) {
                        $query->orWhereIn('local_uuid', array_keys($uuids));
                    }
                })
                ->orderBy('id')
                ->get(['id', 'local_uuid', 'server_id', 'unique_id', 'company_id']);
        }

        $uuidRows = [];
        $serverRows = [];
        foreach ($rows as $row) {
            if (is_string($row->local_uuid) && $row->local_uuid !== '') {
                $uuidRows[$row->local_uuid][] = $row;
            }
            if ($row->server_id !== null) {
                $serverRows[(int) $row->server_id][] = $row;
            }
        }

        $occupied = [];
        if ($portalIds !== []) {
            foreach (DB::table('customers')->whereIn('id', array_keys($portalIds))->pluck('id') as $id) {
                $occupied[(int) $id] = true;
            }
        }

        $resolutions = [];
        foreach ($portalCustomers as $index => $customer) {
            $resolutions[$index] = $this->resolveFromPreloaded($customer, $uuidRows, $serverRows, $occupied);
        }

        return $resolutions;
    }

    /** @param array<string, mixed> $portalCustomer */
    private function resolveFromPreloaded(array $portalCustomer, array $uuidRows, array $serverRows, array $occupied): CustomerIdentityResolution
    {
        $portalId = filter_var($portalCustomer['id'] ?? null, FILTER_VALIDATE_INT);
        if (! $portalId || $portalId < 1) {
            return $this->conflict('invalid_portal_customer_id');
        }

        $localUuid = $portalCustomer['local_uuid'] ?? null;
        $localUuid = is_string($localUuid) && trim($localUuid) !== '' ? trim($localUuid) : null;

        $uuidMatches = $localUuid === null ? [] : ($uuidRows[$localUuid] ?? []);
        $serverMatches = $serverRows[(int) $portalId] ?? [];

        if (count($uuidMatches) > 1) {
            return $this->conflict('duplicate_local_uuid');
        }

        if (count($serverMatches) > 1) {
            return $this->conflict('duplicate_server_id');
        }

        $uuidMatch = $uuidMatches[0] ?? null;
        $serverMatch = $serverMatches[0] ?? null;

        if ($uuidMatch && $serverMatch && (int) $uuidMatch->id !== (int) $serverMatch->id) {
            return $this->conflict('uuid_and_server_id_resolve_to_different_customers');
        }

        if ($uuidMatch) {
            if ($uuidMatch->server_id !== null && (int) $uuidMatch->server_id !== (int) $portalId) {
                return $this->conflict('local_uuid_has_different_server_id');
            }

            return new CustomerIdentityResolution(
                CustomerIdentityResolution::MATCHED,
                (int) $uuidMatch->id,
                'local_uuid',
            );
        }

        if ($serverMatch) {
            if ($localUuid !== null && filled($serverMatch->local_uuid) && $serverMatch->local_uuid !== $localUuid) {
                return $this->conflict('server_id_has_different_local_uuid');
            }

            if (! $this->corroboratesLegacyIdentity($serverMatch, $portalCustomer)) {
                return $this->conflict('legacy_server_id_identity_not_corroborated');
            }

            return new CustomerIdentityResolution(
                CustomerIdentityResolution::MATCHED,
                (int) $serverMatch->id,
                'server_id_legacy',
            );
        }

        if (isset($occupied[(int) $portalId])) {
            return $this->conflict('numeric_id_occupied_without_identity_match');
        }

        return new CustomerIdentityResolution(CustomerIdentityResolution::NEW, (int) $portalId, 'unoccupied_portal_id');
    }

    private function corroboratesLegacyIdentity(object $localCustomer, array $portalCustomer): bool
    {
        $portalCompanyId = $portalCustomer['company_id'] ?? null;
        if ($portalCompanyId === null || $localCustomer->company_id === null
            || (int) $localCustomer->company_id !== (int) $portalCompanyId) {
            return false;
        }

        $portalCode = $portalCustomer['unique_id'] ?? null;
        if (filled($portalCode) && filled($localCustomer->unique_id) && $localCustomer->unique_id !== $portalCode) {
            return false;
        }

        return true;
    }

    private function conflict(string $reason): CustomerIdentityResolution
    {
        return new CustomerIdentityResolution(CustomerIdentityResolution::CONFLICT, reason: $reason);
    }
}
