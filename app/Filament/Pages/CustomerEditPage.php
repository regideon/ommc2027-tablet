<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Services\CustomerProfileFormService;
use App\Services\SyncService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $customerBefore = Customer::with(['tradeProfile', 'categoryHistories', 'categoryEvents', 'users', 'personInCharge'])->findOrFail($this->customerId);
        abort_if($customerBefore->sync_status === 'syncing', 409, 'This Customer is currently being pushed. Wait for the result before editing.');
        if ((int) $this->company_id !== (int) $customerBefore->company_id) {
            $this->addError('company_id', 'Company cannot be changed while correcting a failed Customer.');

            return;
        }
        $wasFailed = $customerBefore->sync_status === 'failed';
        $beforeFingerprint = app(SyncService::class)->customerPayloadFingerprint($customerBefore);

        $profileType = $this->profileType();
        abort_unless($profileType, 422, 'The selected Company has no profile mapping.');

        $this->validate([
            'name' => 'required|string|max:255',
            'unique_id' => 'nullable|string|max:50',
            'company_id' => 'required|exists:companies,id',
            'access_user_ids' => 'nullable|array',
            'access_user_ids.*' => 'integer|exists:users,id',
            'region_specific_id' => 'nullable|exists:region_specifics,id',
            'physical_region_id' => 'nullable|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'area_cluster_id' => 'nullable|exists:area_clusters,id',
            'person_in_charge_id' => 'nullable|integer|exists:users,id',
            'general_category_id' => 'nullable|exists:general_categories,id',
            'competitor_volume' => 'nullable|integer|in:1,2,3',
            'address' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'contact_person' => 'nullable|string|max:255',
            'date_established' => 'nullable|date',
            'active.conversion_program' => ['nullable', Rule::in([...array_keys(config('customer_trade_form.conversion_programs', [])), ''])],
        ]);

        if (! $this->physicalGeographyIsValid()) {
            return;
        }

        if (! $this->validateScopedPortalRules($profileType)) {
            return;
        }

        foreach (CustomerProfileFormService::categoryStreams($profileType) as $stream) {
            foreach ($this->categories[$stream] ?? [] as $category) {
                if ($category !== null && $category !== '' && ! array_key_exists($category, $this->categoryOptions($stream))) {
                    $this->addError('categories', "Every {$stream} annual category must be selected from the allowed options.");

                    return;
                }
            }
        }
        DB::transaction(function () use ($profileType, $wasFailed): void {
            $customer = Customer::with(['company', 'tradeProfile', 'categoryHistories'])->findOrFail($this->customerId);
            abort_if($customer->sync_status === 'syncing', 409, 'This Customer is currently being pushed. Wait for the result before editing.');
            $oldProfile = $customer->tradeProfile?->profile_type ?: CustomerProfileFormService::profileForCompany($customer->company_id);
            if ($oldProfile && $oldProfile !== $profileType) {
                CustomerProfileFormService::archiveProfile($customer, $profileType, auth()->id());
            }

            CustomerProfileFormService::saveAggregate($customer, [
                'name' => $this->name,
                'unique_id' => $customer->unique_id,
                'company_id' => $this->company_id,
                'region_specific_id' => $this->region_specific_id,
                'municipality_id' => $this->municipality_id,
                'province_id' => $this->province_id,
                'barangay_id' => $this->barangay_id,
                'area_cluster_id' => $this->area_cluster_id,
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
            $customer->update([
                'sync_status' => $wasFailed ? 'failed' : 'pending',
                'sync_error' => $wasFailed ? $customer->sync_error : null,
            ]);
        });

        if ($wasFailed) {
            $correctedCustomer = Customer::with(['tradeProfile', 'categoryHistories', 'categoryEvents', 'users', 'personInCharge'])->findOrFail($this->customerId);
            $correctedFingerprint = app(SyncService::class)->customerPayloadFingerprint($correctedCustomer);
            $readyKey = 'customer.manual_retry_ready.'.$this->customerId;

            if (! hash_equals($beforeFingerprint, $correctedFingerprint)) {
                DB::table('sync_states')->updateOrInsert(
                    ['key' => $readyKey],
                    ['value' => json_encode($correctedFingerprint), 'created_at' => now(), 'updated_at' => now()],
                );
            } else {
                DB::table('sync_states')->where('key', $readyKey)->delete();
            }
        }

        Notification::make()->title($wasFailed ? 'Correction saved. Use Retry Customer to make one push attempt.' : 'Customer updated offline')->success()->send();
        $this->redirect(CustomerPage::getUrl());
    }
}
