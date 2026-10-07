<?php

namespace App\Filament\Pages;

use App\Models\AreaCluster;
use App\Models\Barangay;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GeneralCategory;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\RegionSpecific;
use App\Models\Region;
use App\Models\User;
use App\Services\CustomerProfileFormService;
use App\Services\PhilippineAddressResolver;
use App\Services\SyncService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
    public ?int $physical_region_id = null;
    public ?int $province_id = null;
    public ?int $municipality_id = null;
    public ?int $barangay_id = null;
    public ?int $area_cluster_id = null;
    public ?string $locationError = null;
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

        if (auth()->check()) {
            $this->access_user_ids = [auth()->id()];
        }
    }

    protected function getViewData(): array
    {
        return [
            'companies' => Company::orderBy('name')->get(),
            'regions' => Region::whereNotNull('psgc_code')->orderBy('name')->get(),
            'regionSpecifics' => RegionSpecific::orderBy('name')->get(),
            'areaClusters' => $this->region_specific_id
                ? AreaCluster::where('region_specific_id', $this->region_specific_id)->where('enabled', true)->orderBy('name')->get()
                : collect(),
            'provinces' => Province::where('enabled', true)->orderBy('name')->get(),
            'municipalities' => Municipality::where('enabled', true)->orderBy('name')->get(),
            'barangays' => $this->municipality_id
                ? Barangay::where('municipality_id', $this->municipality_id)->where('enabled', true)->orderBy('name')->get()
                : collect(),
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
            $reservation = app(SyncService::class)->reserveCustomerCode($this->company_id);
            if ($reservation !== null) {
                $this->unique_id = $reservation['code'];
                $this->customer_code_reservation_token = $reservation['token'];
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
        $this->area_cluster_id = null;
    }

    public function updatedPhysicalRegionId(): void
    {
        $this->province_id = null;
        $this->municipality_id = null;
        $this->barangay_id = null;
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
    }

    public function updatedMunicipalityId(): void
    {
        $this->barangay_id = null;
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

        $this->locationError = null;

        foreach (['region_specific_id', 'province_id', 'municipality_id', 'barangay_id', 'area_cluster_id', 'address'] as $field) {
            if ($result[$field] !== null) {
                $this->{$field} = $result[$field];
            }
        }

        $this->physical_region_id = $this->municipality_id
            ? Municipality::find($this->municipality_id)?->region_id
            : null;
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
            'contact_number' => 'nullable|string|max:50',
            'business_landline_number' => 'nullable|string|max:50',
            'business_mobile_number' => 'nullable|string|max:50',
            'date_established' => 'nullable|date',
            'active.conversion_program' => ['nullable', Rule::in([...array_keys(config('customer_trade_form.conversion_programs', [])), ''])],
        ]);

        if (! $this->physicalGeographyIsValid()) {
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
        $customerId = DB::transaction(function () use ($profileType): int {
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

    protected function physicalGeographyIsValid(): bool
    {
        if (! $this->municipality_id) {
            return true;
        }

        $municipality = Municipality::find($this->municipality_id);

        if (! $municipality) {
            return true;
        }

        if ($this->physical_region_id && (int) $municipality->region_id !== (int) $this->physical_region_id) {
            $this->addError('municipality_id', 'The City / Municipality does not belong to the selected physical Region.');

            return false;
        }

        if ($this->province_id && (int) $municipality->province_id !== (int) $this->province_id) {
            $this->addError('municipality_id', 'The City / Municipality does not belong to the selected Province.');

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
