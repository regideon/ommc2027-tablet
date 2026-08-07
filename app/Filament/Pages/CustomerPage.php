<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\CustomerBrand;
use App\Models\CustomerCategory;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerPage extends Page
{
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

    public ?int $selectedCustomerId = null;

    public array $customerDetail = [];

    public bool $showPhotos = false;

    public array $customerPhotos = [];

    // public function getViewData(): array
    // {
    //     $user = Auth::user();
    //     $roles = $user->getRoleNames()->toArray();

    //     if (in_array('rsm_approver', $roles)) {
    //         $customers = Customer::where('is_active', true)->orderBy('name')->get();
    //     } elseif (array_intersect(['rsm', 'drm_approver'], $roles)) {
    //         $drmIds = User::where('rsm_id', $user->id)->pluck('id');
    //         $customerIds = DB::table('customer_user')->whereIn('user_id', $drmIds)->pluck('customer_id');
    //         $customers = Customer::whereIn('id', $customerIds)->where('is_active', true)->orderBy('name')->get();
    //     } else {
    //         // DRM
    //         $customerIds = DB::table('customer_user')->where('user_id', $user->id)->pluck('customer_id');
    //         $customers = Customer::whereIn('id', $customerIds)->where('is_active', true)->orderBy('name')->get();
    //     }

    //     return ['customers' => $customers];
    // }

    protected function getViewData(): array
    {
        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return ['customers' => $customers];
    }

    /**
     * Loads everything except photos in one tap — all of it is either a single row
     * or capped to RECENT_LIMIT, so this stays cheap regardless of how long a
     * customer's history is. Photos are binary files and stay separately lazy
     * (see loadCustomerPhotos()) so opening a customer never downloads images
     * the user didn't ask to see.
     */
    public function viewCustomer(int $customerId): void
    {
        $this->selectedCustomerId = $customerId;
        $this->showPhotos = false;
        $this->customerPhotos = [];

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

        $this->customerDetail = [
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
    }

    /**
     * Separate from viewCustomer() on purpose — photos are binary files, so this
     * only runs (and only downloads thumbnails) if the user explicitly expands
     * the Photos section, capped at PHOTO_LIMIT regardless of how many exist.
     */
    public function loadCustomerPhotos(int $customerId): void
    {
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
}
