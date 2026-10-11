# OMMC-20261012-customer-map-location-automation

- **State:** TERMINAL_DELIVERED
- **Phase:** Terminal readiness review
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Human authorized Phase B implementation, Phase C validation, and terminal delivery. This Work does not authorize retained database changes, real mapping activation, provisioning, production access, or deployment.
- **Predecessors:** Customer Location Reference Automation, Customer Add Form Consistency, and Customer Access Mapping Foundation are terminal-delivered.

## Objective

Correct and validate Pick on Map through reverse geocoding, location mapping, form state, and Access recalculation. Preserve the existing explicit location mapping model and Customer Access contract.

## Repository baseline

At investigation start both repositories were clean on `main`, with local `HEAD` equal to `origin/main`:

- Portal: `7a1e8087a6422cd68be941316804264a536001f8`
- Tablet: `3fa421b988b1f8712a3d74376ffe7d5cd8e9e9f2`

The delivered Location Area Cluster mapping model, resolver, reference serialization/cache code, Customer map flows, and prior Work notes were present. The Location, Add Form Consistency, and Access Foundation notes record their accepted scope and remaining real-data gates.

## Verified data evidence

Read-only checks used the active local connections and did not switch databases or write data.

- Portal `SELECT DATABASE()` returned `ommc_henri_local_latest`. `location_area_cluster_mappings` does not exist in this retained schema, so the Portal resolver cannot find any location mapping rows there.
- The Tablet application's configured connection is SQLite. Its `location_area_cluster_mappings` table is also absent in that connection.
- Portal reference rows for the reported example are: Region **7** `Region IV-A (CALABARZON)`; Province **28** `Laguna`; Municipality **505** `City of Calamba`; Barangay **13567** `Punta`.
- Specific Region **53** is `Region IV-A (Laguna, Batangas, Quezon)`, parent Region 7. Area Cluster **18** is `SL - Area 1`; it is the sole enabled Area Cluster under Specific Region 53.
- Specific Region **52** is `Region IV-A (Cavite, Rizal)`, parent Region 7, with two enabled clusters (IDs **16** and **17**). This is a useful multi-cluster regression case.

These existing physical/commercial references do not establish that the Laguna mapping was applied by the map. No retained location-to-cluster assignment rows are configured. The displayed Specific Region 53 / Area Cluster 18 can be retained or manually selected state; it is not evidence of map automation.

## Current event flow and findings

### Portal

1. `resources/views/filament/schemas/components/customer-location-map.blade.php:77-80,122-126,156-159,169-175` sends map clicks, marker drags, or browser location through `resolvePin()` to Livewire `resolveLocation(latitude, longitude)`. Coordinates are written first.
2. `app/Filament/Resources/Customers/Concerns/ResolvesCustomerLocation.php:17-46` is used by both `CreateCustomer` and `EditCustomer`. It calls `PhilippineAddressResolver`, sets physical Region, then directly assigns non-null returned form fields.
3. `app/Services/PhilippineAddressResolver.php:43-90` matches Nominatim Region/Province/Municipality/Barangay tokens to stable local IDs, prefers the matched Municipality's physical parents, then calls `LocationAreaClusterMappingResolver` (`:73-75`). It returns Area Cluster and Specific Region only for a `mapped` decision.
4. `app/Services/LocationAreaClusterMappingResolver.php:27-59` returns `unmapped` if its table is missing, checks Barangay → Municipality → Province in order, and returns an explicit unresolved/invalid decision without falling back to a broader level.

The map method directly mutates `$this->data`; it does not call the Filament field setter/callback. Therefore the `CustomerForm` Specific Region `afterStateUpdated` handler (`:185-204`) that recalculates Access is not invoked by map selection. The locality mapping helper (`:372-393`) is invoked by Province/Municipality/Barangay form field handlers, not by the map trait. Edit's `beforeValidate` (`EditCustomer.php:53-74`) restores existing Access when a controller changed and the mapping is incomplete, but does not assign the complete mapping's users when the map path bypassed the field callback. This confirms a Portal map-to-Access integration gap for Add and Edit.

### Tablet

1. `resources/views/filament/pages/customer-create-page.blade.php:239-243,263-269` handles map clicks by setting coordinate fields and directly calling `resolveLocation`. Marker drag and browser geolocation also call it. The preview `lookupAddress()` (`:291-324`) is separate; Confirm calls `resolveLocation` again (`:326-334`), so a click followed by Confirm can issue duplicate reverse-geocode requests.
2. `app/Filament/Pages/CustomerCreatePage.php:280-316` calls the same `PhilippineAddressResolver`, writes its non-null commercial and physical IDs, then calls `applySpecificRegionChange()` if Specific Region changed.
3. `applySpecificRegionChange()` (`:519-550`) recalculates Access, but also resets Area Cluster to the only enabled cluster or null. For a mapped Specific Region with multiple enabled clusters, it clears the already-resolved Area Cluster. The earlier Phase C correction in `applyLocationAreaClusterMapping()` (`:318-335`) restores the mapped cluster after that recalculation, but `resolveLocation()` does not use that corrected ordering. Thus a genuine map-derived change to a multi-cluster parent can lose the mapped cluster on Tablet Add and Edit.
4. `CustomerEditPage` extends `CustomerCreatePage` and hydrates through the inherited component (`CustomerEditPage.php:12-40`); the same map method and defect apply to Edit.

### Incomplete data and partial reverse-geocode matches

- Both active local connections lack the mapping table, so the current local map resolver returns `unmapped` before consulting any keys. No automatic Area Cluster or Specific Region can be derived from these connections until the table and approved mapping rows exist.
- `PhilippineAddressResolver::match()` requires a unique matching record (`:110-140`). Municipality and Barangay may be null when geocoder tokens are absent or ambiguous. The mapping resolver then considers only the IDs that were actually matched.
- On an unresolved or unmapped result, the resolver returns null commercial IDs. Both map handlers assign only non-null commercial values, so previously selected Specific Region/Area Cluster remain visible. This preserves manual state but can make retained values look newly derived. No fallback mapping is attempted.
- Resolved in Phase B: Portal and Tablet update physical Region only when the resolver returns a valid Region ID; a partial result without a Region match preserves current physical Region state.

## Add/Edit behavior comparison

- Portal Add and Edit share the same `ResolvesCustomerLocation` trait, so both directly set form state without field callback execution. Edit has a save-time controller guard; Add does not have an equivalent map-triggered Access assignment path.
- Tablet Edit inherits the Add page's `resolveLocation()` and `applySpecificRegionChange()` behavior. Map-triggered Access recalculation occurs only when the resolved Specific Region differs from current state. The subsequent cluster reset can overwrite the mapped cluster when the target Specific Region has multiple enabled clusters.
- Manual selector changes continue to use their existing callbacks. The required same-value, manual-preservation, complete-mapping, and incomplete-mapping contracts should remain unchanged.

## Proposed minimal Phase B correction

1. Keep `PhilippineAddressResolver` and `LocationAreaClusterMappingResolver` as the only geography/mapping resolution path. Do not add tables or infer geography from cluster names or coordinates.
2. Portal: route mapped results from `resolveLocation()` through one shared form-state application path that applies Area Cluster and Specific Region and recalculates Customer Access only for a genuine Specific Region change, using the existing `CustomerAccessMappingService`. Preserve current Access for unchanged controllers and incomplete mappings; do not apply partial mappings. Keep OE/IB Company-scoped Access authoritative.
3. Tablet: in `resolveLocation()`, run Specific Region/Access recalculation before restoring the resolver's validated Area Cluster, as the existing `applyLocationAreaClusterMapping()` already does. Avoid a second address-geocoding call. Preserve the mapped cluster only when its parent matches the returned Specific Region.
4. For `unmapped`, `unresolved`, `requires_finer_location`, `invalid`, or incomplete geocoder results, preserve valid manual selections under the accepted contract and expose a clear result/warning if approved. Do not manufacture commercial IDs or apply broader fallback.
5. Keep Add/Edit behavior shared where existing traits/base classes allow; no Push/Pull or Access precedence changes.

Potential implementation files (subject to Phase B GO):

- Portal `app/Filament/Resources/Customers/Concerns/ResolvesCustomerLocation.php`; likely focused regression coverage in `tests/Feature/CustomerLocationMappingTest.php` or a new Customer map automation test.
- Tablet `app/Filament/Pages/CustomerCreatePage.php`; focused tests in a dedicated map automation test file (the existing `CustomerLocationMapTest` has two documented baseline fixture failures).
- Map Blade files only if event deduplication or result feedback is explicitly included; current callbacks already reach the server resolver.

No changes to `PhilippineAddressResolver`, mapping schema, reference snapshot, Push/Pull, or the Customer Access resolver are currently required by the observed flow.

## Regression and disposable E2E plan

Use disposable Portal MySQL and Tablet SQLite databases with synthetic location mappings and test Users only. Do not write to retained databases or activate real mappings.

| Scenario | Expected result |
|---|---|
| Laguna 28 / Calamba 505 / Punta 13567, mapped to AC 18 / SR 53 | Portal and Tablet Add/Edit map flow set exact IDs; Access recalculates only if SR changes and complete Access mapping exists. |
| Start with SR 52 / AC 16, map to Laguna SR 53 / AC 18 | Genuine controller change derives the mapped cluster and applies complete synthetic SL Access mapping; incomplete Access config preserves current Access. |
| Map to a location mapped to SR 52 (two enabled clusters, IDs 16/17) | Exact mapped Area Cluster survives Specific Region recalculation on Tablet and Portal. |
| Explicit unresolved or requires-finer-location decision at Barangay | No Province fallback; do not invent a cluster/SR; preserve supported manual values and report the decision. |
| Nominatim response missing or ambiguous Municipality/Barangay | Only uniquely matched physical IDs are set; no invalid parent chain or broader commercial fallback. Validate Portal/Tablet physical Region parity. |
| Existing manual Area Cluster, unresolved location / same controller | Preserve manual cluster and Access. |
| Add and Edit with complete mapping | Expected Specific Region, Area Cluster and complete synthetic Access are visible and persisted. |
| Add and Edit with absent/incomplete mapping | No partial/empty automatic Access replacement; existing/manual Access remains. |
| Map click then confirm on Tablet | Confirm whether duplicate reverse-geocode is retained or deduplicated without changing selection semantics. |

PASS requires exact form state and persisted references, correct canonical `customer_user` behavior, no duplicate relationships, unchanged-controller manual Access preservation, and no stale result overwrite. Real-data, production, and physical-device runs are outside this Phase A evidence.

## Risks and unresolved decisions

- Real location mapping rows are absent from the active local connections and remain unapproved. The displayed Laguna values cannot demonstrate actual automation.
- Resolved in Phase B: partial results without a matched physical Region preserve the current value consistently in both applications.
- Decide how the UI should communicate unmapped/unresolved/requires-finer-location results without implying the retained manual values came from the map.
- Tablet map click and Confirm can both call the server geocoder for the same point; whether to deduplicate is a small UX/network decision.
- No live Portal deployment, authenticated production Pull, or physical iPad validation was performed.

## Phase A disposition

Phase A investigation completed and the Human authorized the bounded Phase B implementation below.

## Phase B implementation

- **State:** READY_FOR_HUMAN_REVIEW
- **Authorization:** Bounded Phase B GO. No retained database writes, mapping activation, provisioning, deployment, staging, commit, or push.
- **Root cause:** `resolveLocation()` applied the address resolver result, then ran Specific Region/Access recalculation. That recalculation cleared Area Cluster when multiple enabled clusters belonged to the destination Specific Region, even when the location resolver had already validated the exact cluster.
- **Tablet correction:** `CustomerCreatePage::resolveLocation()` now routes mapped results through the shared `applyResolvedLocationAreaClusterMapping()` flow. It applies Specific Region and Access first, then restores the resolver-validated Area Cluster only after verifying that the cluster's parent matches that Specific Region. `applyLocationAreaClusterMapping()` reuses the same helper, and `CustomerEditPage` inherits the fix. Manual Specific Region changes continue through `applySpecificRegionChange()` and still clear invalid clusters.
- **Partial geocoder matches:** Tablet now sets physical Region only when the resolver returns a matched Region ID. A partial response with no Region match preserves existing physical Region and manual commercial selections; it does not manufacture IDs.
- **Scope:** No mapping architecture, schema, migration, map Blade, Access resolver, or Push/Pull contract changes.

### Phase B changed files

- `app/Filament/Pages/CustomerCreatePage.php`
- `tests/Feature/CustomerMapLocationAutomationTest.php`
- `docs/work/OMMC-20261012-customer-map-location-automation.md`

### Phase B validation

Tests ran with Tablet's PHPUnit configuration using SQLite `:memory:` and synthetic references, mapping cache, and test Users.

- `CustomerMapLocationAutomationTest`: **9 passed, 45 assertions**. Synthetic fixtures use the reviewed stable IDs: Region 7, Laguna 28, Calamba 505, Punta 13567, Area Cluster 18 / Specific Region 53, plus Area Clusters 16/17 under Specific Region 52. Tests include GMA 52 → SL 53, resolved cluster persistence under multi-cluster 52, explicitly unresolved location preservation, unchanged controller/manual Access, incomplete mapping preservation, OE Company precedence, partial geocoder handling, Edit inheritance, and local Add save/sync path.
- Focused `CustomerCreatePageDiagnosticTest`: **8 passed, 40 assertions**, including pre-existing manual Specific Region changes and Edit hydration behavior.
- The optional authenticated Tablet-to-Portal integration test was **skipped** because `CUSTOMER_CONTRACT_PORTAL_URL` was not configured to an isolated disposable Portal API. The local Tablet save/push path was exercised with synthetic HTTP responses; no live authenticated Portal sync was claimed.
- Existing `CustomerLocationMapTest` has two previously documented baseline fixture failures. Inspection of the pre-Work `HEAD` test and resolver confirms its fixture contains no location mapping row while the resolver requires one. The failures were not changed or bypassed; dedicated passing tests provide the Phase B regression coverage.
- No retained database writes, physical iPad validation, production access, or live authenticated synchronization occurred.

### Remaining limitations and disposition

Real location mappings and real Access assignments remain unapproved and inactive. Reference restoration/business decisions remain separate. Disposable tests establish implementation behavior only. The implementation is **READY_FOR_HUMAN_REVIEW**; Human review is required before further work or terminal delivery.

## Phase C validation

- **State:** READY_FOR_HUMAN_REVIEW
- **Authorization:** Human authorized Phase C validation only. No retained database writes, real mapping activation, production access, deployment, staging, commit, or push.
- **Regression fixture findings:** Portal `CustomerLocationMappingTest` failed because its synthetic geography lacked a `location_area_cluster_mappings` row; its fixture now maps Province 1 to Area Cluster 1. Tablet `CustomerLocationMapTest` lacked a mapping decision and a Municipality PSGC code required by the resolver. The Tablet test fixture now includes the synthetic mapping and PSGC code. No application behavior changed to satisfy these tests.
- **Fixture regression results:** Portal `CustomerLocationMappingTest`: **5 passed, 18 assertions**. Tablet `CustomerLocationMapTest`: **4 passed, 22 assertions**.
- **Disposable authenticated E2E:** A temporary Portal SQLite database and local Portal API were used with Tablet's disposable SQLite database. Portal migrations were applied only to the new disposable database. Portal and Tablet Users had distinct numeric IDs and matching emails. Authenticated reference Pull, Customer Push, Portal persistence/Pull payload, and Tablet Customer Pull executed.
- **Exact map-derived locations:** Simulated geocoder callbacks resolved Cavite to Region 7 / Province 27 / Municipality 480 / Barangay 12712 / Area Cluster 17 / Specific Region 52, then changed to Laguna / Calamba / Punta: Region 7 / Province 28 / Municipality 505 / Barangay 13567 / Area Cluster 18 / Specific Region 53. Cluster 17 survived recalculation where Specific Region 52 has multiple enabled clusters. Both Customers completed Add, Push, Portal persistence, and Tablet Pull.
- **Access E2E:** The incomplete Specific Region 55 / Area Cluster 20 case retained manual Access and its warning through Add, Push, and Pull. OE and IB Company mappings resolved in Add and their Customers completed Push/Pull with expected email identities. Same-value Specific Region update preserved manual Access. The negative-control User was excluded.
- **Persisted disposable evidence:** Five Portal Customers were created: Laguna `53 / 18 / 28 / 505 / 13567`; Cavite `52 / 17 / 27 / 480 / 12712`; incomplete mapping `55 / 20` with locality fields null; OE Company 5; IB Company 6. The Portal stored 11 Customer Access pairs for the expected GMA, SL, preserved-incomplete, OE, and IB assignments. Tablet Pull reconciled Users by email, preserved each negative local Customer ID, and produced no duplicate Customer or Access relationships.
- **Map interaction boundary:** The Livewire `resolveLocation()` callback ran with deterministic synthetic geocoder responses. No browser map click or physical iPad interaction was performed.
- **Final focused results:** Tablet map, Access, diagnostic, strict snapshot, and authenticated Portal E2E suites: **66 passed, 631 assertions**; `CustomerCreatePortalE2ETest`: **1 passed, 148 assertions**. Portal map, Access, location, mapping resolver, and synchronization suites: **78 passed, 517 assertions**. Pint and `git diff --check` passed in both repositories.
- **Work-owned inventory:** Tablet: `app/Filament/Pages/CustomerCreatePage.php`, `tests/Feature/CustomerMapLocationAutomationTest.php`, `tests/Feature/CustomerCreatePortalE2ETest.php`, `tests/Feature/CustomerLocationMapTest.php`, and this Work note. Portal: `app/Filament/Resources/Customers/Concerns/ResolvesCustomerLocation.php`, `app/Filament/Resources/Customers/Pages/EditCustomer.php`, `app/Filament/Resources/Customers/Schemas/CustomerForm.php`, `tests/Feature/CustomerMapLocationAutomationTest.php`, `tests/Feature/CustomerLocationMappingTest.php`, and this Work note. No migrations or application schema changed.
- **Limitations:** Mappings and Users were synthetic. No retained database writes, real Access mapping activation, production validation, or physical device validation occurred.

Phase C validation is complete. See the terminal-readiness review below.

## Terminal readiness review

- **State:** TERMINAL_DELIVERED
- **Authorization:** Human TERMINAL DELIVERY GO received and executed for the approved paths only.
- **Implementation review:** Portal map resolution recalculates geographic Access only on a genuine Specific Region change; unchanged controllers and incomplete/unavailable mappings preserve manual Access. OE/IB Company-scoped mappings retain precedence. Tablet restores the validated Area Cluster after Specific Region recalculation, only when that cluster belongs to the resolved Specific Region. Portal Edit persists the complete mapped Access result and preserves manual Access for same-controller or incomplete mapping cases.
- **Partial geocoder results:** Both applications update physical Region only when a valid Region ID was resolved. They do not fabricate missing locality, Specific Region, or Area Cluster IDs; unresolved commercial selections remain preserved.
- **Compatibility and schema:** Customer Push/Pull and canonical `customer_user` behavior remain unchanged. No new tables or migrations were introduced by this Work; existing location and Access mapping structures are reused.
- **Validation evidence:** Portal suites: **78 tests, 517 assertions**. Tablet suites: **66 tests, 631 assertions**. Authenticated disposable API E2E: **148 assertions**. Portal and Tablet focused fixture corrections are test-only; no application behavior was changed to satisfy the previously missing fixture rows/PSGC value. Pint and `git diff --check` passed in both repositories.
- **Readiness recheck:** Portal's focused map, Access, location-mapping, and synchronization suites reran as **78 passed, 517 assertions**. Tablet's selected map, diagnostic, Access, location resolver, and snapshot suites reran as **75 passed, 509 assertions, 1 skipped**; the standalone authenticated API E2E test skipped because `CUSTOMER_CONTRACT_PORTAL_URL` is not configured in this review session. This selected Tablet command is not the exact earlier 66-test suite, so its aggregate does not independently reproduce the recorded 66/631 result. The earlier disposable API E2E evidence remains recorded above; it is not represented as rerun here.
- **Evidence boundaries:** The synthetic geocoder callback was exercised in tests, and the authenticated disposable Tablet → Portal → Tablet API path completed. No actual rendered browser map interaction, physical iPad validation, production access, real mapping activation, or real personnel assignment activation is claimed.
- **Portal terminal package:** `app/Filament/Resources/Customers/Concerns/ResolvesCustomerLocation.php`; `app/Filament/Resources/Customers/Pages/EditCustomer.php`; `app/Filament/Resources/Customers/Schemas/CustomerForm.php`; `tests/Feature/CustomerLocationMappingTest.php`; `tests/Feature/CustomerMapLocationAutomationTest.php`; this Work note.
- **Tablet terminal package:** `app/Filament/Pages/CustomerCreatePage.php`; `tests/Feature/CustomerCreatePortalE2ETest.php`; `tests/Feature/CustomerLocationMapTest.php`; `tests/Feature/CustomerMapLocationAutomationTest.php`; this Work note.
- **Git audit:** Both repositories are on `main`; each local `HEAD` equals its configured `origin/main` upstream. The only modified and untracked files are the Work-owned package listed above. No migration, retained database file, secret, generated artifact, or unrelated change is present in the proposed package.
- **Delivery boundary:** One scoped Portal commit contains only the six approved Portal paths; one scoped Tablet commit contains only the five approved Tablet paths. Commit SHAs are reported in the terminal delivery response.
- **Remaining limitations:** Real geographic mapping rows and real personnel Access assignments remain unapproved/inactive. Physical iPad and rendered-map acceptance remain unperformed. No deployment or live production synchronization is claimed.

Terminal delivery is complete. Real-data activation, production synchronization, and physical iPad validation remain outside this Work.
