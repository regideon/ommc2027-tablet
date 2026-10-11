# OMMC-20261011-customer-location-reference-automation

- **State:** READY_FOR_TERMINAL_REVIEW
- **Mode:** Phase B — Location Automation Implementation
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Human authorized bounded Portal and Tablet implementation of location-to-Area-Cluster mapping. No retained database writes, Access activation, User provisioning, production access, deployment, staging, commit, or push.
- **Authoritative requirements:** `2026 Henri Revamp.xlsx`, worksheet `Customer Profile`. Workbook requirements are not database identity approval.
- **Accepted architecture:** retain the five Portal Access mapping tables and Tablet offline cache; `customer_user` remains canonical; geographic Access resolves by Specific Region; OE/IB resolve by Company. Add only `location_area_cluster_mappings` for explicit Province/Municipality/Barangay decisions. Do not add a Customer REG field, use `users.region_type_id` as an Access filter, parse Area Cluster names at runtime, or rewrite committed migrations.

## Phase A objective and boundary

Restore the 17 Specific Region rows present in the verified local Tablet SQLite reference snapshot and missing from Portal. Preserve all IDs. Use current Area Cluster dependent rows as evidence; do not modify them or any Customer location. Do not restore legacy NCR, add NIR, configure province/municipality split rules, derive Area Clusters, or implement coordinate geocoding in this phase.

The Portal target was verified as `ommc_henri_local_latest`. Tablet candidate data was read from `database/database.sqlite` using SQLite read-only mode. PHPUnit validation uses SQLite `:memory:`. The only seeder run against a retained store was the explicitly authorized local Portal restoration recorded below; no migrations or Tablet reference writes were performed.

## Exact Specific Region manifest

Every entry below was present in Tablet and missing in Portal immediately before execution. Candidate source was the Tablet reference snapshot. Dependent counts are the Portal Area Cluster rows that referenced each missing ID before execution.

| ID | Name | Physical Region ID | Existing Area Cluster dependents | Portal before | Portal after | Tablet | Execution |
|---:|---|---:|---:|---|---|---|
| 52 | Region IV-A (Cavite, Rizal) | 7 | 2 | Missing | Present | Present | Executed under Local GO |
| 53 | Region IV-A (Laguna, Batangas, Quezon) | 7 | 1 | Missing | Present | Present | Executed under Local GO |
| 54 | Region III (Central Luzon) | 6 | 3 | Missing | Present | Present | Executed under Local GO |
| 55 | Region XI (Davao Region) | 16 | 1 | Missing | Present | Present | Executed under Local GO |
| 56 | Region X (Northern Mindanao) | 15 | 1 | Missing | Present | Present | Executed under Local GO |
| 57 | Region IX (Zamboanga Peninsula) | 14 | 1 | Missing | Present | Present | Executed under Local GO |
| 58 | Region XII (SOCCSKSARGEN) | 17 | 1 | Missing | Present | Present | Executed under Local GO |
| 59 | Region XIII (Caraga) | 18 | 1 | Missing | Present | Present | Executed under Local GO |
| 60 | Bangsamoro Autonomous Region in Muslim Mindanao (BARMM) | 19 | 3 | Missing | Present | Present | Executed under Local GO |
| 61 | Region II (Cagayan Valley) | 5 | 1 | Missing | Present | Present | Executed under Local GO |
| 62 | Cordillera Administrative Region (CAR) | 3 | 2 | Missing | Present | Present | Executed under Local GO |
| 63 | Region I (Ilocos Region) | 4 | 3 | Missing | Present | Present | Executed under Local GO |
| 64 | Region V (Bicol Region) | 9 | 1 | Missing | Present | Present | Executed under Local GO |
| 65 | Region IV-B (MIMAROPA) | 8 | 3 | Missing | Present | Present | Executed under Local GO |
| 66 | Region VII (Central Visayas) | 12 | 3 | Missing | Present | Present | Executed under Local GO |
| 67 | Region VIII (Eastern Visayas) | 13 | 3 | Missing | Present | Present | Executed under Local GO |
| 68 | Region VI (Western Visayas) | 10 | 4 | Missing | Present | Present | Executed under Local GO |
| **Total** |  |  | **34** |  |  |  |  |

Portal and Tablet each have 19 physical Region rows. All 17 parent Region IDs in the manifest exist in Portal and Tablet. The snapshots agree on physical Region IDs, codes, names, and PSGC codes.

## Area Cluster integrity and schema

- Portal and Tablet each contain 44 Area Cluster rows; the selected reference columns match.
- Before execution, Portal had 10 clusters under existing Specific Region 1 and 34 rows whose parent IDs 52–68 were absent from Portal `region_specifics`. After execution, all 44 parent references resolve.
- The 17 Specific Region parents accounted for all 34 existing child references. The exact parent rows were inserted without inserting, updating, or deleting any Area Cluster.
- `region_specifics.id` is the primary key; `region_specifics.region_id` references `regions.id`. The table has no unique constraint on `(region_id, name)`, so the seeder explicitly detects a same-name/same-parent row at another ID.
- `area_clusters.id` is the primary key; `area_clusters.region_specific_id` references `region_specifics.id`; `(region_specific_id, code)` is unique. Before execution, the 34 existing Portal rows had missing parent records; after execution all declared parent references resolve.
- No schema migration is required for this parent restoration.

## Prepared implementation

Portal `database/seeders/CustomerLocationReferenceRestorationSeeder.php` contains the exact 17-row candidate manifest, including the observed dependent count. It:

- inserts only missing Specific Region rows with the Tablet IDs, names, parent IDs, and observed sort value;
- skips exact existing rows without updating them;
- fails transactionally on a missing physical parent, conflicting existing ID, or duplicate same-name/same-parent record at another ID;
- performs no Area Cluster, Customer, User, Access mapping, or legacy NCR writes; and
- is not registered with `DatabaseSeeder`.

The Human authorized only the exact local execution recorded below. This does not authorize production access or any mapping, identity, or additional location-reference changes.

## Disposable validation

Both repositories' PHPUnit configuration uses SQLite `:memory:`.

- Portal: `php artisan test --compact tests/Feature/CustomerLocationReferenceRestorationSeederTest.php` — **4 tests, 19 assertions passed**. Covers the exact manifest count, transactional conflict refusal, idempotency without changing existing row fields, no unrelated mapping/Area Cluster writes, dependent count total of 34, and Portal location snapshot serialization after restoration.
- Tablet: `php artisan test --compact tests/Feature/LocationReferenceSnapshotTest.php` — **13 tests, 104 assertions passed**. The new test submits a full 19-Region / 18-Specific-Region / 44-Area-Cluster snapshot built from the candidate manifest and verifies strict validation, reference persistence, zero unresolved Area Cluster parents, and snapshot-complete state.
- No retained database was used for test writes. These are fixture-based validations, not a production or live Portal-to-Tablet E2E.

## Requirements and approval gates kept separate

The authoritative `Customer Profile` worksheet is a profile/field requirements matrix. It calls for coordinate-derived location values and Area Cluster choices based on Province, and includes the Region IV-A split candidates. It does not approve the exact reference IDs in this restoration manifest.

Still deferred:

- legacy NCR Specific Region 1 parent reconciliation (Region 1 versus physical NCR Region 2);
- NIR Specific Region representation;
- Region IV-A province split automation;
- explicit Province/Municipality/Barangay-to-Area Cluster derivation rules;
- coordinate/geocoding provider and offline behavior;
- real Access group mappings and approval of the 46 personnel assignments.

The five geographic Access Groups and OE/IB Company configurations remain incomplete; no Specific Region-to-Access-Group rows exist. No Access activation is claimed.

## Accepted candidate geographic crosswalk

The Human accepted this crosswalk for preparation and disposable validation only. It does not authorize population of retained mapping records or Access activation:

| Candidate Access Group | Specific Region IDs |
|---|---|
| GMA | 1, 52 |
| NL | 54, 61, 62, 63 |
| SL | 53, 64, 65 |
| VIS | 66, 67, 68 |
| MIN | 55, 56, 57, 58, 59, 60 |

This is business mapping configuration, separate from restoring the 17 location parent records. No `region_specific_access_groups`, Access Group User, or Company User assignments are part of this Phase A restoration.

## Local execution evidence

On 2026-10-12, the mandatory preflight confirmed Portal `SELECT DATABASE()` = `ommc_henri_local_latest`; Specific Region IDs 52–68 were absent; ID 1 was `(id=1, region_id=1, name=National Capital Region (NCR), sort=1)`; all 17 parent Region IDs existed; candidate Tablet rows matched the seeder manifest; and the 17 Area Cluster dependent counts exactly matched and totaled 34. Before execution, Portal had 1 Specific Region, 44 Area Clusters, 18,988 Customers, 27,141 `customer_user` rows, 5 Access Groups, 0 Specific Region Access mappings, 0 Access Group User assignments, 2 Company configurations, 0 Company configuration User assignments, and 10 Region Types.

Before the seeder, a fresh, non-overwriting SQL recovery snapshot was created at `/private/tmp/OMMC-20261011-customer-location-reference-pre-restore-5315a0c85e9e.sql`. It is 143,394,893 bytes, mode `0600`, SHA-256 `c22aa7f1acaebee50b2c1b9a69a9427a2cd0f344900ecc17466c52f485d247f6`, contains 106 `CREATE TABLE` statements, and has a successful mysqldump footer. The dump used a single InnoDB transaction and included routines, triggers, and events. `mysqldump` emitted its standard database-scoped GTID notice; this is retained as a database recovery dump, not a server-wide GTID backup.

`information_schema` reports the primary key on `region_specifics.id`, the foreign key `region_specifics.region_id → regions.id`, the foreign key `area_clusters.region_specific_id → region_specifics.id`, and unique `area_clusters(region_specific_id, code)`. `region_specifics` has no unique `(region_id, name)` index; the seeder checks this duplicate case itself. Before execution, the retained Portal database contained the 34 missing-parent references despite the declared Area Cluster foreign key. No triggers are defined on `region_specifics`, and no constraints were disabled or altered.

Only `php artisan db:seed --class='Database\\Seeders\\CustomerLocationReferenceRestorationSeeder' --no-interaction` was executed. It completed successfully. The postflight confirmed Specific Regions = 18; all IDs 52–68 exactly match the manifest; ID 1 remains `(id=1, region_id=1, name=National Capital Region (NCR), sort=1)`; orphan Area Cluster references = 0; Area Clusters = 44; Customers = 18,988; and `customer_user` = 27,141. The 17 dependent counts resolve as expected (2, 1, 3, 1, 1, 1, 1, 1, 3, 1, 2, 3, 1, 3, 3, 3, 4). User count is 56 and Region Type count is 10. The Access tables remain at 5 groups, 0 Specific Region mappings, 0 group User assignments, 2 incomplete Company configurations, and 0 Company User assignments. The seeder source was reviewed to write only `region_specifics`; no other seeder, migration, or synchronization operation was run.

### Rollback limits

The seeder transaction rolls back all inserts if an error occurs before commit. After a successful commit, deleting the 17 restored parents is not a safe routine rollback: the pre-existing 34 Area Cluster rows reference them, and the declared foreign key restricts that deletion. Do not delete child clusters or disable foreign-key checks to force reversal. Treat a committed restoration as a reference-integrity repair. If a post-commit emergency reversal is required, it needs a separately approved recovery plan using a consistent pre-execution database snapshot and explicit handling of all intervening writes; no such recovery is authorized by this preparation task.

## Updated disposable validation

After the accepted architecture and crosswalk were recorded, both disposable SQLite suites were rerun:

- Portal `php artisan test --compact tests/Feature/CustomerLocationReferenceRestorationSeederTest.php`: **4 tests, 19 assertions passed**.
- Tablet `php artisan test --compact tests/Feature/LocationReferenceSnapshotTest.php`: **13 tests, 104 assertions passed**.

The Portal test verifies the 17-row insert/idempotency and snapshot serialization. The Tablet test verifies strict acceptance and caching of the complete 19 physical Region / 18 Specific Region / 44 Area Cluster fixture with no orphan parent references. These are synthetic/disposable tests, not retained-data execution or live Tablet-to-Portal E2E.

## Worktree ownership and safety

This phase owns the Portal restoration seeder/test, the Tablet strict-snapshot regression test, and this mirrored note. Existing Portal Customer form/Access activation changes and existing Tablet Customer Add/form changes from prior Works were preserved. No files were staged. No migrations or Tablet reference writes occurred. The only retained-database write was the authorized 17-row Portal `region_specifics` insert described above; no Customer, User, Access, Region Type, or synchronization records were written. No User provisioning, production access, deployment, commit, or push occurred.

**The bounded local restoration is complete and awaiting Human review. Overall Work remains `ACTIVE` because the separate legacy NCR, NIR, location automation, and Access activation decisions remain unresolved. No production access, Access activation, User provisioning, deployment, staging, commit, or push occurred.**

## Phase B — bounded location automation implementation

The Human approved one narrow mapping model after accepting the architecture review. This Phase B implementation adds no location rules or User assignments to retained databases. The historical Phase A restoration and its authorized local database evidence above are unchanged.

### Implemented model and behavior

- Added Portal and Tablet `location_area_cluster_mappings` tables with stable `location_level` + `location_id` keys, unique per geographic reference. Supported levels are `province`, `municipality`, and `barangay`; decisions are `mapped`, `unresolved`, and `requires_finer_location`.
- A mapped decision requires an enabled Area Cluster whose Specific Region parent has the same physical Region as the referenced locality. Portal model validation and runtime resolution check this; Tablet rejects malformed or geographically inconsistent snapshot rows before applying a pull.
- Resolution checks Barangay, then Municipality, then Province. A recorded unresolved or requires-finer decision blocks broader defaults. No rule returns `unmapped`; Add/Edit preserves the user's current Area Cluster and Specific Region in that case.
- A valid rule sets Area Cluster and derives Specific Region from that Area Cluster's parent. A genuine Specific Region change continues through the existing Access resolver. Existing Company-based OE/IB Access, manual Access behavior, `customer_user`, Push precedence, and Customer records are unchanged.
- Portal reference Pull now serializes the mapping rows as an additive optional payload section. Tablet accepts legacy snapshots without the section and leaves its existing location-map cache untouched when it is omitted. When present, the section is validated and replaced transactionally with the location snapshot.
- Portal Customer Add/Edit and Tablet Customer Add/Edit apply mappings when Province, Municipality, or Barangay changes. Coordinate reverse-geocoding still resolves physical location fields; commercial fields now come only from explicit mapping rows, with no Area Cluster name parsing or single-Specific-Region guess.

### Phase B implementation files

Portal implementation paths:

- `app/Filament/Resources/Customers/Schemas/CustomerForm.php`
- `app/Models/LocationAreaClusterMapping.php`
- `app/Services/LocationAreaClusterMappingResolver.php`
- `app/Services/PhilippineAddressResolver.php`
- `app/Services/TabletSyncPayload.php`
- `database/migrations/2026_10_12_090000_create_location_area_cluster_mappings_table.php`
- `tests/Feature/LocationAreaClusterMappingTest.php`

Tablet implementation paths:

- `app/Filament/Pages/CustomerCreatePage.php`
- `app/Models/LocationAreaClusterMapping.php`
- `app/Services/LocationAreaClusterMappingResolver.php`
- `app/Services/PhilippineAddressResolver.php`
- `app/Services/SyncService.php`
- `database/migrations/2026_10_12_090000_create_location_area_cluster_mappings_table.php`
- `tests/Feature/LocationAreaClusterMappingTest.php`
- `tests/Feature/LocationReferenceSnapshotTest.php`

Both repositories already contained worktree edits from earlier accepted Works. Those unrelated edits were preserved; this list identifies the implementation paths changed for Phase B and is not a staging instruction.

### Disposable validation evidence

All test writes used the repositories' disposable SQLite `:memory:` PHPUnit configuration. No migration was run against the retained Portal or Tablet databases.

- Portal relevant suites: `CustomerAccessMappingFormTest`, `TabletCustomerCreationContractTest`, `TabletSplitPullTest`, and `LocationAreaClusterMappingTest` — **65 passed, 463 assertions**.
- Tablet relevant suites: `CustomerCreatePageDiagnosticTest`, `CustomerCreatePortalE2ETest`, `LocationReferenceSnapshotTest`, and `LocationAreaClusterMappingTest` — **57 passed, 430 assertions; 1 skipped**. The skipped case remains as reported by the existing suite.
- Synthetic fixtures proved all five candidate group locations and the Region IV-A split (Cavite → GMA Area Cluster/Specific Region 52; Laguna → SL Area Cluster/Specific Region 53), manual commercial-field preservation when no rule applies, most-specific precedence, blocking decisions, physical Region mismatch rejection, mapping snapshot serialization, and strict Tablet pull/cache rejection for invalid targets.
- Existing Portal synchronization tests in the run cover explicit empty Access and Push precedence. Tablet E2E and sync tests also ran. These are disposable tests, not a live iPad run or real-data location/Access activation.
- `vendor/bin/pint --format agent` passed on the changed PHP files in both repositories. `git diff --check` passed in both.

### Data, migration, and acceptance limitations

- Migrations are additive. Portal applies a foreign key from the optional target Area Cluster to `area_clusters`; locality references are polymorphic and validated by the model because they span three existing tables. Tablet uses the same stable reference IDs without cross-database numeric User identity assumptions. Migration rollback drops only the new mapping table and its configuration rows; it does not touch Customers, Access pivots, or existing reference tables.
- No mapping rows were created or seeded. All five Access Groups and OE/IB configurations remain in their existing incomplete state. Real automatic Access replacement therefore remains inactive until separately approved Access mappings and User assignments are configured.
- Candidate mapping tests are synthetic/disposable only. They do not approve the workbook-derived rules as retained data.
- Still unresolved and deferred: legacy NCR parent reconciliation, NIR representation, Quezon City Barangay assignments, Maguindanao, 34-cluster restoration history (completed in Phase A), workbook rule approval for retained mapping data, approved personnel identities, and any location source rows not represented in the current references.
- No existing Customer was backfilled. No retained database was modified in this implementation phase; no real User assignment was created; no production access, deployment, staging, commit, or push occurred.

**Phase B implementation and disposable validation are complete. Work state: `READY_FOR_HUMAN_REVIEW`. Human review is required before any retained mapping population, retained migration execution, or activation.**

## Phase C — bounded E2E validation evidence (2026-10-12)

All Phase C database writes were confined to `/private/tmp/ommc-location-e2e-portal.sqlite` and PHPUnit SQLite `:memory:` databases. A Portal `php artisan serve` instance bound to `127.0.0.1:18764` used only that disposable SQLite file. The API token, Users, five Access Groups, Specific Region mappings, location rules, and Customer rows were synthetic fixtures. The retained Tablet SQLite reference snapshot was opened read-only only to verify IDs and labels.

### Executed evidence

- Portal targeted suites (`LocationAreaClusterMappingTest`, `CustomerAccessMappingFormTest`, `TabletCustomerCreationContractTest`, `TabletSplitPullTest`, and `CustomerLocationReferenceRestorationSeederTest`): **69 passed, 482 assertions**.
- Tablet targeted suites (`CustomerCreatePageDiagnosticTest`, `CustomerCreatePortalE2ETest`, `LocationReferenceSnapshotTest`, and `LocationAreaClusterMappingTest`), with `CUSTOMER_CONTRACT_PORTAL_URL=http://127.0.0.1:18764`: **58 passed, 497 assertions**.
- API-backed Tablet Add → Portal Push → Portal Customer Pull → Tablet Customer Pull contract scenario: **1 passed, 66 assertions**. It verified a negative Tablet Customer ID remained stable, Portal persisted one Customer, email-based Access identity reconciliation produced three distinct `customer_user` rows, and pulled Customer fields reconciled. The saved Customer used the disposable NCR fixture (Specific Region 1 / Area Cluster 1); this single scenario does not prove all five requested mapped locations persisted end-to-end.
- Authenticated Portal location Pull returned HTTP 200 to the Tablet test process. The strict snapshot validator accepted and cached the synthetic 17-Region / 18-Specific-Region / 12-Area-Cluster / 6-rule payload. This was a local disposable HTTP Pull, not a deployed Portal Pull.
- The first Tablet E2E invocation without `CUSTOMER_CONTRACT_PORTAL_URL` was skipped by its documented guard. Re-running with the isolated localhost Portal URL passed; the final targeted Tablet run had no skipped tests.
- Automated tests cover most-specific locality precedence, unresolved/requires-finer blocking, manual location preservation, and Portal Add/Edit contracts. No physical iPad validation was performed.

### Five-group form diagnostic and blocking defect

Verified read-only reference IDs from Tablet were: Cavite Province 27; Bulacan 20, Santa Maria 348, Guyong 9257; Batangas 26; Iloilo 45; and Davao del Norte 67. The supplied expected targets were GMA `AC 17 → SR 52`, NL `AC 19 → SR 54`, SL `AC 18 → SR 53`, VIS `AC 42 → SR 68`, and MIN `AC 20 → SR 55`. The disposable Portal fixture copied the actual Area Cluster children for those parents: SR 52 has 2 enabled clusters, SR 54 has 3, SR 53 has 1, SR 68 has 4, and SR 55 has 1.

Tablet's resolver returned the expected Area Cluster and Specific Region for all five. On the actual Tablet Add Livewire form after reference Pull, GMA ended with Area Cluster **null** (expected 17), NL **null** (expected 19), SL **18**, VIS **null** (expected 42), and MIN **20**. All five Specific Region values were correct, and synthetic Access assignments were selected without warnings. The first three wrong Area Cluster values occur because the resolver applies the mapped cluster, then `applySpecificRegionChange()` resets the cluster to null whenever the new Specific Region has multiple enabled options. This defeats an explicit deterministic location mapping. It prevents successful exact five-group persisted Push/Pull acceptance for GMA, NL, and VIS; those Customers were not pushed in this failed five-case attempt.

Portal's location resolver/form implementation does not perform that later unique-option reset, and the Portal location/Add/Edit suites passed. No code was changed during Phase C. The minimum correction for a separately authorized fix is to preserve the resolver's mapped `area_cluster_id` after recalculating Access for a genuine Specific Region change, while retaining unique-option behavior for a plain Specific Region selection. Add regression coverage for mapped parent changes with multiple children on Tablet Add/Edit and then rerun the five-customer API-backed loop.

### E2E acceptance status

- **Automated integration:** Passed for existing targeted suites and the one API-backed Customer round trip described above.
- **Disposable Portal/Tablet E2E:** Conditional/partial pass. Reference Pull and one complete Customer Push/Pull round trip passed; the five-group Add form diagnostic exposed the Tablet defect above, and all five persisted scenarios were therefore not completed.
- **Live deployed Portal Pull:** Not run. The authenticated Pull was against `127.0.0.1` and a disposable Portal database.
- **Physical iPad validation:** Not run.
- **Real-data activation:** Not performed or claimed. Approved real location mappings, Access group assignments, and confirmed personnel identities remain prerequisites.

Phase C recommendation: **NO-GO for E2E acceptance** until the Tablet mapped-cluster reset is corrected under separate Human authorization and the GMA/NL/SL/VIS/MIN Customers complete Push/Pull verification. No retained database writes, real mapping activation, User provisioning, production access, deployment, staging, commit, or push occurred.

## Phase C follow-up — Tablet Add mapping-order correction (2026-10-12)

The Human authorized the bounded Tablet correction after the Phase C NO-GO. The defect was in Add's location-resolution sequence: applying a valid Province/Municipality/Barangay mapping selected an Area Cluster and Specific Region, then Specific Region recalculation applied the generic “one enabled cluster only” selection rule and cleared that explicit mapped cluster when its parent had multiple children.

`CustomerCreatePage::applyLocationAreaClusterMapping()` now completes the Specific Region / Access recalculation first and restores the resolver's validated Area Cluster afterward. This does not preserve a cluster across a manual Specific Region change: that existing path still clears an incompatible child, and Tablet Edit inherits the corrected Add-page behavior. Regression coverage verifies mapped Add and Edit values under a multi-cluster parent, followed by a genuine manual Specific Region change that clears the previous Area Cluster. No hardcoded REG or Area Cluster values were added.

### Validation

- Tablet regression suites (`LocationAreaClusterMappingTest`, `LocationReferenceSnapshotTest`, `CustomerCreatePageDiagnosticTest`): **59 passed, 436 assertions**, zero skipped.
- Portal regressions (`LocationAreaClusterMappingTest`, `CustomerAccessMappingFormTest`, `TabletCustomerCreationContractTest`, `TabletSplitPullTest`, `CustomerLocationReferenceRestorationSeederTest`): **69 passed, 482 assertions**, zero skipped. Portal application code was not changed in this follow-up.
- Disposable Tablet → Portal → Tablet E2E: **1 passed, 164 assertions**. The isolated Portal SQLite database began with zero Customers and pivots. A strict location and Access mapping Pull populated Tablet's disposable SQLite test database. The five exact Add inputs resolved and round-tripped as follows:

| Candidate group | Input IDs (Region / Province / Municipality / Barangay) | Add result (Area Cluster / Specific Region) | Initial Portal Access pivot |
|---|---|---|---|
| GMA | 7 / 27 / — / — | 17 / 52 | Portal User 58 only |
| NL | 6 / 20 / 348 / 9257 | 19 / 54 | Portal User 60 only |
| SL | 7 / 26 / — / — | 18 / 53 | Portal User 61 only |
| VIS | 10 / 45 / — / — | 42 / 68 | Portal User 62 only |
| MIN | 16 / 67 / — / — | 20 / 55 | Portal User 63 only |

Each disposable Customer was saved with a negative Tablet ID, pushed through the local Portal API, checked directly in Portal for its location IDs and exact single pivot, then pulled back. Tablet retained each negative local ID, matched Access by email to the Tablet-local User (whose numeric ID differs from Portal), and retained one pivot without duplicates. In the GMA case, negative-control Portal User 59 (`dummy.salesrep2@motolite.com`) was absent from the automatic assignment and pivot.

The GMA Customer was then edited offline. Adding User 59 manually and submitting the unchanged Specific Region 52 preserved the manual set; a Push persisted Portal Users 58 and 59 exactly once each. The Tablet cache was then made explicitly incomplete for Specific Region 53. Changing the controller to 53 derived the valid child Area Cluster 18, displayed the incomplete-mapping warning, and preserved both manually selected Access users. A second Push and Portal check retained exactly Users 58 and 59. These assignments and mapping rows were synthetic disposable fixtures, not business-approved real mappings.

`vendor/bin/pint --format agent` passed for the changed Tablet PHP files and `git diff --check` passed in both repositories. The expanded E2E runner was temporary and removed after execution; permanent regression coverage remains in Tablet `tests/Feature/LocationAreaClusterMappingTest.php`.

### Acceptance boundaries

The Phase C Tablet Add defect is corrected and the five requested disposable location and Access cases now pass end-to-end. The E2E used only disposable SQLite databases and a local Portal API process. It is not live authenticated production Pull, deployed-build evidence, or physical iPad validation. No retained database was written, no real Access mapping was activated, no User was provisioned, and no deployment, staging, commit, or push occurred. Real-data mappings and personnel approvals remain deferred; the Work is awaiting final terminal authorization.

## Final terminal readiness review (2026-10-12)

The Human accepted Phase C correction and disposable E2E evidence. Focused validation remains **Portal 69 tests / 482 assertions** and **Tablet 59 tests / 436 assertions**; the separate disposable Tablet → Portal → Tablet runner passed **1 test / 164 assertions**. The five initial Customer states persisted the exact Area Cluster, Specific Region, physical location, and single synthetic Access assignment. The additional GMA Edit check verified unchanged-controller manual Access preservation and preservation under an explicitly incomplete changed mapping. No tests were skipped in these final runs.

The Portal and Tablet Access mapping structures were not changed by this Work. The five Portal Access tables and one Tablet offline Access cache remain consumed by their existing resolver and sync paths; `customer_user` remains canonical. The only new mapping schema in this Work is `location_area_cluster_mappings` in each application. Its migrations are additive, unique on `(location_level, location_id)`, and drop only the new table on rollback; Portal validates mapped target consistency and Tablet validates the strict reference snapshot before transactional cache replacement. The Specific Region restoration seeder is idempotent and writes only the 17 reviewed `region_specifics` rows when explicitly run; it is not called by `DatabaseSeeder`.

### Terminal package ownership

Portal paths eligible for this Work's terminal package:

- `app/Filament/Resources/Customers/Schemas/CustomerForm.php` — stage only the location-mapping import, locality hooks, and mapping resolver helper; exclude the Company-unselected Access guidance hunk belonging to Customer Add Form Consistency.
- `app/Models/LocationAreaClusterMapping.php`
- `app/Services/LocationAreaClusterMappingResolver.php`
- `app/Services/PhilippineAddressResolver.php`
- `app/Services/TabletSyncPayload.php` — location mapping serialization hunks only.
- `database/migrations/2026_10_12_090000_create_location_area_cluster_mappings_table.php`
- `database/seeders/CustomerLocationReferenceRestorationSeeder.php`
- `tests/Feature/CustomerLocationReferenceRestorationSeederTest.php`
- `tests/Feature/LocationAreaClusterMappingTest.php`
- `docs/work/OMMC-20261011-customer-location-reference-automation.md`

Tablet paths eligible for this Work's terminal package:

- `app/Filament/Pages/CustomerCreatePage.php` — stage location mapping integration and mapped-cluster ordering hunks; exclude unavailable Company/Specific Region presentation hunks belonging to Customer Add Form Consistency.
- `app/Models/LocationAreaClusterMapping.php`
- `app/Services/LocationAreaClusterMappingResolver.php`
- `app/Services/PhilippineAddressResolver.php`
- `app/Services/SyncService.php` — location mapping validation and cache synchronization hunks only.
- `database/migrations/2026_10_12_090000_create_location_area_cluster_mappings_table.php`
- `tests/Feature/LocationAreaClusterMappingTest.php`
- `tests/Feature/LocationReferenceSnapshotTest.php`
- `docs/work/OMMC-20261011-customer-location-reference-automation.md`

Exclude the separate Customer Access Activation artifacts, Customer Add Form Consistency changes/tests, and their mirrored notes. No generated files, secrets, retained database files, or runtime artifacts are part of this Work. The Portal and Tablet worktrees have unrelated/pre-existing changes, including changes within the two form files noted above; terminal staging must select only the listed Work-owned paths and hunks. Proposed commit boundaries: one Portal implementation/test/documentation commit and one Tablet implementation/test/documentation commit on each repository's existing `main` branch. No staging or commit occurred during this review.

Deferred decisions remain: approved production location mappings and personnel Access assignments; live authenticated synchronization; physical iPad validation; NCR parent reconciliation; NIR representation; unresolved Quezon City Barangay assignments; Maguindanao; and future production reference/mapping rollout. No real-data mapping activation is claimed.

**Current Work state: `READY_FOR_TERMINAL_REVIEW`. Awaiting separate Human `TERMINAL GO`.**
