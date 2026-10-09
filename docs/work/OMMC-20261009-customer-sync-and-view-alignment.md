# OMMC-20261009-customer-sync-and-view-alignment

Work-ID: `OMMC-20261009-customer-sync-and-view-alignment`
Work-State: `TERMINAL_DELIVERED`
Mode: Bounded coordinated implementation and local validation
Repositories: Portal `ommc2027`; Tablet `ommc2027-tablet`

## Objective and authorization

Align Customer update persistence, Portal Profile rendering, Tablet Customer View rendering, and safe diagnostics using the mirrored 101-row field matrix as baseline. Human authorized this bounded implementation across both repositories. No commit, push, deployment, PROD access, migrations, or writes to retained business databases are authorized. Keep reference integrity strict and leave existing Fleet failures and code reservations untouched.

## Scope and acceptance history

The prior Customer Add contract remains delivered and unchanged. Human acceptance remains: IB PASS, CAR_CLUBS PASS, OMMC PASS, Last Mile PASS, OE PASS; Fleet TESTED, SYNC NOT ACCEPTED. This Work does not claim Fleet acceptance. Reference-data defects are documented only; no Specific Region or Area Cluster data was changed.

## Repository state at implementation start

- Portal `main`: `8e8cc5f9a95f93f6e96b768a585482492a71a546`, equal to cached `origin/main`; only the new mirrored Work note/matrix were untracked.
- Tablet `main`: `2b2640307639694a6ab9e6abefd166436b7a63a3`, equal to cached `origin/main`; the mirrored Work note/matrix and existing untracked `public/expense_attachments/` were present. Expense attachments were preserved.
- No application implementation existed for this Work at start; prior terminal Work records/matrices were not changed.

## Implemented corrections

### Portal Customer sync update and validation

- The push update now applies optional scalar attributes only when their keys are present. Omitted nullable scalars preserve stored values; supplied `null` clears nullable values. Name and Company remain required. `is_active` is now `sometimes|boolean`, so omission preserves inactive state and invalid null is rejected.
- Omitted PIC email preserves the existing relationship; an explicitly supplied null clears it. Access behavior remains unchanged: only an included Access email list is synchronized, using the existing exact User resolution.
- Omitted `profile_data` or omitted `profile_data.active` preserves the existing active profile. Supplied nested active keys merge by path; explicit null clears that key, and explicit null for the active object clears active values. Portal-owned archived profile history is retained on update.
- API validation now retains and validates the Add Customer active-profile fields, including all eight Owner fields, operating hours, classifications, Category/profile enums, and existing conditional values. This corrects the prior nested-validation behavior that dropped unlisted active keys. Scalar profile classifications are accepted and checked against configured options; typed classification arrays remain accepted for the typed trade-profile field. Annual Category validation now reads outlet options by stream and Fleet/OE/IB options by profile, matching the actual configuration structure; supported stream and year checks remain active.
- Annual category upserts remain keyed by Customer/profile/stream/year. Omitted years remain untouched. Customer UUID/code reservation/idempotency behavior, Portal-owned code, authenticated creator, and transactions were not changed.

### Tablet Customer View and diagnostics

- Customer detail now renders nested Owner fields in a dedicated Owner Profile section; Business Landline, Business Mobile, Date Established, and PIC; Access user names; DRM users derived from Access users with the DRM role; and existing RSM users derived only through those DRM users' `rsm_id` relationships.
- Values come from existing local Customer/profile/User relationships. User queries select only relationship IDs, RSM IDs, and names needed by this view; emails and credential columns are neither loaded nor displayed. Missing or unresolved relationships remain blank/“None assigned”; no values or relationships are manufactured.
- The detail-loading action now clears stale detail before loading and catches failures without showing a false success. It logs a generated reference, Customer/User IDs, exception class/code, source location, sanitized database driver error summary where applicable, and the first application stack frame. It does not log exception messages, SQL bindings, profile/customer fields, credentials, or raw stack arguments. The user sees a failure notification with the reference for support.
- Location relationships and strict reference validation were not changed.

### Physical iPad Customer View failure diagnosis

- Correlated all four Human-provided diagnostic references in the available local Tablet `storage/logs/laravel.log`. Each records the same `Illuminate\\Database\\Eloquent\\ModelNotFoundException`, originating at Eloquent `Builder.php:634`, with application frame `CustomerPage.php:261` calling `findOrFail`. These are repeated instances of one root cause, not independent failures.
- Read-only inspection of the configured local SQLite database found that none of the four logged requested IDs exists exactly, while each has a nearby existing local Customer ID. The logged values match JavaScript `Number(...).toString()` rounding of those existing 64-bit IDs. This is consistent with the generated random negative 64-bit IDs in `CustomerCreatePage` and the Customer list’s numeric `wire:click` argument crossing the browser’s JavaScript number boundary. No Customer fields or business rows were changed.
- Corrected the detail, retry, and photo actions to pass decimal Customer IDs as strings. The Livewire selected/retrying identity is retained as a string, and each action validates the decimal integer before querying or invoking retry. This preserves exact IDs without weakening failure handling or changing synchronization state.
- Added a disposable SQLite regression using a 64-bit local Customer ID. It verifies the quoted action argument, exact selected ID, successful detail render, and absence of the loading-error notification. `CustomerPageDetailsTest`: **4 passed, 37 assertions**.
- All four logged errors terminated before `findOrFail` returned a Customer. The preceding profile, brand, category, notes, visits, and photo-count queries did not throw. This evidence points to a corrupted/mismatched lookup key, not an optional relationship, schema, annual-history, or sync-state exception. The source log does not establish whether each target Customer was newly created or pulled; that distinction is not needed to explain the browser ID rounding.

### Customer identity and 64-bit reconciliation

- Tablet Add Customer assigns a random negative signed 64-bit local primary key (`-random_int(1, PHP_INT_MAX)`) and a UUID. That key remains the local identity for Customer FKs and related rows; sync does not replace it.
- Portal Customer Push validates the UUID, finds/creates the Portal row by `local_uuid` inside a database transaction, and returns the Portal `id` as `server_id` only after the transaction completes. Tablet marks a row `synced` only after a successful HTTP response contains a valid positive integer `server_id`; `server_id` and `sync_status` are persisted in the same SQL update. A 2xx response without a valid ID is recorded as failed. Focused coverage confirms `sync_status=synced, server_id=NULL` is not produced by this path.
- Portal pull includes `local_uuid`. Tablet previously matched by `server_id`, then same primary key, but ignored UUID. If Portal committed a Create and the Tablet missed its acknowledgment, a later Pull could therefore insert a second Tablet row. Pull now resolves by known `server_id`, then exact `local_uuid`, then the legacy same-ID fallback. For a UUID match whose local row is pending/failed/conflicted, Pull attaches a missing authoritative `server_id` and Portal base timestamp while preserving local field values, status, Customer Code reservation state, and related records. A UUID already bound to a different Portal ID fails the pull rather than being reassigned.
- Read-only inspection of the configured local Tablet SQLite found 711 synced Customers with a `server_id`, zero synced Customers with a null `server_id`, and three failed Customers with a null `server_id`. It also found two duplicate `server_id` groups (23595 and 23596), each represented by two negative local keys with distinct local UUIDs. The source of these pre-existing duplicates cannot be proven from current local state. No local Customer rows or relationships were changed; duplicate cleanup/reconciliation remains a separate Human-reviewed data task.
- Local Customer IDs now cross browser boundaries as decimal strings in Customer detail, retry, photos, Edit Customer Livewire state, Salescall customer options/call payloads, unplanned Salescall results, and Customer detail state containing the Portal ID. PHP validates/converts action strings before database operations; no primary-key/schema migration is involved.
- Disposable SQLite tests cover large negative-ID Push acknowledgment, Pull preserving a negative key and Salescall FK, lost-ack UUID recovery without overwriting failed local values, malformed 2xx acknowledgment, Edit state serialization, Salescall note/unplanned-call actions, and Customer detail/Photos. No retained business database was written.

## Validation and test evidence

All feature tests used each repository's PHPUnit SQLite `:memory:` database (`RefreshDatabase`). No live API endpoint was contacted and no retained database was written.

- Portal combined sync/profile/pull suites (`TabletCustomerCreationContractTest`, `CustomerSyncPortalViewTest`, `TabletSplitPullTest`, `SyncPullCustomerScopeTest`): **30 passed, 350 assertions**. Covers sparse omission, explicit nullable clears, nested profile merge/clear, optional-value validation, all six Company mappings with populated active profile and annual-history values through Portal persistence/pull, preserved omitted history years, and Portal Profile rendering across all six variants.
- Tablet combined detail/access/search/diagnostic/form/push/retry/reference/local-persistence suites (`CustomerPageDetailsTest`, `CustomerPageAccessTest`, `CustomerPageSearchTest`, `CustomerOnlyPushTest`, `LocationReferenceSnapshotTest`, `CustomerCreatePageDiagnosticTest`, `CustomerLocationSyncTest`, `CustomerCreatePageSelectsTest`, `CustomerLocationPersistenceTest`): **56 passed, 426 assertions**. Covers all Owner fields, business contacts/date, Access/PIC/DRM/RSM, sparse optional data, bounded failure logging, local form/push, retry exhaustion and reference protections.
- Tablet integrated live Portal API test: **1 skipped** because no isolated disposable Portal API endpoint was configured. No live endpoint was attempted. Physical Tablet→Portal end-to-end evidence remains pending.
- Pint passed for all changed PHP files in both repositories. PHP lint passed for all changed PHP files. `git diff --check` and documentation whitespace validation are recorded below after final review.
- No known baseline failure was reproduced in these focused suites. Full repository suites were not run; no claim is made about unrelated historical baseline failures.

### Addendum — Customer View ID transport correction

- Re-ran `CustomerPageDetailsTest`, `CustomerPageAccessTest`, `CustomerPageSearchTest`, `CustomerOnlyPushTest`, `CustomerLocationSyncTest`, `CustomerLocationPersistenceTest`, `CustomerLocationPushTest`, and `CustomerCreatePageDiagnosticTest`: **49 passed, 349 assertions**.
- Re-ran Portal `TabletCustomerCreationContractTest`, `CustomerSyncPortalViewTest`, `TabletSplitPullTest`, and `SyncPullCustomerScopeTest`: **30 passed, 350 assertions**.
- Pint passed for the changed Tablet PHP files. PHP lint passed for `CustomerPage.php` and `CustomerPageDetailsTest.php`. `git diff --check` is part of the final review.
- Runtime root-cause evidence is from the four recorded local Tablet errors and a read-only query of the configured local SQLite DB; the automated regression uses an isolated test database. The Human's physical iPad data was not changed.

### Addendum — Customer identity reconciliation

- Tablet identity/push/pull/UI regression suites (`CustomerOnlyPushTest`, `CustomerPagedPullTest`, `CustomerIdentityBoundaryTest`, `CustomerPageDetailsTest`, `CustomerCreatePageDiagnosticTest`, `CustomerLocationPersistenceTest`): **54 passed, 446 assertions**.
- Portal push/profile/pull contract suites (`TabletCustomerCreationContractTest`, `CustomerSyncPortalViewTest`, `TabletSplitPullTest`, `SyncPullCustomerScopeTest`): **30 passed, 350 assertions**.
- Pint passed for changed Tablet PHP files; PHP lint passed for all changed Tablet PHP files. Full Tablet and Portal suites were not run.

## Reference integrity and Fleet disposition

The inspected local Portal database previously had 34 of 44 enabled Area Clusters with missing Specific Region parents. Tablet strict complete-snapshot validation remains intact. No production/reference rows, IDs, Customer FKs, or migrations were changed. Minimum evidence for separate data correction: authoritative source/backup provenance for each orphan, intended canonical Specific Region IDs, and validation that all affected clusters belong to those parents, approved by the data owner. Never map by label or change IDs in this Work.

FLEET3737 and FLEET3739 remain local failed records with exhausted 422 retries on Specific Region ID 53 in the previously inspected local environment. Their local UUIDs, Customer Codes/reservations, retry counts, and data were not changed and no retry was invoked. Before retry, establish the correct environment's current canonical reference set and reconcile the selected reference through the authorized edit flow; preserve the same UUID and reserved code. Fleet synchronization remains unaccepted.

## Remaining unknowns and limitations

- The four reported Customer View exceptions are now correlated to JavaScript rounding of 64-bit local IDs and the UI action boundary is corrected. Physical iPad retest is still required to verify the deployed build and any new diagnostic reference if another failure occurs.
- The exact before/after payload and Portal rows for the Human-reported missing OE/Owner/Annual data were not provided. API and rendering defects are covered; the target record's historical data-loss cause is not established.
- FLEET3739's physical iPad endpoint and exact deployment/runtime database remain unverified.
- The Area Cluster orphan provenance and physical iPad Pull result remain unverified.
- There is no configured isolated Portal API endpoint for a live cross-repository normal-flow test. Automated Portal API push+pull and Tablet-side local persistence tests are separate; they do not prove device runtime connectivity.
- Human QA and deployment validation are outstanding. No deployment is authorized.

## Human device QA

On a safe test Customer and the intended iPad build:
1. Install/reload the build containing the string-ID action correction. Open Customer View for a locally created Customer whose ID is a large negative 64-bit value, then verify detail and Photos actions work without a loading-error notification.
2. Open Customer View with populated and sparse optional values; confirm the page loads and displays Owner, business contacts/date, Access, PIC, DRM, and derived RSM.
3. If a load fails, capture the displayed reference and local app log entry; confirm the log contains diagnostic metadata but no Customer field values or credentials.
4. Create/update an OE Customer with Owner fields and multiple Annual Category years; compare local data, Portal Profile, Portal pull, and Tablet View.
5. Repeat representative populated/omitted optional profile fields for OMMC, Last Mile, Fleet, IB, and CAR_CLUBS. Keep Fleet status TESTED, SYNC NOT ACCEPTED until separately confirmed.
6. Verify location Pull still fails closed on invalid hierarchy data and succeeds only with a complete valid canonical snapshot; do not repair local/Portal business rows as part of QA.

## Deployment readiness

**Not ready for deployment or terminalization.** Physical-device QA, target-record reconciliation, Fleet sync acceptance, and local Area Cluster orphan provenance remain unresolved. No schema change/migration was introduced. Await Human functional review and acceptance.

## Work-note state

`ACTIVE` — bounded implementation and disposable automated validation are complete; Human functional/device QA remains. No commit or push was made. Stop for Human review.

## Addendum — location Pull parent-integrity investigation (2026-10-09)

- Portal `TabletSyncPayload::locations()` serializes `RegionSpecific::all()` without filtering and enabled Area Clusters (`AreaCluster::where('enabled', true)`). `streamLocations()` derives section counts from the exact serialized collections and streams the full enabled Barangay selection. The location route returns this stream directly. No incorrect field mapping or parent/child hierarchy coupling was found.
- Read-only inspection of the Portal database selected by this local application found one Specific Region row (ID `1`) and 44 enabled Area Clusters. Thirty-four enabled Area Clusters (IDs `16`–`49`) point to absent Specific Region IDs `52`–`68`. These rows are not excluded by an API parent filter: the missing parent records do not exist in the inspected database. The result confirms the previously reported local orphan condition, but does not establish that the physical iPad uses this Portal instance/database.
- Tablet `SyncService::validateLocationSnapshot()` correctly requires `area_clusters.region_specific_id` to be present in the same snapshot's `region_specifics` ID set. `pullLocations()` validates the complete payload before applying it, then applies references and completion state in one DB transaction. Invalid references therefore fail before writes; existing local Customer foreign keys and local reference rows are not rewritten by a failed validation.
- Relevant disposable tests: Portal `TabletSplitPullTest`: **6 passed, 47 assertions**; Tablet `LocationReferenceSnapshotTest`: **9 passed, 83 assertions**. These establish endpoint section/count behavior and strict Tablet snapshot handling, but do not prove the physical iPad's endpoint/database.
- No application code, reference data, Customer data, schema, or migration was changed for this investigation. Do not filter out the 34 enabled clusters, fabricate Specific Regions, or remap parent IDs. Data-owner approval and authoritative source evidence are required before any data correction. Physical iPad retest must first record the configured Portal environment/instance and confirm the exact response's missing parent IDs without exposing credentials.

## Addendum — Specific Region source and recovery investigation (2026-10-09)

- V1 (`ommc2026`) is not an authoritative source for Specific Region IDs 52–68. Its tracked migrations define no Region/Specific Region/Area Cluster reference tables; the legacy Customer model stores location labels and scalar IDs. Its two local SQLite databases contain no Customer or location-reference tables. No V1 records establish names, active status, parents, or stable IDs for 52–68.
- V2 migration history establishes that `region_specifics.id` is a primary key with a required `region_id` FK; `area_clusters.region_specific_id` is an FK with cascade-on-delete and a unique `(region_specific_id, code)` key. `region_specifics` has no active/enabled column. The Area Cluster migration is recorded and the configured database currently has that FK. No migration or current `DatabaseSeeder` call imports Specific Regions 52–68. A historical `RecoveryRegionSeeder` (commit `a4d57ce`) supplied only three name-based references (NCR, CAR, Region I), not IDs 52–68; it was not called by that commit's `DatabaseSeeder`.
- Current `SampleCustomerSeeder` and `TabletSampleSeeder` source each disable MySQL FK checks, truncate `region_specifics`, and reinsert only NCR; neither truncates `area_clusters`. If either was run against a database already containing the affected Area Clusters, this is a concrete mechanism capable of leaving orphan rows despite the FK. The current `DatabaseSeeder` does not invoke either seeder. There is no execution log or database audit evidence proving that either ran against the inspected database; cause therefore remains **UNDETERMINED**, with destructive sample seeding a source-supported hypothesis, not a confirmed historical cause. Incomplete import, deletion, or a different environment are also not established.
- The configured Portal connection used for the prior read-only inspection identifies database `ommc_henri_reconcile_customers`, a retained reconciliation database; it is not evidence of the physical iPad's target. No V1/V2 physical-device configuration evidence establishes that target, and no remote endpoint was contacted.
- Read-only aggregate inspection found 15,664 Customer rows referencing Area Clusters 16–49. Of those, 15,663 have `customers.region_specific_id` null; one has Specific Region ID 1 while its Area Cluster points to missing parent 52. Thus restoring parents alone could expose a Customer hierarchy conflict that must be reviewed without changing that Customer in this Work. No Customer rows were modified. No live foreign-key violations currently exist on `customers.region_specific_id`; all non-null values refer to the sole existing parent.
- Exact child-to-missing-parent relationships are: 16→52, 17→52; 18→53; 19→54, 27→54, 31→54; 20→55; 21→56; 22→57; 23→58; 24→59; 25→60, 26→60, 43→60; 28→61; 29→62, 32→62; 30→63, 48→63, 49→63; 33→64; 34→65, 35→65, 44→65; 36→66, 38→66, 39→66; 37→67, 40→67, 46→67; 41→68, 42→68, 45→68, 47→68. These child links establish which IDs are referenced, but do not establish the missing rows' names or `region_id` parents.
- No evidence-backed Specific Region correction dataset can be produced. Do not infer names or physical `region_id` values from Area Cluster names, codes, Customer locations, or label similarities. Before correction, obtain an authoritative approved reference export/source containing each ID, exact name, active state if applicable, canonical `region_id`, and confirmation of each Area Cluster relationship. Then perform a read-only target preflight; make a secured backup; dry-run exact-ID/name/parent collision and Customer hierarchy checks; apply only in a separately authorized bounded transaction; verify counts/FKs/Pull payload; and retain a tested rollback procedure. The conflicting Customer reference identified above requires its own approved resolution and must block a simplistic parent-only repair.
- Investigation did not change application code, seeders, migrations, or business data. Work remains `ACTIVE`; stop for Human data-correction approval and authoritative source evidence.


## Addendum — authoritative Customer contract verification (2026-10-09)

### Database identity and evidence boundary

- Portal Laravel configuration selects MySQL and the effective database name is `ommc_henri_reconcile_customers`. This is the retained local reconciliation database; the read-only identity query does not establish which instance the physical iPad targets. No credentials were recorded.
- Tablet Laravel configuration selects SQLite at `database/database.sqlite`; the configured synchronization URL parses to `http://127.0.0.1:8000`. This is configuration evidence, not a verified runtime connection or physical iPad endpoint. The iPad endpoint remains unverified.
- Cross-repository runtime evidence was collected only with a newly created disposable Portal SQLite database, a temporary API server on loopback, and the Tablet PHPUnit in-memory SQLite database. No retained reconciliation or production business data was written.

### Portal canonical storage model

- `customers` is canonical for Company, Portal Customer ID, local UUID, Customer Code, name, General Category, commercial location (`region_specific_id`, `area_cluster_id`), physical location (`province_id`, `municipality_id`, `barangay_id`), contact/address/coordinates, active state, creator, and PIC. `physical_region_id` is not a Customer column.
- `customer_trade_profiles` is the canonical Customer Add/Edit company-profile aggregate: `profile_type`, typed trade columns, and nested JSON `profile_data`. The separate `customer_profiles` table is Salescall/visit-scoped profile data, not the Add Customer Owner/profile destination.
- `customer_category_histories` stores annual Category by Customer/profile/stream/year. `customer_user` is the Access relationship. Customer Scope is not a Portal table; visibility is derived from Access and schedule assignment. DRM is resolved from Access User roles and RSM is derived from DRM `rsm_id`; there is no direct Customer RSM field.
- Customer Code reservation and sequence tables are Portal-owned. The Portal allocates/reserves Customer Code; Tablet stores the returned code/token for retry. `created_by` is authenticated Portal identity.
- Portal Company/profile mappings cover OMMC, LAST_MILE, CAR_CLUBS→outlet; FLEET→fleet; OE→oe; IB→ib. Portal profile fields and category streams are configuration- and validation-backed; annual rows upsert on Customer/profile/stream/year and omission does not delete other years.

### Field-level result

The mirrored TSV retains the original 101 rows and adds `authoritative_alignment_status` plus row-level evidence. The status is conservative: a field is ALIGNED only where test assertions cover the relevant value family across the disposable live API flow and Tablet view; rows without that evidence remain NEEDS RUNTIME EVIDENCE.

- Physical Region is an intentionally transient selector/filter under the current Human decision. It filters Province options and has no independent Customer storage or round-trip destination; selected descendants are the canonical persisted location values. This is by design, not a mismatch.
- Portal-only/deferred: Landmass has a Portal canonical column/profile representation but no Tablet Add field or sync mapping. Legacy `customers.contact_number` is not exposed by Tablet Add; this is distinct from Business Landline and Business Mobile. These are not claims that currently exposed Tablet controls are silently dropped.
- Other supported Add fields have source paths through local Customer/trade/history/Access storage, push serialization, Portal validation/storage, pull serialization/hydration, and Tablet rendering; only field families with explicit live assertion coverage are marked as such in the matrix. Exact agreement with the physical iPad endpoint/database is not established.

### Disposable round-trip verification

- Existing OMMC full Add→Push→Portal API/database→Pull test: **1 passed, 42 assertions**. It checks Portal-issued Customer Code, General Category, all six persisted location FKs (excluding transient physical Region), scalar contacts, PIC/Access identity-by-email, outlet typed fields, Owner data, annual AB years, Portal server ID, retained negative local ID, and no mutation of pre-existing Portal Customers.
- A supplemental temporary non-committed test exercised OMMC, Last Mile, CAR_CLUBS, Fleet, OE, and IB through Tablet Livewire Add, a Portal API on disposable SQLite, Portal pull payload, Tablet Pull, and Tablet detail. A later fixture audit found that Fleet/OE/IB active-profile fields were placed under `trade` rather than the form’s `active` profile data; the 185 assertions therefore do **not** establish those company-specific profile values round-tripped. Treat that result as limited evidence for fields actually serialized by that fixture, not full six-variant field-fidelity acceptance. Direct Portal contract tests separately exercise the company-specific Portal profile fields and Portal Profile display. The temporary test and disposable database/server were removed.
- Existing Portal focused tests (`TabletCustomerCreationContractTest`, `CustomerSyncPortalViewTest`, `TabletSplitPullTest`, `SyncPullCustomerScopeTest`) and Tablet focused Customer suites were run in this investigation; the detailed commands/results are recorded in the latest execution log. Portal Profile view source is covered separately by `CustomerSyncPortalViewTest`; that does not prove the physical Portal UI instance.
- The live six-variant check uses one disposable dataset and does not establish the state of any Human-reported target Customer, actual iPad build, physical iPad API endpoint, or all possible enum/optional-value combinations. A same-value full Portal browser Profile session for every test row was not exercised; those rows remain appropriately qualified in the matrix.

### Identity, relationships, and reference integrity

- Existing focused tests and code inspection establish the generated negative signed 64-bit local ID remains the Tablet primary key, and IDs cross JavaScript/Livewire actions as decimal strings. Push stores a validated positive Portal `server_id` and `synced` state only after a valid successful acknowledgment. Pull prioritizes server ID and local UUID, preserving the local primary key and related local references; UUID conflict fails closed. The temporary six-variant run confirmed one local row per test Customer and retained local identity after Pull for the values it actually submitted. It did not prove all six company-specific active-profile field families. Photos remain Salescall-related, and Salescall Customer FKs retain local IDs.
- Strict reference validation remains unchanged. In the inspected retained local Portal DB, enabled Area Clusters 16–49 reference missing Specific Region parents 52–68; authoritative names/physical Region parents and the physical iPad target remain unknown. This is a reference-data blocker, not evidence of a schema or serialization mismatch. No records were fabricated/remapped and the Tablet validator was not weakened.

### Corrections, further evidence, and readiness

- No implementation change, migration, schema change, seed/import, reference rewrite, or retained Customer/reference data change was made in this verification. No change to the physical Region contract is proposed; it remains a transient selector/filter as directed.
- To close the remaining runtime evidence, capture the physical iPad’s configured non-secret environment/host identity, test safe disposable Customers across all supported fields, compare exact local row and Push payload with Portal canonical rows and Portal Profile display, then Pull and compare Tablet row and view. Obtain authoritative reference export for missing Specific Regions before any attempt to recover location Pull.
- Fleet remains `TESTED, SYNC NOT ACCEPTED`; its exhausted 422 records and reservations were not retried or modified. Human acceptance remains IB PASS, CAR_CLUBS PASS, OMMC PASS, Last Mile PASS, OE PASS; Fleet is explicitly not accepted.
- Deployment GO/NO-GO: **NO-GO** pending physical iPad endpoint/build evidence, target-record reconciliation, and location reference recovery evidence. Work state remains `ACTIVE`; stop for Human review.


## Addendum — actual iPad location endpoint and missing-parent evidence (2026-10-09)

### Endpoint and runtime verification

- Read the Tablet endpoint configuration without recording credentials. The current local profile points to loopback; the ignored production profile points to the Portal host. These are build/developer configuration candidates, not evidence that the physical iPad uses either profile. No iPad build metadata, managed configuration, or sanitized on-device request capture is available in this workspace.
- The local Portal and Tablet Laravel server processes are present on loopback. The local Portal application's effective CLI configuration selects MySQL database `ommc_henri_reconcile_customers`. That identifies this configured local reconciliation database, not the database behind the iPad's endpoint. No live iPad endpoint or its runtime MySQL configuration has been established.
- No request was sent to the production-profile host. The preceding Work authorization prohibits PROD access; the current environment evidence does not bind that host to the physical iPad. No authenticated request was attempted against an unverified target. Therefore the physical iPad's actual location Pull response is **not verified**, and the known local inconsistency cannot be attributed to that iPad.

### Read-only reference-source search

- Inspected available Portal/V1/Tablet source names, reference migrations/models/seeders, and the archived reconciliation artifacts for a Specific Region export or backup containing IDs 52–68. Available code establishes schema and relationships only; no authoritative reference export/backup with those parent rows was found. V1 contains no canonical Specific Region master table. The archived reconciliation material is Customer audit/reconciliation output, not an approved source for Specific Region names or Region parents.
- The current local Portal database evidence remains the previously established result: Specific Region IDs **52–68** are absent; **34** enabled Area Clusters **16–49** reference them. Exact pairs: 16→52, 17→52; 18→53; 19→54, 27→54, 31→54; 20→55; 21→56; 22→57; 23→58; 24→59; 25→60, 26→60, 43→60; 28→61; 29→62, 32→62; 30→63, 48→63, 49→63; 33→64; 34→65, 35→65, 44→65; 36→66, 38→66, 39→66; 37→67, 40→67, 46→67; 41→68, 42→68, 45→68, 47→68.
- Missing row data is exact as to identity and relationships observed in the children: IDs **52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68** have no Specific Region rows in the inspected local database. The intended `name` and required physical `region_id` for each row are unknown; Area Cluster child rows do not determine either value. No approved exact correction dataset can be prepared.

### Required next evidence and disposition

- To establish the iPad target, capture its current location Pull request URL from the deployed iPad build or its managed endpoint configuration; include scheme, host, and path only, with Authorization/Cookie headers and query secrets removed. A deployment/build owner must identify the active build/profile. If the host is production, any request must be separately authorized under the applicable production-access policy.
- To verify server database identity, an authorized operator for that exact running Portal instance must provide a credential-free effective connection identity (driver and database name) or run the read-only identity query there. Then a read-only request to that same endpoint can compare every returned Area Cluster parent against the returned Specific Region ID set.
- Obtain an authoritative reference export/backup containing each missing ID, exact name, canonical `region_id`, and confirmation of the associated Area Cluster parent links. Without it, stop before any data correction. No data/code/schema/migration/seeder changes were made in this investigation.
- Work remains `ACTIVE`. No production request, database write, migration, seed, code change, commit, push, or deployment occurred. Stop for Human review.


## Addendum — Customer identity and edit regression corrections (2026-10-10)

- Tablet Pull now resolves a Portal Customer by authoritative `server_id`, then exact `local_uuid`, then the legacy same-ID fallback. It rejects conflicting UUID/server-ID matches and duplicate local rows mapped to one Portal ID before applying the pull transaction. When a lost push acknowledgment is recovered by UUID, it stores the authoritative Portal `server_id` while preserving the negative local primary key, local values for protected pending/failed rows, reservation token, sync status, and related local FKs. Normal pulled rows now persist a supplied `local_uuid`, enabling repeated Pull to update the same local row. No primary-key replacement or schema change was made.
- Customer Edit no longer applies the Add flow's obsolete Access-user-must-have-RSM rule. It preserves a hidden/inapplicable PIC on non-outlet edits instead of clearing it. Regression coverage confirms an Access user without RSM remains assigned, the existing PIC remains, and the local Customer edit succeeds.
- Tablet focused tests run: `CustomerPagedPullTest`, `CustomerCreatePageDiagnosticTest`, `CustomerPageDetailsTest`, `CustomerIdentityBoundaryTest`, and `CustomerOnlyPushTest`: **56 passed, 460 assertions**. Earlier Portal contract/Profile/Pull suites remain **30 passed, 350 assertions**. These tests use disposable/in-memory test databases.
- The 101-row matrix now marks Physical Region `ALIGNED` to the approved transient-selector contract. Other field rows remain conservatively `NEEDS RUNTIME EVIDENCE` unless a specific automated assertion covers the relevant path. Fleet remains `TESTED, SYNC NOT ACCEPTED`; no failed Fleet record was retried or changed.
- Full Company-specific Tablet-to-Portal-to-Tablet field round-trip is not established for all populated optional fields: the previously described temporary 185-assertion probe had a fixture-placement error for Fleet/OE/IB active profile fields. Portal-side variant/profile persistence and Portal Profile rendering are covered separately by direct Portal tests; physical iPad validation and a corrected integrated round-trip remain outstanding.
- Location Pull remains blocked by missing Specific Region parents in the inspected local reference data; this is deferred and unresolved. No reference validation was weakened.
- Work remains `ACTIVE`. No commit, push, deployment, PROD access, retained business-data writes, migration, or destructive Git operation occurred. `public/expense_attachments/` and unrelated worktree files remain preserved.

### Final validation update (2026-10-10)

- Tablet focused regression command (Pull identity/idempotency, Add/Edit, Customer detail, identity boundary, and Push): **56 passed, 460 assertions**.
- Portal focused sync/update/Profile/Pull command: **30 passed, 350 assertions**.
- Pint passed for the changed PHP files in both repositories. PHP lint passed for every changed/untracked PHP source/test file listed in the current Work diff. `git diff --check` passed in both repositories.
- Matrix has 101 data rows: **2 ALIGNED** (Physical Region by the approved transient-selector contract; Access after the Portal form/API validation alignment), **97 NEEDS RUNTIME EVIDENCE**, and **2 NOT IMPLEMENTED** (Portal-only/deferred fields). The matrix and note are mirrored byte-for-byte in both repositories.
- No full repository test suite or integrated live endpoint test was run in this continuation. No actual iPad validation was run. Deployment readiness remains **NO-GO** pending corrected populated-field end-to-end evidence, physical-device acceptance, and the separately deferred location reference-data issue.

## Addendum — Portal Access/RSM validation alignment and FLEET3739 evidence (2026-10-10)

### Portal Access validation finding and correction

- Exact source of the Human-reported message was the custom closure on `App\\Filament\\Resources\\Customers\\Schemas\\CustomerForm.php`, Access Select (`users`). It queried selected users with `whereNull('rsm_id')` and failed the form with “Each assigned Access user must have an RSM relationship.” No matching requirement exists in the Portal Tablet sync API, Customer model validation, or database schema. The API validates supplied Access email list shape, uniqueness, exact unique Portal User resolution, and relationship persistence; it does not require `rsm_id`.
- Removed only the Portal form's extra RSM requirement. Filament's `relationship('users', 'name')` validation remains and rejects invalid User IDs. No RSM is fabricated and no role/authorization logic was changed. Portal Access still persists to `customer_user`; DRM remains derived from Access user roles and RSM from those Users' `rsm_id`. The form displays “No RSM relationship” when no selected Access user resolves one; it does not invent a relationship.
- Added `CustomerAccessFormContractTest`: Portal Customer Edit accepts multiple valid Access users with and without RSM, preserves their RSM values and membership, rejects fabricated IDs, and retains Customer Code, PIC, nested Owner data, Fleet profile fields, and annual Category history. Existing API contract tests already cover no-RSM Access persistence/pull and unknown/duplicate identities; a new API regression covers multiple Access users with mixed RSM assignments and confirms neither assignment changes.

### FLEET3739 local failure evidence

- Read-only inspection of configured Tablet SQLite `database/database.sqlite` found the requested code `FLEET3739` as a local failed Customer: `sync_status=failed`, 3 attempts, `server_id=NULL`, `company_id=4`, `region_specific_id=53`, no Area Cluster, with local UUID and Customer Code reservation token present. The payload builder includes the local UUID, Customer Code/token, Fleet profile (`account_type`, `classifications`, `battery_class`, `status`, nested Owner), one Access email, and `region_specific_id=53`; values such as token/email/name were not disclosed. The local row has no annual-history records.
- Stored Push error is HTTP 422: `Portal returned HTTP 422: The selected region specific id is invalid. region_specific_id: The selected region specific id is invalid.` The message and field detail repeat the same rejection. The available stored evidence names only `region_specific_id`; the raw original HTTP response body was not separately retained, so this is based on Tablet's persisted response summary. No Push or manual retry was issued.
- The previous read-only local Portal investigation found Specific Region ID 53 absent in the configured reconciliation database. The physical iPad's effective Portal endpoint/database remains unverified; this does not prove that this is the exact database that rejected FLEET3739. Area Cluster/Specific Region recovery remains deferred.

### Validation and remaining limits

- Portal focused tests (`TabletCustomerCreationContractTest`, `CustomerAccessFormContractTest`): **25 passed, 262 assertions**. This covers no-RSM and mixed-RSM Access, multiple Users, invalid identities, persistence, and existing Customer fields.
- Tablet focused Customer tests (`CustomerCreatePageDiagnosticTest`, `CustomerOnlyPushTest`, `CustomerPagedPullTest`, `CustomerPageDetailsTest`, `CustomerIdentityBoundaryTest`): **56 passed, 460 assertions**. No Tablet code change was needed for this correction.
- Pint, PHP lint, and `git diff --check` passed in both repositories after the final edits. Tests used disposable SQLite databases; the FLEET3739 inspection opened the configured Tablet SQLite in read-only mode. No retained record was modified.
- Fleet remains `TESTED, SYNC NOT ACCEPTED`. Its preserved record/code/token still require authorized reference reconciliation before explicit retry; this Work did not retry or modify it. Physical iPad validation remains outstanding. Location Pull remains unresolved because of the deferred reference-data issue. Work remains `ACTIVE`; deployment readiness remains **NO-GO**.

## Addendum — independent Customer Pull after location snapshot failure (2026-10-10)

### Confirmed dependency and correction

- The Customer list action runs the paginated Customer Pull state machine in `PullsCustomers` / `SyncService::pullCustomersStep()`. A new run first refreshes locations, then calls the separate `/api/sync/pull/customers` endpoint. Previously any location Pull error returned immediately and prevented the independent Customer request.
- Portal Customer Pull response construction does not depend on a freshly returned location snapshot. Customer foreign keys are scalar canonical IDs; Tablet Customer location columns are nullable integer values without SQLite FK constraints. The Tablet can retain those IDs without inventing lookup rows. Customer detail relations may resolve to no label while the reference row is unavailable; the stored Customer IDs remain intact.
- The location snapshot validator remains strict and unchanged. A malformed/inconsistent snapshot is rejected before reference application; existing location tables remain unchanged. Customer Pull now proceeds against existing local lookups while preserving the Portal's exact physical and commercial FK values. No labels are used to remap IDs, and no Customer location value is cleared because a lookup is absent.
- Required Company is not optional: each Customer row must contain a valid Company ID resolvable from local or incoming Company references. Unresolved/malformed Company fails the Customer page transaction with an actionable error. This avoids silently accepting an incomplete required relationship. PIC/Access email mapping retains its existing fail-closed behavior.
- Final Customer list status now reports the two stages independently. Customer Pull success with failed location refresh is a partial result, and `full_sync_succeeded` remains false. A Customer endpoint failure is separately shown alongside any location failure. The single inline status area is updated in place rather than stacking duplicate notifications.
- The separate Salescall full-sync result now emits a success flag from the actual run outcome; its UI no longer announces completion as success after an exception. The ordinary schedule/full `SyncService::pull()` path still runs locations as a prerequisite and remains blocked by an invalid location snapshot. This correction applies to the independent Customer list Pull action only.

### Validation and remaining evidence

- Tablet focused tests: `CustomerPagedPullTest`, `LocationReferenceSnapshotTest`, `SyncServiceBinaryFailureResilienceTest`, `CustomerPageDetailsTest`, and `CustomerIdentityBoundaryTest`: **40 passed, 398 assertions**. Coverage includes invalid snapshot atomicity plus successful Customer Pull, unresolved required Company rejection, independently reported Customer endpoint failure, repeated-pull identity behavior, and false-success prevention.
- Portal focused sync contract tests: `TabletCustomerCreationContractTest`, `TabletSplitPullTest`, and `SyncPullCustomerScopeTest`: **29 passed, 296 assertions**. Portal code was not changed for this correction.
- Pint formatting and PHP lint passed for the changed Tablet service, concern, page, and focused test files; `git diff --check` passed in both repositories. No schema/migration or retained-data changes.
- The known local Area Cluster / Specific Region inconsistency (parents 52–68 missing from the inspected retained reconciliation database) remains a separate unresolved blocker. No parent rows, child links, or Tablet validation were changed. The physical iPad endpoint and its live response remain unverified.
- Physical iPad retest after a separately authorized build: use the Customer list “Pull Customers” action; verify the Customer pages load and display the preserved Portal IDs, while the invalid location refresh is reported as partial failure and local reference rows remain unchanged. Confirm the UI does not say “All Synced.” The standard full/schedule Pull is expected to remain blocked until the reference data is corrected. Do not retry exhausted Fleet Pushes as part of this Pull check.
- Work state remains `ACTIVE` pending Human functional review. No commit, push, deployment, PROD access, business-data writes, schema change, migration, or destructive Git operation occurred. Existing `public/expense_attachments/` and unrelated worktree state were preserved.

## Addendum — Customer Pull reconciliation evidence and outcome counts (2026-10-10)

### Request, pagination, filtering, and identity

- The Customer list `Pull Customers` button runs the Alpine component in `resources/views/components/customer-pull.blade.php`, which repeatedly invokes the Livewire `CustomerPage::pullCustomersStep()` action. That delegates to `SyncService::pullCustomersStep()`; each action makes at most one HTTP request.
- A new/resumed run attempts strict `/api/sync/pull/locations` first. Whether that stage succeeds or fails, the Customer stage requests `/api/sync/pull/customers` independently. Location validation/application remains atomic and unchanged.
- Portal validates `after_id` (nonnegative integer), `limit` (1–1000; Tablet currently sends 500), `updated_since` (date), and bounded comma-separated `ids`. It scopes rows to the authenticated Tablet user's visible Customers, orders by ascending Portal Customer ID, and uses keyset pagination (`id > after_id`). It fetches `limit + 1` to determine whether `next_after_id` is the last returned ID or null. `total` is returned only for the first page. On the final full-scan page, Portal returns `scope_ids`; Tablet records scope and fetches newly re-entered/inactive missing IDs in bounded `ids` requests. Subsequent incremental scans send the stored `updated_since` watermark, which Portal applies to Customer and supported child-row changes. The first-page server time is stored as the next watermark only after the run completes.
- Tablet reconciles each returned row by authoritative `server_id`, then exact `local_uuid`, then legacy same-primary-key fallback. Conflicting server-ID/UUID mappings fail the page transaction; repeated Pull updates the existing row rather than inserting a duplicate. A newly pulled row stores Portal `server_id` and `local_uuid`; an existing negative Tablet primary key and local related-record references remain in place.

### Safe outcome counts and user message

- Customer Pull progress now reports cumulative safe counts: `returned`, `inserted`, `updated`, `unchanged`, and `rejected`. These are aggregate counts only; no Customer names, IDs, payload values, or credentials are added to diagnostics.
- Inserted/updated/unchanged classification compares pre/post local Customer data and associated trade profile, annual category history, and Access relationships. Synchronization-only timestamps/state are excluded, so timestamp refresh alone is not reported as a Customer change. Customer row application, scope reconciliation, and cursor checkpoint share a local transaction. A page rejected by validation or scope reconciliation is rolled back and its returned row count is reported as rejected; retry does not inherit partial page writes.
- The completion message now names each count. For example, `0 changed` means no persisted Customer/profile/history/Access data differed; returned rows are separately reported as unchanged. When location refresh failed, the same message explicitly says Customer Pull succeeded, reference data was preserved, and full synchronization is incomplete. Location and Customer failures remain separately visible.

### Validation and limits

- Tablet focused tests: `CustomerPagedPullTest`, `LocationReferenceSnapshotTest`, `SyncServiceBinaryFailureResilienceTest`, `CustomerPageDetailsTest`, and `CustomerIdentityBoundaryTest`: **42 passed, 456 assertions**. New coverage pulls a new Portal Customer, updates an existing one, leaves an unchanged one intact over two keyset pages, verifies `updated_since`/`after_id`, checks no duplicate row, and proves zero changed is reported as unchanged. Existing coverage verifies strict invalid-location snapshot rejection with independent Customer Pull and separately reported Customer endpoint failure.
- Portal pagination/scope contract tests: `TabletSplitPullTest`, `SyncPullCustomerScopeTest`, `TabletCustomerCreationContractTest`: **29 passed, 296 assertions**. They exercise the actual Portal endpoint's scope, filters, cursor, changed-child selection, and serialization using disposable feature-test databases.
- The integrated Tablet-to-Portal disposable endpoint test (`CustomerCreatePortalE2ETest`) was run and **skipped** because no isolated Portal API endpoint is configured. The Tablet reconciliation regression uses an HTTP fake; it demonstrates correct behavior for Portal-shaped new/changed/unchanged responses but is not evidence that a new live Portal database row was fetched by a running Tablet. Human/device integrated confirmation remains needed.
- Pint passed in both repositories; PHP lint passed for changed Tablet Pull code/tests and the Portal endpoint; `git diff --check` passed in both repositories. No Portal application code, schema, reference validation, retained database, or reference data was changed for this addendum.
- Work remains `ACTIVE`. No commit, push, deployment, PROD access, or destructive Git operation occurred. Location-reference inconsistency remains unresolved; no Area Cluster or Specific Region correction was attempted.

## Addendum — Customer Pull notification wording (2026-10-10)

- Human-approved Customer Pull notification policy is now explicit: successful completion with no inserted or changed rows displays exactly `Customers are up to date.`; success with one or more inserts/changes displays exactly `Customer Pull completed successfully.`; only Customer Pull failure displays `Customer Pull failed. Please try again.` A successful empty response is a successful Customer Pull, never a failure.
- Internal aggregate counts (`returned`, `inserted`, `updated`, `unchanged`, `rejected`) remain in the Livewire progress response for troubleshooting, but the Customer Pull notification does not display them.
- A failed location-reference refresh does not change the Customer Pull notification. The service retains `location_refresh_failed` and `full_sync_succeeded=false`; the Customer list retains the partial-state styling. Technical location failure detail is recorded in a warning log with its error code and message, and remains excluded from the Customer Pull notification. The separate full/scheduled sync error flow is unchanged.
- This correction changes only the Customer Pull notification mapping and location-failure diagnostics. It does not change the Portal API, pagination/cursors, Customer reconciliation, Customer transaction, location validation/persistence, Push, or full/scheduled Pull orchestration.
- Focused Tablet tests: `CustomerPagedPullTest` **22 passed, 302 assertions**. Coverage includes zero Customers, unchanged records/zero changes, insert/update counts, actual Customer Pull failure, Customer success despite location failure, accurate partial/full-sync flags, and location failure diagnostic logging. The integrated disposable Tablet-to-Portal endpoint remains unavailable as recorded above.
- Pint, PHP lint, and `git diff --check` passed for the changed Tablet files. Work remains `ACTIVE`; no commit, push, deployment, PROD access, migration, or retained business-data change occurred. Expense attachments and unrelated worktree state remain preserved.


## Addendum — Annual Category UI binding and FLEET3740 (2026-10-10)

### Evidence and root cause

- Read-only inspection of the configured Tablet SQLite database found FLEET3740 / Test FLEET at local ID `-438237530857064606`, `server_id=23596`, `sync_status=synced`, with **zero** `customer_category_histories` rows. Read-only inspection of the configured local Portal database found Portal Customer ID `23596` with the matching identity and **zero** category-history rows. These are configured local database observations; they do not establish the physical iPad's Portal endpoint.
- No successful Push request body or durable UI selection evidence for FLEET3740 was available. Therefore whether annual values were selected for this particular Customer is **undetermined**; no category values have been inferred or recreated.
- The confirmed code defect is earlier than local persistence. The shared Tablet Add/Edit Blade emitted Alpine expressions such as `$wire.categories.fleet.2018`. JavaScript treats the numeric year after dot access as invalid syntax; Node reproduced a `SyntaxError`. The categories UI therefore did not reliably bind numeric year keys to Livewire state. This is the first confirmed failing boundary. The Portal profile correctly renders an empty marker when no corresponding history row exists.
- Once populated values exist in component state, Tablet `CustomerProfileFormService::saveAggregate()` persists nonblank values as history keyed by profile/stream/year; Customer Push serializes those rows; Portal validates and upserts them on Customer/profile/stream/year; Portal Pull returns them. Omitted rows are not deleted. The correction changes the shared Add/Edit binding to bracket access (`$wire.categories['fleet'][2018]`) and does not change the API or history schema.

### Correction and regression coverage

- Tablet Add/Edit now emits bracket-notation bindings for numeric annual years. Added Fleet coverage for 2018/2026 values across local Add, Push payload, Edit, and update Push, asserting an edit to one year preserves the other. Added an intentionally blank Fleet case asserting no fabricated history rows.
- Portal contract coverage asserts a supplied Fleet history is persisted under the canonical Fleet profile/stream/year and returned by Customer Pull. Portal Profile coverage asserts the Annual Categories section and empty-year display when no history exists. No Portal application code changed.
- The 101-row matrix's annual-category rows record the corrected browser binding, unchanged persistence/sync path, and this unresolved per-device evidence; their status remains `NEEDS RUNTIME EVIDENCE` until integrated/device validation.

### Validation and remaining evidence

- Tablet focused command (`CustomerCreatePageDiagnosticTest`, `CustomerOnlyPushTest`, `CustomerPagedPullTest`, `CustomerCreatePortalE2ETest`): **59 passed, 594 assertions, 1 skipped**. The skipped integrated endpoint case has no isolated Portal API endpoint configured.
- Portal focused command (`TabletCustomerCreationContractTest`, `CustomerSyncPortalViewTest`): **25 passed, 312 assertions**.
- Pint passed in both repositories. PHP lint passed for the changed PHP test files. `git diff --check` passed in both repositories. The Tablet category path was exercised with Livewire/database and mocked Push tests; integrated live Tablet-to-Portal execution and physical iPad browser behavior remain unverified.
- Physical QA: on the iPad, use a current authorized build; create a disposable Fleet Customer with distinct category choices for at least 2018 and 2026, verify local history before Push, Portal history and Profile after Push, then Pull and verify Tablet history/View. Edit only one year and verify other years survive Push/Pull. Also create a Fleet Customer with intentionally blank categories and confirm no history is fabricated. Confirm the iPad's intended Portal environment before comparing records. For FLEET3740, re-enter values only from an authoritative source if Human confirms they were intended; do not invent history.
- Work remains `ACTIVE`. No retained business data, schema, reference data, or migrations were changed; no commit, push, or deployment occurred. Existing expense attachments and unrelated worktree state remain preserved.


## Terminal delivery record (2026-10-10)

- Human granted functional acceptance for the Customer synchronization and view-alignment scope. Latest Human QA: FLEET3741 displayed Customer Information, Fleet Profile, Owner Profile, and Annual Category values in Portal. This acceptance does not erase separately deferred reference-data and earlier failed-record issues.
- Final focused validation on the delivery source: Portal **35 passed, 392 assertions** (`TabletCustomerCreationContractTest`, `CustomerAccessFormContractTest`, `CustomerSyncPortalViewTest`, `TabletSplitPullTest`, `SyncPullCustomerScopeTest`); Tablet **94 passed, 829 assertions** across Customer detail/identity/access/search, Push/Pull, location contract, Add/Edit, retry, and sync failure resilience suites. Pint passed in both repositories; PHP lint passed for all changed PHP source/test files; `git diff --check` passed in both.
- Work-owned files were reviewed and are staged only in clean delivery worktrees based on the fetched `origin/main` tips. Repository-specific implementation commit hashes will be added to the mirrored terminal record after the implementation commits are created; a commit does not contain its own hash.
- Deferred and unresolved: invalid Specific Region parents 52–68 in the inspected local reference dataset; location refresh and full/scheduled Pull limitations remain unresolved and were not bypassed. FLEET3739 and other exhausted Fleet 422 records, including reservation reuse and reference reconciliation before retry, remain separate follow-up work. FLEET3741 acceptance does not imply those records synchronized. No reference repair, migration, business-data write, PROD access, or deployment occurred.
- Work state: **TERMINAL_DELIVERED**. Delivery was performed as separate Portal and Tablet commits pushed normally to `origin/main`; no force push. Original worktrees and Tablet expense attachments were preserved.
