<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCategoryHistory;
use App\Models\CustomerTradeProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class CustomerProfileFormService
{
    public static function profileForCompany(?int $companyId): ?string
    {
        $code = $companyId ? Company::find($companyId)?->code : null;

        return Company::profileTypeForCode($code);
    }

    public static function categoryYears(string $profile): array
    {
        return config("customer_trade_form.category_streams.{$profile}.years", []);
    }

    public static function categoryStreams(string $profile): array
    {
        return config("customer_trade_form.category_streams.{$profile}.streams", []);
    }

    public static function categoryOptions(string $profile, string $stream): array
    {
        $options = config("customer_trade_form.profile_category_options.{$profile}.{$stream}");
        $options ??= config("customer_trade_form.profile_category_options.{$profile}", []);

        return array_combine($options, $options);
    }

    public static function defaultCategories(string $profile): array
    {
        $result = [];
        foreach (self::categoryStreams($profile) as $stream) {
            foreach (self::categoryYears($profile) as $year) {
                $result[$stream][$year] = null;
            }
        }

        return $result;
    }

    public static function hydrate(Customer $customer): array
    {
        $profile = $customer->tradeProfile;
        $profileType = $profile?->profile_type ?: self::profileForCompany($customer->company_id);
        $active = is_array($profile?->profile_data) && is_array($profile->profile_data['active'] ?? null)
            ? $profile->profile_data['active']
            : [];
        $categories = self::defaultCategories($profileType ?: 'outlet');

        foreach ($customer->categoryHistories as $history) {
            $stream = $history->stream;
            if (! $stream && $history->profile_type) {
                $stream = $history->profile_type;
            }
            if (! $stream && $profileType === 'outlet') {
                $stream = match ($profile?->entry_detail) {
                    'AB' => 'ab', 'MCB' => 'mcb', default => null,
                };
                $stream ??= str_starts_with((string) $history->category, 'AB ') ? 'ab' : null;
                $stream ??= str_starts_with((string) $history->category, 'MCB ') ? 'mcb' : null;
            }
            if ($stream && isset($categories[$stream]) && array_key_exists($history->category_year, $categories[$stream])) {
                $categories[$stream][$history->category_year] = $history->category;
            }
        }

        return [
            'name' => $customer->name,
            'unique_id' => $customer->unique_id,
            'company_id' => $customer->company_id,
            'region_specific_id' => $customer->region_specific_id,
            'province_id' => $customer->municipality?->province_id,
            'municipality_id' => $customer->municipality_id,
            'general_category_id' => $customer->general_category_id,
            'competitor_volume' => $customer->competitor_volume,
            'address' => $customer->address,
            'latitude' => $customer->latitude,
            'longitude' => $customer->longitude,
            'contact_person' => $customer->contact_person,
            'contact_number' => $customer->contact_number,
            'business_landline_number' => $customer->business_landline_number,
            'business_mobile_number' => $customer->business_mobile_number,
            'date_established' => optional($customer->date_established)->format('Y-m-d'),
            'is_active' => $customer->is_active,
            'access_user_ids' => Schema::hasTable('customer_user') ? $customer->users->modelKeys() : [],
            'person_in_charge_id' => $customer->person_in_charge_id,
            'profile_type' => $profileType,
            'trade' => $profile?->toArray() ?? [],
            'active' => $active,
            'categories' => $categories,
        ];
    }

    public static function profileSnapshot(Customer $customer): array
    {
        $profile = $customer->tradeProfile;

        return [
            'profile_type' => $profile?->profile_type ?: self::profileForCompany($customer->company_id),
            'company_code' => $customer->company?->code,
            'person_in_charge_id' => $customer->person_in_charge_id,
            'typed_trade_values' => $profile?->only([
                'house_number', 'entry_detail', 'classifications', 'ommc_brands',
                'ommc_mcb_brands', 'tpl_pollux', 'other_competitor_brands',
                'mcb_competitors', 'other_competitors_note', 'working_days',
                'operating_hours', 'motiv_user', 'delivery_method', 'ulab',
            ]) ?? [],
            'profile_data' => self::activePayload($profile),
        ];
    }

    public static function activePayload(?CustomerTradeProfile $profile): array
    {
        $data = $profile?->profile_data;

        return is_array($data) && is_array($data['active'] ?? null) ? $data['active'] : [];
    }

    public static function saveAggregate(Customer $customer, array $state, string $profileType): void
    {
        $customer->fill(Arr::only($state, [
            'name', 'unique_id', 'company_id', 'region_specific_id', 'municipality_id',
            'general_category_id', 'competitor_volume', 'address', 'latitude', 'longitude',
            'contact_person', 'contact_number', 'business_landline_number',
            'business_mobile_number', 'date_established', 'is_active', 'person_in_charge_id',
        ]));
        $customer->save();

        $trade = $state['trade'] ?? [];
        $active = is_array($state['active'] ?? null) ? $state['active'] : [];
        $profile = $customer->tradeProfile()->firstOrNew([]);
        $profile->fill(Arr::only($trade, [
            'house_number', 'entry_detail', 'classifications', 'ommc_brands',
            'ommc_mcb_brands', 'tpl_pollux', 'other_competitor_brands',
            'mcb_competitors', 'other_competitors_note', 'working_days',
            'operating_hours', 'motiv_user', 'delivery_method', 'ulab',
        ]));
        $profile->profile_type = $profileType;
        $existing = is_array($profile->profile_data) ? $profile->profile_data : [];
        $profile->profile_data = ['active' => $active, 'archived_profiles' => $existing['archived_profiles'] ?? []];
        $profile->save();

        if (array_key_exists('access_user_ids', $state) && Schema::hasTable('customer_user')) {
            $customer->users()->sync(array_values(array_filter($state['access_user_ids'] ?? [])));
        }

        foreach ($state['categories'] ?? [] as $stream => $years) {
            if (! in_array($stream, self::categoryStreams($profileType), true)) continue;
            foreach ($years as $year => $category) {
                if (blank($category)) continue;
                CustomerCategoryHistory::updateOrCreate(
                    ['customer_id' => $customer->id, 'profile_type' => $profileType, 'stream' => $stream, 'category_year' => (int) $year],
                    ['category' => $category]
                );
            }
        }
    }

    public static function archiveProfile(Customer $customer, string $newProfileType, int|string|null $actorId = null): void
    {
        $profile = $customer->tradeProfile()->firstOrNew([]);
        $data = is_array($profile->profile_data) ? $profile->profile_data : [];
        $archives = is_array($data['archived_profiles'] ?? null) ? $data['archived_profiles'] : [];
        $archives[] = [...self::profileSnapshot($customer), 'archived_at' => now()->toISOString(), 'archived_by' => $actorId];

        $profile->profile_type = $newProfileType;
        $profile->profile_data = ['active' => [], 'archived_profiles' => $archives];
        $profile->fill([
            'house_number' => null, 'entry_detail' => null, 'classifications' => null,
            'ommc_brands' => null, 'ommc_mcb_brands' => null, 'tpl_pollux' => null,
            'other_competitor_brands' => null, 'mcb_competitors' => null,
            'other_competitors_note' => null, 'working_days' => null,
            'operating_hours' => null, 'motiv_user' => null, 'delivery_method' => null,
            'ulab' => null,
        ]);
        $profile->save();
    }
}
