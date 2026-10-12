<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerAccessMapping;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class CustomerAccessMappingResolver
{
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
