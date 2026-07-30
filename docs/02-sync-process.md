# Sync Process: Tablet ↔ Portal

**Tablet file:** `app/Services/SyncService.php`  
**Portal file:** `app/Http/Controllers/Api/SyncController.php`

---

## Ano ang Sync?

Ang sistema ay may **dalawang database**:
- **Portal** (ommc2027) → MySQL, nandito ang master data
- **Tablet** (ommc2027-tablet) → SQLite, local copy para makapag-trabaho offline

Ang **sync** ay ang proseso ng pag-transfer ng data sa pagitan nila — **PULL** (portal → tablet) at **PUSH** (tablet → portal).

```
PORTAL (MySQL)                    TABLET (SQLite)
ommc2027                          ommc2027-tablet
    │                                   │
    │ ←─────── PULL ──────────────────  │  Tablet kumuha ng data mula portal
    │                                   │
    │ ─────── PUSH ───────────────────→ │  Tablet nagpadala ng bagong data sa portal
```

---

## PULL — Portal → Tablet

**Kapag:** Login, manual sync tap, o after login  
**File (tablet):** `app/Services/SyncService.php` → `pull()`  
**File (portal):** `app/Http/Controllers/Api/SyncController.php` → `pull()`  
**Route:** `GET /api/sync/pull`

### Anong Data ang Kinukuha ng Tablet?

```php
// File: app/Http/Controllers/Api/SyncController.php (PORTAL)
public function pull(Request $request): JsonResponse
{
    $user = $request->user();
    $roles = $user->getRoleNames()->toArray();

    // --- ROLE-BASED CUSTOMER FILTERING ---
    // Hindi lahat ng customers ay nakikita ng lahat ng users

    if (in_array('rsm_approver', $roles)) {
        // RSM Approver / VP — nakikita LAHAT ng customers
        $customers = Customer::with('branches')->where('is_active', true)->get();

    } elseif (array_intersect(['rsm', 'drm_approver'], $roles)) {
        // RSM — nakikita lang ang customers ng kanyang mga DRM
        $drmIds = User::where('rsm_id', $user->id)->pluck('id');
        $customerIds = DB::table('customer_user')->whereIn('user_id', $drmIds)->pluck('customer_id')->unique();
        $customers = Customer::with('branches')->whereIn('id', $customerIds)->where('is_active', true)->get();

    } else {
        // DRM — nakikita lang ang sariling customers
        $customerIds = DB::table('customer_user')->where('user_id', $user->id)->pluck('customer_id');
        $customers = Customer::with('branches')->whereIn('id', $customerIds)->where('is_active', true)->get();
    }

    return response()->json([
        'customers'              => $customers,
        'regions'                => Region::all(),
        'region_specifics'       => RegionSpecific::all(),
        'itinerary_statuses'     => ItineraryStatus::all(),
        'salescall_statuses'     => SalescallStatus::all(),
        'salescall_types'        => SalescallType::all(),
        'material_groups'        => MaterialGroup::all(),
        'brands'                 => Brand::where('enabled', true)->get(),
        'categories'             => Category::all(),
        'sub_categories'         => SubCategory::all(),
        'sub_sub_categories'     => SubSubCategory::all(),
        'customer_brands'        => CustomerBrand::whereIn('customer_id', $customers->pluck('id'))->get(),
        'customer_categories'    => CustomerCategory::whereIn('customer_id', $customers->pluck('id'))->get(),
        'customer_notes'         => CustomerNote::where('created_by', $user->id)->get(),
        'salescall_image_categories' => SalescallImageCategory::orderBy('sort')->get(),
        'salescall_image_types'      => SalescallImageType::orderBy('sort')->get(),

        // APPROVED itineraries lang — kasama ang salescalls at brands
        'itineraries' => Itinerary::with(
                'salescalls.customer',
                'salescalls.salescallBrands',
                'salescalls.salescallCategory'
            )
            ->where('created_by', $user->id)
            ->where('itinerary_status_id', ItineraryStatus::idFor(ItineraryStatus::APPROVED))
            ->get(),
    ]);
}
```

### Paano Sino-save ng Tablet ang Pull Data?

```php
// File: app/Services/SyncService.php (TABLET) — pull() method

// 1. ITINERARIES — i-upsert based sa local_uuid
foreach ($data['itineraries'] ?? [] as $itinerary) {
    $local = Itinerary::updateOrCreate(
        ['local_uuid' => $itinerary['local_uuid'] ?? (string) $itinerary['id']],
        [
            'server_id'           => $itinerary['id'],
            'created_by'          => $user->id,
            'date_month'          => $itinerary['date_month'] ?? null,
            'itinerary_status_id' => $itinerary['itinerary_status_id'] ?? null,
            'sync_status'         => 'synced',
        ]
    );

    // 2. SALESCALLS ng bawat itinerary
    foreach ($itinerary['salescalls'] ?? [] as $sc) {

        // IMPORTANT: Huwag i-overwrite kung may pending local changes
        // (e.g., nag-check-in na ang DRM pero hindi pa na-push sa portal)
        $hasPendingLocalChanges = Salescall::where('local_uuid', $localUuid)
            ->whereIn('sync_status', ['pending', 'failed'])
            ->exists();

        if (! $hasPendingLocalChanges) {
            $localSalescall = Salescall::updateOrCreate(
                ['local_uuid' => $localUuid],
                [
                    'server_id'          => $sc['id'],
                    'itinerary_id'       => $local->id,
                    'customer_id'        => $sc['customer_id'],
                    'visit_date'         => $sc['route_start_at'] ?? $sc['actual_in'] ?? null,
                    'actual_in'          => $sc['actual_in'] ?? null,
                    'actual_out'         => $sc['actual_out'] ?? null,
                    'salescall_status_id'=> $sc['salescall_status_id'] ?? null,
                    'outcome_reason'     => $sc['outcome_reason'] ?? null,
                    'sync_status'        => 'synced',
                ]
            );
        }
    }
}

// 3. CUSTOMERS — i-upsert based sa id
foreach ($data['customers'] ?? [] as $customer) {
    DB::table('customers')->updateOrInsert(
        ['id' => $customer['id']],
        [
            'name'       => $customer['name'],
            'unique_id'  => $customer['unique_id'] ?? null,
            'address'    => $customer['address'] ?? null,
            'latitude'   => $customer['latitude'] ?? null,
            'longitude'  => $customer['longitude'] ?? null,
            'is_active'  => $customer['is_active'] ?? true,
            'updated_at' => now(),
        ]
    );
}

// ... ganito rin para sa brands, categories, customer_notes, etc.
```

### Pull Flow Summary

```
Tablet                              Portal
  │                                    │
  │  GET /api/sync/pull                │
  │  Authorization: Bearer {token}     │
  │ ─────────────────────────────────→ │
  │                                    │ I-check ang role
  │                                    │ DRM / RSM / VP
  │                                    │ Filter ng customers
  │  JSON: customers, itineraries,     │
  │        brands, categories, etc.    │
  │ ←───────────────────────────────── │
  │                                    │
  │ I-save sa local SQLite             │
  │ (updateOrCreate para walang dupe)  │
```

---

## PUSH — Tablet → Portal

**Kapag:** May bagong data na ginawa sa tablet (itinerary, salescall, photo, etc.)  
**File (tablet):** `app/Services/SyncService.php` → `push()`  
**File (portal):** `app/Http/Controllers/Api/SyncController.php` → `push*()` methods

### Ano ang Kinokolekta at Pinapadala?

Lahat ng rows na may `sync_status = 'pending'` (o `'failed'` na may attempts < 3) ay pinapadala sa portal.

### Push Order — Mahalaga ang Sequence!

```
1. Itineraries       →  Kailangan muna bago ang salescalls
2. Salescalls        →  Kailangan muna bago ang brands/photos/etc.
3. Salescall Brands  →  Kailangan ng salescall.server_id
4. Salescall Category→  Kailangan ng salescall.server_id
5. Salescall Images  →  Kailangan ng salescall.server_id
6. Customer Profiles →  Kailangan ng salescall.server_id
7. Customer Notes    →  Pwedeng independent
```

Kung wala pang `server_id` ang parent (e.g., hindi pa na-push ang salescall), ang children (images, brands) ay **skip muna** at susubukan ulit sa susunod na push.

### Code ng Push

```php
// File: app/Services/SyncService.php (TABLET)

public function push(): SyncResult
{
    $client = $this->client($user->api_token);

    // ─── 1. ITINERARIES ───
    $pendingItineraries = Itinerary::where('sync_status', 'pending')
        ->orWhere(fn ($q) => $q->where('sync_status', 'failed')->where('sync_attempts', '<', 3))
        ->get();

    foreach ($pendingItineraries as $itinerary) {
        $response = $client->post("{$this->serverUrl}/api/sync/push/itinerary", [
            'local_uuid'          => $itinerary->local_uuid,
            'date_month'          => $itinerary->date_month,
            'itinerary_status_id' => $itinerary->itinerary_status_id,
        ]);

        if ($response->successful()) {
            // I-save ang server_id — kailangan ito ng salescalls
            $itinerary->update(['sync_status' => 'synced', 'server_id' => $response->json('server_id')]);
        }
    }

    // ─── 2. SALESCALLS ───
    $pendingSalescalls = Salescall::where('sync_status', 'pending')->get();

    foreach ($pendingSalescalls as $salescall) {
        $response = $client->post("{$this->serverUrl}/api/sync/push/salescall", [
            'local_uuid'          => $salescall->local_uuid,
            'itinerary_uuid'      => $salescall->itinerary->local_uuid,
            'itinerary_server_id' => $salescall->itinerary->server_id,
            'customer_id'         => $salescall->customer_id,
            'actual_in'           => $salescall->actual_in?->toDateTimeString(),
            'actual_out'          => $salescall->actual_out?->toDateTimeString(),
            'salescall_status_id' => $salescall->salescall_status_id,
            'outcome_reason'      => $salescall->outcome_reason,
            // ... at marami pang fields
        ]);

        if ($response->successful()) {
            $salescall->update(['sync_status' => 'synced', 'server_id' => $response->json('server_id')]);
        }
    }

    // ─── 3. SALESCALL BRANDS ───
    // Hintayin ang server_id ng parent salescall
    foreach ($pendingBrandSalescallIds as $salescallId) {
        $salescall = Salescall::find($salescallId);

        if (! $salescall?->server_id) {
            continue; // Skip — salescall hindi pa na-push
        }

        $response = $client->post("{$this->serverUrl}/api/sync/push/salescall-brands", [
            'salescall_server_id' => $salescall->server_id,
            'brands'              => $rows->map(fn ($r) => [
                'material_group_id' => $r->material_group_id,
                'brand_id'          => $r->brand_id,
                'quantity'          => $r->quantity,
            ])->all(),
        ]);
    }

    // ─── 4. SALESCALL IMAGES (photo files) ───
    foreach ($pendingImages as $image) {
        if (! $image->salescall?->server_id) {
            continue; // Skip — salescall hindi pa na-push
        }

        // I-attach ang actual image file
        $response = $client
            ->attach('image', fopen($image->local_path, 'r'), basename($image->local_path))
            ->post("{$this->serverUrl}/api/sync/push/salescall-image", [
                'local_uuid'              => $image->local_uuid,
                'salescall_server_id'     => $image->salescall->server_id,
                'salescall_image_type_id' => $image->salescall_image_type_id,
                'latitude'                => $image->latitude,
                'longitude'               => $image->longitude,
            ]);
    }

    // ─── 5. CUSTOMER PROFILES, NOTES ───
    // ... pareho ang pattern — hintay ng server_id, tapos push
}
```

### Portal Side ng Push

```php
// File: app/Http/Controllers/Api/SyncController.php (PORTAL)

// Itinerary
public function pushItinerary(Request $request): JsonResponse
{
    $itinerary = Itinerary::updateOrCreate(
        ['local_uuid' => $data['local_uuid']],
        [...$data, 'created_by' => auth()->id(), 'sync_status' => 'synced']
    );

    return response()->json(['server_id' => $itinerary->id]);
    // Ibinabalik ang server_id → tablet ang mag-save nito
}

// Salescall
public function pushSalescall(Request $request): JsonResponse
{
    // Hanapin ang parent itinerary — by local_uuid o by server_id (fallback)
    $itinerary = Itinerary::where('local_uuid', $data['itinerary_uuid'])->first()
        ?? Itinerary::find($data['itinerary_server_id']);

    $salescall = Salescall::updateOrCreate(
        ['local_uuid' => $data['local_uuid']],
        [...$data, 'itinerary_id' => $itinerary->id, 'created_by' => auth()->id()]
    );

    return response()->json(['server_id' => $salescall->id]);
}

// Photo upload
public function pushSalescallImage(Request $request): JsonResponse
{
    // I-upload ang file sa portal's storage
    $path = $request->file('image')->store('salescall_images/'.$salescall->id, 'public');

    $image = SalescallImage::updateOrCreate(
        ['local_uuid' => $data['local_uuid']],
        ['salescall_id' => $salescall->id, 'path' => $path, ...]
    );

    return response()->json(['server_id' => $image->id]);
}
```

---

## Sync Status — Tracking ng Bawat Record

Bawat table na kailangan ng sync ay may `sync_status` column:

| Status | Ibig Sabihin |
|--------|-------------|
| `pending` | Bagong data sa tablet, hindi pa napapunta sa portal |
| `synced` | Na-sync na sa portal, updated na ang server_id |
| `failed` | Sinubukang i-push pero may error |

At `sync_attempts` column para sa retry limit (max 3 tries):

```
sync_status = 'pending'    → I-push sa next sync
sync_status = 'failed'
  + sync_attempts < 3     → Subukan pa rin (retry)
  + sync_attempts >= 3    → Itigil na (manual intervention needed)
```

---

## Photo / Image Offline Handling

Kapag kumuha ng larawan ang DRM habang **offline**:

```
DRM kumuhang picture
         ↓
Na-save sa tablet storage (local file path)
Na-record sa SQLite: sync_status = 'pending'
         ↓
May internet na
         ↓
push() → Hanapin ang salescall.server_id (kailangan muna)
         ↓
Mag-upload ng actual file → POST /api/sync/push/salescall-image
         ↓
Portal nag-save ng file → ibinabalik ang server_id
         ↓
Tablet updated: sync_status = 'synced'
```

**Code:**
```php
// File: app/Services/SyncService.php
foreach ($pendingImages as $image) {
    // Skip kung hindi pa na-push ang parent salescall
    if (! $image->salescall?->server_id) {
        continue;
    }

    // Basta nandoon pa ang local file
    if (! file_exists($image->local_path)) {
        $this->markFailed($image, 'Local file not found');
        continue;
    }

    // I-upload ang file
    $response = $client
        ->attach('image', fopen($image->local_path, 'r'), basename($image->local_path))
        ->post("{$this->serverUrl}/api/sync/push/salescall-image", [...]);
}
```

---

## Conflict Resolution — Kung Nag-conflict ang Data

**Rule:** Kung may `pending` o `failed` local changes sa isang salescall, **hindi i-overwrite** ng pull ang local data.

```php
// File: app/Services/SyncService.php — pull() method
$hasPendingLocalChanges = Salescall::where('local_uuid', $localUuid)
    ->whereIn('sync_status', ['pending', 'failed'])
    ->exists();

if ($hasPendingLocalChanges) {
    // Huwag i-overwrite — ang tablet data ang mas bago
    // Hindi baguhin, hintayin matapos ang push
    continue;
}

// Safe na i-overwrite ng portal data
$localSalescall = Salescall::updateOrCreate(...);
```

**Reasoning:** Halimbawa, nag-check-in na ang DRM sa isang customer (nai-record locally ang `actual_in`), pero hindi pa na-push sa portal. Kapag nag-pull ulit, ang portal ay may `actual_in = null` pa kasi hindi pa nakita ng portal ang check-in. Kaya huwag i-overwrite — pabayaang ma-push muna ang local check-in.

---

## Speed Test — Bago Mag-sync

Bago mag-push ng mabibigat na data (lalo na mga photos), sinusukat muna ang internet speed:

```php
// File: app/Services/SyncService.php
public function measureSpeedMbps(): ?float
{
    $bytes = 300_000; // 300KB test payload
    $payload = str_repeat('0', $bytes);

    $start = microtime(true);
    $response = $this->client($user->api_token)
        ->withBody($payload, 'application/octet-stream')
        ->post("{$this->serverUrl}/api/sync/speedtest");
    $elapsed = microtime(true) - $start;

    // Calculate Mbps
    return (($bytes * 8) / $elapsed) / 1_000_000;
}
```

---

## Buod ng Lahat ng API Endpoints

| Direction | Method | URL | Ginagawa |
|-----------|--------|-----|----------|
| Pull | `GET` | `/api/sync/pull` | Kunin lahat ng data (customers, itineraries, etc.) |
| Push | `POST` | `/api/sync/push/itinerary` | Itinerary na ginawa sa tablet |
| Push | `POST` | `/api/sync/push/salescall` | Salescall (check-in/out data) |
| Push | `POST` | `/api/sync/push/salescall-brands` | Brands na nire-record per visit |
| Push | `POST` | `/api/sync/push/salescall-category` | Category/sub-category ng customer |
| Push | `POST` | `/api/sync/push/salescall-image` | Photo files (multipart) |
| Push | `POST` | `/api/sync/push/customer-profile` | Customer profile form |
| Push | `POST` | `/api/sync/push/customer-note` | Quick notes ng DRM |
| Push | `DELETE` | `/api/sync/push/customer-note` | Delete ng note |
| Speed | `POST` | `/api/sync/speedtest` | Sukatin ang upload speed |

---

## Important: local_uuid vs server_id

```
TABLET                           PORTAL
local_uuid = "uuid-abc-123"  →  server_id = 42  (portal's auto-increment ID)
```

- **`local_uuid`** — Ginawa ng tablet, unique identifier para sa offline tracking
- **`server_id`** — ID ng record sa portal database (nakuha pagkatapos ng push)

Ginagamit ang `local_uuid` para ma-match ang tablet record sa portal record kapag nag-push — kaya kahit maraming beses mag-push ng pareho, `updateOrCreate` lang (hindi magdo-duplicate).
