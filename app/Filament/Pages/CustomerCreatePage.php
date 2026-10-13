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
use App\Models\RegionType;
use App\Models\User;
use App\Services\CustomerAccessMappingResolver;
use App\Services\CustomerProfileFormService;
use App\Services\LocationAreaClusterMappingResolver;
use App\Services\PhilippineAddressResolver;
use App\Services\SyncService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public ?int $customer_region_type_id = null;

    public ?int $physical_region_id = null;

    public ?int $province_id = null;

    public ?int $municipality_id = null;

    public ?int $barangay_id = null;

    public ?int $area_cluster_id = null;

    public ?string $locationError = null;

    public ?string $accessMappingWarning = null;

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

    public ?string $landmass_code = null;

    public ?int $person_in_charge_id = null;

    public array $access_user_ids = [];

    public array $automaticallyMappedAccessUserIds = [];

    public bool $is_active = true;

    public array $trade = [];

    public array $active = [];

    public array $categories = [];

    public array $referenceReadFailures = [];

    protected ?string $companyChangeOldProfile = null;

    protected ?string $companyChangeOldCode = null;

    protected ?int $companyChangeOldId = null;

    protected ?int $initialRegionSpecificId = null;

    protected ?int $regionSpecificIdBeforeUpdate = null;

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

        if (auth()->check()) {
            $this->person_in_charge_id = (int) auth()->id();
            $this->initializeCreatorAccessForAdd();
        }
    }

    protected function getViewData(): array
    {
        if (! ($this instanceof CustomerEditPage) && ! $this->ensureCustomerAddReferencesAvailable()) {
            return [
                'companies' => collect(),
                'companyReferenceUnavailable' => true,
                'regions' => collect(),
                'customerRegionTypes' => collect(),
                'legacySpecificRegionUnresolved' => false,
                'selectedTypeName' => null,
                'applicableSpecificIds' => collect(),
                'applicableAreaClusters' => false,
                'customerProfileType' => null,
                'regionSpecificReferenceUnavailable' => true,
                'areaClusters' => collect(),
                'provinces' => collect(),
                'municipalities' => collect(),
                'barangays' => collect(),
                'generalCategories' => collect(),
                'landmassOptions' => [],
                'users' => collect(),
            ];
        }

        $legacySpecificRegionUnresolved = $this instanceof CustomerEditPage && $this->initialRegionSpecificId === 1;
        $typeName = RegionType::whereKey($this->customer_region_type_id)->value('name');
        $groupCode = $typeName ? config('customer_region_grouping.region_types.'.$typeName) : null;
        $specificIds = $groupCode ? (array) config('customer_region_grouping.specific_regions.'.$groupCode, []) : [];
        $allowedRegionIds = RegionSpecific::query()->whereIn('id', $specificIds)->pluck('region_id')->unique()->all();
        $applicableSpecificIds = $this->physical_region_id
            ? RegionSpecific::query()->whereIn('id', $specificIds)->where('region_id', $this->physical_region_id)->pluck('id')
            : collect();
        $applicableAreaClusters = AreaCluster::query()->whereIn('region_specific_id', $applicableSpecificIds)->where('enabled', true)->exists();
        $customerProfileType = CustomerProfileFormService::profileForCompany($this->company_id);
        $preserveExistingSelection = $this instanceof CustomerEditPage ? $this->physical_region_id : null;
        $regions = Region::query()->where(function ($query) use ($allowedRegionIds, $preserveExistingSelection): void {
            if ($allowedRegionIds !== []) {
                $query->whereIn('id', $allowedRegionIds);
            } elseif ($preserveExistingSelection !== null) {
                $query->whereKey($preserveExistingSelection);
            } else {
                $query->whereRaw('1 = 0');
            }

            if ($preserveExistingSelection !== null
                && ! in_array((int) $preserveExistingSelection, array_map('intval', $allowedRegionIds), true)
                && $allowedRegionIds !== []) {
                $query->orWhereKey($preserveExistingSelection);
            }
        })->orderBy('name')->get();

        return [
            'companies' => Company::orderBy('name')->get(),
            'companyReferenceUnavailable' => $this->company_id !== null && ! Company::whereKey($this->company_id)->exists(),
            'regions' => $regions,
            'customerRegionTypes' => RegionType::query()
                ->whereIn('name', array_keys((array) config('customer_region_grouping.region_types', [])))
                ->orderBy('sort')->get(),
            'selectedTypeName' => $typeName,
            'applicableSpecificIds' => $applicableSpecificIds,
            'applicableAreaClusters' => $applicableAreaClusters,
            'customerProfileType' => $customerProfileType,
            'legacySpecificRegionUnresolved' => $legacySpecificRegionUnresolved,
            'regionSpecificReferenceUnavailable' => $this->region_specific_id !== null && ! RegionSpecific::whereKey($this->region_specific_id)->exists(),
            'areaClusters' => $this->region_specific_id
                ? AreaCluster::where('region_specific_id', $this->region_specific_id)
                    ->where(fn ($query) => $query->where('enabled', true)->orWhere('id', $this->area_cluster_id))
                    ->orderBy('name')->get()
                : collect(),
            'provinces' => Province::where(fn ($query) => $query->where('enabled', true)->orWhere('id', $this->province_id))->orderBy('name')->get(),
            'municipalities' => Municipality::where(fn ($query) => $query->where('enabled', true)->orWhere('id', $this->municipality_id))->orderBy('name')->get(),
            'barangays' => $this->municipality_id
                ? Barangay::where(fn ($query) => $query->where('municipality_id', $this->municipality_id)->where('enabled', true)->orWhere('id', $this->barangay_id))->orderBy('name')->get()
                : collect(),
            'generalCategories' => GeneralCategory::orderBy('sort')->get(),
            'landmassOptions' => (array) (json_decode((string) DB::table('sync_states')->where('key', 'landmass_options')->value('value'), true) ?: []),
            'users' => User::query()->orderBy('name')->get()->sortBy(fn (User $user): string => $user->fullName())->values(),
        ];
    }

    public function profileType(): ?string
    {
        return CustomerProfileFormService::profileForCompany($this->company_id);
    }

    public function locationReferenceSnapshotReady(): bool
    {
        return (int) ($this->syncStateArray('location_reference_snapshot_complete')['reference_contract_version'] ?? 0) >= 3;
    }

    public function updatingCompanyId(): void
    {
        $this->companyChangeOldId = $this->company_id;
        $this->companyChangeOldProfile = $this->profileType();
        $this->companyChangeOldCode = Company::find($this->company_id)?->code;
    }

    public function updatedCompanyId(): void
    {
        $companyChanged = $this->companyChangeOldId !== $this->company_id;
        $this->companyChangeOldId = null;

        if (! $companyChanged) {
            $this->companyChangeOldProfile = null;
            $this->companyChangeOldCode = null;

            return;
        }

        $this->resetValidation();
        $this->unique_id = null;
        $this->customer_code_reservation_token = null;

        if ($this->company_id) {
            $reservation = app(SyncService::class)->reserveCustomerCode($this->company_id);
            if ($reservation !== null) {
                $this->unique_id = $reservation['code'];
                $this->customer_code_reservation_token = $reservation['token'];
            }
        }

        $profile = $this->profileType();
        $profileChanged = $this->companyChangeOldProfile !== $profile;
        if ($profileChanged) {
            $owner = data_get($this->active, 'owner');
            $this->trade = [];
            $this->active = $profile === 'oe' ? ['sulfuric_acid' => false] : [];
            if (is_array($owner)) {
                $this->active['owner'] = $owner;
            }
            $this->categories = $profile ? CustomerProfileFormService::defaultCategories($profile) : [];
        }

        $newCode = Company::find($this->company_id)?->code;
        $companyAccessChanged = $this->companyChangeOldCode !== $newCode
            && (in_array($this->companyChangeOldProfile, ['oe', 'ib'], true)
                || in_array($profile, ['oe', 'ib'], true));
        $this->companyChangeOldProfile = null;
        $this->companyChangeOldCode = null;

        if ($companyAccessChanged && $this instanceof CustomerEditPage) {
            $this->recalculateAccessForCurrentControls();
        }
    }

    public function updatedRegionSpecificId(): void
    {
        if ($this->regionSpecificIdBeforeUpdate === $this->region_specific_id) {
            $this->regionSpecificIdBeforeUpdate = null;
            if ($this instanceof CustomerEditPage) {
                $this->inspectAccessMappingAvailability();
            }

            return;
        }

        $this->regionSpecificIdBeforeUpdate = null;
        $this->applySpecificRegionChange();
    }

    public function updatingRegionSpecificId(): void
    {
        $this->regionSpecificIdBeforeUpdate = $this->region_specific_id;
    }

    public function updatedPhysicalRegionId(): void
    {
        $this->province_id = null;
        $this->municipality_id = null;
        $this->barangay_id = null;

        if ($this->physical_region_id === null && $this->initialRegionSpecificId === null) {
            $this->region_specific_id = null;
            $this->area_cluster_id = null;

            return;
        }

        $this->synchronizeCommercialRegion();
    }

    public function updatedCustomerRegionTypeId(): void
    {
        $this->synchronizeCommercialRegion();
    }

    protected function synchronizeCommercialRegion(): void
    {
        if (! $this->physical_region_id || ! $this->customer_region_type_id
            || ($this->initialRegionSpecificId === 1 && (int) $this->region_specific_id === 1)) {
            return;
        }

        $typeName = RegionType::whereKey($this->customer_region_type_id)->value('name');
        $groupCode = $typeName ? config('customer_region_grouping.region_types.'.$typeName) : null;
        $specificIds = $groupCode ? (array) config('customer_region_grouping.specific_regions.'.$groupCode, []) : [];
        $matches = RegionSpecific::query()->whereIn('id', $specificIds)->where('region_id', $this->physical_region_id)->get();

        if ($matches->count() !== 1) {
            return;
        }

        $nextId = (int) $matches->first()->id;
        if ($nextId === (int) $this->region_specific_id) {
            return;
        }

        $this->region_specific_id = $nextId;
        $this->area_cluster_id = AreaCluster::where('region_specific_id', $nextId)->where('enabled', true)->count() === 1
            ? AreaCluster::where('region_specific_id', $nextId)->where('enabled', true)->value('id')
            : null;
        $this->applySpecificRegionChange();
    }

    public function updatedGeneralCategoryId(): void
    {
        if ((int) $this->general_category_id !== 1) {
            $this->competitor_volume = null;
        }
    }

    public function updatedProvinceId(): void
    {
        $this->municipality_id = null;
        $this->barangay_id = null;
        $this->applyLocationAreaClusterMapping();
    }

    public function updatedMunicipalityId(): void
    {
        $this->barangay_id = null;
        $this->applyLocationAreaClusterMapping();
    }

    public function updatedBarangayId(): void
    {
        $this->applyLocationAreaClusterMapping();
    }

    public function resolveLocation(?float $latitude = null, ?float $longitude = null): void
    {
        $latitude ??= is_numeric($this->latitude) ? (float) $this->latitude : null;
        $longitude ??= is_numeric($this->longitude) ? (float) $this->longitude : null;

        if ($latitude === null || $longitude === null) {
            return;
        }

        $this->latitude = (string) $latitude;
        $this->longitude = (string) $longitude;

        $result = app(PhilippineAddressResolver::class)->resolve($latitude, $longitude);

        if (! $result['ok']) {
            $this->locationError = 'Internet connection required for location.';

            return;
        }

        $this->locationError = $result['address_is_partial']
            ? 'The provider display address was unavailable or exceeded the 500-character field limit; only available details were filled.'
            : null;

        $resolvedRegionId = $result['region_id']
            ?? ($result['province_id'] !== null ? Province::whereKey($result['province_id'])->value('region_id') : null);
        $preserveLegacySpecificRegion = $this->initialRegionSpecificId === 1 || (int) ($this->region_specific_id ?? 0) === 1;
        $this->physical_region_id = $resolvedRegionId ?? $this->physical_region_id;

        $provinceId = $result['province_id'] ?? $this->province_id;
        if ($resolvedRegionId !== null && $provinceId !== null
            && ! Province::whereKey($provinceId)->where('region_id', $resolvedRegionId)->exists()) {
            $provinceId = null;
        }
        $municipalityId = $result['municipality_id'] ?? $this->municipality_id;
        if ($provinceId !== null && $municipalityId !== null
            && ! Municipality::whereKey($municipalityId)->where('province_id', $provinceId)->exists()) {
            $municipalityId = null;
        }
        $barangayId = $result['barangay_id'] ?? $this->barangay_id;
        if ($municipalityId !== null && $barangayId !== null
            && ! Barangay::whereKey($barangayId)->where('municipality_id', $municipalityId)->exists()) {
            $barangayId = null;
        }
        if ($municipalityId === null) {
            $barangayId = null;
        }
        $this->province_id = $provinceId;
        $this->municipality_id = $municipalityId;
        $this->barangay_id = $barangayId;
        if ($result['address'] !== null) {
            $this->address = $result['address'];
        }

        if ($result['area_cluster_id'] !== null && $result['region_specific_id'] !== null) {
            if (! $preserveLegacySpecificRegion) {
                $this->applyResolvedLocationAreaClusterMapping([
                    'decision' => 'mapped',
                    'area_cluster_id' => $result['area_cluster_id'],
                    'region_specific_id' => $result['region_specific_id'],
                    'source_level' => null,
                ]);
            }
        } elseif (! $preserveLegacySpecificRegion && $resolvedRegionId !== null
            && $this->region_specific_id !== null
            && ! RegionSpecific::whereKey($this->region_specific_id)->where('region_id', $resolvedRegionId)->exists()) {
            $this->region_specific_id = null;
            $this->area_cluster_id = null;
        }
    }

    protected function applyLocationAreaClusterMapping(): void
    {
        $resolution = app(LocationAreaClusterMappingResolver::class)->resolve($this->province_id, $this->municipality_id, $this->barangay_id);
        $this->applyResolvedLocationAreaClusterMapping($resolution);
    }

    /** @param array{decision: string, area_cluster_id: ?int, region_specific_id: ?int, source_level: ?string} $resolution */
    protected function applyResolvedLocationAreaClusterMapping(array $resolution): void
    {
        if ($resolution['decision'] !== 'mapped'
            || ! $resolution['area_cluster_id']
            || ! $resolution['region_specific_id']
            || $this->initialRegionSpecificId === 1) {
            return;
        }

        $previousRegionSpecificId = $this->region_specific_id;
        $this->region_specific_id = $resolution['region_specific_id'];
        $this->customer_region_type_id = self::customerRegionTypeIdForSpecificRegion($this->region_specific_id);
        if ((int) ($previousRegionSpecificId ?? 0) !== (int) ($this->region_specific_id ?? 0)) {
            $this->applySpecificRegionChange();
        }

        // Access recalculation for the Specific Region can reset Area Cluster
        // to the single available option. A mapped location is already an
        // explicit, validated choice, so restore it after that recalculation.
        if (AreaCluster::query()->whereKey($resolution['area_cluster_id'])
            ->where('region_specific_id', $resolution['region_specific_id'])->exists()) {
            $this->area_cluster_id = $resolution['area_cluster_id'];
        }
    }

    public function categoryOptions(string $stream): array
    {
        return CustomerProfileFormService::categoryOptions($this->profileType() ?: 'outlet', $stream);
    }

    public function saveCustomer(): void
    {
        if (! $this->ensureCustomerAddReferencesAvailable()) {
            return;
        }

        $profileType = $this->profileType();
        $deliveryDetailApplies = $profileType === 'outlet' && data_get($this->active, 'delivery_type') === 'yes';
        $this->validate([
            'name' => 'required|string|max:255',
            'is_active' => ['required', 'boolean'],
            'unique_id' => 'nullable|string|max:50',
            'company_id' => 'required|exists:companies,id',
            'access_user_ids' => [User::query()->exists() ? 'required' : 'nullable', 'array', User::query()->exists() ? 'min:1' : 'min:0'],
            'access_user_ids.*' => 'integer|distinct|exists:users,id',
            'region_specific_id' => 'nullable|exists:region_specifics,id',
            'physical_region_id' => 'nullable|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'area_cluster_id' => 'nullable|exists:area_clusters,id',
            'person_in_charge_id' => [User::query()->exists() ? 'required' : 'nullable', 'integer', 'exists:users,id'],
            'general_category_id' => [GeneralCategory::query()->exists() ? 'required' : 'nullable', 'exists:general_categories,id'],
            'competitor_volume' => [(int) $this->general_category_id === 1 ? 'required' : 'nullable', 'integer', 'in:1,2,3'],
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'contact_person' => 'required|string|max:255',
            'contact_number' => 'nullable|string|max:50',
            'business_landline_number' => 'required|string|max:50',
            'business_mobile_number' => 'required|string|max:50',
            'date_established' => 'required|date',
            'landmass_code' => ['nullable', 'string', 'max:30', Rule::in(array_keys($this->syncStateArray('landmass_options')))],
            'active.conversion_program' => ['nullable', Rule::in([...array_keys(config('customer_trade_form.conversion_programs', [])), ''])],
            'active.warehouse_code' => [Rule::requiredIf(fn (): bool => $this->profileType() === 'outlet' && (bool) data_get($this->trade, 'motiv_user')), 'string', 'max:255'],
            'active.delivery_type' => ['nullable', Rule::in(['yes', 'no'])],
            'active.delivery_detail' => $deliveryDetailApplies
                ? ['required', Rule::in(['own_delivery', 'meh'])]
                : ['nullable'],
        ]);

        abort_unless($profileType, 422, 'The selected Company has no profile mapping.');

        if (! $this->locationReferenceSnapshotReady()) {
            throw ValidationException::withMessages([
                'landmass_code' => 'Landmass reference options have not been synchronized. Sync reference data before saving this Customer.',
            ]);
        }

        $this->validateCreateOptionState();

        $this->validateProfileCompleteness($profileType);

        if (! $this->physicalGeographyIsValid()) {
            return;
        }

        $applicableCategoryStreams = $this->applicableCategoryStreams($profileType);
        foreach ($applicableCategoryStreams as $stream) {
            foreach ($this->categories[$stream] ?? [] as $year => $category) {
                if ($category !== null && $category !== '' && ! array_key_exists($category, $this->categoryOptions($stream))) {
                    $this->addError('categories', "Every {$stream} annual category must be selected from the allowed options.");

                    return;
                }
            }
        }
        $categoriesForPersistence = array_intersect_key($this->categories, array_flip($applicableCategoryStreams));
        $activeForPersistence = $this->active;
        if ($profileType === 'outlet' && data_get($this->active, 'delivery_type') !== 'yes') {
            unset($activeForPersistence['delivery_detail']);
        }

        $customerId = DB::transaction(function () use ($profileType, $categoriesForPersistence, $activeForPersistence): int {
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
                'landmass_code' => $this->landmass_code,
                'person_in_charge_id' => $this->person_in_charge_id,
                'is_active' => $this->is_active,
                'sync_status' => 'pending',
                'sync_attempts' => 0,
            ]);
            $customer->save();
            CustomerProfileFormService::saveAggregate($customer, [
                'trade' => $this->trade,
                'active' => $activeForPersistence,
                'categories' => $categoriesForPersistence,
                'access_user_ids' => $this->access_user_ids,
            ], $profileType);

            return (int) $customer->id;
        });

        $pushResult = app(SyncService::class)->pushCustomer($customerId);

        if ($pushResult->success && $pushResult->failedCount === 0) {
            Notification::make()->title('Customer created and synced')->success()->send();
        } else {
            Notification::make()
                ->title('Customer saved locally; synchronization is still pending.')
                ->body($pushResult->message)
                ->warning()
                ->send();
        }

        $this->redirect(CustomerPage::getUrl());
    }

    private function ensureCustomerAddReferencesAvailable(): bool
    {
        $this->referenceReadFailures = [];
        $sources = [
            'companies' => ['company_id'],
            'users' => ['access_user_ids', 'person_in_charge_id'],
            'general_categories' => ['general_category_id'],
            'region_types' => ['customer_region_type_id'],
            'regions' => ['physical_region_id'],
            'region_specifics' => ['physical_region_id'],
            'area_clusters' => ['area_cluster_id'],
            'provinces' => ['province_id'],
            'municipalities' => ['municipality_id'],
            'barangays' => ['barangay_id'],
        ];

        foreach ($sources as $table => $fields) {
            try {
                DB::table($table)->limit(1)->get();
            } catch (QueryException) {
                $message = 'Reference options could not be loaded. Restore or sync the reference data before saving.';
                foreach ($fields as $field) {
                    $this->referenceReadFailures[$field] = $message;
                    $this->addError($field, $message);
                }
            }
        }

        return $this->referenceReadFailures === [];
    }

    private function validateCreateOptionState(): void
    {
        $customerRegionTypeIds = RegionType::query()
            ->whereIn('name', array_keys((array) config('customer_region_grouping.region_types', [])))
            ->pluck('id')
            ->all();
        $this->validate([
            'customer_region_type_id' => [$customerRegionTypeIds !== [] ? 'required' : 'nullable', 'integer', Rule::in($customerRegionTypeIds)],
        ]);

        $groupName = RegionType::whereKey($this->customer_region_type_id)->value('name');
        $groupCode = $groupName ? config('customer_region_grouping.region_types.'.$groupName) : null;
        $specificIds = $groupCode ? (array) config('customer_region_grouping.specific_regions.'.$groupCode, []) : [];
        $specifics = RegionSpecific::query()->whereIn('id', $specificIds)->get();
        $this->validate([
            'physical_region_id' => [$specifics->isNotEmpty() ? 'required' : 'nullable', 'integer', Rule::in(Region::query()->whereIn('id', $specifics->pluck('region_id'))->pluck('id')->all())],
        ]);
        $specific = $specifics->first(fn (RegionSpecific $row): bool => (int) $row->region_id === (int) $this->physical_region_id);
        if ($specifics->isNotEmpty() && ! $specific) {
            throw ValidationException::withMessages(['physical_region_id' => 'Select a Specific Region from the approved options for this Region grouping.']);
        }
        $this->region_specific_id = $specific?->id;
        $clusterIds = $specific ? AreaCluster::where('region_specific_id', $specific->id)->where('enabled', true)->pluck('id')->all() : [];
        $this->validate([
            'area_cluster_id' => [$clusterIds !== [] ? 'required' : 'nullable', 'nullable', Rule::in($clusterIds)],
        ]);
        $provinceIds = Province::where('region_id', $this->physical_region_id)->where('enabled', true)->pluck('id')->all();
        $this->validate([
            'province_id' => [$provinceIds !== [] ? 'required' : 'nullable', 'nullable', Rule::in($provinceIds)],
        ]);
        $municipalities = Municipality::where('region_id', $this->physical_region_id)->where('province_id', $this->province_id)->where('enabled', true)->pluck('id')->all();
        $this->validate([
            'municipality_id' => [$municipalities !== [] ? 'required' : 'nullable', 'nullable', Rule::in($municipalities)],
        ]);
        $barangays = $this->municipality_id ? Barangay::where('municipality_id', $this->municipality_id)->where('enabled', true)->pluck('id')->all() : [];
        $this->validate([
            'barangay_id' => [$barangays !== [] ? 'required' : 'nullable', 'nullable', Rule::in($barangays)],
        ]);
        if (User::query()->exists() && ! User::whereKey($this->person_in_charge_id)->exists()) {
            throw ValidationException::withMessages(['person_in_charge_id' => 'Select a valid Person in Charge.']);
        }
    }

    private function syncStateArray(string $key): array
    {
        return (array) (json_decode((string) DB::table('sync_states')->where('key', $key)->value('value'), true) ?: []);
    }

    private function validateProfileCompleteness(string $profileType): void
    {
        $outletEntryDetailOptions = config('customer_trade_form.profile_entry_details.outlet', []);
        $outletClassificationOptions = config('customer_trade_form.profile_classifications.outlet', []);
        $outletWorkingDayOptions = config('customer_trade_form.working_days', []);
        $outletConversionProgramOptions = array_keys(config('customer_trade_form.conversion_programs', []));
        $fleetClassificationOptions = config('customer_trade_form.profile_classifications.fleet', []);
        $oeEntryDetailOptions = config('customer_trade_form.profile_entry_details.oe', []);
        $oeClassificationOptions = config('customer_trade_form.profile_classifications.oe', []);
        $ibClassificationOptions = config('customer_trade_form.profile_classifications.ib', []);
        $rules = [
            'active.owner.name' => 'required|string|max:255',
            'active.owner.birthday' => 'required|date',
            'active.owner.nickname' => 'required|string|max:255',
            'active.owner.successor_name' => 'required|string|max:255',
            'active.owner.successor_birthday' => 'required|date',
            'active.owner.relationship' => 'required|string|max:255',
            'active.owner.generation' => 'required|string|max:255',
            'active.owner.hobbies' => 'required|string|max:1000',
        ];

        $rules += match ($profileType) {
            'outlet' => [
                'trade.entry_detail' => $outletEntryDetailOptions === []
                    ? ['prohibited']
                    : ['required', Rule::in($outletEntryDetailOptions)],
                'trade.classifications' => $outletClassificationOptions === []
                    ? ['nullable', 'array', 'size:0']
                    : ['required', 'array', 'min:1'],
                'trade.classifications.*' => ['required', Rule::in($outletClassificationOptions)],
                'trade.working_days' => $outletWorkingDayOptions === []
                    ? ['nullable', 'array', 'size:0']
                    : ['required', 'array', 'min:1'],
                'trade.working_days.*' => ['required', Rule::in($outletWorkingDayOptions)],
                'trade.motiv_user' => ['required', 'boolean'],
                'active.conversion_program' => $outletConversionProgramOptions === []
                    ? ['prohibited']
                    : ['required', Rule::in($outletConversionProgramOptions)],
                'active.operating_hours.start' => ['required', 'date_format:H:i'],
                'active.operating_hours.end' => ['required', 'date_format:H:i'],
                'active.delivery_type' => ['required', Rule::in(['yes', 'no'])],
                'active.ulab' => ['required', Rule::in(['GRC', 'Consolidator', 'None'])],
            ],
            'fleet' => [
                'active.account_type' => ['required', Rule::in(['newly_mapped', 'existing'])],
                'active.classifications' => $fleetClassificationOptions === []
                    ? ['prohibited']
                    : ['required', Rule::in($fleetClassificationOptions)],
                'active.battery_class' => ['required', Rule::in(['maintenance_free', 'low_maintenance'])],
                'active.status' => ['required', Rule::in(['active', 'dormant', 'irregular'])],
            ],
            'oe' => [
                'trade.entry_detail' => $oeEntryDetailOptions === []
                    ? ['prohibited']
                    : ['required', Rule::in($oeEntryDetailOptions)],
                'active.classifications' => $oeClassificationOptions === []
                    ? ['prohibited']
                    : ['required', Rule::in($oeClassificationOptions)],
                'active.battery_class' => ['required', Rule::in(['maintenance_free', 'low_maintenance'])],
                'active.sulfuric_acid' => [data_get($this->trade, 'entry_detail') === 'Acid' ? 'required' : 'nullable', 'boolean'],
            ],
            'ib' => [
                'active.classifications' => $ibClassificationOptions === []
                    ? ['prohibited']
                    : ['required', Rule::in($ibClassificationOptions)],
            ],
            default => [],
        };

        foreach ($this->applicableCategoryStreams($profileType) as $stream) {
            foreach (CustomerProfileFormService::categoryYears($profileType) as $year) {
                $categoryOptions = array_keys($this->categoryOptions($stream));
                $rules["categories.{$stream}.{$year}"] = $categoryOptions === []
                    ? ['prohibited']
                    : ['required', Rule::in($categoryOptions)];
            }
        }

        $this->validate($rules);
    }

    /**
     * @return array<int, string>
     */
    private function applicableCategoryStreams(string $profileType): array
    {
        $streams = CustomerProfileFormService::categoryStreams($profileType);

        if ($profileType !== 'outlet') {
            return $streams;
        }

        $activeStreams = match ($this->trade['entry_detail'] ?? null) {
            'AB' => ['ab'],
            'MCB' => ['mcb'],
            'AB and MCB' => ['ab', 'mcb'],
            default => [],
        };

        return array_values(array_intersect($streams, $activeStreams));
    }

    protected static function customerRegionTypeIdForSpecificRegion(mixed $specificRegionId): ?int
    {
        if (! $specificRegionId || in_array((int) $specificRegionId, (array) config('customer_region_grouping.legacy_specific_region_ids', []), true)) {
            return null;
        }

        foreach ((array) config('customer_region_grouping.specific_regions', []) as $groupCode => $ids) {
            if (! in_array((int) $specificRegionId, $ids, true)) {
                continue;
            }

            $typeName = array_search($groupCode, (array) config('customer_region_grouping.region_types', []), true);
            $id = $typeName ? RegionType::where('name', $typeName)->value('id') : null;

            return $id ? (int) $id : null;
        }

        return null;
    }

    protected function physicalGeographyIsValid(): bool
    {
        if ($this->physical_region_id && $this->province_id) {
            $province = Province::find($this->province_id);

            if (! $province || (int) $province->region_id !== (int) $this->physical_region_id) {
                $this->addError('province_id', 'The Province does not belong to the selected physical Region or has no physical Region mapping.');

                return false;
            }
        }

        if (! $this->municipality_id) {
            return $this->commercialGeographyIsValid();
        }

        $municipality = Municipality::find($this->municipality_id);

        if (! $municipality) {
            return $this->commercialGeographyIsValid();
        }

        if ($this->physical_region_id && (int) $municipality->region_id !== (int) $this->physical_region_id) {
            $this->addError('municipality_id', 'The City / Municipality does not belong to the selected physical Region.');

            return false;
        }

        if ($this->province_id && (int) $municipality->province_id !== (int) $this->province_id) {
            $this->addError('municipality_id', 'The City / Municipality does not belong to the selected Province.');

            return false;
        }

        if ($this->barangay_id && ! Barangay::whereKey($this->barangay_id)->where('municipality_id', $this->municipality_id)->exists()) {
            $this->addError('barangay_id', 'The Barangay does not belong to the selected City / Municipality.');

            return false;
        }

        return $this->commercialGeographyIsValid();
    }

    protected function commercialGeographyIsValid(): bool
    {
        $specificRegionChanged = $this->initialRegionSpecificId === null
            || (int) ($this->initialRegionSpecificId ?? 0) !== (int) ($this->region_specific_id ?? 0);

        if ($specificRegionChanged && $this->region_specific_id) {
            $specificRegion = RegionSpecific::find($this->region_specific_id);
            if (! $this->physical_region_id || ! $specificRegion
                || (int) $specificRegion->region_id !== (int) $this->physical_region_id) {
                $this->addError('region_specific_id', 'Select a Specific Region that belongs to the selected physical Region.');

                return false;
            }
        }

        if (! $this->area_cluster_id) {
            return true;
        }

        if (! $this->region_specific_id || ! AreaCluster::whereKey($this->area_cluster_id)->where('region_specific_id', $this->region_specific_id)->exists()) {
            $this->addError('area_cluster_id', 'The Area Cluster does not belong to the selected Specific Region.');

            return false;
        }

        return true;
    }

    protected function applySpecificRegionChange(): void
    {
        $clusters = $this->region_specific_id
            ? AreaCluster::query()->where('region_specific_id', $this->region_specific_id)->where('enabled', true)->orderBy('id')->pluck('id')
            : collect();
        $this->area_cluster_id = $clusters->count() === 1 ? (int) $clusters->first() : null;

        if ($this instanceof CustomerEditPage && ! in_array($this->profileType(), ['oe', 'ib'], true)) {
            $this->applyAccessMappingForChangedController();
        }
    }

    protected function initializeCreatorAccessForAdd(): void
    {
        $resolution = app(CustomerAccessMappingResolver::class)->forCreator(auth()->id());
        $this->access_user_ids = $resolution['user_ids'];
        $this->accessMappingWarning = $resolution['warning'];
    }

    protected function applyAccessMappingForChangedController(): void
    {
        $this->recalculateAccessForCurrentControls();
    }

    protected function recalculateAccessForCurrentControls(): void
    {
        $mapping = app(CustomerAccessMappingResolver::class)->forCustomer(
            $this->company_id,
            $this->region_specific_id,
        );

        if (! $mapping['complete']) {
            $this->accessMappingWarning = $mapping['warning'];

            return;
        }

        $previousMappedUserIds = array_map('intval', $this->automaticallyMappedAccessUserIds);
        $currentUserIds = array_values(array_unique(array_map('intval', $this->access_user_ids)));
        $manualUserIds = array_values(array_diff($currentUserIds, $previousMappedUserIds));
        $authenticatedUserId = auth()->id();
        $mappedUserIds = array_values(array_unique(array_map('intval', $mapping['user_ids'])));

        $this->access_user_ids = array_values(array_unique([
            ...$manualUserIds,
            ...($authenticatedUserId ? [(int) $authenticatedUserId] : []),
            ...$mappedUserIds,
        ]));
        $this->automaticallyMappedAccessUserIds = $mappedUserIds;
        $this->accessMappingWarning = null;
    }

    protected function initializeAutomaticAccessUserIds(): void
    {
        $mapping = app(CustomerAccessMappingResolver::class)->forCustomer(
            $this->company_id,
            $this->region_specific_id,
        );

        $this->automaticallyMappedAccessUserIds = $mapping['complete']
            ? array_values(array_unique(array_map('intval', $mapping['user_ids'])))
            : [];
    }

    protected function inspectAccessMappingAvailability(): void
    {
        $mapping = app(CustomerAccessMappingResolver::class)->forCustomer(
            $this->company_id,
            $this->region_specific_id,
        );
        $this->accessMappingWarning = $mapping['complete'] ? null : $mapping['warning'];
    }
}
