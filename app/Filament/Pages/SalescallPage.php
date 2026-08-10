<?php

namespace App\Filament\Pages;

use App\Listeners\HandleLocationReceived;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerBrand;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use App\Models\Itinerary;
use App\Models\MaterialGroup;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallCategory;
use App\Models\SalescallImage;
use App\Models\SalescallImageCategory;
use App\Models\SalescallImageType;
use App\Models\SalescallStatus;
use App\Models\SalescallType;
use App\Models\SubCategory;
use App\Services\SyncService;
use App\Support\NativeMediaPath;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Facades\Camera;
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

    public array $customerNotes = [];

    public bool $hasSavedBrands = false;

    public bool $photosComplete = false;

    public ?int $pendingPhotoSalescallId = null;

    public ?int $pendingPhotoTypeId = null;

    /**
     * Pending native captures keyed by the capture ID delivered back on
     * PhotoTaken / MediaSelected / PhotoCancelled / PermissionDenied events.
     * Each entry is ['salescall_id' => int, 'type_id' => int].
     *
     * @var array<string, array{salescall_id: int, type_id: int}>
     */
    public array $pendingPhoto = [];

    public function mount(): void
    {
        $this->preselectedId = (int) request()->get('call') ?: null;
    }

    public function loadPhotos(?int $salescallId): void
    {
        if (! $salescallId) {
            return;
        }
        $images = SalescallImage::with('type.category')
            ->where('salescall_id', $salescallId)
            ->orderBy('created_at', 'desc')
            ->get();

        $this->callPhotos = $images->map(fn ($img) => [
            'id' => $img->id,
            'url' => $this->previewUrlFor($img),
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

    /**
     * Quick Notes are customer-level (not visit-level) and private to the author —
     * written once, then visible on every current/future salescall for that same
     * customer, regardless of which visit they were jotted down during.
     */
    public function loadCustomerNotes(int $customerId): void
    {
        $this->customerNotes = CustomerNote::where('customer_id', $customerId)
            ->where('created_by', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (CustomerNote $note) => [
                'id' => $note->id,
                'title' => $note->title,
                'body' => $note->body,
                'created_at' => $note->created_at->diffForHumans(),
            ])
            ->all();
    }

    public function saveCustomerNote(int $customerId, ?string $title, string $body): void
    {
        $body = trim($body);

        if ($body === '') {
            return;
        }

        $existingCount = CustomerNote::where('customer_id', $customerId)
            ->where('created_by', auth()->id())
            ->count();

        if ($existingCount >= CustomerNote::MAX_PER_CUSTOMER_PER_USER) {
            Notification::make()
                ->title('Note limit reached — max '.CustomerNote::MAX_PER_CUSTOMER_PER_USER.' notes per customer. Delete an old one first.')
                ->danger()
                ->send();

            return;
        }

        CustomerNote::create([
            'customer_id' => $customerId,
            'created_by' => auth()->id(),
            'title' => filled($title) ? trim($title) : null,
            'body' => $body,
            'local_uuid' => (string) \Str::uuid(),
            'sync_status' => 'pending',
        ]);

        $this->loadCustomerNotes($customerId);
        Notification::make()->title('Note saved.')->success()->send();
    }

    public function updateCustomerNote(int $noteId, ?string $title, string $body): void
    {
        $note = CustomerNote::where('id', $noteId)->where('created_by', auth()->id())->first();
        $body = trim($body);

        if (! $note || $body === '') {
            return;
        }

        $note->update([
            'title' => filled($title) ? trim($title) : null,
            'body' => $body,
            'sync_status' => 'pending',
        ]);

        $this->loadCustomerNotes($note->customer_id);
        Notification::make()->title('Note updated.')->success()->send();
    }

    public function deleteCustomerNote(int $noteId): void
    {
        $note = CustomerNote::where('id', $noteId)->where('created_by', auth()->id())->first();

        if (! $note) {
            return;
        }

        $customerId = $note->customer_id;

        if ($note->server_id) {
            app(SyncService::class)->pushCustomerNoteDelete($note->local_uuid);
        }

        $note->delete();

        $this->loadCustomerNotes($customerId);
        Notification::make()->title('Note deleted.')->success()->send();
    }

    public function takePhoto(int $salescallId, int $typeId): void
    {
        $this->pendingPhotoSalescallId = $salescallId;
        $this->pendingPhotoTypeId = $typeId;

        if (function_exists('nativephp_call')) {
            $capture = Camera::getPhoto();

            $this->pendingPhoto[$capture->getId()] = [
                'salescall_id' => $salescallId,
                'type_id' => $typeId,
            ];

            $capture->start();
        }
    }

    public function pickFromGallery(int $salescallId, int $typeId): void
    {
        $this->pendingPhotoSalescallId = $salescallId;
        $this->pendingPhotoTypeId = $typeId;

        if (function_exists('nativephp_call')) {
            $picker = Camera::pickImages('image')->single();

            $this->pendingPhoto[$picker->getId()] = [
                'salescall_id' => $salescallId,
                'type_id' => $typeId,
            ];

            $picker->start();
        }
    }

    /**
     * TEMP: diagnostic sink for the photo-flow instrumentation in salescall-page.blade.php.
     * Only invoked when window.__photoServerLog is enabled; writes a Laravel log line.
     *
     * @param  array<string, mixed>  $state
     */
    public function logPhotoFlow(array $state): void
    {
        Log::info('[photoflow]', $state);
    }

    #[On('native:'.PhotoTaken::class)]
    public function onPhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        [$salescallId, $typeId] = $this->resolvePendingPhotoContext($id);

        if (! $salescallId || ! $typeId) {
            return;
        }

        $this->saveImageFromPath($path, $salescallId, $typeId);
    }

    #[On('native:'.MediaSelected::class)]
    public function onMediaSelected(bool $success, array $files = [], int $count = 0, ?string $error = null, bool $cancelled = false, ?string $id = null): void
    {
        [$salescallId, $typeId] = $this->resolvePendingPhotoContext($id);

        if ($cancelled) {
            return;
        }

        if (! $success || empty($files) || ! $salescallId || ! $typeId) {
            Notification::make()
                ->title($error ?: 'Could not import the selected photo.')
                ->danger()
                ->send();

            return;
        }

        $path = NativeMediaPath::resolve($files[0]);

        if ($path === null) {
            Notification::make()
                ->title('Selected photo path is invalid.')
                ->danger()
                ->send();

            return;
        }

        $this->saveImageFromPath($path, $salescallId, $typeId);
    }

    #[On('native:'.PhotoCancelled::class)]
    public function onPhotoCancelled(bool $cancelled = true, ?string $id = null): void
    {
        if ($id !== null) {
            unset($this->pendingPhoto[$id]);

            return;
        }

        $this->pendingPhoto = [];
        $this->pendingPhotoSalescallId = null;
        $this->pendingPhotoTypeId = null;
    }

    #[On('native:'.PermissionDenied::class)]
    public function onCameraPermissionDenied(string $action = 'photo', ?string $id = null): void
    {
        if ($id !== null) {
            unset($this->pendingPhoto[$id]);
        } else {
            $this->pendingPhoto = [];
            $this->pendingPhotoSalescallId = null;
            $this->pendingPhotoTypeId = null;
        }

        Notification::make()
            ->title('Camera permission is required to take photos.')
            ->danger()
            ->send();
    }

    /**
     * Resolve which salescall + photo type an incoming native event belongs to.
     * ID-keyed pending context is preferred whenever the event carries a capture
     * ID; the legacy single-slot pending props are only used when no ID is
     * available (older builds, Jump mode, direct test calls).
     *
     * @return array{0: ?int, 1: ?int} [salescallId, typeId]
     */
    private function resolvePendingPhotoContext(?string $id): array
    {
        if ($id !== null && isset($this->pendingPhoto[$id])) {
            $context = $this->pendingPhoto[$id];
            unset($this->pendingPhoto[$id]);

            return [$context['salescall_id'], $context['type_id']];
        }

        if ($id !== null) {
            return [null, null];
        }

        $salescallId = $this->pendingPhotoSalescallId;
        $typeId = $this->pendingPhotoTypeId;
        $this->pendingPhotoSalescallId = null;
        $this->pendingPhotoTypeId = null;

        return [$salescallId, $typeId];
    }

    private function saveImageFromPath(string $sourcePath, int $salescallId, int $typeId): void
    {
        $resolvedPath = NativeMediaPath::resolve($sourcePath) ?? $sourcePath;

        if (! is_file($resolvedPath)) {
            Log::warning('Sales call photo source file missing', ['path' => $resolvedPath]);
            Notification::make()
                ->title('Photo file was not available. Please try again.')
                ->danger()
                ->send();

            return;
        }

        $ext = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)) ?: 'jpg';
        $filename = 'salescall_images/'.\Str::uuid().'.'.$ext;

        Storage::disk('local')->makeDirectory('salescall_images');
        $fullPath = Storage::disk('local')->path($filename);

        if (! @copy($resolvedPath, $fullPath)) {
            Log::warning('Sales call photo copy failed', ['from' => $resolvedPath, 'to' => $fullPath]);
            Notification::make()
                ->title('Could not save the photo.')
                ->danger()
                ->send();

            return;
        }

        [$latitude, $longitude] = $this->extractPhotoGps($fullPath);

        $image = SalescallImage::create([
            'salescall_id' => $salescallId,
            'salescall_image_type_id' => $typeId,
            'local_path' => $fullPath,
            'local_uuid' => (string) \Str::uuid(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'sync_status' => 'pending',
        ]);

        $this->mirrorPhotoForPreview($image);

        $this->loadPhotos($salescallId);
    }

    public function saveImage(int $salescallId, int $typeId, string $base64Data): void
    {
        $raw = preg_replace('#^data:image/\w+;base64,#i', '', $base64Data);
        $filename = 'salescall_images/'.\Str::uuid().'.jpg';

        Storage::disk('local')->put($filename, base64_decode($raw));

        $fullPath = Storage::disk('local')->path($filename);
        [$latitude, $longitude] = $this->extractPhotoGps($fullPath);

        $image = SalescallImage::create([
            'salescall_id' => $salescallId,
            'salescall_image_type_id' => $typeId,
            'local_path' => $fullPath,
            'local_uuid' => (string) \Str::uuid(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'sync_status' => 'pending',
        ]);

        $this->mirrorPhotoForPreview($image);

        $this->loadPhotos($salescallId);
    }

    public function deleteImage(int $imageId): void
    {
        $image = SalescallImage::findOrFail($imageId);
        $salescallId = $image->salescall_id;

        if ($image->local_path && file_exists($image->local_path)) {
            @unlink($image->local_path);
        }

        $this->deletePreviewMirror($image);

        $image->delete();

        $this->loadPhotos($salescallId);

        Notification::make()->title('Photo deleted.')->success()->send();
    }

    /**
     * Deterministic, collision-safe preview mirror relative to the web root.
     * Keyed on the persisted photo's local_uuid (unique per record) plus the
     * extension of its canonical storage file — never on a random filename, so
     * two captures with the same source basename still get distinct previews
     * and re-mirroring always lands on the same path.
     */
    private function previewMirrorRelativePath(SalescallImage $image): string
    {
        $ext = strtolower(pathinfo($image->local_path, PATHINFO_EXTENSION)) ?: 'jpg';
        $uuid = $image->local_uuid ?: (string) $image->id;

        return 'salescall_images/'.$uuid.'.'.$ext;
    }

    /**
     * Copy a persisted photo's canonical file into the web root so the native
     * binary-safe `/_assets/...` handlers can stream it. Returns the relative
     * web path on success, null when the source is missing or the copy fails.
     */
    private function mirrorPhotoForPreview(SalescallImage $image): ?string
    {
        $relative = $this->previewMirrorRelativePath($image);

        if (! $image->local_path || ! is_file($image->local_path)) {
            return null;
        }

        try {
            File::ensureDirectoryExists(public_path('salescall_images'));
        } catch (\Throwable) {
            return null;
        }

        if (@copy($image->local_path, public_path($relative)) === false) {
            return null;
        }

        return $relative;
    }

    private function deletePreviewMirror(SalescallImage $image): void
    {
        $relative = $this->previewMirrorRelativePath($image);

        if (is_file(public_path($relative))) {
            @unlink(public_path($relative));
        }
    }

    /**
     * Build the preview URL for a photo, self-healing a missing mirror from the
     * canonical storage file when possible. Falls back to the Laravel route
     * (web + any platform without a binary-safe asset handler).
     */
    private function previewUrlFor(SalescallImage $image): string
    {
        $relative = $this->previewMirrorRelativePath($image);

        if (! is_file(public_path($relative)) && $this->mirrorPhotoForPreview($image) === null) {
            return '/salescall-image/'.$image->id;
        }

        return '/_assets/'.$relative;
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

        $this->requestGpsCapture('checkin-'.$salescallId, 'use-browser-geolocation', $salescallId);
    }

    /**
     * DRM/RSM ad-hoc visit not covered by the AI-generated monthly itinerary.
     * Attaches to the itinerary matching the chosen scheduled month (must already
     * exist locally — itineraries are only ever created via sync, never on-device)
     * and is flagged salescall_type_id = Unplanned so the portal can distinguish it
     * from AI-generated / RSM-added calls. Mirrors the portal's own "Add Customer"
     * flow on /ommcpanel/my-itineraries/{id} (ManageSalesItinerary::getHeaderActions()),
     * which also collects a "Scheduled At" datetime.
     *
     * @return array<string, mixed>|null shape matches getViewData()'s $calls map, for the
     *                                   caller to push straight into Alpine's `calls` array
     */
    public function createUnplannedSalescall(int $customerId, string $scheduledAt, bool $isOnline = false): ?array
    {
        $scheduled = Carbon::parse($scheduledAt);

        $itinerary = Itinerary::where('created_by', auth()->id())
            ->whereYear('date_month', $scheduled->year)
            ->whereMonth('date_month', $scheduled->month)
            ->first();

        if (! $itinerary) {
            Notification::make()
                ->title("No itinerary found for {$scheduled->format('F Y')} yet — pull the latest schedule from the server first.")
                ->danger()
                ->send();

            return null;
        }

        $customer = Customer::find($customerId);

        if (! $customer) {
            return null;
        }

        $salescall = Salescall::create([
            'itinerary_id' => $itinerary->id,
            'customer_id' => $customer->id,
            'salescall_type_id' => SalescallType::idFor(SalescallType::UNPLANNED),
            'salescall_status_id' => SalescallStatus::idFor(SalescallStatus::PENDING),
            'visit_date' => $scheduled,
            'route_start_at' => $scheduled,
            'created_by' => auth()->id(),
            'local_uuid' => (string) \Str::uuid(),
            'sync_status' => 'pending',
        ]);

        Notification::make()->title('Unplanned salescall added.')->success()->send();

        if ($isOnline) {
            $this->runSync();
        }

        return [
            'id' => $salescall->id,
            'seq' => $salescall->id,
            'local_uuid' => $salescall->local_uuid,
            'customer_id' => $customer->id,
            'name' => $customer->name ?? '—',
            'unique_id' => $customer->unique_id ?? '',
            'location' => $customer->address ?? '',
            'lat' => $customer->latitude ?? null,
            'lng' => $customer->longitude ?? null,
            'time' => $scheduled->format('h:i A'),
            'date_label' => $scheduled->format('D, M j'),
            'status' => 'scheduled',
            'type' => SalescallType::UNPLANNED,
            'sync_status' => 'pending',
            'filter_group' => $scheduled->isToday() ? 'today' : ($scheduled->between(Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()) ? 'week' : 'month'),
        ];
    }

    public function loadBrands(int $salescallId): void
    {
        $rows = SalescallBrand::where('salescall_id', $salescallId)->get();

        $this->hasSavedBrands = $rows->isNotEmpty();

        $this->currentBrands = collect(range(1, 5))->mapWithKeys(function ($groupId) use ($rows) {
            $groupRows = $rows->where('material_group_id', $groupId)->values();

            $items = $groupRows->isEmpty()
                ? [['brand_id' => null, 'quantity' => null, 'brand_other' => '']]
                : $groupRows->map(fn ($r) => [
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
            // Message reflects only the active requirement below (brands) — photos
            // are temporarily optional, see canSubmitSalescall().
            Notification::make()->title('Save brands before submitting.')->danger()->send();

            return;
        }

        Salescall::findOrFail($salescallId)->update([
            'actual_out' => now(),
            'salescall_status_id' => SalescallStatus::idFor($statusName),
            'outcome_reason' => $reason,
            'sync_status' => 'pending',
        ]);

        $this->requestGpsCapture('submit-'.$salescallId, 'use-browser-geolocation-submit', $salescallId);

        $this->dispatch('finish-done', salescallId: $salescallId, outcome: $outcome);
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
            Log::warning('GPS capture request failed: '.$e->getMessage());
        }
    }

    /**
     * Native geolocation results arrive via Livewire (`native:` + event class),
     * matching the working camera/gallery pattern. The vendor
     * `_native/api/events` JSON path uses Request::get(), which does not read
     * JSON bodies, so Event::listen alone never persists coordinates.
     */
    #[On('native:'.LocationReceived::class)]
    public function onLocationReceived(
        bool $success,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $accuracy = null,
        ?int $timestamp = null,
        ?string $provider = null,
        ?string $error = null,
        ?string $id = null,
    ): void {
        app(HandleLocationReceived::class)->handle(new LocationReceived(
            $success,
            $latitude,
            $longitude,
            $accuracy,
            $timestamp,
            $provider,
            $error,
            $id,
        ));
    }

    public function finishLocation(int $salescallId, float $lat, float $lng, bool $isOnline = false): void
    {
        Salescall::findOrFail($salescallId)->update([
            'latitude_actual_out' => $lat ?: null,
            'longitude_actual_out' => $lng ?: null,
        ]);

        if ($isOnline) {
            $this->runSync();
        }
    }

    private function canSubmitSalescall(int $salescallId): bool
    {
        // Photo requirement temporarily disabled (optional for now) — uncomment
        // to re-enable "photo in every subcategory" as a submit requirement:
        // && $this->allPhotoTypesCovered(
        //     SalescallImage::where('salescall_id', $salescallId)->pluck('salescall_image_type_id')
        // );
        return SalescallBrand::where('salescall_id', $salescallId)->exists();
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

        if ($result->success) {
            $this->refreshCallsSyncStatus();
        } else {
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
            $filename = 'customer_profiles/'.\Str::uuid().'.png';
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

        if ($result->success) {
            $this->refreshCallsSyncStatus();
        } else {
            Notification::make()->title($result->message)->danger()->send();
        }
    }

    private function refreshCallsSyncStatus(): void
    {
        $statuses = Salescall::where('created_by', auth()->id())
            ->pluck('sync_status', 'id')
            ->toArray();

        $this->dispatch('calls-sync-refreshed', statuses: $statuses);
    }

    protected function getViewData(): array
    {
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $calls = Salescall::with(['customer', 'salescallStatus', 'salescallType'])
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
                    'customer_id' => $call->customer_id,
                    'name' => $call->customer->name ?? '—',
                    'unique_id' => $call->customer->unique_id ?? '',
                    'location' => $call->customer->address ?? '',
                    'lat' => $call->customer->latitude ?? null,
                    'lng' => $call->customer->longitude ?? null,
                    'time' => $visitDate->format('h:i A'),
                    'date_label' => $visitDate->format('D, M j'),
                    'status' => $call->status,
                    'type' => $call->salescallType?->name,
                    'sync_status' => $call->sync_status,
                    'filter_group' => $filterGroup,
                ];
            });

        $imageCategories = SalescallImageCategory::with('types:id,salescall_image_category_id,name,slug')
            ->orderBy('sort')
            ->get(['id', 'name', 'slug'])
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'types' => $cat->types->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            ]);

        return [
            'callsJson' => $calls->toJson(),
            'firstId' => $calls->first(fn ($c) => $c['status'] === 'in_progress')['id'] ?? $calls->first()['id'] ?? null,
            'materialGroupsJson' => MaterialGroup::orderBy('name')->get(['id', 'name'])->toJson(),
            'brandsJson' => Brand::where('enabled', true)->orderBy('name')->get(['id', 'material_group_id', 'name'])->toJson(),
            'preselectedId' => $this->preselectedId,
            'imageCategoriesJson' => $imageCategories->toJson(),
            'categoriesJson' => Category::orderBy('name')->get(['id', 'name'])->toJson(),
            'subCategoriesJson' => SubCategory::orderBy('name')->get(['id', 'category_id', 'name', 'with_form'])->toJson(),
            'customersJson' => Customer::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'unique_id', 'address', 'latitude', 'longitude'])->toJson(),
            'canAddSalescall' => auth()->user()?->hasAnyRole(['drm', 'rsm']) ?? false,
        ];
    }
}
