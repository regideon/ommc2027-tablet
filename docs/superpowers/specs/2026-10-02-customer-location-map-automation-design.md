# Customer Location Map Automation — Design

- **Date:** 2026-10-02
- **Work ID:** `OMMC-20261002-customer-location-map-automation`
- **Status:** Design — awaiting Human review of this spec
- **Repositories:** Portal `ommc2027` (Filament admin) and Tablet `ommc2027-tablet`
  (cross-repository Work, same ID in both)
- **State:** `PLANNED` (implementation not authorized yet)

## Objective

Replace the manual Customer location hierarchy inputs in **both** the portal
admin and the tablet with a single map-driven location. The rep or admin pins or
drags the marker (Grab-like); the pin sets the customer's **latitude**,
**longitude**, and **address**. The existing physical/commercial hierarchy is
resolved best-effort from the coordinates and saved for sync/reporting, but is
not user-editable anywhere.

## Human approval

Approved 2026-10-02: remove the manual location controls **entirely**, in admin
and tablet alike; align both; the map is the only input. Only `latitude`,
`longitude`, and `address` materially matter for the customer location.

## Current state (evidence)

- Portal already persists on `customers`: `region_specific_id`, `province_id`,
  `municipality_id`, `barangay_id`, `area_cluster_id`, `address`, `latitude`,
  `longitude`. Reference tables: `regions` (19), `region_specifics`,
  `provinces`, `municipalities` (1,643), `barangays` (42,010), `area_clusters`
  (44).
- Portal form `app/Filament/Resources/Customers/Schemas/CustomerForm.php`
  (lines 94–184): Address textarea plus manual selects for `physical_region_id`
  (Region), `region_specific_id` (Specific Region), `area_cluster_id`,
  `province_id`, `municipality_id`, `barangay_id`, and latitude/longitude inputs.
- Portal sync pull already exposes `area_clusters` and `barangays`
  (`app/Http/Controllers/Api/SyncController.php` lines 170–175). Push validates
  `area_cluster_id`, `province_id`, `barangay_id` (lines 485–489) and persists
  them with `?? null` (lines 638–642) — an absent field currently **nulls** the
  stored value.
- Tablet `customers` has only `region_specific_id`, `municipality_id`,
  `address`, `latitude`, `longitude`. Tablet pull ignores province/barangay/area
  cluster (`app/Services/SyncService.php` lines 308–335); push sends only
  `region_specific_id`, `municipality_id`, `address`, `latitude`, `longitude`
  (lines 707–750).
- Tablet form `resources/views/filament/pages/customer-create-page.blade.php`
  (lines 22–45): manual Region / Specific Region / Province / City-Municipality
  selects, latitude/longitude inputs, and disabled Barangay/Area Cluster
  placeholders.

## Accepted decisions

1. Both repos: remove all manual location controls — Region, Specific Region,
   Province, City/Municipality, Barangay, Area Cluster, and the Address
   textarea.
2. The Leaflet map is the only location input. Pin click/drag and "Use Current
   Location" set latitude/longitude.
3. On a coordinate change, reverse-geocode with **OpenStreetMap Nominatim** and
   auto-fill the address plus a best-effort hierarchy.
4. Resolved values are persisted and synced. The tablet gains the missing
   reference tables and customer columns to mirror the portal; the portal needs
   no new columns.
5. Offline or geocode failure: show "Internet connection required for
   location." and keep the last saved values — never erase them.
6. Area Cluster stays scoped by Specific Region and is auto-selected only when
   unambiguous; otherwise it stays null. No manual fallback anywhere (accepted).
7. No portal schema/data change beyond a defensive push fix. No data backfill.

## Architecture and components

### `PhilippineAddressResolver` (one per repository)

Input: latitude, longitude. Output: a result with the resolved IDs, a composed
street/address string, and a distinct failure reason.

- Calls `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=..&lon=..&zoom=18&addressdetails=1`
  with a descriptive `User-Agent` (usage-policy compliance).
- Normalizes names (case, punctuation, accents, leading "City of" /
  "Municipality of" / "Barangay"/"Brgy") and matches against local reference
  rows:
  - **Region** — `regions.name` / `psgc_code`, from `state` / `region`.
  - **Province** — `provinces.name`, from `county` / `state_district`, scoped by
    the resolved region.
  - **City/Municipality** — `municipalities.name`, from `city` / `municipality`
    / `town`, scoped by the resolved province/region.
  - **Barangay** — `barangays.name`, from `suburb` / `village` /
    `neighbourhood` / `quarter` / `hamlet`, scoped by the resolved
    municipality.
  - **Specific Region** — `region_specifics.name`, by normalized match to the
    resolved political region.
  - **Area Cluster** — only when the resolved Specific Region has exactly one
    enabled Area Cluster; otherwise null.
  - **Street / address** — `house_number` + `road`; fallback to a trimmed
    `display_name` (max 500 characters).
- **Fail-closed:** any component that does not match exactly one row resolves to
  null; nothing is guessed.
- Distinct error signal for offline/timeout/non-200 so the UI can show the
  "Internet connection required for location." message.

### Portal admin (`ommc2027`)

- `CustomerForm`: remove the Address textarea and the six location selects.
  Replace with the Leaflet map plus a **read-only** resolved-location display
  (Region, Specific Region, Area Cluster, Province, City/Municipality,
  Barangay, Address). The persisted fields remain in form state as hidden
  dehydrated state, shown only through read-only placeholders; no editable
  control remains.
- `resources/views/filament/schemas/components/customer-location-map.blade.php`:
  on pin change, call a Livewire action (`resolveLocation`) with the
  coordinates so the server-side resolver updates the state.
- `SyncController::pushCustomer`: preserve existing `region_specific_id`,
  `area_cluster_id`, `province_id`, `municipality_id`, and `barangay_id` when a
  request omits them (use `array_key_exists`), so a partial tablet push cannot
  null reconciled portal data.

### Tablet (`ommc2027-tablet`)

- New migrations mirroring the portal: `barangays` and `area_clusters` tables,
  plus nullable indexed `province_id`, `barangay_id`, `area_cluster_id` on
  `customers`. Follow the tablet's existing unsigned-big-integer, no-FK
  convention.
- `SyncService::pull`: upsert `barangays` and `area_clusters`; map
  `province_id`, `barangay_id`, and `area_cluster_id` into the customer upsert.
- `SyncService::push`: send `province_id`, `barangay_id`, and `area_cluster_id`
  when locally known, so resolved and previously-pulled values stay in parity.
- `customer-create-page.blade.php` (shared by create and edit): remove the
  manual location controls and the address textarea; keep the existing map modal
  as the only location input; show read-only resolved values; show the offline
  message on failure.
- `CustomerCreatePage` / `CustomerEditPage`: add a `resolveLocation(lat, lng)`
  action that calls the resolver and updates state.
- `CustomerProfileFormService::hydrate` / `saveAggregate`: carry the new fields.
- `CustomerPage` view: display the resolved Province, Barangay, and Area
  Cluster.

## Data flow

1. The user opens "Pick on Map", moves the pin, or taps "Use Current Location".
2. The map JS sets latitude/longitude and calls `resolveLocation`.
3. The resolver reverse-geocodes and updates the address and any resolvable
   hierarchy fields.
4. Save persists the customer; sync pushes the values; the portal validates and
   stores them.
5. Offline: step 3 reports failure; the UI shows "Internet connection required
   for location." while existing values remain untouched.

## Error handling

- Geocode failure → no field is overwritten; the offline message is shown.
- Ambiguous or unknown component → that component stays null (never guessed).
- Portal push omitting location fields → existing values preserved.
- Tablet push with unresolved hierarchy → sends the locally stored (last-pulled)
  value to preserve parity.

## Testing

- **Resolver** feature tests with `Http::fake`: a known locality maps every
  component; an ambiguous name yields null; missing keys yield null; HTTP
  failure/offline yields the error signal.
- **Portal form** test: the manual location controls and Address textarea are
  absent; the resolve action populates the state.
- **Portal push** test: absent location keys do not null existing values.
- **Tablet** tests: migrations add the tables/columns; pull stores the new
  reference tables and customer foreign keys; push sends the new foreign keys;
  the form has no manual controls; the offline message renders.
- Run the affected suites, `pint` on changed PHP, and `php artisan view:cache`.

## Non-goals

- No changes to the portal customer view/export beyond what already exists.
- No offline boundary polygons or offline geocoding.
- No province→Area Cluster mapping.
- No data backfill or reconciliation.

## Risks and open questions

- With no manual controls anywhere, Area Cluster (and often Specific Region)
  stay null for tablet-created or geocode-unresolvable customers. Accepted.
- Address becomes non-editable; a poor reverse-geocode result cannot be
  corrected in-app. Accepted because latitude/longitude are the source of truth.
- Nominatim name matching is heuristic; fail-closed avoids bad data but can
  leave fields null.
- If only latitude/longitude/address truly matter, the best-effort hierarchy
  resolution could be dropped to reduce scope — flagged for the reviewer.

## Cross-repository workflow

- Same Work ID and materially equivalent Work notes in both repositories.
- Portal changes happen on an isolated worktree/branch
  (`1-tablet-customer-module-location`) because the portal is currently mid-work
  on `4-be-ai-schedule-policy`.
- Both repositories must be ready before delivery. No commit or push without
  separate explicit terminal authorization.

## Manual acceptance

- **Portal admin:** editing a customer shows only the map plus read-only
  location; moving the pin updates address and coordinates; offline shows the
  message.
- **Tablet:** same behavior; a created/edited customer syncs latitude, longitude,
  address, and any resolved hierarchy to the portal without wiping existing
  portal values.

## Revision 2026-10-06 � dropdowns restored (offline fallback)

The Human revised the location-input decision. Manual controls are restored and remain editable; the map still resolves and fills them.

- Admin and tablet: Region, Specific Region, Province, City/Municipality, Barangay, Area Cluster are dropdowns, and Address is an editable field. Latitude/Longitude are editable and nullable.
- Offline: the dropdowns are the fallback; no blocking error. The map shows a soft hint when a lookup needs internet.
- Online + map pick: the pin reverse-geocodes and sets those dropdowns/address via `resolveLocation`, and the user can adjust them afterward.
- Data, sync, resolver, models, and validations are unchanged. The tablet now renders real Barangay and Area Cluster dropdowns (previously disabled placeholders), filtered by the selected City/Municipality and Specific Region.
