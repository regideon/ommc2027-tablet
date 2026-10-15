# Sync Process: Tablet ↔ Portal

**Tablet file:** `app/Services/SyncService.php`  
**Portal files:** `app/Http/Controllers/Api/Sync/` (per-module controllers)

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

- **Kapag:** Login, manual sync tap, o after login
- **File (tablet):** `app/Services/SyncService.php`
- **File (portal):** `app/Http/Controllers/Api/Sync/` (per-module controllers)

**Mga route (per-module — hindi na isang solong combined pull route):**

| Route | Tablet method | Kinukuha |
|-------|---------------|----------|
| `GET /api/schedule/pull` | `pull()` | Approved itineraries + salescalls + lookup tables |
| `GET /api/locations/pull` | `pullLocations()` | Location references (regions → barangays); minsan lang kapag wala pa sa device |
| `GET /api/customers/pull` | `pullCustomersStep()` | Customer list, paged (100 kada page) |
| `GET /api/notifications/pull` | `pullNotifications()` | Notifications, paged, may `since` watermark |

### Anong Data ang Kinukuha ng Tablet?

Bawat route ay may sariling payload — wala nang isang malaking pull response:

- **`schedule/pull`** — ang approved itineraries ng user kasama ang salescalls at ang maliliit na lookup tables (regions, statuses, material groups, brands, categories, etc.).
- **`locations/pull`** — location reference snapshot (regions pababa sa barangays); minsan lang kinukuha kapag wala pa sa device (`location_reference_snapshot_complete`).
- **`customers/pull`** — ang customer list, paged (100 kada page); may per-user run at watermark (`customers.pulled_through.<user>`).
- **`notifications/pull`** — ang in-app notifications ng naka-login na user, paged gamit ang `since` watermark.

Ang role-based filtering ng customers (DRM / RSM / VP) ay nasa Portal side ng `schedule/pull` at `customers/pull`.

### Paano Sino-save ng Tablet ang Pull Data?

```php
// File: app/Services/SyncService.php (TABLET) — pull() / pullCustomersStep() methods

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
Tablet                                  Portal
  │                                        │
  │  GET /api/schedule/pull                │
  │  GET /api/locations/pull               │
  │  GET /api/customers/pull               │
  │  GET /api/notifications/pull           │
  │  Authorization: Bearer {token}         │
  │ ─────────────────────────────────────→ │
  │                                        │ I-check ang role
  │                                        │ DRM / RSM / VP
  │                                        │ Filter ng customers
  │  JSON kada route: schedule, locations, │
  │       customers (paged), notifications │
  │ ←───────────────────────────────────── │
  │                                        │
  │ I-save sa local SQLite                 │
  │ (updateOrCreate para walang dupe)      │
```

---

## PUSH — Tablet → Portal (single record)

- **Kapag:** Pagkatapos ng local changes (Customer create/edit, notes) — sa sync o sa manual push action.
- **File (tablet):** `app/Services/Sync/Push/CustomerPushService.php`
- **File (portal):** `app/Http/Controllers/Api/Sync/CustomerController.php` → `App\Services\Sync\TabletCustomerPushService`

**Isang record kada request.** Walang envelope, walang queue:

1. I-claim ng tablet ang row (`sync_status = syncing`).
2. `POST /api/customers/sync` kasama ang flat Customer item.
3. I-apply ang outcome; kapag failure, ibalik ang dating status.

| Outcome | Ibig Sabihin | Gawin ng Tablet |
|---|---|---|
| `200` | Na-apply; flat acknowledgment kasama ang `customer_snapshot` | I-reconcile locally, i-mark na `synced` |
| `409` | `{code: "customer_conflict"}` — nagbago ang Portal row (`base_updated_at` mismatch) | I-mark na `conflict`, kailangan ng review |
| `422` | `{message, errors}` validation/domain rejection | I-record ang failure; retryable habang attempts < 3 |
| `401` | Expired ang token | Itigil ang run, mag-login ulit |

**Sync statuses:** `pending`, `syncing`, `synced`, `failed`, `conflict`.
Walang `queued` state.

**Mga pull route (per-module):** `GET /api/schedule/pull`,
`/api/locations/pull`, `/api/customers/pull`, `/api/notifications/pull`.

**Iba pang push:** hindi nagbago ang itinerary, salescall, forms, expense, at attachment push; nasa endpoint table sa ibaba ang buong listahan.

---

## Sync Status — Tracking ng Bawat Record

Bawat table na kailangan ng sync ay may `sync_status` column:

| Status | Ibig Sabihin |
|--------|-------------|
| `pending` | Bagong data sa tablet, hindi pa napapunta sa portal |
| `syncing` | Na-claim na ang record para sa push; saglit lang habang tumatakbo ang request |
| `synced` | Na-sync na sa portal, updated na ang server_id |
| `failed` | Sinubukang i-push pero may error |
| `conflict` | `409 customer_conflict` — nagbago ang portal row; kailangan ng manual review |

Walang `queued` state — synchronous ang push, hindi naka-queue.

At `sync_attempts` column para sa retry limit (max 3 tries):

```
sync_status = 'pending'    → I-push sa next sync
sync_status = 'syncing'    → Nasa push na; kung hindi natapos, ibabalik sa 'failed'
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
Mag-upload ng actual file → POST /api/salescalls/push-image
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
        ->post("{$this->serverUrl}/api/salescalls/push-image", [...]);
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
        ->post("{$this->serverUrl}/api/speedtest");
    $elapsed = microtime(true) - $start;

    // Calculate Mbps
    return (($bytes * 8) / $elapsed) / 1_000_000;
}
```

---

## Buod ng Lahat ng API Endpoints

| Direction | Method | URL | Ginagawa |
|-----------|--------|-----|----------|
| Pull | `GET` | `/api/schedule/pull` | Approved itineraries + salescalls + lookup tables |
| Pull | `GET` | `/api/locations/pull` | Location references (regions → barangays) |
| Pull | `GET` | `/api/customers/pull` | Customer list (paged) |
| Pull | `GET` | `/api/notifications/pull` | Notifications (paged, may `since` watermark) |
| Push | `POST` | `/api/customers/sync` | Customer create/edit — isang flat record kada request; `200` / `409 customer_conflict` / `422` / `401` |
| Push | `POST` | `/api/itineraries/push` | Itinerary na ginawa sa tablet |
| Push | `POST` | `/api/salescalls/push` | Salescall (check-in/out data) |
| Push | `POST` | `/api/salescalls/push-brands` | Brands na nire-record per visit |
| Push | `POST` | `/api/salescalls/push-category` | Category/sub-category ng customer |
| Push | `POST` | `/api/salescalls/push-image` | Photo files (multipart) |
| Push | `POST` | `/api/expenses/push` | Expense records |
| Push | `POST` | `/api/expenses/push-attachment` | Expense attachments (multipart) |
| Push | `POST` | `/api/customer-profiles/push` | Customer profile form |
| Push | `POST` | `/api/customer-profiles/push-attachment` | Customer profile attachments (multipart) |
| Push | `POST` | `/api/customer-notes/push` | Quick notes ng DRM |
| Push | `POST` | `/api/customer-notes/delete` | Delete ng note |
| Push | `POST` | `/api/base-locations/push` | Base location ng user |
| Speed | `POST` | `/api/speedtest` | Sukatin ang upload speed |

---

## Important: local_uuid vs server_id

```
TABLET                           PORTAL
local_uuid = "uuid-abc-123"  →  server_id = 42  (portal's auto-increment ID)
```

- **`local_uuid`** — Ginawa ng tablet, unique identifier para sa offline tracking
- **`server_id`** — ID ng record sa portal database (nakuha pagkatapos ng push)

Ginagamit ang `local_uuid` para ma-match ang tablet record sa portal record kapag nag-push — kaya kahit maraming beses mag-push ng pareho, `updateOrCreate` lang (hindi magdo-duplicate).
