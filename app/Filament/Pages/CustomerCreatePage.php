<?php

namespace App\Filament\Pages;

use App\Models\AreaCluster;
use App\Models\Barangay;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GeneralCategory;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Region;
use App\Models\RegionSpecific;
use App\Models\User;
use App\Services\CustomerProfileFormService;
use App\Services\SyncService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerCreatePage extends Page
{
    protected string $view = 'filament.pages.customer-create-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'customers/create';

    protected static ?string $title = 'Add Customer';

    public string $name = '';

    public ?string $unique_id = null;

    public ?string $customer_code_reservation_token = null;

    public ?int $company_id = null;

    public ?int $region_specific_id = null;

    public ?int $area_cluster_id = null;

    public ?int $physical_region_id = null;

    public ?int $province_id = null;

    public ?int $municipality_id = null;

    public ?int $barangay_id = null;

    public ?int $general_category_id = null;

    public ?int $competitor_volume = null;

    public string $address = '';

    public ?string $latitude = null;

    public ?string $longitude = null;

    public ?string $contact_person = null;

    public ?string $contact_number = null;

    public ?string $business_landline_number = null;

    public ?string $business_mobile_number = null;

    public ?string $date_established = null;

    public ?int $person_in_charge_id = null;

    public array $access_user_ids = [];

    public bool $is_active = true;

    public array $trade = [];

    public array $active = [];

    public array $categories = [];

    protected ?string $companyChangeOldProfile = null;

    public function mount(?int $customerId = null): void
    {
        $this->trade = [
            'house_number' => null,
            'entry_detail' => null,
            'classifications' => [],
            'ommc_brands' => [],
            'ommc_mcb_brands' => [],
            'tpl_pollux' => [],
            'other_competitor_brands' => [],
            'mcb_competitors' => [],
            'other_competitors_note' => null,
            'working_days' => [],
            'operating_hours' => [],
            'motiv_user' => false,
            'delivery_method' => null,
            'ulab' => null,
        ];

        $this->categories = CustomerProfileFormService::defaultCategories('outlet');
    }

    protected function getViewData(): array
    {
        return [
            'companies' => Company::orderBy('name')->get(),
            'regions' => Region::whereNotNull('psgc_code')->orderBy('name')->get(),
            'regionSpecifics' => RegionSpecific::orderBy('name')->get(),
            'provinces' => Province::where('enabled', true)->orderBy('name')->get(),
            'municipalities' => Municipality::where('enabled', true)->orderBy('name')->get(),
            'generalCategories' => GeneralCategory::orderBy('sort')->get(),
            'users' => User::orderBy('name')->get(),
        ];
    }

    public function profileType(): ?string
    {
        return CustomerProfileFormService::profileForCompany($this->company_id);
    }

    public function updatingCompanyId(): void
    {
        $this->companyChangeOldProfile = $this->profileType();
    }

    public function updatedCompanyId(): void
    {
        $this->unique_id = null;
        $this->customer_code_reservation_token = null;

        if ($this->company_id) {
            Log::info('Customer Code reservation requested from Add Customer.', [
                'company_id' => $this->company_id,
            ]);
            $reservation = app(SyncService::class)->reserveCustomerCode($this->company_id);
            if ($reservation !== null) {
                $this->unique_id = $reservation['code'];
                $this->customer_code_reservation_token = $reservation['token'];
                Log::info('Customer Code reservation applied to Add Customer.', [
                    'company_id' => $this->company_id,
                    'code_returned' => true,
                    'reservation_token_returned' => true,
                ]);
            } else {
                Log::warning('Customer Code reservation was unavailable to Add Customer.', [
                    'company_id' => $this->company_id,
                ]);
            }
        }

        $profile = $this->profileType();

        if ($this->companyChangeOldProfile !== null && $this->companyChangeOldProfile === $profile) {
            $this->companyChangeOldProfile = null;

            return;
        }

        $this->trade = [];
        $this->active = [];
        $this->categories = $profile ? CustomerProfileFormService::defaultCategories($profile) : [];
        $this->person_in_charge_id = null;
        $this->companyChangeOldProfile = null;
    }

    public function updatedRegionSpecificId(): void
    {
        if ($this->area_cluster_id && ! AreaCluster::query()
            ->whereKey($this->area_cluster_id)
            ->where('region_specific_id', $this->region_specific_id)
            ->exists()) {
            $this->area_cluster_id = null;
        }
    }

    public function updatedPhysicalRegionId(): void
    {
        if (! $this->physical_region_id) {
            $this->province_id = null;
            $this->municipality_id = null;
            $this->barangay_id = null;

            return;
        }

        if ($this->province_id && ! Province::query()
            ->whereKey($this->province_id)
            ->where('region_id', $this->physical_region_id)
            ->exists()) {
            $this->province_id = null;
            $this->municipality_id = null;
            $this->barangay_id = null;

            return;
        }

        if ($this->municipality_id && ! $this->municipalityMatchesSelection()) {
            $this->municipality_id = null;
            $this->barangay_id = null;

            return;
        }

        if ($this->barangay_id && ! $this->barangayMatchesSelection()) {
            $this->barangay_id = null;
        }
    }

    public function updatedGeneralCategoryId(): void
    {
        if ((int) $this->general_category_id !== 1) {
            $this->competitor_volume = null;
        }
    }

    public function updatedProvinceId(): void
    {
        if ($this->municipality_id && ! $this->municipalityMatchesSelection()) {
            $this->municipality_id = null;
            $this->barangay_id = null;
        } elseif ($this->barangay_id && ! $this->barangayMatchesSelection()) {
            $this->barangay_id = null;
        }
    }

    public function updatedMunicipalityId(): void
    {
        if ($this->barangay_id && ! $this->barangayMatchesSelection()) {
            $this->barangay_id = null;
        }
    }

    public function provinceOptions(): Collection
    {
        if (! $this->physical_region_id) {
            return collect();
        }

        return Province::query()
            ->where('region_id', $this->physical_region_id)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();
    }

    public function municipalityOptions(): Collection
    {
        if (! $this->physical_region_id) {
            return collect();
        }

        $query = Municipality::query()
            ->where('region_id', $this->physical_region_id)
            ->where('enabled', true);

        if ($this->province_id) {
            $query->where('province_id', $this->province_id);
        } else {
            $query->whereNull('province_id');
        }

        return $query->orderBy('name')->get();
    }

    public function areaClusterOptions(): Collection
    {
        if (! $this->region_specific_id) {
            return collect();
        }

        return AreaCluster::query()
            ->where('region_specific_id', $this->region_specific_id)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();
    }

    public function barangayOptions(): Collection
    {
        if (! $this->municipality_id) {
            return collect();
        }

        return Barangay::query()
            ->where('municipality_id', $this->municipality_id)
            ->where('enabled', true)
            ->orderBy('name')
            ->get();
    }

    protected function municipalityMatchesSelection(): bool
    {
        if (! $this->municipality_id || ! $this->physical_region_id) {
            return false;
        }

        $query = Municipality::query()
            ->whereKey($this->municipality_id)
            ->where('region_id', $this->physical_region_id);

        if ($this->province_id) {
            $query->where('province_id', $this->province_id);
        } else {
            $query->whereNull('province_id');
        }

        return $query->exists();
    }

    protected function barangayMatchesSelection(): bool
    {
        return (bool) ($this->barangay_id && $this->municipality_id && Barangay::query()
            ->whereKey($this->barangay_id)
            ->where('municipality_id', $this->municipality_id)
            ->where('enabled', true)
            ->exists());
    }

    public function categoryOptions(string $stream): array
    {
        return CustomerProfileFormService::categoryOptions($this->profileType() ?: 'outlet', $stream);
    }

    public function saveCustomer(): void
    {
        $profileType = $this->profileType();
        abort_unless($profileType, 422, 'The selected Company has no profile mapping.');

        $this->validate([
            'name' => 'required|string|max:255',
            'unique_id' => 'nullable|string|max:50',
            'company_id' => 'required|exists:companies,id',
            'access_user_ids' => 'nullable|array',
            'access_user_ids.*' => 'integer|exists:users,id',
            'region_specific_id' => 'nullable|exists:region_specifics,id',
            'area_cluster_id' => 'nullable|exists:area_clusters,id',
            'physical_region_id' => 'nullable|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'person_in_charge_id' => 'nullable|integer|exists:users,id',
            'general_category_id' => 'nullable|exists:general_categories,id',
            'competitor_volume' => 'nullable|integer|in:1,2,3',
            'address' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'contact_person' => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:50',
            'business_landline_number' => 'nullable|string|max:50',
            'business_mobile_number' => 'nullable|string|max:50',
            'date_established' => 'nullable|date',
        ]);

        if (! $this->physicalGeographyIsValid()) {
            return;
        }

        if (! $this->commercialGeographyIsValid()) {
            return;
        }

        if (! $this->validateScopedPortalRules($profileType)) {
            return;
        }

        foreach (CustomerProfileFormService::categoryStreams($profileType) as $stream) {
            foreach ($this->categories[$stream] ?? [] as $year => $category) {
                if ($category !== null && $category !== '' && ! array_key_exists($category, $this->categoryOptions($stream))) {
                    $this->addError('categories', "Every {$stream} annual category must be selected from the allowed options.");

                    return;
                }
            }
        }
        DB::transaction(function () use ($profileType): void {
            do {
                $localId = -random_int(1, PHP_INT_MAX);
            } while (Customer::withTrashed()->whereKey($localId)->exists());

            $customer = new Customer;
            $customer->id = $localId;
            $customer->fill([
                'local_uuid' => (string) Str::uuid(),
                'name' => $this->name,
                'unique_id' => $this->unique_id,
                'customer_code_reservation_token' => $this->customer_code_reservation_token,
                'company_id' => $this->company_id,
                'region_specific_id' => $this->region_specific_id,
                'area_cluster_id' => $this->area_cluster_id,
                'province_id' => $this->province_id,
                'municipality_id' => $this->municipality_id,
                'barangay_id' => $this->barangay_id,
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
                'person_in_charge_id' => $profileType === 'outlet' ? $this->person_in_charge_id : null,
                'is_active' => $this->is_active,
                'sync_status' => 'pending',
                'sync_attempts' => 0,
            ]);
            $customer->save();
            CustomerProfileFormService::saveAggregate($customer, [
                'trade' => $this->trade,
                'active' => $this->active,
                'categories' => $this->categories,
                'access_user_ids' => $this->access_user_ids,
            ], $profileType);
        });

        Notification::make()->title('Customer saved offline')->success()->send();
        $this->redirect(CustomerPage::getUrl());
    }

    protected function physicalGeographyIsValid(): bool
    {
        if (! $this->municipality_id && ! $this->physical_region_id && ! $this->province_id && ! $this->barangay_id) {
            return true;
        }

        if ($this->province_id && $this->physical_region_id) {
            $province = Province::find($this->province_id);
            if (! $province || (int) $province->region_id !== (int) $this->physical_region_id) {
                $this->addError('province_id', 'The Province does not belong to the selected physical Region.');

                return false;
            }
        }

        if (! $this->municipality_id) {
            if ($this->barangay_id) {
                $this->addError('barangay_id', 'The Barangay requires a selected City / Municipality.');

                return false;
            }

            return true;
        }

        $municipality = Municipality::find($this->municipality_id);
        if (! $municipality || ($this->physical_region_id && (int) $municipality->region_id !== (int) $this->physical_region_id)) {
            $this->addError('municipality_id', 'The City / Municipality does not belong to the selected physical Region.');

            return false;
        }

        if ((int) $municipality->province_id !== (int) $this->province_id && ! ($municipality->province_id === null && $this->province_id === null)) {
            $this->addError('municipality_id', 'The City / Municipality does not belong to the selected Province.');

            return false;
        }

        if ($this->barangay_id && (! Barangay::query()
            ->whereKey($this->barangay_id)
            ->where('municipality_id', $this->municipality_id)
            ->where('enabled', true)
            ->exists())) {
            $this->addError('barangay_id', 'The Barangay does not belong to the selected City / Municipality.');

            return false;
        }

        return true;
    }

    protected function commercialGeographyIsValid(): bool
    {
        if (! $this->area_cluster_id) {
            return true;
        }

        $areaCluster = AreaCluster::find($this->area_cluster_id);
        if (! $areaCluster || ! $this->region_specific_id || (int) $areaCluster->region_specific_id !== (int) $this->region_specific_id) {
            $this->addError('area_cluster_id', 'The Area Cluster does not belong to the selected Specific Region.');

            return false;
        }

        return true;
    }

    protected function validateScopedPortalRules(string $profileType): bool
    {
        $accessIds = array_filter($this->access_user_ids);
        if (User::whereIn('id', $accessIds)->whereNull('rsm_id')->exists()) {
            $this->addError('access_user_ids', 'Each assigned Access user must have an RSM relationship.');

            return false;
        }

        return true;
    }
}
