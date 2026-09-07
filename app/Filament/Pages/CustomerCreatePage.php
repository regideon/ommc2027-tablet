<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCategoryHistory;
use App\Models\CustomerTradeProfile;
use App\Models\GeneralCategory;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\RegionSpecific;
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
    public ?int $province_id = null;
    public ?int $municipality_id = null;
    public ?int $general_category_id = null;
    public ?int $competitor_volume = null;
    public string $address = '';
    public ?string $latitude = null;
    public ?string $longitude = null;
    public ?string $contact_person = null;
    public ?string $contact_number = null;
    public bool $is_active = true;
    public array $trade = [];
    public array $category_histories = [];

    public function mount(): void
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

        foreach (config('customer_trade_form.category_years') as $year) {
            $this->category_histories[$year] = ['category_year' => $year, 'category' => null];
        }
    }

    protected function getViewData(): array
    {
        return [
            'companies' => Company::orderBy('name')->get(),
            'regions' => RegionSpecific::orderBy('name')->get(),
            'provinces' => Province::where('enabled', true)->orderBy('name')->get(),
            'municipalities' => Municipality::where('enabled', true)->orderBy('name')->get(),
            'generalCategories' => GeneralCategory::orderBy('sort')->get(),
        ];
    }

    public function updatedRegionSpecificId(): void
    {
        $this->province_id = null;
        $this->municipality_id = null;
    }

    public function updatedProvinceId(): void
    {
        $this->municipality_id = null;
    }

    public function categoryOptions(?string $entryDetail = null): array
    {
        $values = $entryDetail === 'AB and MCB'
            ? array_merge(config('customer_trade_form.categories.AB'), config('customer_trade_form.categories.MCB'))
            : (config("customer_trade_form.categories.{$entryDetail}") ?? array_merge(config('customer_trade_form.categories.AB'), config('customer_trade_form.categories.MCB')));

        return array_combine($values, $values);
    }

    public function saveCustomer(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'unique_id' => 'nullable|string|max:50',
            'company_id' => 'required|exists:companies,id',
            'region_specific_id' => 'required|exists:region_specifics,id',
            'province_id' => 'required|exists:provinces,id',
            'municipality_id' => 'required|exists:municipalities,id',
            'general_category_id' => 'required|exists:general_categories,id',
            'competitor_volume' => 'nullable|integer|in:1,2,3',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'contact_person' => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:50',
            'trade.classifications' => 'required|array|min:1',
            'category_histories' => 'required|array|size:9',
            'category_histories.*.category_year' => 'required|integer',
            'category_histories.*.category' => 'required|string',
        ]);

        $allowed = $this->categoryOptions($this->trade['entry_detail'] ?? null);
        foreach ($this->category_histories as $history) {
            if (! array_key_exists($history['category'], $allowed)) {
                $this->addError('category_histories', 'Each annual category must match the selected Entry Detail.');
                return;
            }
        }

        DB::transaction(function (): void {
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
                'is_active' => $this->is_active,
                'sync_status' => 'pending',
                'sync_attempts' => 0,
            ]);
            $customer->save();

            CustomerTradeProfile::create(['customer_id' => $customer->id, ...$this->trade]);
            foreach ($this->category_histories as $history) {
                CustomerCategoryHistory::create([
                    'customer_id' => $customer->id,
                    'category_year' => $history['category_year'],
                    'category' => $history['category'],
                ]);
            }
        });

        Notification::make()->title('Customer saved offline')->success()->send();
        $this->redirect(CustomerPage::getUrl());
    }
}
