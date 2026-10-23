# OMMC-20261023-customer-location-map-search

- **State:** READY_FOR_TERMINAL_REVIEW
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Human authorized implementation of place/address search in the customer location map for the Admin portal and Tablet. No commit, push, deployment, database write, migration, seeder, or Customer Push/Pull change is authorized. Terminal delivery requires separate explicit Human authorization.
- **Objective:** Add a general OpenStreetMap Nominatim place/address search to the customer location map in Admin and Tablet. Selecting a result recenters the map, places or moves the pin, fills the coordinate fields, previews the address, and continues through the existing location resolver.

## Accepted decisions

- The search is a general worldwide place/address search with no country filter.
- Searching runs on Enter or button activation and automatically after one second of typing once the query has at least three characters.
- The result list is capped at five entries, and any in-flight search is aborted when a newer one starts.
- Selecting a result uses the existing Leaflet map and Livewire coordinate state; no backend proxy, API key, or new dependency is introduced.
- The Admin map is inline, so the search sits directly above the map. The Tablet search sits inside the existing "Pin Customer Location" popup.
- Nominatim attribution and offline error handling are shown in the picker.

## Affected and owned files

- Tablet: `resources/views/filament/pages/customer-create-page.blade.php`, `tests/Feature/CustomerLocationPickerTest.php`, and this note.
- Portal: `resources/views/filament/schemas/components/customer-location-map.blade.php`, `tests/Feature/CustomerLocationMappingTest.php`, and this note.
- No database tables/rows, seeders, migrations, Customer Push/Pull, or deployment are changed.

## Unrelated changes to preserve

- The modified `docs/work/OMMC-20261023-customer-location-map-name-resolution.md` in both repositories belongs to prior authorized resolver work and must not be altered by this Work.

## Validation plan

- Watch the new focused tests fail before implementation in both repositories.
- Run the focused Tablet and Portal test files after implementation.
- Run `vendor/bin/pint --dirty --format agent` and `git diff --check` in both repositories.

## Implementation

- Tablet adds a search field and results list inside the "Pin Customer Location" popup. Selecting a result sets the coordinates, moves the marker, centers the map, and previews the result's display name; the existing "Use This Location" action still performs resolution.
- Portal adds the same search above the inline customer location map. Selecting a result sets the Livewire coordinate state, moves the marker, centers the map, and calls the existing `resolveLocation` action.
- Both use `https://nominatim.openstreetmap.org/search` with `format=jsonv2`, `limit=5`, and `addressdetails=1`, a one-second debounce, three-character minimum, and aborted in-flight requests.
- Search accepts a full address string as typed and displays the provider's full `display_name` for each result.
- Follow-up correction: result panels float absolutely over the map instead of participating in the layout, and the Tablet map minimum height is reduced to 10rem so the footer buttons remain visible.
- Follow-up correction: result panels use an explicit `z-index: 1001` so they paint above Leaflet panes and map controls.
- Follow-up correction: the Tablet picker prefills the search with the current Address field and writes the selected result's full display name into the Address field immediately.
- Admin UI correction: the Location section now uses Filament input/button components and inline layout styles, so it renders correctly regardless of app Tailwind compilation. The search row stays horizontal, the map is fixed at 24rem with a rounded border, and the results panel uses Filament dropdown classes for theme-aware styling.
- Search state resets when the Tablet popup closes; the Portal map keeps its query while the form remains mounted.
- No backend endpoint, dependency, database, or sync contract changed.

## Validation

- Watched both new tests fail before implementation for the missing search UI, and watched the follow-up floating/full-address/z-index assertions fail before the correction.
- Tablet `tests/Feature/CustomerLocationPickerTest.php`: 3 passed, 24 assertions; with `CustomerLocationPersistenceTest`, 5 passed and 30 assertions.
- Portal `tests/Feature/CustomerLocationMappingTest.php` plus `tests/Feature/CustomerMapLocationAutomationTest.php`: 19 passed, 110 assertions; the focused search test alone passes with 30 assertions.
- Portal `php artisan view:cache`: Blade templates cached successfully.
- `vendor/bin/pint --dirty --format agent` and `git diff --check` passed in both repositories.
- Tablet adjacent suites `CustomerLocationMapTest`, `CustomerLocationPickerTest`, `CustomerLocationPersistenceTest`, and `CustomerLocationSaveTest`: 7 passed, 3 failed, 42 assertions. The same three failures were reproduced with this Work's files stashed: two stale expected address strings in `CustomerLocationMapTest` and one required-field validation failure in `CustomerLocationSaveTest`. They are pre-existing and unrelated.

## Remaining work

- Manual UI acceptance on Admin and Tablet requires a browser with Internet access; not performed in this session.
- Terminal delivery (commit/push) requires separate explicit Human authorization.

## State history

- 2026-10-23: `ACTIVE` — implementation authorized and started.
- 2026-10-23: `READY_FOR_TERMINAL_REVIEW` — implementation and focused validation complete; terminal delivery not authorized.
- 2026-10-23: `ACTIVE` — follow-up correction requested for floating results, hidden footer buttons, and full-address handling.
- 2026-10-23: `READY_FOR_TERMINAL_REVIEW` — floating results, footer visibility, full-address handling, and explicit z-index implemented and validated; terminal delivery not authorized.
- 2026-10-23: `READY_FOR_TERMINAL_REVIEW` — Admin Location UI rebuilt with Filament components and inline layout; terminal delivery not authorized.
