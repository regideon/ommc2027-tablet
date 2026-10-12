<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\PullsCustomers;
use App\Models\Customer;
use App\Models\CustomerBrand;
use App\Models\CustomerCategory;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\User;
use App\Services\SyncService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CustomerPage extends Page
{
    use PullsCustomers;

    protected string $view = 'filament.pages.customer-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Customers';

    protected static ?string $title = '';

    protected static ?int $navigationSort = 450;

    /**
     * Detail rows shown per customer — deliberately small. This page can list 2,000+
     * customers, so nothing here may scale with that; every query below is either a
     * single row or capped, and none of it runs until a specific customer is tapped.
     */
    private const RECENT_LIMIT = 10;

    private const PHOTO_LIMIT = 15;

    public ?string $selectedCustomerId = null;

    public array $customerDetail = [];

    public bool $showPhotos = false;

    public array $customerPhotos = [];

    public string $search = '';

    public bool $pushingCustomers = false;

    public ?string $retryingCustomerId = null;

    protected function getViewData(): array
    {
        $user = Auth::user();
        $roles = $user->getRoleNames()->toArray();

        // Customers the portal reported in this user's scope on their last
        // customer pull (see SyncService::recordCustomerScope()).
        $pulledIds = DB::table('customer_scopes')->where('user_id', $user->id)->pluck('customer_id');

        $customers = Customer::query()->where('is_active', true);

        if (! in_array('rsm_approver', $roles)) {
            if (array_intersect(['rsm', 'drm_approver'], $roles)) {
                $drmIds = User::where('rsm_id', $user->id)->pluck('id');
                $customerIds = DB::table('customer_user')->whereIn('user_id', $drmIds)->pluck('customer_id')->merge($pulledIds)->unique();
            } else {
                // DRM
                $customerIds = DB::table('customer_user')->where('user_id', $user->id)->pluck('customer_id')->merge($pulledIds)->unique();
            }

            $customers->whereIn('id', $customerIds);
        }

        $term = trim($this->search);

        if ($term !== '') {
            $pattern = '%'.mb_strtolower($term).'%';
            $customers->where(function ($query) use ($pattern): void {
                $query->whereRaw('LOWER(customers.name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(customers.unique_id) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(customers.address) LIKE ?', [$pattern])
                    ->orWhereHas('municipality', fn ($location) => $location->whereRaw('LOWER(name) LIKE ?', [$pattern]))
                    ->orWhereHas('province', fn ($location) => $location->whereRaw('LOWER(name) LIKE ?', [$pattern]))
                    ->orWhereHas('barangay', fn ($location) => $location->whereRaw('LOWER(name) LIKE ?', [$pattern]))
                    ->orWhereHas('areaCluster', fn ($location) => $location->whereRaw('LOWER(name) LIKE ?', [$pattern]))
                    ->orWhereHas('regionSpecific', fn ($location) => $location->whereRaw('LOWER(name) LIKE ?', [$pattern]));
            });
        }

        return [
            'customers' => $customers->orderBy('name')->get(),
            'hasPendingCustomerPushWork' => app(SyncService::class)->hasPendingCustomerPushWork(),
        ];
    }

    public function pushCustomers(): void
    {
        if ($this->pushingCustomers) {
            return;
        }

        $this->pushingCustomers = true;

        try {
            $result = app(SyncService::class)->pushPendingCustomers();
            $notification = Notification::make()->title($result->message);

            if ($result->failedCount > 0) {
                $notification->warning();
            } elseif ($result->success) {
                $notification->success();
            } else {
                $notification->danger();
            }

            $notification->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->title('Customer push could not be completed. Pending Customers remain saved locally.')
                ->danger()
                ->send();
        } finally {
            $this->pushingCustomers = false;
        }
    }

    public function retryCustomerPush(string $customerId): void
    {
        $customerId = $this->validatedCustomerId($customerId);
        $customerIdString = (string) $customerId;

        if ($this->retryingCustomerId !== null || $this->selectedCustomerId !== $customerIdString) {
            return;
        }

        $this->retryingCustomerId = $customerIdString;
        try {
            $result = app(SyncService::class)->retryExhaustedCustomer($customerId);
            $notification = Notification::make()->title($result->message);
            $result->success ? $notification->success() : $notification->warning();
            $notification->send();
            $this->viewCustomer($customerId);
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Customer retry could not be completed. The local Customer remains saved.')->danger()->send();
        } finally {
            $this->retryingCustomerId = null;
        }
    }

    /**
     * Loads everything except photos in one tap — all of it is either a single row
     * or capped to RECENT_LIMIT, so this stays cheap regardless of how long a
     * customer's history is. Photos are binary files and stay separately lazy
     * (see loadCustomerPhotos()) so opening a customer never downloads images
     * the user didn't ask to see.
     */
    public function viewCustomer(string $customerId): void
    {
        $customerId = $this->validatedCustomerId($customerId);
        $this->selectedCustomerId = (string) $customerId;
        $this->showPhotos = false;
        $this->customerPhotos = [];
        $this->customerDetail = [];

        try {
            $this->loadCustomerDetail($customerId);
        } catch (Throwable $exception) {
            $reference = (string) Str::uuid();
            $applicationFrame = collect($exception->getTrace())->first(function (array $frame): bool {
                $file = $frame['file'] ?? null;

                return is_string($file) && str_starts_with($file, app_path().DIRECTORY_SEPARATOR);
            });
            $this->selectedCustomerId = null;
            $this->customerDetail = [];

            Log::error('Tablet Customer detail load failed.', [
                'reference' => $reference,
                'customer_id' => $customerId,
                'user_id' => Auth::id(),
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
                'source_file' => basename($exception->getFile()),
                'source_line' => $exception->getLine(),
                'database_error_code' => $exception instanceof QueryException
                    ? ($exception->errorInfo[0] ?? null)
                    : null,
                'database_error_summary' => $exception instanceof QueryException
                    ? substr((string) ($exception->errorInfo[2] ?? 'Database query failed.'), 0, 200)
                    : null,
                'application_frame' => $applicationFrame ? [
                    'file' => basename($applicationFrame['file']),
                    'line' => $applicationFrame['line'] ?? null,
                    'class' => $applicationFrame['class'] ?? null,
                    'function' => $applicationFrame['function'] ?? null,
                ] : null,
            ]);

            Notification::make()
                ->title('Customer details could not be loaded.')
                ->body('Please try again. Reference: '.$reference)
                ->danger()
                ->send();

            return;
        }
    }

    private function loadCustomerDetail(int $customerId): void
    {

        $profile = CustomerProfile::whereHas('salescall', fn ($q) => $q->where('customer_id', $customerId))
            ->latest('created_at')
            ->first();

        $brands = CustomerBrand::where('customer_id', $customerId)
            ->with(['materialGroup', 'brand'])
            ->get();

        $category = CustomerCategory::where('customer_id', $customerId)
            ->with(['category', 'subCategory'])
            ->first();

        $notes = CustomerNote::where('customer_id', $customerId)
            ->where('created_by', auth()->id())
            ->latest('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get();

        // DRMs see only their own visits; RSMs see every rep's visits to this
        // customer (mirrors the "RSM sees all DRMs under them" visibility used
        // elsewhere in the app — see mountVp()/CustomerPage's commented role logic).
        $visitsQuery = Salescall::where('customer_id', $customerId)
            ->with(['salescallStatus', 'createdBy']);

        if (! auth()->user()?->hasRole('rsm')) {
            $visitsQuery->where('created_by', auth()->id());
        }

        $visits = $visitsQuery
            ->orderByRaw('COALESCE(actual_in, visit_date) DESC')
            ->limit(self::RECENT_LIMIT)
            ->get();

        $photoCount = SalescallImage::whereHas('salescall', fn ($q) => $q->where('customer_id', $customerId))->count();

        $customer = Customer::with([
            'company', 'tradeProfile', 'categoryHistories', 'province', 'barangay',
            'areaCluster', 'regionSpecific', 'personInCharge:id,name', 'users:id,name,rsm_id',
            'municipality.region', 'municipality.province',
        ])->findOrFail($customerId);
        $accessUsers = $customer->users->sortBy('name')->values();
        $drmUsers = $accessUsers->filter(fn (User $user): bool => $user->hasRole('drm'))->values();
        $rsmIds = $drmUsers->pluck('rsm_id')->filter()->unique()->values();
        $rsmUsers = $rsmIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $rsmIds)->orderBy('name')->get(['id', 'name']);
        $physicalRegion = $customer->municipality?->region?->name;
        $province = $customer->province?->name ?? $customer->municipality?->province?->name;
        $municipality = $customer->municipality?->name;
        $specificRegion = $customer->regionSpecific?->name;
        $pushErrorHistory = json_decode((string) DB::table('sync_states')->where('key', 'customer.push_error_history.'.$customer->id)->value('value'), true);

        $this->customerDetail = [
            'customer' => [
                'unique_id' => $customer->unique_id,
                'company' => $customer->company?->name,
                'address' => $customer->address,
                'contact_person' => $customer->contact_person,
                'contact_number' => $customer->contact_number,
                'business_landline_number' => $customer->business_landline_number,
                'business_mobile_number' => $customer->business_mobile_number,
                'date_established' => $customer->date_established?->format('Y-m-d'),
                'person_in_charge' => $customer->personInCharge?->name,
                'physical_region' => $physicalRegion,
                'province' => $province,
                'barangay' => $customer->barangay?->name,
                'area_cluster' => $customer->areaCluster?->name,
                'municipality' => $municipality,
                'specific_region' => $specificRegion,
                'latitude' => $customer->latitude,
                'longitude' => $customer->longitude,
                'general_category' => $customer->generalCategory?->name,
                'competitor_volume' => match ($customer->competitor_volume) {
                    1 => 'High', 2 => 'Medium', 3 => 'Low', default => null
                },
                'is_active' => $customer->is_active,
                'sync_status' => $customer->sync_status,
                'sync_error' => $customer->sync_error,
                'previous_push_errors' => is_array($pushErrorHistory) ? array_slice($pushErrorHistory, 0, -1) : [],
                'sync_attempts' => $customer->sync_attempts,
                'server_id' => $customer->server_id === null ? null : (string) $customer->server_id,
            ],
            'access_users' => $accessUsers->map(fn (User $user): array => [
                'name' => $user->name,
            ])->all(),
            'drm_users' => $drmUsers->map(fn (User $user): array => [
                'name' => $user->name,
            ])->all(),
            'rsm_users' => $rsmUsers->map(fn (User $user): array => [
                'name' => $user->name,
            ])->all(),
            'trade_profile' => $customer->tradeProfile ? [
                'profile_type' => $customer->tradeProfile->profile_type,
                'house_number' => $customer->tradeProfile->house_number,
                'entry_detail' => $customer->tradeProfile->entry_detail,
                'classifications' => $customer->tradeProfile->classifications,
                'ommc_brands' => $customer->tradeProfile->ommc_brands,
                'ommc_mcb_brands' => $customer->tradeProfile->ommc_mcb_brands,
                'tpl_pollux' => $customer->tradeProfile->tpl_pollux,
                'other_competitor_brands' => $customer->tradeProfile->other_competitor_brands,
                'mcb_competitors' => $customer->tradeProfile->mcb_competitors,
                'other_competitors_note' => $customer->tradeProfile->other_competitors_note,
                'working_days' => $customer->tradeProfile->working_days,
                'operating_hours' => $customer->tradeProfile->operating_hours,
                'motiv_user' => $customer->tradeProfile->motiv_user,
                'delivery_method' => match ($customer->tradeProfile->delivery_method) {
                    'resq_hub' => 'ResQ Hub', 'own_delivery' => 'Own Delivery', default => null
                },
                'ulab' => $customer->tradeProfile->ulab,
                'profile_data' => $customer->tradeProfile->profile_data,
            ] : null,
            'category_histories' => $customer->categoryHistories->map(fn ($history) => [
                'year' => $history->category_year,
                'profile_type' => $history->profile_type,
                'stream' => $history->stream,
                'category' => $history->category,
            ])->all(),
            'profile' => $profile ? [
                'registered_name' => $profile->registered_name,
                'owner_name' => $profile->owner_name,
                'classification' => $profile->classification,
                'mobile' => $profile->mobile,
                'submitted_at' => $profile->created_at->diffForHumans(),
            ] : null,

            'brands' => $brands->map(fn (CustomerBrand $b) => [
                'material_group' => $b->materialGroup?->name ?? '—',
                'brand' => $b->brand?->name ?? $b->brand_other ?? '—',
                'quantity' => $b->quantity,
            ])->all(),

            'category' => $category ? [
                'category' => $category->category?->name,
                'sub_category' => $category->subCategory?->name,
            ] : null,

            'notes' => $notes->map(fn (CustomerNote $n) => [
                'title' => $n->title,
                'body' => $n->body,
                'created_at' => $n->created_at->diffForHumans(),
            ])->all(),

            'visits' => $visits->map(fn (Salescall $s) => [
                'date' => $s->visit_date->format('M j, Y'),
                'status' => $s->status,
                'visited_by' => $s->createdBy?->name ?? '—',
            ])->all(),

            'photo_count' => $photoCount,
        ];
    }

    public function closeCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->customerDetail = [];
        $this->showPhotos = false;
        $this->customerPhotos = [];
        $this->dispatch('customer-modal-closed');
    }

    /**
     * Separate from viewCustomer() on purpose — photos are binary files, so this
     * only runs (and only downloads thumbnails) if the user explicitly expands
     * the Photos section, capped at PHOTO_LIMIT regardless of how many exist.
     */
    public function loadCustomerPhotos(string $customerId): void
    {
        $customerId = $this->validatedCustomerId($customerId);
        $this->showPhotos = true;

        $this->customerPhotos = SalescallImage::whereHas('salescall', fn ($q) => $q->where('customer_id', $customerId))
            ->with('type')
            ->latest('created_at')
            ->limit(self::PHOTO_LIMIT)
            ->get()
            ->map(fn (SalescallImage $img) => [
                'id' => $img->id,
                'type' => $img->type?->name ?? '—',
            ])
            ->all();
    }

    private function validatedCustomerId(string $customerId): int
    {
        $validatedId = filter_var($customerId, FILTER_VALIDATE_INT);

        abort_if($validatedId === false, 404);

        return $validatedId;
    }
}
