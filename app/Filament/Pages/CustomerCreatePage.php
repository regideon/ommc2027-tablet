<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Customer;
use App\Models\GeneralCategory;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\RegionSpecific;
use App\Models\Region;
use App\Models\User;
use App\Services\CustomerProfileFormService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
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
    public ?int $company_id = null;
    public ?int $region_specific_id = null;
    public ?int $physical_region_id = null;
    public ?int $province_id = null;
    public ?int $municipality_id = null;
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
        // Commercial geography is independent from physical geography.
    }

    public function updatedPhysicalRegionId(): void
    {
        $this->province_id = null;
        $this->municipality_id = null;
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
            'contact_number' => 'nullable|string|max:50',
            'business_landline_number' => 'nullable|string|max:50',
            'business_mobile_number' => 'nullable|string|max:50',
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
            foreach ($this->categories[$stream] ?? [] as $year => $category) {
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
        if ($profileType === 'oe' && ($this->trade['entry_detail'] ?? null) === 'Acid' && ! array_key_exists('sulfuric_acid', $this->active)) {
            $this->addError('active.sulfuric_acid', 'Sulfuric Acid is required for Acid accounts.');
            return;
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
        $municipality = Municipality::find($this->municipality_id);
        if (! $municipality || (int) $municipality->region_id !== (int) $this->physical_region_id) {
            $this->addError('municipality_id', 'The City / Municipality does not belong to the selected physical Region.');
            return false;
        }

        if ((int) $municipality->province_id !== (int) $this->province_id && ! ($municipality->province_id === null && $this->province_id === null)) {
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

        if ($profileType === 'outlet' && blank($this->person_in_charge_id)) {
            $this->addError('person_in_charge_id', 'Person in Charge is required for Outlet.');
            return false;
        }

        if ($profileType === 'outlet') {
            foreach ([
                'working_days' => 'Working Days',
                'ulab' => 'ULAB',
                'operating_hours.start' => 'Opening Time',
                'operating_hours.end' => 'Closing Time',
                'delivery_type' => 'Delivery Type',
            ] as $key => $label) {
                $value = data_get($this->trade, $key) ?? data_get($this->active, $key);
                if (blank($value) || (is_array($value) && count($value) === 0)) {
                    $this->addError('trade', "{$label} is required for Outlet.");
                    return false;
                }
            }

            if (($this->active['delivery_type'] ?? null) === 'yes' && blank($this->active['delivery_detail'] ?? null)) {
                $this->addError('active.delivery_detail', 'Delivery Detail is required when Delivery Type is Yes.');
                return false;
            }

            if (($this->trade['motiv_user'] ?? false) && blank($this->active['warehouse_code'] ?? null)) {
                $this->addError('active.warehouse_code', 'Warehouse Code is required for MOTIV users.');
                return false;
            }
        }

        if ($profileType === 'oe' && ($this->trade['entry_detail'] ?? null) === 'Acid' && ! array_key_exists('sulfuric_acid', $this->active)) {
            $this->addError('active.sulfuric_acid', 'Sulfuric Acid is required for Acid accounts.');
            return false;
        }

        return true;
    }
}
