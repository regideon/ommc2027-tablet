<?php

namespace App\Services\Sync\Push;

use App\Models\Customer;

final class CustomerPushPayload
{
    /** @param array<string, mixed> $item */
    private function __construct(private readonly array $item) {}

    public static function fromCustomer(Customer $customer): self
    {
        $customer->loadMissing(['tradeProfile', 'categoryHistories', 'categoryEvents', 'users', 'personInCharge']);

        $payload = [
            'local_uuid' => $customer->local_uuid,
            'server_id' => $customer->server_id,
            'base_updated_at' => $customer->server_updated_at,
            'sync_intent' => $customer->server_id ? 'update' : 'create',
            'name' => $customer->name,
            'unique_id' => $customer->unique_id,
            'customer_code_reservation_token' => $customer->customer_code_reservation_token,
            'company_id' => $customer->company_id,
            'landmass_code' => $customer->landmass_code,
            'general_category_id' => $customer->general_category_id,
            'competitor_volume' => $customer->competitor_volume,
            'region_specific_id' => $customer->region_specific_id,
            'municipality_id' => $customer->municipality_id,
            'address' => $customer->address,
            'latitude' => $customer->latitude,
            'longitude' => $customer->longitude,
            'contact_person' => $customer->contact_person,
            'contact_number' => $customer->contact_number,
            'business_landline_number' => $customer->business_landline_number,
            'business_mobile_number' => $customer->business_mobile_number,
            'date_established' => optional($customer->date_established)->format('Y-m-d'),
            'is_active' => $customer->is_active,
            'profile_type' => $customer->tradeProfile?->profile_type,
            'person_in_charge_email' => $customer->personInCharge?->email,
            'access_user_emails' => $customer->users->pluck('email')->values()->all(),
            'trade_profile' => $customer->tradeProfile?->toArray() ?? [],
            'profile_data' => $customer->tradeProfile?->profile_data ?? [],
            'category_histories' => $customer->categoryHistories->map(fn ($history) => [
                'profile_type' => $history->profile_type ?: $customer->tradeProfile?->profile_type,
                'stream' => $history->stream ?: (($customer->tradeProfile?->profile_type === 'outlet') ? match ($customer->tradeProfile?->entry_detail) {
                    'AB' => 'ab', 'MCB' => 'mcb', default => (str_starts_with((string) $history->category, 'AB ') ? 'ab' : (str_starts_with((string) $history->category, 'MCB ') ? 'mcb' : null)),
                } : $customer->tradeProfile?->profile_type),
                'category_year' => $history->category_year,
                'category' => $history->category,
            ])->values()->all(),
            'category_events' => $customer->categoryEvents->map(fn ($event) => [
                'event_key' => $event->event_key,
                'profile_type' => $event->profile_type,
                'stream' => $event->stream,
                'category' => $event->category,
                'effective_at' => $event->effective_at?->toISOString(),
                'source' => $event->source,
                'supersedes_event_key' => $event->supersedes_event_key,
            ])->values()->all(),
        ];

        foreach (['province_id', 'barangay_id', 'area_cluster_id'] as $locationId) {
            if ($customer->{$locationId} !== null) {
                $payload[$locationId] = $customer->{$locationId};
            }
        }

        return new self($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->item;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->item, JSON_THROW_ON_ERROR));
    }
}
