<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerAccessMapping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerAccessMappingResolver
{
    /**
     * Resolve the signed-in User's complete Access groups from the offline snapshot.
     *
     * @return array{complete: bool, user_ids: list<int>, warning: ?string}
     */
    public function forCreator(?int $creatorUserId): array
    {
        $creator = $creatorUserId ? User::query()->find($creatorUserId) : null;
        if (! $creator) {
            return $this->incomplete('The signed-in User could not be resolved for Customer Access.');
        }

        $creatorId = (int) $creator->id;
        if (! Schema::hasTable('customer_access_mappings')) {
            return [
                'complete' => false,
                'user_ids' => [$creatorId],
                'warning' => 'Creator Access group references are unavailable offline. The creator is selected; Access can be adjusted manually.',
            ];
        }

        $snapshotValue = DB::table('sync_states')->where('key', 'location_reference_snapshot_complete')->value('value');
        $snapshot = is_string($snapshotValue) ? json_decode($snapshotValue, true) : null;
        if (! is_array($snapshot) || ($snapshot['reference_contract_version'] ?? 0) < 2) {
            return [
                'complete' => false,
                'user_ids' => [$creatorId],
                'warning' => 'Creator Access group references are unavailable offline. The creator is selected; Access can be adjusted manually. Sync references to refresh them.',
            ];
        }

        $groups = CustomerAccessMapping::query()->where('scope', 'group')->orderBy('mapping_key')->get();
        if ($groups->isEmpty()) {
            return [
                'complete' => false,
                'user_ids' => [$creatorId],
                'warning' => 'The signed-in User has no configured Access group. The creator is selected; Access can be adjusted manually.',
            ];
        }

        $userIds = [$creatorId];
        $creatorGroupCodes = [];
        $incompleteGroupCodes = [];
        foreach ($groups as $group) {
            $references = is_array($group->user_references) ? $group->user_references : [];
            $creatorBelongsToGroup = collect($references)->contains(
                fn ($reference): bool => is_array($reference) && ($reference['email'] ?? null) === $creator->email,
            );
            if (! $creatorBelongsToGroup) {
                continue;
            }

            $creatorGroupCodes[] = $group->mapping_key;
            if (! $group->is_complete) {
                $incompleteGroupCodes[] = $group->mapping_key;
            }

            $groupUserIds = [];
            foreach ($references as $reference) {
                if (! is_array($reference)) {
                    $incompleteGroupCodes[] = $group->mapping_key;

                    continue;
                }

                $email = $reference['email'] ?? null;
                if (! is_string($email) || $email === '') {
                    $incompleteGroupCodes[] = $group->mapping_key;

                    continue;
                }

                $matches = User::query()->where('email', $email)->get(['id', 'email']);
                if ($matches->count() !== 1) {
                    $incompleteGroupCodes[] = $group->mapping_key;

                    continue;
                }

                $groupUserIds[] = (int) $matches->first()->id;
            }

            if ($groupUserIds === []) {
                continue;
            }

            $userIds = [...$userIds, ...$groupUserIds];
        }

        $creatorGroupCodes = array_values(array_unique($creatorGroupCodes));
        $incompleteGroupCodes = array_values(array_unique($incompleteGroupCodes));
        if ($creatorGroupCodes === []) {
            return [
                'complete' => false,
                'user_ids' => [$creatorId],
                'warning' => 'The signed-in User has no configured Access group. The creator is selected; Access can be adjusted manually.',
            ];
        }

        return [
            'complete' => $incompleteGroupCodes === [],
            'user_ids' => array_values(array_unique($userIds)),
            'warning' => $incompleteGroupCodes === []
                ? null
                : 'One or more creator Access groups are incomplete or unavailable offline ('.implode(', ', $incompleteGroupCodes).'). Resolvable assigned Users were included; verify remaining memberships manually.',
        ];
    }

    /**
     * @return array{complete: bool, user_ids: list<int>, warning: ?string}
     */
    public function forCustomer(?int $companyId, ?int $regionSpecificId): array
    {
        $company = $companyId ? Company::find($companyId) : null;
        $profile = Company::profileTypeForCode($company?->code);
        $isCompanyScoped = in_array($profile, ['oe', 'ib'], true);

        if (($isCompanyScoped && ! $company) || (! $isCompanyScoped && ! $regionSpecificId)) {
            return ['complete' => false, 'user_ids' => [], 'warning' => null];
        }

        if (! Schema::hasTable('customer_access_mappings')) {
            return $this->incomplete('Customer Access mappings are unavailable offline. Existing Access is preserved.');
        }

        $mapping = CustomerAccessMapping::query()
            ->where('scope', $isCompanyScoped ? 'company' : 'region_specific')
            ->where('mapping_key', $isCompanyScoped ? (string) $company->code : (string) $regionSpecificId)
            ->first();

        if (! $mapping?->is_complete || ! is_array($mapping->user_references) || $mapping->user_references === []) {
            return $this->incomplete('Customer Access mapping is missing or incomplete offline. Existing Access is preserved.');
        }

        $userIds = [];
        foreach ($mapping->user_references as $reference) {
            $email = $reference['email'] ?? null;
            if (! is_string($email) || $email === '') {
                return $this->incomplete('Customer Access mapping contains an invalid User reference. Existing Access is preserved.');
            }

            $matches = User::query()->get(['id', 'email'])
                ->filter(fn (User $user): bool => $user->email === $email)
                ->values();

            if ($matches->count() !== 1) {
                return $this->incomplete('A mapped User is unavailable or ambiguous on this device. Existing Access is preserved.');
            }

            $userIds[] = (int) $matches->first()->id;
        }

        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return $this->incomplete('Customer Access mapping has no eligible Users. Existing Access is preserved.');
        }

        return ['complete' => true, 'user_ids' => $userIds, 'warning' => null];
    }

    /**
     * @return array{complete: false, user_ids: list<int>, warning: string}
     */
    private function incomplete(string $warning): array
    {
        return ['complete' => false, 'user_ids' => [], 'warning' => $warning];
    }
}
