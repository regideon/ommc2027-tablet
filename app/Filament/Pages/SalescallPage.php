<?php

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerBrand;
use App\Models\CustomerProfile;
use App\Models\MaterialGroup;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallCategory;
use App\Models\SalescallImage;
use App\Models\SalescallImageCategory;
use App\Models\SalescallImageType;
use App\Models\SalescallStatus;
use App\Models\SubCategory;
use App\Services\SyncService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Native\Mobile\Facades\Geolocation;

/**
 * TODOS
DRM In Change Profile if no form then ma'am karla auto approve.
DRM If partially completed or cancel/void, reason textbox.
RSM AI generated, edit of RSM flag salescall.
RSM cancel/void, reason textbox.
Email notification for sir Ricky approval of RSM itinerary.
 */
class SalescallPage extends Page
{
    protected string $view = 'filament.pages.salescall-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $navigationLabel = 'Sales Calls';

    protected static ?string $title = '';

    protected static ?int $navigationSort = 100;

    public ?int $pendingCheckInId = null;

    public ?int $preselectedId = null;

    public array $callPhotos = [];

    public array $currentProfile = [];

    public array $currentBrands = [];

    public array $currentCategory = [];

    public bool $hasSavedBrands = false;

    public bool $photosComplete = false;

    public function mount(): void
    {
        $this->preselectedId = (int) request()->get('call') ?: null;

        if (function_exists('nativephp_call')) {
            Geolocation::requestPermissions()->get();
        }
    }

    public function loadPhotos(int $salescallId): void
    {
        $images = SalescallImage::with('type.category')
            ->where('salescall_id', $salescallId)
            ->orderBy('created_at', 'desc')
            ->get();

        $this->callPhotos = $images->map(fn($img) => [
            'id' => $img->id,
            'url' => '/salescall-image/' . $img->id,
            'type' => $img->type?->name ?? '—',
            'category' => $img->type?->category?->name ?? '—',
        ])->all();

        $this->photosComplete = $this->allPhotoTypesCovered($images->pluck('salescall_image_type_id'));
    }

    private function allPhotoTypesCovered(Collection $coveredTypeIds): bool
    {
        $totalTypes = SalescallImageType::count();

        if ($totalTypes === 0) {
            return true;
        }

        return $coveredTypeIds->filter()->unique()->count() >= $totalTypes;
    }

    public function saveImage(int $salescallId, int $typeId, string $base64Data): void
    {
        $raw = preg_replace('#^data:image/\w+;base64,#i', '', $base64Data);
        $filename = 'salescall_images/' . \Str::uuid() . '.jpg';

        Storage::disk('local')->put($filename, base64_decode($raw));

        $fullPath = Storage::disk('local')->path($filename);
        [$latitude, $longitude] = $this->extractPhotoGps($fullPath);

        SalescallImage::create([
            'salescall_id' => $salescallId,
            'salescall_image_type_id' => $typeId,
            'local_path' => $fullPath,
            'local_uuid' => (string) \Str::uuid(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'sync_status' => 'pending',
        ]);

        $this->loadPhotos($salescallId);
    }

    public function deleteImage(int $imageId): void
    {
        $image = SalescallImage::findOrFail($imageId);
        $salescallId = $image->salescall_id;

        if ($image->local_path && file_exists($image->local_path)) {
            @unlink($image->local_path);
        }

        $image->delete();

        $this->loadPhotos($salescallId);

        Notification::make()->title('Photo deleted.')->success()->send();
    }

    /**
     * Reads GPS coordinates from a photo's EXIF data, if present. Camera-captured
     * and gallery-picked photos typically retain EXIF GPS when location access
     * was granted to the source app; screenshots or re-encoded images won't have it.
     *
     * @return array{0: ?float, 1: ?float} [latitude, longitude]
     */
    private function extractPhotoGps(string $path): array
    {
        if (! function_exists('exif_read_data')) {
            return [null, null];
        }

        $exif = @exif_read_data($path);

        if (! $exif || empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) {
            return [null, null];
        }

        $latitude = $this->gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
        $longitude = $this->gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');

        return [$latitude, $longitude];
    }

    private function gpsToDecimal(array $coordinate, string $hemisphere): float
    {
        $degrees = isset($coordinate[0]) ? $this->gpsFractionToFloat($coordinate[0]) : 0.0;
        $minutes = isset($coordinate[1]) ? $this->gpsFractionToFloat($coordinate[1]) : 0.0;
        $seconds = isset($coordinate[2]) ? $this->gpsFractionToFloat($coordinate[2]) : 0.0;

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        return in_array(strtoupper($hemisphere), ['S', 'W'], true) ? -$decimal : $decimal;
    }

    private function gpsFractionToFloat(string $value): float
    {
        $parts = explode('/', $value);

        if (count($parts) < 2 || (float) $parts[1] === 0.0) {
            return (float) $parts[0];
        }

        return (float) $parts[0] / (float) $parts[1];
    }

    public function initiateCheckIn(int $salescallId): void
    {
        $activeElsewhere = Salescall::where('created_by', auth()->id())
            ->where('id', '!=', $salescallId)
            ->whereNotNull('actual_in')
            ->whereNull('actual_out')
            ->exists();

        if ($activeElsewhere) {
            Notification::make()->title('Finish your current visit before starting another.')->danger()->send();

            return;
        }

        $this->pendingCheckInId = $salescallId;

        Salescall::findOrFail($salescallId)->update([
            'actual_in' => now(),
            'sync_status' => 'pending',
        ]);

        $this->requestGpsCapture('checkin-' . $salescallId, 'use-browser-geolocation', $salescallId);
    }

    public function loadBrands(int $salescallId): void
    {
        $rows = SalescallBrand::where('salescall_id', $salescallId)->get();

        $this->hasSavedBrands = $rows->isNotEmpty();

        $this->currentBrands = collect(range(1, 5))->mapWithKeys(function ($groupId) use ($rows) {
            $groupRows = $rows->where('material_group_id', $groupId)->values();

            $items = $groupRows->isEmpty()
                ? [['brand_id' => null, 'quantity' => null, 'brand_other' => '']]
                : $groupRows->map(fn($r) => [
                    'brand_id' => $r->brand_id,
                    'quantity' => $r->quantity,
                    'brand_other' => $r->brand_other ?? '',
                ])->values()->all();

            return [$groupId => $items];
        })->all();
    }

    public function saveBrands(int $salescallId, array $groups): void
    {
        $salescall = Salescall::findOrFail($salescallId);

        SalescallBrand::where('salescall_id', $salescallId)->delete();
        CustomerBrand::where('customer_id', $salescall->customer_id)->delete();

        foreach ($groups as $materialGroupId => $rows) {
            foreach ($rows as $row) {
                if (empty($row['brand_id'])) {
                    continue;
                }

                $quantity = $row['quantity'] !== '' && $row['quantity'] !== null ? $row['quantity'] : null;
                $brandOther = $row['brand_other'] ?: null;

                SalescallBrand::create([
                    'salescall_id' => $salescallId,
                    'customer_id' => $salescall->customer_id,
                    'material_group_id' => (int) $materialGroupId,
                    'brand_id' => $row['brand_id'],
                    'quantity' => $quantity,
                    'brand_other' => $brandOther,
                    'local_uuid' => (string) \Str::uuid(),
                    'sync_status' => 'pending',
                ]);

                CustomerBrand::create([
                    'customer_id' => $salescall->customer_id,
                    'material_group_id' => (int) $materialGroupId,
                    'brand_id' => $row['brand_id'],
                    'quantity' => $quantity,
                    'brand_other' => $brandOther,
                    'last_salescall_id' => $salescallId,
                    'last_updated_by' => auth()->id(),
                ]);
            }
        }

        $this->hasSavedBrands = SalescallBrand::where('salescall_id', $salescallId)->exists();

        Notification::make()->title('Brands saved.')->success()->send();
    }

    public function loadCategories(int $salescallId): void
    {
        $existing = SalescallCategory::where('salescall_id', $salescallId)->first();

        $this->currentCategory = [
            'category_id' => $existing?->category_id,
            'sub_category_id' => $existing?->sub_category_id,
        ];
    }

    public function saveCategory(int $salescallId, int $categoryId, int $subCategoryId, bool $silent = false): void
    {
        $salescall = Salescall::with('customer')->findOrFail($salescallId);

        $record = SalescallCategory::firstOrNew(['salescall_id' => $salescallId]);

        if (! $record->local_uuid) {
            $record->local_uuid = (string) \Str::uuid();
        }

        $record->fill([
            'customer_id' => $salescall->customer_id,
            'category_id' => $categoryId,
            'sub_category_id' => $subCategoryId,
            'sync_status' => 'pending',
            'approval_status' => 'initial_approval',
        ]);

        $record->save();

        $this->ensureCustomerProfileExists($salescall, $subCategoryId);

        if (! $silent) {
            Notification::make()->title('Category saved.')->success()->send();
        }
    }

    /**
     * The rsm -> change_profile_final_approver approval trail lives on
     * CustomerProfile, but the full profile form only renders (and only gets
     * submitted via saveProfile()) when the chosen sub-category has with_form
     * = 1. Saving a category must always leave a CustomerProfile row behind —
     * minimal defaults when there's no form — so there's something for that
     * approval flow to attach to either way. Never overwrites an already-
     * filled-in profile, only keeps its sub_category_id current.
     */
    private function ensureCustomerProfileExists(Salescall $salescall, int $subCategoryId): void
    {
        $profile = CustomerProfile::firstOrNew(['salescall_id' => $salescall->id]);

        if ($profile->exists) {
            $profile->update(['sub_category_id' => $subCategoryId]);

            return;
        }

        $profile->local_uuid = (string) \Str::uuid();

        $profile->fill([
            'sub_category_id' => $subCategoryId,
            'registered_name' => $salescall->customer?->name ?? '',
            'owner_name' => '',
            'address' => $salescall->customer?->address ?? '',
            'mobile' => $salescall->customer?->contact_number ?? '',
            'sync_status' => 'pending',
        ]);

        $profile->save();
    }

    #[On('nativephp-checkin-complete')]
    public function onCheckinComplete(int $salescallId): void
    {
        $this->dispatch('checkin-done', salescallId: $salescallId);
    }

    public function checkIn(int $salescallId, float $lat, float $lng, bool $isOnline = false): void
    {
        Salescall::findOrFail($salescallId)->update([
            'latitude_actual_in' => $lat ?: null,
            'longitude_actual_in' => $lng ?: null,
            'sync_status' => 'pending',
        ]);

        if ($isOnline) {
            $this->runSync();
        }
    }

    /**
     * Ends the visit with the given outcome. Writes actual_out synchronously
     * (mirroring initiateCheckIn's actual_in) so the outcome is never lost to
     * a slow or failed GPS fix; the GPS listener/finishLocation() only backfill
     * the exit coordinates afterwards.
     */
    public function initiateFinish(int $salescallId, string $outcome, ?string $reason = null): void
    {
        $statusName = match ($outcome) {
            'completed' => SalescallStatus::COMPLETED,
            'partially_completed' => SalescallStatus::PARTIALLY_COMPLETED,
            'cancelled' => SalescallStatus::CANCELLED,
            default => null,
        };

        if (! $statusName) {
            Notification::make()->title('Unknown visit outcome.')->danger()->send();

            return;
        }

        if ($outcome === 'cancelled' && blank($reason)) {
            Notification::make()->title('Please provide a reason for cancelling this visit.')->danger()->send();

            return;
        }

        if ($outcome === 'completed' && ! $this->canSubmitSalescall($salescallId)) {
            Notification::make()->title('Save brands and upload at least one photo per category before submitting.')->danger()->send();

            return;
        }

        Salescall::findOrFail($salescallId)->update([
            'actual_out' => now(),
            'salescall_status_id' => SalescallStatus::idFor($statusName),
            'outcome_reason' => $reason,
            'sync_status' => 'pending',
        ]);

        $this->requestGpsCapture('submit-' . $salescallId, 'use-browser-geolocation-submit', $salescallId);

        $this->dispatch('finish-done', salescallId: $salescallId);
    }

    /**
     * GPS capture is best-effort — the salescall's core state is already
     * persisted by the time this runs. If the native bridge throws (permission
     * denied, hardware unavailable, etc.) it must never fail the whole request,
     * or events dispatched after it (like 'finish-done') would never fire and
     * the UI would be left stuck waiting.
     */
    private function requestGpsCapture(string $requestId, string $browserFallbackEvent, int $salescallId): void
    {
        if (! function_exists('nativephp_call')) {
            $this->dispatch($browserFallbackEvent, salescallId: $salescallId);

            return;
        }

        try {
            Geolocation::getCurrentPosition()
                ->fineAccuracy()
                ->id($requestId)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('GPS capture request failed: ' . $e->getMessage());
        }
    }

    public function finishLocation(int $salescallId, float $lat, float $lng): void
    {
        Salescall::findOrFail($salescallId)->update([
            'latitude_actual_out' => $lat ?: null,
            'longitude_actual_out' => $lng ?: null,
        ]);
    }

    private function canSubmitSalescall(int $salescallId): bool
    {
        return SalescallBrand::where('salescall_id', $salescallId)->exists()
            && $this->allPhotoTypesCovered(
                SalescallImage::where('salescall_id', $salescallId)->pluck('salescall_image_type_id')
            );
    }

    public function syncNow(): void
    {
        $this->runSync();
    }

    /**
     * Called automatically when the device regains connectivity. Only pushes
     * pending data once the measured upload speed clears the configured
     * minimum, so a weak signal doesn't stall or fail mid-sync.
     */
    public function autoSyncIfFast(): void
    {
        $sync = app(SyncService::class);

        if (! $sync->hasPendingChanges()) {
            $this->dispatch('auto-sync-skipped', reason: 'nothing-pending');

            return;
        }

        $mbps = $sync->measureSpeedMbps();
        $minMbps = (float) config('sync.min_speed_mbps', 10);

        if ($mbps === null || $mbps < $minMbps) {
            $this->dispatch('auto-sync-skipped', reason: 'slow-connection', mbps: $mbps);

            return;
        }

        $this->dispatch('auto-sync-started');

        $result = app(SyncService::class)->push();

        $this->dispatch('auto-sync-done', success: $result->success);

        if (! $result->success) {
            Notification::make()->title($result->message)->danger()->send();
        }
    }

    public function pullNow(): void
    {
        $result = app(SyncService::class)->pull();

        if ($result->success) {
            $this->dispatch('pull-done');
        } else {
            $this->dispatch('pull-failed');
            Notification::make()->title($result->message)->danger()->send();
        }
    }

    public function loadProfile(int $salescallId): void
    {
        $salescall = Salescall::with('customer')->findOrFail($salescallId);
        $existing = CustomerProfile::where('salescall_id', $salescallId)->first();

        $this->currentProfile = [
            'sub_category_id' => $existing?->sub_category_id,
            'registered_name' => $existing?->registered_name ?? $salescall->customer?->name ?? '',
            'owner_name' => $existing?->owner_name ?? '',
            'address' => $existing?->address ?? $salescall->customer?->address ?? '',
            'tin' => $existing?->tin ?? '',
            'landline' => $existing?->landline ?? '',
            'mobile' => $existing?->mobile ?? $salescall->customer?->contact_number ?? '',
            'classification' => $existing?->classification ?? '',
            'incentive_type' => $existing?->incentive_type ?? 'lumpsum_monthly',
            'birthday' => $existing?->birthday?->format('Y-m-d') ?? '',
            'gender' => $existing?->gender ?? '',
            'marital_status' => $existing?->marital_status ?? '',
            'brand_products' => $existing?->brand_products ?? [],
            'has_signature' => ! empty($existing?->signature_path),
        ];
    }

    public function saveProfile(
        int $salescallId,
        ?int $subCategoryId,
        string $registeredName,
        string $ownerName,
        string $address,
        ?string $tin,
        ?string $landline,
        ?string $mobile,
        ?string $classification,
        ?string $incentiveType,
        ?string $birthday,
        ?string $gender,
        ?string $maritalStatus,
        array $brandProducts,
        ?string $signatureData,
    ): void {
        $profile = CustomerProfile::firstOrNew(['salescall_id' => $salescallId]);

        if (! $profile->local_uuid) {
            $profile->local_uuid = (string) \Str::uuid();
        }

        $profile->fill([
            'sub_category_id' => $subCategoryId,
            'registered_name' => $registeredName,
            'owner_name' => $ownerName,
            'address' => $address,
            'tin' => $tin ?: null,
            'landline' => $landline ?: null,
            'mobile' => $mobile ?: null,
            'classification' => $classification ?: null,
            'incentive_type' => $incentiveType ?: null,
            'birthday' => $birthday ?: null,
            'gender' => $gender ?: null,
            'marital_status' => $maritalStatus ?: null,
            'brand_products' => $brandProducts ?: null,
            'sync_status' => 'pending',
            'sync_attempts' => 0,
        ]);

        if ($signatureData) {
            $raw = preg_replace('#^data:image/\w+;base64,#i', '', $signatureData);
            $filename = 'customer_profiles/' . \Str::uuid() . '.png';
            Storage::disk('local')->put($filename, base64_decode($raw));
            $profile->signature_path = Storage::disk('local')->path($filename);
        }

        $profile->save();

        Notification::make()->title('Profile saved.')->success()->send();
    }

    private function runSync(): void
    {
        $result = app(SyncService::class)->push();

        $this->dispatch('sync-done');

        if (! $result->success) {
            Notification::make()->title($result->message)->danger()->send();
        }
    }

    protected function getViewData(): array
    {
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $calls = Salescall::with(['customer', 'salescallStatus'])
            ->where('created_by', auth()->id())
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('actual_in', [$monthStart, $monthEnd])
                    ->orWhereBetween('visit_date', [$monthStart, $monthEnd]);
            })
            ->orderByRaw('COALESCE(actual_in, visit_date) ASC')
            ->get()
            ->values()
            ->map(function (Salescall $call) use ($weekStart, $weekEnd) {
                $visitDate = $call->visit_date;

                $filterGroup = match (true) {
                    $visitDate->isToday() => 'today',
                    $visitDate->between($weekStart, $weekEnd) => 'week',
                    default => 'month',
                };

                return [
                    'id' => $call->id,
                    'seq' => $call->id,
                    'local_uuid' => $call->local_uuid,
                    'name' => $call->customer->name ?? '—',
                    'unique_id' => $call->customer->unique_id ?? '',
                    'location' => $call->customer->address ?? '',
                    'lat' => $call->customer->latitude ?? null,
                    'lng' => $call->customer->longitude ?? null,
                    'time' => $visitDate->format('h:i A'),
                    'date_label' => $visitDate->format('D, M j'),
                    'status' => $call->status,
                    'sync_status' => $call->sync_status,
                    'filter_group' => $filterGroup,
                ];
            });

        $imageCategories = SalescallImageCategory::with('types:id,salescall_image_category_id,name,slug')
            ->orderBy('sort')
            ->get(['id', 'name', 'slug'])
            ->map(fn($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'types' => $cat->types->map(fn($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            ]);

        return [
            'callsJson' => $calls->toJson(),
            'firstId' => $calls->first()['id'] ?? null,
            'materialGroupsJson' => MaterialGroup::orderBy('name')->get(['id', 'name'])->toJson(),
            'brandsJson' => Brand::where('enabled', true)->orderBy('name')->get(['id', 'material_group_id', 'name'])->toJson(),
            'preselectedId' => $this->preselectedId,
            'imageCategoriesJson' => $imageCategories->toJson(),
            'categoriesJson' => Category::orderBy('name')->get(['id', 'name'])->toJson(),
            'subCategoriesJson' => SubCategory::orderBy('name')->get(['id', 'category_id', 'name', 'with_form'])->toJson(),
        ];
    }
}
