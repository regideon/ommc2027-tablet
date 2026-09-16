<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Services\CustomerProfileFormService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CustomerEditPage extends CustomerCreatePage
{
    protected static ?string $slug = 'customers/{customerId}/edit';

    protected static ?string $title = 'Edit Customer';

    public int $customerId;

    public function mount(?int $customerId = null): void
    {
        abort_unless($customerId !== null, 404);
        $customer = Customer::with(['company', 'municipality.region', 'municipality.province', 'users', 'tradeProfile', 'categoryHistories'])->findOrFail($customerId);
        $state = CustomerProfileFormService::hydrate($customer);

        foreach ($state as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
        $this->physical_region_id = $customer->municipality?->region_id;
        $this->trade = array_merge($this->trade, $state['trade'] ?? []);
        $this->active = $state['active'] ?? [];
        $this->categories = $state['categories'] ?? [];
        $this->customerId = $customer->id;
    }

    public function saveCustomer(): void
    {
        $profileType = $this->profileType();
        abort_unless($profileType, 422, 'The selected Company has no profile mapping.');

        $this->validate([
            'name' => 'required|string|max:255',
            'unique_id' => 'nullable|string|max:50',
            'company_id' => 'required|exists:companies,id',
            'access_user_ids' => 'required|array|min:1',
            'access_user_ids.*' => 'integer|exists:users,id',
            'region_specific_id' => 'required|exists:region_specifics,id',
            'physical_region_id' => 'required|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',
            'municipality_id' => 'required|exists:municipalities,id',
            'person_in_charge_id' => 'nullable|integer|exists:users,id',
            'general_category_id' => 'required|exists:general_categories,id',
            'competitor_volume' => 'nullable|integer|in:1,2,3',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'contact_person' => 'required|string|max:255',
            'date_established' => 'required|date',
        ]);

        if (! $this->physicalGeographyIsValid()) {
            return;
        }

        if (! $this->validateScopedPortalRules($profileType)) {
            return;
        }

        $classifications = $profileType === 'outlet' ? ($this->trade['classifications'] ?? []) : ($this->active['classifications'] ?? null);
        if ($profileType === 'outlet' ? count($classifications) < 1 : blank($classifications)) {
            $this->addError('trade.classifications', 'Classification is required.');
            return;
        }

        foreach (CustomerProfileFormService::categoryStreams($profileType) as $stream) {
            foreach ($this->categories[$stream] ?? [] as $category) {
                if (! $category || ! array_key_exists($category, $this->categoryOptions($stream))) {
                    $this->addError('categories', "Every {$stream} annual category must be selected from the allowed options.");
                    return;
                }
            }
        }
        if ($profileType === 'outlet' && ! in_array($this->trade['entry_detail'] ?? null, ['AB', 'MCB', 'AB and MCB'], true)) {
            $this->addError('trade.entry_detail', 'Entry Detail is required for Outlet.');
            return;
        }
        foreach (['name' => 'Name of Owner', 'birthday' => 'Birthday', 'relationship' => 'Relationship with the Owner', 'generation' => 'Generation'] as $key => $label) {
            if (blank($this->active['owner'][$key] ?? null)) {
                $this->addError("active.owner.{$key}", "{$label} is required.");
                return;
            }
        }
        if ($profileType === 'fleet' && blank($this->active['account_type'] ?? null)) {
            $this->addError('active.account_type', 'Type is required.');
            return;
        }
        if (in_array($profileType, ['fleet', 'oe'], true) && blank($this->active['battery_class'] ?? null)) {
            $this->addError('active.battery_class', 'Battery Class is required.');
            return;
        }
        if ($profileType === 'fleet' && blank($this->active['status'] ?? null)) {
            $this->addError('active.status', 'Status is required.');
            return;
        }
        if (($this->trade['motiv_user'] ?? false) && blank($this->active['warehouse_code'] ?? null)) {
            $this->addError('active.warehouse_code', 'Warehouse Code is required for MOTIV users.');
            return;
        }
        if (($this->active['delivery_type'] ?? null) === 'yes' && blank($this->active['delivery_detail'] ?? null)) {
            $this->addError('active.delivery_detail', 'Delivery Detail is required when Delivery Type is Yes.');
            return;
        }

        DB::transaction(function () use ($profileType): void {
            $customer = Customer::with(['company', 'tradeProfile', 'categoryHistories'])->findOrFail($this->customerId);
            $oldProfile = $customer->tradeProfile?->profile_type ?: CustomerProfileFormService::profileForCompany($customer->company_id);
            if ($oldProfile && $oldProfile !== $profileType) {
                CustomerProfileFormService::archiveProfile($customer, $profileType, auth()->id());
            }

            CustomerProfileFormService::saveAggregate($customer, [
                'name' => $this->name,
                'unique_id' => $this->unique_id,
                'company_id' => $this->company_id,
                'region_specific_id' => $this->region_specific_id,
                'municipality_id' => $this->municipality_id,
                'general_category_id' => $this->general_category_id,
                'competitor_volume' => $this->competitor_volume,
                'address' => $this->address,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'contact_person' => $this->contact_person,
                'contact_number' => $this->contact_number,
                'business_landline_number' => $this->business_landline_number,
                'business_mobile_number' => $this->business_mobile_number,
                'date_established' => $this->date_established,
                'is_active' => $this->is_active,
                'person_in_charge_id' => $profileType === 'outlet' ? $this->person_in_charge_id : null,
                'trade' => $this->trade,
                'active' => $this->active,
                'categories' => $this->categories,
                'access_user_ids' => $this->access_user_ids,
            ], $profileType);
            $customer->update(['sync_status' => 'pending', 'sync_error' => null]);
        });

        Notification::make()->title('Customer updated offline')->success()->send();
        $this->redirect(CustomerPage::getUrl());
    }
}
