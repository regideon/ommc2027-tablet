# OMMC-20261011-customer-region-access-automation

- **State:** TERMINAL_DELIVERED
- **Mode:** Terminal execution
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Required delivery scope:** Full functional parity in Portal and Tablet, including Add/Edit and Tablet → Portal → Tablet reconciliation
- **Objective:** Preserve the physical Region location form, use the selected Specific Region as the persisted commercial location and Customer Access driver, and repair the offline location-reference contract through a reviewed rollout.
- **Authorization:** Human accepted Phase 4A as a conditional pass and authorized the same-value Access preservation follow-up in Portal and Tablet, then authorized the bounded Tablet no-controller warning correction. Disposable test databases only. No Phase 4B, retained database writes, User provisioning, reference repair, PROD access, deployment, commit, or push.
- **Current review:** The no-controller warning correction is accepted. Final implementation review and disposable regression suites pass for the Work-owned scope. Terminal authorization has not been given; no staging, commit, or push is authorized.
- **Separate data gate:** Reconcile the Human-supplied 46 Access assignments and all user identities. No identity guessing or User provisioning in this Work phase.

## Human decisions and accepted contracts

- Region remains the physical geographic Region. No five-value Customer Business Region or separate Access selector is added.
- Specific Region is selected under the physical Region and controls geographic Customer Access through an explicit approved mapping.
- Region IV-A can contain Specific Regions from different business Access groups. Do not infer group membership from Specific Region names, Area Cluster names, or codes.
- Area Cluster choices depend on Specific Region; auto-select only when exactly one valid enabled cluster exists. Manual choice remains available when multiple clusters exist.
- Province depends on physical Region; Municipality depends on Province while preserving independent-locality handling; Barangay depends on Municipality.
- OE and IB Access remain Company-scoped.
- `customer_user` remains canonical Customer Access. No existing Access backfill or bulk update. No forced RSM or role-based Access eligibility.
- When Specific Region changes, an authorized Customer save replaces the whole Access selection with mapped users. When Specific Region is unchanged, preserve Access and manual changes.
- Preserve Customer Push/Pull omission semantics, stable Tablet negative local Customer IDs, Portal canonical Customer identity, and terminal Customer Sync audit behavior.

## Scope clarification — full Portal/Tablet parity

Both applications are explicitly in scope. Planning or implementation of only one application does not satisfy this Work.

**Portal responsibilities:** canonical physical and commercial location reference data; canonical Customer and `customer_user` persistence; Access mapping configuration; Customer Add/Edit dependencies; backward-compatible Push/Pull endpoints; and reference-data integrity validation.

**Tablet responsibilities:** offline location and Access mapping cache; matching Customer Add/Edit dependencies and selection behavior; offline Access resolution; Push/Pull reconciliation; and stable negative local Customer IDs until Portal assigns the canonical identity.

Both applications must support physical Region selection, Specific Region filtering by physical Region, Area Cluster filtering with auto-selection only for exactly one valid choice, Province/Municipality/Barangay dependencies, geographic Access derived from the selected Specific Region, OE/IB Company mappings, preservation of manual Access while controlling values remain unchanged, and whole-list replacement when controlling values change.

## Validation acceptance gate

Report Portal and Tablet validation separately. Required evidence includes the application-specific form, persistence/cache, mapping, and regression coverage described below. Also run and report an end-to-end Tablet offline Add/Edit → Portal Push and canonical persistence → Tablet Pull reconciliation flow, including Access and location values and negative-ID stability. Completion requires passing results from both applications and the cross-application flow; one-sided behavior is incomplete.

## Current authorized phase

The same-value Access preservation follow-up and bounded Tablet no-controller correction are accepted. Phase 4B reference restoration and production data activation remain outside this authorization. The Work is ready for Human terminal review; do not stage, commit, or push without separate terminal authorization.

## Final terminal readiness review — 2026-10-11

### A. Change inventory and proposed staging paths

Review of both worktrees found no changed or untracked files outside this Work. No staging has occurred. The following lists are proposed staging paths only.

**Portal — proposed Portal commit (15 paths):**
- `app/Filament/Resources/Customers/Pages/EditCustomer.php`
- `app/Filament/Resources/Customers/Schemas/CustomerForm.php`
- `app/Http/Controllers/Api/SyncController.php`
- `app/Models/CustomerAccessGroup.php`
- `app/Models/CustomerCompanyAccessConfiguration.php`
- `app/Models/RegionSpecific.php`
- `app/Models/RegionSpecificAccessGroup.php`
- `app/Services/CustomerAccessMappingService.php`
- `app/Services/TabletSyncPayload.php`
- `database/migrations/2026_10_11_120000_create_customer_access_mapping_tables.php`
- `docs/work/OMMC-20261011-customer-region-access-automation.md`
- `tests/Feature/CustomerAccessMappingFormTest.php`
- `tests/Feature/CustomerCodeCustomerTableTest.php` (reviewed test-only assertion correction; application query behavior is unchanged)
- `tests/Feature/TabletCustomerCreationContractTest.php`
- `tests/Feature/TabletSplitPullTest.php`

**Tablet — proposed Tablet commit (12 paths):**
- `app/Filament/Pages/CustomerCreatePage.php`
- `app/Filament/Pages/CustomerEditPage.php`
- `app/Models/CustomerAccessMapping.php`
- `app/Services/CustomerAccessMappingResolver.php`
- `app/Services/SyncService.php`
- `database/migrations/2026_10_11_133824_create_customer_access_mappings_table.php`
- `docs/work/OMMC-20261011-customer-region-access-automation.md`
- `resources/views/filament/pages/customer-create-page.blade.php`
- `tests/Feature/CustomerCreatePageDiagnosticTest.php`
- `tests/Feature/CustomerCreatePortalE2ETest.php`
- `tests/Feature/CustomerLocationReferenceSchemaTest.php`
- `tests/Feature/LocationReferenceSnapshotTest.php`

Proposed commit boundary: one Portal commit containing only the Portal paths above, and one Tablet commit containing only the Tablet paths above. Keep all other paths excluded. No commit message or terminal action is authorized yet.

### B. Final behavior and compatibility review

- Portal and Tablet both use the selected `region_specific_id` for geographic Access; OE/IB Companies use Company-scoped mapping keys.
- Explicit Access in Tablet Push remains authoritative, including an explicit empty list. Omitted Access applies a complete mapping only on a genuine controller change; unchanged controller values preserve manual Access. Incomplete or unavailable mappings preserve the prior relationship. Tablet no-controller resolution is neutral and warning-free.
- Both resolvers avoid role/RSM filtering. `customer_user` remains canonical Portal persistence; Tablet identity reconciliation remains email-based.
- Physical Region filters Specific Region and Province; Specific Region filters Area Cluster and auto-selects only a single enabled option. Province → Municipality → Barangay filtering and independent-locality behavior remain. Portal dropdowns show their scoped options without requiring search text and do not preload national Municipality or Barangay lists.
- Portal adds five additive mapping tables with unique keys and foreign keys; Tablet adds one additive cache table with a unique `(scope, mapping_key)` key and serialized email identities, without cross-database User IDs. No mapping rows are seeded. Rollback drops only new tables, child-first. No Customer, `customer_user`, location, User, or existing mapping data is backfilled or altered.
- Tablet mapping serialization is additive to the reference payload; strict snapshot validation remains. Legacy Push omission behavior, negative Tablet Customer IDs, and Portal canonical identity remain covered by the accepted disposable E2E evidence.

### C. Final validation

- **Portal:** 69 passed, 558 assertions on PHPUnit SQLite in-memory, across mapping forms, location form, Push contracts, mapping serialization/Pull, Customer Code query assertions, and sync regressions. Pint and `git diff --check` passed.
- **Tablet Work-focused suite:** 116 passed, 895 assertions; one connected E2E test skipped in this run because no isolated `CUSTOMER_CONTRACT_PORTAL_URL` was configured. Pint and `git diff --check` passed.
- **Preserved Phase 4A disposable E2E evidence:** one live synthetic Tablet → Portal → Tablet test passed with 67 assertions. It used isolated disposable databases and synthetic mappings/users; it is not real-data E2E evidence and makes no production-activation claim.
- A broader Tablet run also exercised the untouched `CustomerLocationMapTest` and observed two failures where its test municipality fixture lacks `psgc_code`, while the unchanged address resolver intentionally restricts those geocoder matches to PSGC-backed rows. The failures are in that untouched fixture/test and resolver; they are outside this Work's selector/mapping changes and the separately queued location-automation scope. The dedicated `PhilippineAddressResolverTest` passed. No test or resolver correction was made here.

### D. Deferred dependencies and safety

Deferred separately from this implementation package: real Access mapping activation; reconciliation of all 46 supplied User assignments; missing Specific Region references; 34 orphan Area Cluster parents; Province/Municipality-driven location automation; NIR coverage; and legacy NCR parent reconciliation. Mapping and User tables remain unpopulated. Existing Customers were not backfilled.

All new migrations were exercised only through disposable test databases. The earlier Phase 4A E2E used the recorded disposable schema/file and synthetic identities. No retained database was written, no production system was accessed, and no real Users were provisioned. A production-schema compatibility preflight and separately authorized data rollout remain future work.

### E. Terminal readiness verdict

**READY_FOR_TERMINAL_REVIEW** for the implementation package and proposed staging sets above. This is not authorization to stage, commit, push, deploy, activate real mappings, or start the separate Location Automation Work. Await separate Human terminal authorization.

## Final Access readiness review — 2026-10-11

**Status: NOT READY for terminal review; implementation correction requires Human approval.** Same-value correction remains the accepted working baseline.

- Tablet `CustomerAccessMappingResolver::forCustomer()` checks for the local `customer_access_mappings` table before checking whether a controller is selected. If the cache table is absent and both Company and Specific Region are unset, it returns `Customer Access mappings are unavailable offline. Existing Access is preserved.` This is misleading: no mapping lookup is applicable until a controlling value exists. Tablet `CustomerCreatePage::inspectAccessMappingAvailability()` and Edit hydration surface this warning. The observed iPad message is consistent with a missing local cache table, but this review did not inspect or modify the iPad database.
- Minimal correction identified during the initial review: resolve controller applicability before cache availability. This correction was subsequently approved and implemented; see “Bounded Tablet no-controller correction” below.
- With a valid controller, current resolver behavior distinguishes: absent mapping table → offline cache unavailable; missing row or `is_complete=false`/empty users → missing or incomplete mapping warning; invalid email reference → invalid mapped identity warning; zero or multiple local email matches → mapped User unavailable/ambiguous warning; valid complete mapping with unique local email matches → complete user IDs and no warning. Mapping failures return no IDs; page recalculation returns early and retains the current Access selection.
- Reference Pull failures and rejected snapshots are reported through Pull result/logging and strict transactional validation; the form resolver does not identify Pull failure as its own warning state. A missing mapping row can mean no mapping configured or mapping references not successfully synchronized; the UI warning does not distinguish those causes. Successful snapshots cache mapping rows; incomplete rows remain non-applicable and do not replace Access.
- No-controller with an existing mapping table currently returns the non-offline guidance `Select a Specific Region to resolve Customer Access. Existing Access is preserved.` rather than a neutral state. No-controller with absent table returns the misleading offline warning above. This needs correction for the explicit no-controller requirement.
- Portal resolver has no offline-cache concept. With no geographic controller it reports that Specific Region is required; incomplete mappings preserve current Access. Complete mapping selection and changed/unchanged precedence are consistent with Tablet at the behavior level, though the no-controller presentation differs.
- Disposable SQLite validation this review: Tablet `CustomerCreatePageDiagnosticTest` plus `CustomerLocationReferenceSchemaTest`: 29 passed, 264 assertions. Portal `CustomerAccessMappingFormTest`, `TabletCustomerCreationContractTest`, and `TabletSplitPullTest`: 56 passed, 429 assertions. Tablet cross-app E2E test skipped because `CUSTOMER_CONTRACT_PORTAL_URL` was not set to an isolated disposable Portal API; no real-data E2E claim is made. The test suite did not cover absent-cache plus no-controller, which is the identified defect.
- Manual Access remains selectable and is retained when mapping is incomplete; existing focused Portal and Tablet tests passed. Real mapping assignments, identity reconciliation, and reference synchronization remain unapproved data gates. Location reference restoration and location automation remain a separate Work dependency.
- Classification: (A) Tablet resolver no-controller warning ordering is an implementation defect; (B) real Region/Company mapping assignments and User identities are unapproved production-data dependencies; (C) missing Specific Region/Area Cluster/NIR reference reconciliation and deferred location automation are separate Work dependencies.
- At the time of this initial review, terminal readiness was blocked by the no-controller defect. It has since been corrected and tested as recorded below. No real-data activation, production mapping, reference repair, PROD access, deployment, commit, or push occurred.

## Bounded Tablet no-controller correction — 2026-10-11

**State: ACTIVE; implementation defect corrected; stopped for Human review.** Human approved the bounded correction. Same-value update behavior and Portal Push precedence were left unchanged.

- Root cause confirmed: `CustomerAccessMappingResolver::forCustomer()` inspected the mapping-cache table before determining whether the selected Company or Specific Region provided an applicable Access controller. Edit hydration could consequently show an offline warning for an unset controller when the cache table was absent.
- The resolver now determines whether an OE/IB Company controller or geographic Specific Region controller applies first. With no applicable controller it returns `complete=false`, empty mapped IDs, and `warning=null`; it does not inspect the mapping cache. The existing caller therefore preserves the current/manual Access list and displays no warning. Applicable controllers retain existing cache, completeness, reference, and local email identity resolution behavior.
- Added Add and Edit regression coverage for no controller with the cache table absent, and with unrelated incomplete cached mapping data. Also covered a selected geographic controller with missing cache and Add/Edit OE/IB mappings that are incomplete. Existing tests continue to exercise complete and missing geographic mappings, complete OE/IB mappings, manual Access preservation, same-value/repeated updates, and Edit hydration.
- Files changed for this correction: `app/Services/CustomerAccessMappingResolver.php` and `tests/Feature/CustomerCreatePageDiagnosticTest.php`. Mirrored Work notes updated. No Portal application code changed.
- Disposable SQLite validation: `CustomerCreatePageDiagnosticTest`, `CustomerLocationReferenceSchemaTest`, `LocationReferenceSnapshotTest`, and `CustomerCreatePortalE2ETest`: **48 passed, 383 assertions; 1 skipped** because no isolated `CUSTOMER_CONTRACT_PORTAL_URL` was configured. Pint (`--test`) passed for both changed PHP files; `git diff --check` passed.
- No further implementation defect was found in the tested scope. Complete synthetic mappings resolve without warning; incomplete mappings retain Access. This correction does not establish real mapping activation or real-data Tablet → Portal → Tablet completion.
- Recommendation: the bounded Tablet correction is ready for Human review. Do not claim production activation. Real mapping/User data gates remain unapproved, and reference restoration/location automation remains a separate Work dependency. Work remains ACTIVE as directed.

## Verified current state

Read-only Portal MySQL inspection explicitly selected `ommc_henri_local_latest`; `SELECT DATABASE()` returned that database on `Januss-MacBook-Pro.local:3306`. No database writes were issued.

| Reference/data table | Portal rows | Tablet local SQLite rows |
|---|---:|---:|
| `regions` | 19 | 19 |
| `region_types` | 10 | 10 |
| `region_specifics` | 1 | 18 |
| `area_clusters` | 44 | 44 |
| `provinces` | 85 | 85 |
| `municipalities` | 1,643 | 1,643 |
| `barangays` | 42,010 | 42,010 |

Portal has 18 PSGC-coded physical Regions plus legacy `MM / Metro Manila` (no PSGC code). The physical hierarchy is `regions → provinces → municipalities → barangays`. Province has a physical `region_id`; Municipality has `region_id` and optional `province_id`; Barangay belongs to Municipality. Customers store nullable Province, Municipality, and Barangay IDs, but no persisted physical Region ID.

The current commercial hierarchy is `customers.region_specific_id → region_specifics.region_id → regions`, with `customers.area_cluster_id → area_clusters.region_specific_id`. The commercial parent reuses the physical `regions` table, but it does not express the five Access groups.

Portal currently has only Specific Region ID 1 (`National Capital Region (NCR)`, parent Region ID 1, legacy Metro Manila). Thirty-four of its 44 Area Clusters refer to absent Specific Region IDs 52–68; 10 clusters are parented to ID 1. Tablet local SQLite contains the 18 Specific Region rows and all 44 clusters. The Portal location payload is a full snapshot; Tablet validates parent references and applies a snapshot transactionally. The Portal snapshot therefore fails Tablet parent validation because 34 cluster parents are absent, leaving the Tablet's previously stored rows in place. This is the likely explanation for the Portal/Tablet count difference; the reason the restored Portal snapshot lacks those rows is not established here.

The physical Region/Province selector dependency currently requires a selected physical Region. Of 85 Provinces, 82 have a physical Region; three legacy city entries (Quezon City, Caloocan, Manila) have no physical Region. There are 1,035 Customers with Province and no Municipality, 54 with neither, and 37 with a stored Province that differs from the Municipality's Province. Customer Edit currently hydrates physical Region through Municipality, so Province-only records do not establish the selector state.

The five requested labels are not safely represented by `region_types`. That table is linked to Users and supports salescall/media behavior; it contains 10 mixed values, including Fleet/OE/IB Team, and uses `Metro Manila` where the Access list says `GMA`. It has no Customer or Specific Region relationship. Reusing it would change its established semantics.

## Specific Region to Access group reconciliation proposal

The 18 Tablet records are listed below. For records with a canonical physical parent other than the known Region IV-A split, the candidate group is obtained by resolving the parent Region through the previously Human-supplied proposed physical crosswalk, then storing that result as an explicit Specific Region mapping. The earlier crosswalk was proposed for review; its approval is not recorded. Therefore these candidate rows still require Human approval before they become mapping data. This is not runtime derivation and it does not use Specific Region names or Area Cluster prefixes to determine the group.

| Tablet Specific Region ID | Physical parent ID | Specific Region name | Proposed Access group | Status |
|---:|---:|---|---|---|
| 1 | 1 (legacy `MM`, no PSGC) | National Capital Region (NCR) | GMA | Needs Human approval: reconcile legacy parent to canonical NCR ID 2 or approve an explicit legacy alias |
| 52 | 7 | Region IV-A (Cavite, Rizal) | Unresolved | Human mapping required; cannot infer from name/code. Region IV-A split is an explicit exception |
| 53 | 7 | Region IV-A (Laguna, Batangas, Quezon) | Unresolved | Human mapping required; cannot infer from name/code. Region IV-A split is an explicit exception |
| 54 | 6 | Region III (Central Luzon) | NL | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 55 | 16 | Region XI (Davao Region) | MIN | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 56 | 15 | Region X (Northern Mindanao) | MIN | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 57 | 14 | Region IX (Zamboanga Peninsula) | MIN | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 58 | 17 | Region XII (SOCCSKSARGEN) | MIN | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 59 | 18 | Region XIII (Caraga) | MIN | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 60 | 19 | Bangsamoro Autonomous Region in Muslim Mindanao (BARMM) | MIN | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 61 | 5 | Region II (Cagayan Valley) | NL | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 62 | 3 | Cordillera Administrative Region (CAR) | NL | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 63 | 4 | Region I (Ilocos Region) | NL | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 64 | 9 | Region V (Bicol Region) | SL | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 65 | 8 | Region IV-B (MIMAROPA) | SL | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 66 | 12 | Region VII (Central Visayas) | VIS | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 67 | 13 | Region VIII (Eastern Visayas) | VIS | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |
| 68 | 10 | Region VI (Western Visayas) | VIS | Proposed from approved physical Region crosswalk; confirm as explicit Specific Region mapping |

The Portal has no Specific Region rows for IDs 52–68. They are Tablet-local candidates only until the Portal source and parent identities are reconciled. NIR has a physical Region in the Portal but no Specific Region in the 18-row Tablet snapshot; whether a NIR Specific Region should be added is unresolved. No assignment is made for NIR in this proposal.

## Proposed architecture

### A. Minimal schema

Do not add `customers.region_id` or another Customer location selector. Reuse `customers.region_specific_id` as the persisted commercial location reference. Keep the physical Region form state separate and derive physical Region from Municipality or Province where available.

Add a dedicated, stable five-row `customer_access_groups` lookup (`GMA`, `NL`, `SL`, `VIS`, `MIN`) and a `region_specific_access_groups` mapping with:

- foreign key `region_specific_id → region_specifics.id`, unique so a Specific Region has at most one Access group;
- foreign key `customer_access_group_id → customer_access_groups.id`;
- an `enabled` flag; only enabled mappings with valid Specific Region references and non-deleted eligible Users can supply Access;
- no name/ID literals in application logic. Mapping data must resolve Specific Region references by an approved stable key and fail closed on duplicate or unmatched rows.

Add `customer_access_group_user` with foreign keys to the Access group and `users`, unique `(customer_access_group_id, user_id)`. Add a `customer_company_access_configurations` header with one row per Company and a completeness marker, plus `customer_company_access_configuration_user` with unique `(configuration_id, user_id)`. Access writes continue to use the existing `customer_user` pivot.

`region_types` should not be the target mapping table without a separate Human decision changing its semantics. Dedicated lookup rows and explicit Specific Region mappings avoid coupling Customer Access to User/salescall classification.

### B. Form behavior

- Physical Region remains the location Region selector.
- Filter Specific Region by `region_specifics.region_id = selected physical Region`; preserve multiple Specific Regions for Region IV-A and require the user to choose the correct one.
- When Specific Region changes, clear stale Area Cluster and recalculate Access selection from the explicit Specific Region → group → user mapping.
- When Specific Region stays unchanged, preserve current Access, including manual changes.
- Area Cluster options are enabled children of selected Specific Region. Auto-select only when exactly one valid child exists; otherwise preserve manual choice or leave unset.
- Province options filter by selected physical Region, excluding rows with no physical Region; preserve independent-locality handling for municipalities without Province.
- Municipality options filter by selected Province and physical Region, with existing null-Province independent locality behavior retained.
- Barangay options filter by selected Municipality.
- On Customer Edit, hydrate current physical Region from Municipality, then Province where possible. Do not backfill existing Customers; unresolved legacy location remains nullable and unchanged until a user makes an authorized edit.

### C. Location reference reconciliation

Before deploying dependent selectors or strict offline mapping:

1. Preflight Portal and Tablet records by stable natural identity, not assumed numeric-ID equality: physical parent stable code/PSGC plus exact Specific Region name; verify uniqueness before applying.
2. Review/import the 17 Tablet candidate Specific Region records 52–68 into the authoritative Portal reference source only after approval. Resolve legacy NCR Specific Region ID 1 against canonical physical NCR ID 2 explicitly.
3. Reconcile all 44 Area Clusters by approved `(Specific Region identity, cluster code)`; the current 34 orphan rows are not independently usable until their parents exist.
4. Decide whether to add the missing NIR Specific Region. Do not manufacture it during this planning phase.
5. Confirm Province and Municipality parent integrity and the 37 Customer Province/Municipality disagreements; do not bulk-correct Customer data under this Work.
6. Re-pull the complete reference snapshot and verify its counts and parent graph before marking Tablet location references complete.

## Push/Pull and offline compatibility

- Portal remains canonical for Customer IDs and Access persistence. Tablet continues to use stable negative local IDs until Portal acknowledges the canonical Customer ID.
- Add only nullable/optional Customer location or mapping metadata if implementation proves it necessary; preferred Access group resolution uses the existing `region_specific_id` plus server-side mapping and does not require another Customer field.
- For old clients that omit Specific Region or Access keys, retain existing omission semantics. For new Region/Specific Region changes, send explicit selected `region_specific_id` and complete selected `access_user_emails`; Portal validates and persists through `customer_user`.
- Offline Tablet must pull the Access group lookup, Specific Region → group mapping, and all eligible mapped Users needed for assignment. Resolve Users by approved stable identity/email, never by guessed name or local numeric ID.
- Keep the strict location snapshot compatible. If its required sections or counts change, version the contract or add an independently versioned mapping payload so old Tablets do not reject an expanded count manifest.
- No Customer Access backfill. The new mapping schema does not alter existing Customer or `customer_user` records; existing Access changes only on an authorized save.

## Tests and rollout sequence

### Required regression and E2E coverage

Validation results must be reported in three distinct sections: Portal, Tablet, and cross-application E2E. For each application, cover Add and Edit form dependencies, manual Access preservation on unchanged controls, replacement on changed controls, mapping resolution, and persistence (Portal) or offline cache/local persistence (Tablet). The E2E result must show a Tablet-created or edited Customer moving through Portal Push/Pull and returning to Tablet with canonical identity, location, and Access reconciled while the negative local ID remains stable according to the existing contract.

- Region filtering for all physical Regions and Specific Region choices; Region IV-A shows multiple Specific Regions.
- Every approved Specific Region → one Access group mapping; unresolved or duplicate mappings fail closed.
- Correct unique user assignments for GMA, NL, SL, VIS, MIN; OE and IB use Company mapping; no duplicate `customer_user` rows; no role/RSM constraint.
- Changing Specific Region replaces the whole Access selection without accumulating the old group; reselecting/keeping the same Specific Region preserves manual Access edits.
- Area Cluster filters by Specific Region; one enabled child auto-selects; zero/multiple children do not guess.
- Province filtering by physical Region, Municipality filtering by Province, independent localities, Barangay filtering by Municipality, and Province-only edit hydration.
- Portal Add/Edit persistence and `customer_user` destination; Tablet offline Add with negative ID stable through Push; Portal canonical ID returned; Pull reconciliation retains exact location and Access.
- Old-client omitted-key semantics, stale/incomplete offline mapping behavior, and strict reference snapshot rejection/atomicity.
- Existing Customers remain compatible and are not bulk-updated.

### Rollout order

1. Human approves the Specific Region → group rows, including NCR legacy handling, Region IV-A split, and NIR.
2. Human separately approves the 46-person identity reconciliation and eligible User manifest; no identity or role inference.
3. Produce a read-only target preflight for duplicate natural keys, orphan parents, invalid Areas, and current PROD schema compatibility. No PROD connection is authorized in this Work phase.
4. Prepare additive reversible schema migrations and idempotent reference/mapping data resolution; require exact preflight success before applying. Rollback drops only new mapping/schema objects and never deletes existing Customers, Access, or location references.
5. Deploy Portal schema/API with optional backward-compatible fields and complete approved reference data; verify Pull integrity.
6. Deploy Tablet local schema and offline mapping support; old clients continue using the existing payload contract until upgrade is available.
7. Run targeted automated tests and authorized end-to-end acceptance. Do not commit, push, or deploy without separate authorization.

## Remaining Human gates

- Approve the proposed physical crosswalk, then approve the 15 candidate rows derived from it as explicit Specific Region mapping rows.
- Explicitly assign Specific Region IDs 52 and 53 to different Access groups; no name or cluster-prefix inference is used here.
- Approve whether Specific Region ID 1 should be reparented/matched to canonical NCR Region ID 2 or handled as a legacy alias.
- Decide whether a NIR Specific Region should exist.
- Approve reference restoration and handling of the 37 Province/Municipality Customer mismatches separately from this prospective feature.
- Complete the separate 46-user identity/data gate, including ambiguous and missing candidates.
- Confirmed Human rule: a genuine geographic `region_specific_id` change replaces Access only after complete mapping resolution; unchanged values preserve manual Access. OE/IB use Company mapping changes.

## Phase 1 implementation record — Portal foundation

### Schema and behavior

- Added additive mapping tables: `customer_access_groups` (stable code/name and explicit `is_complete` readiness), `region_specific_access_groups` (one enabled group per Specific Region), `customer_access_group_user` (unique group/User pairs), `customer_company_access_configurations` (one explicit completeness marker per Company), and `customer_company_access_configuration_user` (unique configuration/User pairs).
- Added Eloquent models and relations for the two mapping paths. No groups, Specific Region mappings, Company configurations, User assignments, or Customer/pivot records are seeded or backfilled.
- Added `CustomerAccessMappingService`. It resolves OE/IB by Company, geographic Customers by Specific Region, and fails closed when mapping is missing, incomplete, empty, or includes soft-deleted Users. User roles and RSM are not used for eligibility.
- Portal Customer Add/Edit now filters Specific Regions by the physical Region, rejects a mismatched Specific Region, clears invalid dependent choices on Region changes, auto-selects an Area Cluster only when exactly one enabled child exists, and hydrates physical Region from Province when Municipality is absent.
- A changed geographic Specific Region replaces Access only when its mapping is complete. A changed Company recalculates Access only when either the old or new Company is on the OE/IB Company-scoped path. Unchanged controlling values preserve manual Access edits. Edit save restores the existing Access set if a changed control has incomplete mapping; the form displays the unresolved configuration message.
- Tablet Push preserves omitted Access when controls are unchanged; after the contract correction below, an omitted Access key on changed controls applies only a complete mapping. Explicit Access remains authoritative regardless of mapping completeness.

### Portal files changed

- `database/migrations/2026_10_11_120000_create_customer_access_mapping_tables.php`
- `app/Models/CustomerAccessGroup.php`
- `app/Models/RegionSpecificAccessGroup.php`
- `app/Models/CustomerCompanyAccessConfiguration.php`
- `app/Models/RegionSpecific.php`
- `app/Services/CustomerAccessMappingService.php`
- `app/Filament/Resources/Customers/Schemas/CustomerForm.php`
- `app/Filament/Resources/Customers/Pages/EditCustomer.php`
- `app/Http/Controllers/Api/SyncController.php`
- `tests/Feature/CustomerAccessMappingFormTest.php`
- `tests/Feature/TabletCustomerCreationContractTest.php`
- `tests/Feature/CustomerCodeCustomerTableTest.php` (acceptance-review-only assertion correction)
- This Work note

### Validation and boundaries

- Portal targeted suite: **46 passed, 399 assertions** across Access form contracts, the new mapping form tests, location mapping, Customer sync view, Pull scope, and Tablet Customer Push/Pull contract tests.
- PHP formatting: `vendor/bin/pint --format agent` passed for changed PHP files.
- Acceptance review isolated and corrected the Customer Code test's strict object identity assertion; see the review results below. Customer Code application behavior was unchanged.
- Tests ran with PHPUnit's configured `sqlite :memory:` database. No retained PROD-derived or other local database was accessed or changed. No production mapping data, User, or Customer assignments were written.
- Portal migration has not been applied to a persistent database. It was exercised only by tests using disposable in-memory schemas.
- Tablet application code and tests were not changed or run. Tablet Phase 2 and Tablet → Portal → Tablet E2E remain outstanding.
- No deployment, staging, commit, or push occurred.

### Remaining gates and next phase

- Mapping data remains intentionally empty pending authoritative Specific Region crosswalk approval, reconciliation of missing Portal reference records and orphan Area Clusters, NIR decision, and the separate 46-assignment identity reconciliation. No missing Users were provisioned.
- Before production rollout, preflight duplicate mapping keys, soft-deleted/missing Users, existing `customer_user` duplicate pairs, and compatibility of the additive MySQL migration. Existing duplicate `customer_user` rows are not cleaned by this phase.
- **Phase 2 readiness:** Portal foundation is implemented and targeted Portal tests pass. Tablet implementation may begin only after Human review of this Phase 1 result. Offline mapping payload/cache, Tablet Add/Edit parity, negative-ID preservation, and end-to-end reconciliation are not yet validated.
- Overall Work remains **ACTIVE** because Tablet Phase 2 and cross-application acceptance remain.

## Portal Phase 1 acceptance review

### A. Access Push contract review — corrected below

`Api\\SyncController::pushCustomer` distinguishes an omitted `access_user_emails` key (`null`) from an explicitly submitted empty list (`[]`). The initial review found that mapping resolution was only evaluated when Access was explicitly submitted; the Human-approved correction below changes that behavior.

| Push case | Current Portal result |
|---|---|
| Explicit Access list, unchanged or changed controllers | Resolves each exact email to one Portal User and syncs that list to `customer_user`, regardless of mapping completeness. |
| Explicit empty list, unchanged or changed controllers | Syncs an empty list and clears Access. |
| Omitted Access, unchanged controllers | Does not sync; existing Access remains. This preserves legacy sparse Tablet clients. |
| Omitted Access, changed controller, complete mapping | Resolves and syncs the complete configured mapping. |
| Omitted Access, changed controller, incomplete mapping | Does not sync; existing Access remains and response includes `access_mapping_warning`. |

The initial review identified the omitted-key/changed-controller mismatch and inconsistent explicit-list handling. The Human then approved explicit Access precedence and authorized the correction recorded below.


## Portal Push contract correction — Human-approved

The Human approved explicit Access precedence and authorized the bounded Portal correction. `pushCustomer` now resolves mapping changes independently of explicit Access presence. If `access_user_emails` is submitted, exact-email resolution and `customer_user::sync()` persist that list, including `[]`, regardless of mapping completeness. If it is omitted, a genuine Specific Region change or OE/IB Company change applies the destination mapping only when complete; otherwise the existing relationship remains and the response includes `access_mapping_warning`. Omission with unchanged controls remains a no-op. Mapping resolution still has no role/RSM filter, and canonical persistence remains `customer_user`.

The warning is an optional additive Push response property; legacy clients that ignore unknown response properties retain their existing request/persistence behavior. No Tablet application code was changed.

### Contract correction tests and validation

- Added disposable-database coverage for explicit nonempty Access with complete/incomplete mappings, explicit empty Access with complete/incomplete mappings, omitted Access with changed Specific Region and complete/incomplete mappings, unchanged Specific Region preservation, complete OE↔IB Company changes, and incomplete Company mapping preservation/reporting.
- Existing coverage verifies legacy omitted-field compatibility, exact email identity rejection, duplicate submitted emails, create rollback on invalid input, and duplicate-free pivot persistence. Added an update rejection assertion confirming invalid Access identities do not partially change the Customer or Access relationship.
- Focused Customer, sync, location, and Pull suites: **82 passed, 551 assertions** on PHPUnit `sqlite :memory:`. Changed PHP files pass Pint's formatting check; `git diff --check` passes.
- The Portal Push contract correction is ready for Human review. Remaining assignment/reference-data gates and all Tablet Phase 2 and E2E work remain outstanding. Work stays **ACTIVE**; no migration against retained data, deployment, staging, commit, or push occurred.

### B. Customer Code broader failure

The isolated `CustomerCodeCustomerTableTest` failure was a test assertion defect, not a query/result regression. The two ordered query results had identical row values, ordering, and property types; `toBe()` failed because separately fetched `stdClass` rows are distinct object instances. The test now normalizes each row to an associative array and retains strict `toBe()` comparison, so order, field values, and PHP value types remain checked. This is test-only; Customer Code behavior is unchanged.

### C. Schema and migration safety

- The new migration adds five mapping tables only. It makes no Customer, `customer_user`, Region, Specific Region, Area Cluster, Province, or User table alteration and contains no inserts/seeds/backfill.
- Foreign keys reference existing `region_specifics`, `customer_access_groups`, `customer_company_access_configurations`, `companies`, and `users`. Mapping keys are unique: one mapping per Specific Region, one company configuration per Company, and one row per group/configuration and User pair.
- Mapping child rows cascade only when their new mapping parent is deleted. Region/Company/User references use restrictive deletes, protecting referenced canonical data.
- Rollback drops mapping tables from children to parents and leaves all existing application records untouched. Existing Customer and `customer_user` rows are not transformed.
- Tests exercised the migration through `RefreshDatabase` with PHPUnit `sqlite :memory:` only. No retained database or production schema was accessed. Production MySQL compatibility still requires a target-schema preflight and approved rollout; this review did not connect to PROD.
- Completeness is an explicit readiness flag, not computed from an approved assignment manifest. Configuration must remain incomplete until a separately reviewed, complete mapping is loaded. The migration does not silently seed any mapping.

### D. Portal form review

Code and focused form tests confirm that Specific Region options are scoped to the selected physical Region; a Specific Region must belong to that Region; Area Cluster options are scoped to Specific Region and auto-select only for exactly one enabled child; Province options are scoped to physical Region; Municipality and Barangay retain their dependent hierarchy and independent-locality behavior. Customer Edit can hydrate physical Region from Municipality or Province when Municipality is absent. Unchanged Specific Region preserves manual Access; a complete mapping is applied on a changed Specific Region; incomplete mappings preserve existing Access. OE/IB use Company-scoped configuration. The resolver has no role or RSM filter.

The form and mapping tests do not substitute for seeded authoritative assignment data: all mapping tables remain empty, and no real GMA/NL/SL/VIS/MIN or OE/IB Access set was exercised.

### E. Validation and corrections

- Disposable Portal suite (SQLite in-memory): **47 passed, 413 assertions** across Access form contracts, mapping/form behavior, location mapping, Portal sync view, Pull scope, Tablet Customer Push/Pull contract, and Customer Code table coverage.
- The Customer Code test-only strict-value correction is included in `tests/Feature/CustomerCodeCustomerTableTest.php`.
- The initial acceptance review made no application behavior correction; the subsequent Human-approved bounded correction is recorded above.
- No migration ran against a retained database. No User provisioning, Customer backfill, deployment, staging, commit, or push occurred.

### F. Acceptance status and remaining blockers

**The Portal Push contract correction is ready for Human review.** The previously identified Push mismatch is corrected and focused tests pass. Remaining data gates (approved Specific Region mappings, reference repair, and User reconciliation) are unchanged. Tablet Phase 2, offline behavior, negative local-ID verification, and Tablet → Portal → Tablet E2E were not started or validated.

Work remains **ACTIVE** and stopped at this review for Human direction. No Tablet Phase 2 work was performed.

## Tablet Phase 2 implementation record — bounded review

### Tablet implementation

- Added the local `customer_access_mappings` cache table with a unique `(scope, mapping_key)` key, completeness flag, and serialized User references. Geographic rows use canonical `region_specific_id`; OE/IB rows use Company codes. No cross-database numeric User IDs are used.
- Tablet location Pull accepts an optional additive `customer_access_mappings` section. Existing six-section location count metadata and strict parent/count validation remain unchanged. A successful legacy location snapshot without mappings clears the old mapping cache; malformed mappings reject the whole reference snapshot transaction and preserve the prior cache.
- Mapping User references are matched by exact email against local Tablet Users. The Pull may create/update device-local User reference rows with a random local credential only when a mapped identity is not already cached; this is offline cache hydration, not Portal account provisioning. Ambiguous local email matches fail the snapshot.
- Customer Add/Edit now filters Specific Region by physical Region; filters Area Cluster by Specific Region; selects the sole enabled Area Cluster only when exactly one exists; retains Province → Municipality → Barangay dependencies and Province-only Region hydration; and recalculates Access only on actual controller changes. Complete mappings replace the selection; missing/incomplete/ambiguous mappings preserve existing selection and display a warning. Manual Access remains editable and unchanged controls do not recalculate it. OE/IB resolve by Company code. No role/RSM filtering was added.
- Location Pull does not recalculate Customer Access. Existing Pull Access reconciliation remains email-based, and existing protected sync-state handling remains intact. Customer local IDs and the negative-ID Push identity path were not changed.

### Files changed in Tablet

- `database/migrations/2026_10_11_133824_create_customer_access_mappings_table.php`
- `app/Models/CustomerAccessMapping.php`
- `app/Services/CustomerAccessMappingResolver.php`
- `app/Services/SyncService.php`
- `app/Filament/Pages/CustomerCreatePage.php`
- `app/Filament/Pages/CustomerEditPage.php`
- `resources/views/filament/pages/customer-create-page.blade.php`
- `tests/Feature/CustomerCreatePageDiagnosticTest.php`
- `tests/Feature/CustomerLocationReferenceSchemaTest.php`
- `tests/Feature/LocationReferenceSnapshotTest.php`
- This mirrored Work note

### Additive Portal change for offline Pull

- `app/Services/TabletSyncPayload.php` now serializes the optional mappings by stable Specific Region ID / Company code and User email/name, without Portal User IDs. The mapping section is outside the six strict location reference counts. `tests/Feature/TabletSplitPullTest.php` verifies the additive payload and unchanged count keys.
- Portal mapping serialization returns incomplete status when the mapping is not explicitly complete, has no Users, or has missing/duplicate User references. The Portal Phase 1 schema and Push behavior are otherwise unchanged.

### Validation results

- Tablet disposable SQLite focused/regression suite: **96 passed, 1 skipped, 801 assertions** across Add/Edit, offline location snapshots, Customer sync, persistence, identity boundary, Push/Pull, and legacy compatibility tests.
- Tablet PHP formatting: `vendor/bin/pint --dirty --format agent` passed after formatting the changed files.
- Portal disposable SQLite suite: **52 passed, 401 assertions** across mapping form behavior, Tablet mapping serialization, Customer Push contract, and Pull scope tests.
- Portal PHP formatting: `vendor/bin/pint --dirty --format agent` passed.
- Tests use configured disposable SQLite test databases. No retained database or PROD access, persistent migration, reference repair, User provisioning, deployment, staging, commit, or push occurred.

### Remaining requirements and status

- No approved mapping or personnel data was seeded. Full real-assignment behavior remains gated by the approved Specific Region → business group manifest, the 46-assignment identity reconciliation, missing Portal Specific Regions/orphan Area Clusters, and NIR coverage.
- The test suites exercise Tablet Pull and Portal serialization separately with explicit fixtures. A full live Tablet → Portal → Tablet E2E run is still required after the data gates are approved and the same Portal/Tablet API environment is available. It must verify location and Access persistence, canonical Customer ID reconciliation, and stable negative local ID behavior.
- At the Phase 2 close, production data reconciliation had not begun. Work remained **ACTIVE** pending the next Human direction.

## Phase 3 read-only reconciliation preparation (historical checkpoint)

### Scope and database verification

This phase used SELECT-only inspection. Portal's configured MySQL schema and `SELECT DATABASE()` both returned `ommc_henri_local_latest`; no database switch was made. The Tablet reference database was its configured local SQLite file. No migration, write, reference repair, or provisioning was performed.

| Data | Portal local snapshot | Tablet local SQLite |
|---|---:|---:|
| Users | 56 | 7 local accounts |
| Physical Regions | 19 | 19 |
| Specific Regions | 1 | 18 |
| Area Clusters | 44 | 44 |
| Customers | 18,988 | 705 |
| `customer_user` rows | 27,141 | 765 |
| Access mapping tables | Absent | `customer_access_mappings` absent |

Portal's mapping migration is intentionally not applied to this retained snapshot. Mapping-key and mapping-row checks are therefore not applicable to the live snapshot; there are no mapping rows to validate. Company codes OE and IB each occur once (Portal Company IDs 5 and 6; Tablet Company IDs 5 and 6).

### A. Location reconciliation manifest

`region_specifics` has no code column; “specific code” is therefore N/A. Parent Region code is shown for stable cross-reference. Tablet rows are candidate source evidence, not accepted authority. The proposed Portal restore keeps each Tablet Specific Region ID and exact name/parent; no ID is invented or renumbered. Every restore remains pending Human approval of the source snapshot.

| Specific ID | Exact Specific Region name | Parent Region ID / code | Portal status | Tablet status | Proposed operation | Evidence / confidence / approval |
|---:|---|---|---|---|---|---|
| 1 | National Capital Region (NCR) | 1 / `MM` | Exists | Exists | Resolve parent relationship; candidate is reparent this same row to canonical Region 2 while retaining ID 1 and all Customer references. Do not duplicate the NCR row. | Exact row in both DBs; 3,181 Portal Customers reference ID 1. Region 2 is the PSGC NCR row. High confidence that the current selector mismatch exists; reparent operation requires Human approval because it changes reference semantics. |
| 52 | Region IV-A (Cavite, Rizal) | 7 / `PSGC-0400000000` | Missing | Exists | Restore Portal Specific Region ID 52 with exact Tablet name and parent. | Exact Tablet row and 2 Area Cluster children (IDs 16–17); high record evidence, not authority; approval required. |
| 53 | Region IV-A (Laguna, Batangas, Quezon) | 7 / `PSGC-0400000000` | Missing | Exists | Restore Portal Specific Region ID 53 with exact Tablet name and parent. | Exact Tablet row and Area Cluster 18; high record evidence, not authority; approval required. |
| 54 | Region III (Central Luzon) | 6 / `PSGC-0300000000` | Missing | Exists | Restore Portal Specific Region ID 54 with exact Tablet name and parent. | Exact Tablet row and three children (19, 27, 31); high record evidence, not authority; approval required. |
| 55 | Region XI (Davao Region) | 16 / `PSGC-1100000000` | Missing | Exists | Restore Portal Specific Region ID 55 with exact Tablet name and parent. | Exact Tablet row and Cluster 20; high record evidence, not authority; approval required. |
| 56 | Region X (Northern Mindanao) | 15 / `PSGC-1000000000` | Missing | Exists | Restore Portal Specific Region ID 56 with exact Tablet name and parent. | Exact Tablet row and Cluster 21; high record evidence, not authority; approval required. |
| 57 | Region IX (Zamboanga Peninsula) | 14 / `PSGC-0900000000` | Missing | Exists | Restore Portal Specific Region ID 57 with exact Tablet name and parent. | Exact Tablet row and Cluster 22; high record evidence, not authority; approval required. |
| 58 | Region XII (SOCCSKSARGEN) | 17 / `PSGC-1200000000` | Missing | Exists | Restore Portal Specific Region ID 58 with exact Tablet name and parent. | Exact Tablet row and Cluster 23; high record evidence, not authority; approval required. |
| 59 | Region XIII (Caraga) | 18 / `PSGC-1600000000` | Missing | Exists | Restore Portal Specific Region ID 59 with exact Tablet name and parent. | Exact Tablet row and Cluster 24; high record evidence, not authority; approval required. |
| 60 | Bangsamoro Autonomous Region in Muslim Mindanao (BARMM) | 19 / `PSGC-1900000000` | Missing | Exists | Restore Portal Specific Region ID 60 with exact Tablet name and parent. | Exact Tablet row and three children (25, 26, 43); high record evidence, not authority; approval required. |
| 61 | Region II (Cagayan Valley) | 5 / `PSGC-0200000000` | Missing | Exists | Restore Portal Specific Region ID 61 with exact Tablet name and parent. | Exact Tablet row and Cluster 28; high record evidence, not authority; approval required. |
| 62 | Cordillera Administrative Region (CAR) | 3 / `PSGC-1400000000` | Missing | Exists | Restore Portal Specific Region ID 62 with exact Tablet name and parent. | Exact Tablet row and children 29, 32; high record evidence, not authority; approval required. |
| 63 | Region I (Ilocos Region) | 4 / `PSGC-0100000000` | Missing | Exists | Restore Portal Specific Region ID 63 with exact Tablet name and parent. | Exact Tablet row and children 30, 48, 49; high record evidence, not authority; approval required. |
| 64 | Region V (Bicol Region) | 9 / `PSGC-0500000000` | Missing | Exists | Restore Portal Specific Region ID 64 with exact Tablet name and parent. | Exact Tablet row and Cluster 33; high record evidence, not authority; approval required. |
| 65 | Region IV-B (MIMAROPA) | 8 / `PSGC-1700000000` | Missing | Exists | Restore Portal Specific Region ID 65 with exact Tablet name and parent. | Exact Tablet row and children 34, 35, 44; high record evidence, not authority; approval required. |
| 66 | Region VII (Central Visayas) | 12 / `PSGC-0700000000` | Missing | Exists | Restore Portal Specific Region ID 66 with exact Tablet name and parent. | Exact Tablet row and children 36, 38, 39; high record evidence, not authority; approval required. |
| 67 | Region VIII (Eastern Visayas) | 13 / `PSGC-0800000000` | Missing | Exists | Restore Portal Specific Region ID 67 with exact Tablet name and parent. | Exact Tablet row and children 37, 40, 46; high record evidence, not authority; approval required. |
| 68 | Region VI (Western Visayas) | 10 / `PSGC-0600000000` | Missing | Exists | Restore Portal Specific Region ID 68 with exact Tablet name and parent. | Exact Tablet row and children 41, 42, 45, 47; high record evidence, not authority; approval required. |
| Not assigned | Negros Island Region (NIR) | 11 / `PSGC-1800000000` | Missing | Missing | No operation authorized. If Human confirms a commercial Specific Region is required, add one under physical Region 11 using an approved name; let the canonical DB assign its ID. | Physical Region 11 exists in both DBs and has 3 Provinces; there is no Specific Region or Cluster source record. Candidate name is copied from physical Region only. Low confidence as a commercial record; Human decision required. |

#### Exact 34 Portal Area Cluster parent references

All 34 Area Cluster rows below exist in both databases with the same ID, code, name, and parent key. Tablet resolves each parent; Portal does not currently have Specific Region IDs 52–68. Proposed treatment is to restore approved parent references above, leaving Area Cluster IDs and parent IDs unchanged. Each row is pending the same source approval; no child is reassigned based on its prefix.

| Cluster ID | Code | Name | Missing Portal parent ID |
|---:|---|---|---:|
| 16 | `gmaarea6` | GMA - Area 6 | 52 |
| 17 | `gmaarea12` | GMA - Area 12 | 52 |
| 18 | `slarea1` | SL - Area 1 | 53 |
| 19 | `nlarea3` | NL - Area 3 | 54 |
| 27 | `nlarea1` | NL - Area 1 | 54 |
| 31 | `nlarea2` | NL - Area 2 | 54 |
| 20 | `minarea1` | MIN - Area 1 | 55 |
| 21 | `minarea2` | MIN - Area 2 | 56 |
| 22 | `minarea5` | MIN - Area 5 | 57 |
| 23 | `minarea4` | MIN - Area 4 | 58 |
| 24 | `minarea3` | MIN - Area 3 | 59 |
| 25 | `minarea2` | MIN - Area 2 | 60 |
| 26 | `minarea6` | MIN - Area 6 | 60 |
| 43 | `minarea4` | MIN - Area 4 | 60 |
| 28 | `nlarea2` | NL - Area 2 | 61 |
| 29 | `nlarea1` | NL - Area 1 | 62 |
| 32 | `nlarea2` | NL - Area 2 | 62 |
| 30 | `nlarea1` | NL - Area 1 | 63 |
| 48 | `nlarea3` | NL - Area 3 | 63 |
| 49 | `nlarea2` | NL - Area 2 | 63 |
| 33 | `slarea2` | SL - Area 2 | 64 |
| 34 | `slarea3` | SL - Area 3 | 65 |
| 35 | `slarea4` | SL - Area 4 | 65 |
| 44 | `slarea1` | SL - Area 1 | 65 |
| 36 | `visarea3` | VIS - Area 3 | 66 |
| 38 | `visarea5` | VIS - Area 5 | 66 |
| 39 | `visarea4` | VIS - Area 4 | 66 |
| 37 | `visarea7` | VIS - Area 7 | 67 |
| 40 | `visarea6` | VIS - Area 6 | 67 |
| 46 | `visarea4` | VIS - Area 4 | 67 |
| 41 | `visarea2` | VIS - Area 2 | 68 |
| 42 | `visarea1` | VIS - Area 1 | 68 |
| 45 | `visarea3` | VIS - Area 3 | 68 |
| 47 | `visarea7` | VIS - Area 7 | 68 |

No cluster code/name is globally unique: repeats such as `slarea1`, `nlarea1`, and `visarea3` are scoped by Specific Region. Keep the existing parent relation and do not deduplicate by code.

### B. Candidate Specific Region → business group manifest

The following is a proposal for Human review, not mapping data. For IDs 54–68, the candidate follows the earlier Human-proposed physical crosswalk and matches the physical parent in the Tablet snapshot; matching Area Cluster labels are corroborating candidate evidence only. IDs 52/53 demonstrate the Region IV-A split in the Tablet cluster labels. No name/prefix is accepted as authority. Every listed mapping must be explicitly approved before activation.

| Specific ID | Name | Parent Region ID | Proposed group | Evidence | Status |
|---:|---|---:|---|---|---|
| 1 | National Capital Region (NCR) | 1 (legacy `MM`) | GMA | Prior proposed NCR→GMA crosswalk; legacy parent conflict unresolved | Candidate; approve group and parent handling |
| 52 | Region IV-A (Cavite, Rizal) | 7 | GMA | Two Tablet children named GMA Area 6/12 | Candidate split; explicit Human approval required |
| 53 | Region IV-A (Laguna, Batangas, Quezon) | 7 | SL | Tablet child named SL Area 1 | Candidate split; explicit Human approval required |
| 54 | Region III (Central Luzon) | 6 | NL | Prior physical crosswalk plus Tablet parent and NL-labeled children | Candidate; approve |
| 55 | Region XI (Davao Region) | 16 | MIN | Prior physical crosswalk plus Tablet parent and MIN-labeled child | Candidate; approve |
| 56 | Region X (Northern Mindanao) | 15 | MIN | Prior physical crosswalk plus Tablet parent and MIN-labeled child | Candidate; approve |
| 57 | Region IX (Zamboanga Peninsula) | 14 | MIN | Prior physical crosswalk plus Tablet parent and MIN-labeled child | Candidate; approve |
| 58 | Region XII (SOCCSKSARGEN) | 17 | MIN | Prior physical crosswalk plus Tablet parent and MIN-labeled child | Candidate; approve |
| 59 | Region XIII (Caraga) | 18 | MIN | Prior physical crosswalk plus Tablet parent and MIN-labeled child | Candidate; approve |
| 60 | Bangsamoro Autonomous Region in Muslim Mindanao (BARMM) | 19 | MIN | Prior physical crosswalk plus Tablet parent and MIN-labeled children | Candidate; approve |
| 61 | Region II (Cagayan Valley) | 5 | NL | Prior physical crosswalk plus Tablet parent and NL-labeled child | Candidate; approve |
| 62 | Cordillera Administrative Region (CAR) | 3 | NL | Prior physical crosswalk plus Tablet parent and NL-labeled children | Candidate; approve |
| 63 | Region I (Ilocos Region) | 4 | NL | Prior physical crosswalk plus Tablet parent and NL-labeled children | Candidate; approve |
| 64 | Region V (Bicol Region) | 9 | SL | Prior physical crosswalk plus Tablet parent and SL-labeled child | Candidate; approve |
| 65 | Region IV-B (MIMAROPA) | 8 | SL | Prior physical crosswalk plus Tablet parent and SL-labeled children | Candidate; approve |
| 66 | Region VII (Central Visayas) | 12 | VIS | Prior physical crosswalk plus Tablet parent and VIS-labeled children | Candidate; approve |
| 67 | Region VIII (Eastern Visayas) | 13 | VIS | Prior physical crosswalk plus Tablet parent and VIS-labeled children | Candidate; approve |
| 68 | Region VI (Western Visayas) | 10 | VIS | Prior physical crosswalk plus Tablet parent and VIS-labeled children | Candidate; approve |
| Not assigned | Negros Island Region (NIR) | 11 | VIS candidate | Earlier physical crosswalk includes NIR under VIS; no commercial Specific Region record exists | Unmapped; approve whether to create reference and map it |

### C. User identity reconciliation manifest — 46 assignments

Candidates below come from the 56 Portal `users` rows. Portal stores `name` and `last_name` separately; supplied middle initials/names are generally absent from that schema. “Candidate” is not acceptance. All candidate identities require explicit Human confirmation before a mapping manifest is activated. No role filter is proposed; the supplied Function remains descriptive metadata. All 56 rows have `deleted_at = NULL`; the schema has no separate `is_active` field. Portal email and username have no duplicate values in this snapshot.

| # | Supplied name | Group | Function | Candidate Portal User ID / email | Match status | Required Human decision |
|---:|---|---|---|---|---|---|
| 1 | Sherwin P. Clerigo | GMA | DRM | 5 / `spclerigo@motolite.com` | Strong base-name + surname match; middle initial omitted | Confirm identity |
| 2 | Neil S. Mopera | GMA | DRM | 7 / `neil.mopera@motolite.com` | Strong base-name + surname match | Confirm identity |
| 3 | Ronnie N. Cruz | GMA | DRM | 11 / `ronnie.cruz@motolite.com` | Strong base-name + surname match | Confirm identity |
| 4 | Jhay-Ar Moreno | GMA | DRM | 12 / `jay-ar.moreno@motolite.com` | Possible spelling variant `Jhay-Ar` / `Jay-ar` | Confirm same person or reject |
| 5 | Gerardo P. Leonardo | NL | RSM | 29 / `gerry.leonardo@motolite.com` | Possible nickname variant Gerardo/Gerry | Confirm same person or reject |
| 6 | Carl P. Bernaldez | NL | DRM | 32 / `carl.bernaldez@motolite.com` | Strong base-name + surname match | Confirm identity |
| 7 | Jerick P. Caday | NL | DRM | 30 / `jerick.caday@motolite.com` | Strong base-name + surname match | Confirm identity |
| 8 | Juanito J. Bernardo | NL | DRM | 31 / `john.bernardo@motolite.com` | Possible given-name variant Juanito/John | Confirm same person or reject |
| 9 | Lee Marvin Samson | NL | RSR | 33 / `leemarvin.samson@motolite.com` | Strong full given-name + surname match | Confirm identity |
| 10 | Benedict F. Teoxon | SL | RSM | 34 / `benedict.teoxon@motolite.com` | Strong base-name + surname match | Confirm identity |
| 11 | Daniel T. Canton | SL | DRM | 36 / `daniel.canton@motolite.com` | Strong base-name + surname match | Confirm identity |
| 12 | Samuellee Buiser | SL | DRM | 35 / `sam.buiser@motolite.com` | Possible Sam/Samuellee variant | Confirm same person or reject |
| 13 | Raffy Marfilla | SL | RSR | 38 / `raffy.marfilla@motolite.com` | Strong full-name match | Confirm identity |
| 14 | Natalio E. Omictin | VIS | RSM | 39 / `jong.omictin@motolite.com` | Surname matches; given name does not | Do not match by surname; identify or confirm missing account |
| 15 | Anthoniel Delos Santos | VIS | DRM | 41 / `anthoniel.delossantos@motolite.com` | Strong full-name match | Confirm identity |
| 16 | Josie G. Gabo | VIS | DRM | — | No matching Portal User | Keep provision candidate; approve unique email and username separately before controlled provisioning |
| 17 | Michael P. Camacho | VIS | DRM | 43 / `mike.camacho@motolite.com` | Possible Michael/Mike variant | Confirm same person or reject |
| 18 | Roberto J. Sotero | VIS | DRM | 42 / `robert.sotero@motolite.com` | Possible Roberto/Robert variant | Confirm same person or reject |
| 19 | Joelo C. Pellejo | VIS | DRM | 40 / `joelo.pellejo@motolite.com` | Strong base-name + surname match | Confirm identity |
| 20 | Ben Resurreccion | VIS | RSR | 57 / `ben.resurrection@motolite.com` | Similar but different surname spelling (`Resurreccion` / `Resurrection`) | Confirm spelling/identity or reject |
| 21 | Khristia Intong | VIS | RSR | 44 / `khristia.intong@motolite.com` | Strong full-name match | Confirm identity |
| 22 | Van Rodriguez | VIS | RSR | 45 / `van.rodriguez@motolite.com` | Strong full-name match | Confirm identity |
| 23 | Ernest A. Damaso | MIN | RSM | 16 / `ernest.damaso@motolite.com` | Strong base-name + surname match | Confirm identity |
| 24 | Gikko Villanueva | MIN | DRM | 19 / `gikko.villanueva@motolite.com` | Strong full-name match | Confirm identity |
| 25 | Robertson L. Villoria | MIN | DRM | 18 / `robert.viloria@motolite.com` | Similar surname; given-name differs | Do not auto-match; Human identify or reject candidate |
| 26 | Angelo Ponce De Leon | MIN | DRM | 17 / `gelo.deleon@motolite.com` | Possible Angelo/Gelo and compound-surname variant | Confirm same person or reject |
| 27 | Allan O. Sitjar | MIN | RSR | 20 / `allan.sitjar@motolite.com` | Strong base-name + surname match | Confirm identity |
| 28 | Froilan Dave Ladica | MIN | RSR | 22 / `dave.ladica@motolite.com` | Supplied first name absent; Dave/Ladica candidate | Confirm same person or reject |
| 29 | Joseph G. Chua | MIN | RSR | 21 / `joseph.chua@motolite.com` | Strong base-name + surname match | Confirm identity |
| 30 | Joule T. Villanueva | OE | AB - OE | 55 / `joule.villanueva@motolite.com` | Strong base-name + surname match | Confirm identity |
| 31 | Erwin Ellis L. Martin | OE | MCB - OE | 56 / `ellis.martin@motolite.com` | Surname matches; supplied given name is absent | Do not auto-match; Human identify or reject candidate |
| 32 | John Allen L. David | IB | IB Sales | 51 / `allen.david@motolite.com` | Surname and second supplied name match; first name absent | Do not auto-match; Human identify or reject candidate |
| 33 | Gerald D. Pastoral | IB | IB Sales | A: 53 / `gerald.pastoral@motolite.com`; B: 62 / `gerarld.pastoral@motolite.com` | Two distinct records; A has matching name, B has typo-like account name/email. Keep separate; do not merge or choose | Human identify intended account and decide whether B is a duplicate account |
| 34 | Noriel R. Muscosa | IB | IB Sales | 54 / `noriel.muscosa@motolite.com` | Strong base-name + surname match | Confirm identity |
| 35 | Norvin P. Santos | IB | IB Sales | 52 / `norvin.santos@motolite.com` | Strong base-name + surname match | Confirm identity |
| 36 | Allan Jefferson Llave | GMA | DRM - MCB | 23 / `allan.llave@motolite.com` | Base name + surname match; supplied middle name absent | Confirm identity |
| 37 | Roswald Jed De Vera San Miguel | GMA | DRM - MCB | 24 / `jed.sanmiguel@motolite.com` | `Jed` and final compound surname match; supplied other names absent | Do not auto-match; Human confirm full identity |
| 38 | Allan Tubongbanua | NL | DRM - MCB | 25 / `allan.tubongban@motolite.com` | Surname differs/truncated (`Tubongbanua` / `Tubongban`) | Confirm same person or reject |
| 39 | Neil Chavez | SL | DRM - MCB | 26 / `neil.chavez@motolite.com` | Strong full-name match | Confirm identity |
| 40 | Raul A. Cagas | VIS | DRM - MCB | 27 / `raul.cagas@motolite.com` | Strong base-name + surname match | Confirm identity |
| 41 | Aries Galagar | MIN | DRM - MCB | 28 / `aries.galagar@motolite.com` | Strong full-name match | Confirm identity |
| 42 | Vincent C. Ortiz | GMA | FLEET HEAD | — | No matching Portal User | Keep provision candidate; approve unique email and username separately before controlled provisioning |
| 43 | Jenson Cagampan Dimaiwat | GMA | FLEET | 47 / `jenson.dimaiwat@motolite.com` | Base name + surname match; supplied middle name absent | Confirm identity |
| 44 | Ramon Christopher Roces | SL | FLEET | 49 / `rc.roces@motolite.com` | Only initials + surname candidate | Do not auto-match; Human identify or reject candidate |
| 45 | Joel Zamora | MIN | FLEET | 50 / `jong.zamora@motolite.com` | Surname matches; given name differs | Do not auto-match; Human identify or reject candidate |
| 46 | Patrick Vibal | NL | FLEET | 48 / `patrick.vibal@motolite.com` | Strong full-name match | Confirm identity |

The likely candidates for rows with a unique base-name/surname match are not activated here. The assignment Functions remain descriptive; no DRM/RSM/RSR/Fleet role eligibility is applied. Known missing Josie and Vincent remain unprovisioned. Gerald IDs 53 and 62 remain separate.

### D. Read-only data integrity preflight

| Check | Portal local snapshot | Tablet local SQLite |
|---|---|---|
| Duplicate `(customer_id,user_id)` pivot pairs | 0 | 0 |
| `customer_user` rows with missing User | 0 | 0 |
| `customer_user` rows with missing Customer | 0 | 11 distinct missing Customer IDs; all 11 are negative IDs, so report as retained Tablet orphan pivots and do not delete/reconcile in this phase |
| Access pivots to soft-deleted Portal Users | 0 | Not represented by a Tablet `deleted_at` column |
| Duplicate User emails / usernames | 0 / 0 | 0 / 0 |
| Orphan Area Cluster parent | 34 (all parents 52–68 absent from Portal) | 0 |
| Customer Specific Region ID missing | 0 | 0 |
| Customer Area Cluster ID missing | 0 | 0 |
| Customer Specific Region vs selected Area Cluster parent conflicts | 1: Customer 5763 has Specific Region 1 and Cluster 16, whose parent is Specific Region 52 | Not applicable to existing reference completeness; no such missing-parent conflict was counted |
| Customer Province/Municipality relationship conflicts | 37 Customers have stored Province differing from the Municipality's Province | Not counted in this preflight on Tablet |
| Customer RegionSpecific/AreaCluster missing references | 0 / 0 | 0 / 0 |
| Duplicate mapping keys / incomplete configured mappings | Not applicable: mapping tables absent; migration not run | Not applicable: cache table absent; migration not run |
| OE/IB Company targets | Exactly one OE (ID 5) and one IB (ID 6) | Exactly one OE (ID 5) and one IB (ID 6) |

Portal has 19 physical Regions. Legacy Region 1 (`MM`, Metro Manila) and canonical Region 2 (`PSGC-1300000000`, National Capital Region) both have zero directly attached Provinces in this snapshot; RegionSpecific ID 1 is parented to Region 1. Thus today's new-customer Region selector, which exposes the PSGC NCR Region 2, cannot offer Specific Region ID 1 under its current parent. The same selector/reference mismatch exists on Tablet. Moving the existing Specific Region parent may resolve it while preserving its stable ID and 3,181 Customer references, but is a data operation requiring explicit approval. The one Customer/Cluster conflict above also needs separate review.

### E. Activation and disposable E2E readiness

**Safe as disposable test fixtures:** create isolated Portal and Tablet SQLite test databases; run the two additive migrations only there; build a self-consistent synthetic physical Region/Specific Region/Area Cluster tree; create test Companies OE/IB; create synthetic active Users with test-only unique email identities; insert complete and incomplete mapping rows; create a Customer, local negative-ID Customer, and `customer_user` assignments; and exercise authenticated Push, canonical Portal persistence, Pull, Access reconciliation, and negative-ID stability. Do not copy real retained User or Customer rows into fixtures.

**Human approval required before real mapping activation:** approve the Specific Region ID/name/parent restore manifest; decide Region 1/2 NCR parent treatment; approve 52→GMA and 53→SL; approve every other candidate business group; decide whether to create NIR commercial Specific Region; reconcile the 46 supplied User identities (especially the non-exact candidates, Gerald's separate IDs 53/62, and the two missing accounts); and approve a complete mapping manifest for GMA/NL/SL/VIS/MIN and OE/IB. Reference names and user identities must stay unmodified until approved.

**Separate production rollout authorization required:** target production schema preflight; apply additive migrations; reconcile and apply approved canonical reference rows and mappings; provision only Human-approved missing Users with approved unique email/username and first-login credential controls; deploy compatible Portal serializer and Tablet code; and run authorized production Pull verification. This phase has no production authorization.

**E2E readiness:** code paths and disposable fixture shapes exist, but prior validation ran Portal and Tablet suites separately, not a connected two-app E2E. The current retained Portal location snapshot is not valid for Tablet strict Pull while its 34 Area Cluster parents are missing. A clean self-consistent synthetic fixture avoids that blocker and can validate protocol/identity behavior without using real assignments. For a real-data E2E, the approved reference and identity gates plus a successfully synchronized mapping payload are prerequisites.

### F. Remaining Human decisions

1. Approve, reject, or amend the Tablet-sourced Specific Region restore rows 52–68.
2. Decide whether Specific Region ID 1 should be reparented from legacy Region 1 to canonical NCR Region 2, or provide another explicit compatibility rule. Keep ID 1 if retained so existing Customers remain referenced.
3. Review the single Customer 5763 Area Cluster/Specific Region mismatch and the 37 Province/Municipality inconsistencies separately; no backfill is proposed.
4. Confirm whether a commercial Specific Region is required for NIR and, if so, its exact approved name; do not preassign an ID.
5. Approve the candidate 18 Specific Region business groups in section B, including Region IV-A split, and the NIR mapping if a row is created.
6. Review all 46 identity rows; explicitly choose the Gerald account; resolve all non-exact candidates; approve unique email/username before controlled provisioning for Josie and Vincent.
7. Approve the full User assignment set for each geographic group and for Company OE/IB. Functions remain descriptive unless a separate business decision establishes filtering.

### G. Recommended bounded Phase 4 plan

1. Human review and sign-off of the location and business-group manifests, plus identity decisions, without touching retained data.
2. Add a disposable cross-application E2E harness using synthetic approved-shape fixtures; verify Portal Push precedence, canonical `customer_user`, Tablet Pull, location identity, and negative local-ID stability. Report separately from real-data activation.
3. Produce a reviewed, idempotent data package that resolves reference rows by stable approved IDs/codes and Users by approved email; include preflight assertions and rollback limited to new rows/mapping tables. Do not run it on retained/production DBs in Phase 4 unless separately authorized.
4. After separate Human GO for local activation, use a disposable restored copy—not the retained snapshot—to dry-run the approved manifest and strict Tablet Pull validation. Preserve the original retained DB.
5. Only after separate production authorization, prepare production preflight/migration/data application and phased Portal/Tablet rollout; no production activity is authorized by this plan.

Phase 3 was read-only preparation. At that checkpoint the Work remained **ACTIVE** pending Human direction; no retained writes, mapping activation, migration, repair, provisioning, PROD access, deployment, staging, commit, or push occurred.

## Phase 4A disposable end-to-end verification

**Authorization:** Human authorized isolated disposable Portal MySQL and Tablet SQLite databases, migrations on those databases only, and synthetic mapping fixtures. No retained database reconciliation, User provisioning, production rollout, PROD access, deployment, staging, commit, or push.

### Disposable environment

- Portal MySQL: `127.0.0.1:3306`, disposable schema `ommc_phase4a_e2e_20261011`; all 128 Portal migrations applied there. The schema was verified with `SELECT DATABASE()` before seeding. Synthetic data only.
- Tablet SQLite: `/private/tmp/ommc_tablet_phase4a_20261011.sqlite`; Tablet migrations applied to this disposable file and `PRAGMA database_list` verified the target.
- Portal API: local `127.0.0.1:8123`, configured against the disposable Portal schema. Seven synthetic users with contract-test email identities; no real User accounts or retained assignments.
- Synthetic references included physical Regions, an IV-A GMA/SL Specific Region split, Area Clusters with both one and multiple enabled options, Province/Municipality/Barangay chains, complete GMA/SL and OE/IB mappings, and both missing and incomplete geographic mappings.

### Validation results

- **Portal:** 52 passed, 405 assertions for `CustomerAccessMappingFormTest`, `TabletCustomerCreationContractTest`, `TabletSplitPullTest`, and `CustomerCodeCustomerTableTest`. Covered dependent Portal selectors, edit behavior, changed/unchanged controllers, OE/IB mapping, explicit and omitted Access Push semantics, incomplete mapping preservation, duplicate prevention, rejected Push rollback, reference serialization, and Customer Code result behavior.
- **Tablet regression suite:** 97 passed, 815 assertions across Add/Edit, offline mapping, strict reference validation/cache rollback, location persistence and dependencies, Push/Pull, identity, and negative-ID boundary suites.
- **Live Tablet → Portal → Tablet:** 1 passed, 67 assertions. Portal reference Pull populated Tablet's cache; the form selected among the IV-A GMA/SL split, left multiple Area Clusters unselected, selected a unique valid Area Cluster, filtered physical location values, resolved complete mapping locally without an offline warning, and preserved Access with a warning for missing/incomplete mappings. Tablet created a Customer with manual Access; actual Push persisted the location and Access email identities on Portal; actual Customer Pull reconciled Access by email and retained the same negative local Customer ID. Portal's pre-existing Customer snapshot remained unchanged. No duplicate Access rows were observed.
- **Malformed snapshots and rejected Push:** covered by passing automated suites: an invalid reference snapshot was rejected atomically with cached references preserved; invalid Access identity Push rolled back Customer and Access changes.

### Finding and remaining blockers

- Exploratory direct Livewire property assignment of the *same* `region_specific_id` invokes the update hook and recalculates Access, dropping a manual adjustment. The normal interaction path tested here changes controlling values before mapping and preserves manual Access when unchanged; a browser interaction selecting the already-selected option was not separately exercised. Treat same-value update behavior as an implementation edge case to review before real activation. No application code was changed in Phase 4A.
- Real activation remains blocked on Human-approved Specific Region → business-group mappings, reviewed 46-assignment identity reconciliation, missing Specific Region reference restoration, the 34 orphan Area Cluster parents, legacy NCR/Region IV-A/NIR decisions, and any approved provisioning inputs. No real mapping data was activated.
- Phase 4A technical disposable verification is complete. **Readiness for Phase 4B reference restoration: not ready** until the same-value behavior is resolved and the reference/identity data gates are approved. This Work remains **ACTIVE** and stops for Human review.

No retained database was written or migrated. No real assignments were changed, Users provisioned, production accessed, deployment performed, or commits/pushes made.

## Bounded Portal location selector UX correction

**Authorization:** Human authorized a Portal-only correction so Customer Add/Edit Location dropdowns display choices immediately without a search term. Keep searchable selection, preserve dependencies and existing business rules, do not preload national Municipality or Barangay data, and use disposable tests only. Tablet code is out of scope for this continuation.

### Change

Filament's location fields used custom `getSearchResultsUsing()` callbacks without initial `options()`, producing search-first dropdowns. Added initial option callbacks and preload for Region, Specific Region, Area Cluster, Province, City/Municipality, and Barangay. Each callback is scoped to the selected parent; Municipality preserves province-scoped and independent-locality behavior, and Barangay options require a selected Municipality. Search results remain searchable and capped to the existing 50-result search behavior. The full Municipality or Barangay reference tables are not preloaded.

Edit hydration now explicitly restores physical Region from the stored Municipality or Province when the form's non-persisted Region state is empty. This preserves Province-only legacy Customer compatibility discovered during the requested verification.

### Validation

Portal tests used the configured SQLite in-memory test database (`:memory:`). `CustomerAccessMappingFormTest`, `CustomerLocationMappingTest`, `CustomerAccessFormContractTest`, `TabletCustomerCreationContractTest`, and `CustomerCodeCustomerTableTest`: **54 passed, 397 assertions**. The new Add/Edit test verifies untyped initial option availability across all six fields, parent filtering, clearing invalid children after parent changes, independent-locality handling, Province-only Edit hydration, and unchanged Access state while options load. `vendor/bin/pint --test` passed for the changed Portal form and test; `git diff --check` passed.

No Tablet application code, migrations, reference data, retained databases, User assignments, or Access business rules were changed. Work remains **ACTIVE** and stops for Human review; no deployment, staging, commit, or push occurred.

## Phase 4A follow-up — same-value Access preservation

**Authorization:** Human accepted Phase 4A as a conditional pass and authorized correction of same-value controller updates before any real mapping activation. Do not begin Phase 4B. Use disposable test databases only; no retained data, User provisioning, reference repair, PROD access, deployment, staging, commit, or push.

### Root cause and correction

Tablet's `updatedRegionSpecificId()` unconditionally called the mapping/Area Cluster recalculation path. Livewire can invoke the updated hook when a client submits the same property value, so a manual Access choice could be replaced by the mapping and a manually selected Area Cluster could be reset even though the controller had not changed. Tablet Edit hydration itself assigns state during mount and does not fire this hook; the regression was the later same-value Livewire update.

Tablet now captures the pre-update Specific Region in `updatingRegionSpecificId()` and returns without recalculation when the post-update value is identical. The company selector similarly captures the old Company ID and returns early for an identical ID, avoiding repeat processing. Genuine geographic and OE/IB Company changes still resolve complete mappings; incomplete mappings preserve Access. Manual Access remains editable and persists across unchanged and repeated updates.

Portal's Specific Region and Company callbacks already compared old and new values for Access resolution. The Specific Region callback now exits before Area Cluster recalculation on an identical value, and Company selection exits before other callback processing. Portal Edit still compares the submitted controller against the persisted record at save time. Add/Edit hydration does not run the automatic replacement path.

### Regression results

- **Portal:** 58 passed, 416 assertions against the disposable SQLite in-memory test database. Includes Add/Edit same-value and repeated updates, unchanged hydration, genuine Specific Region changes with complete and incomplete mappings, manual Access, OE/IB company changes and repeated selections, location dependencies, Push contracts, and Customer regressions.
- **Tablet:** 86 passed, 764 assertions against SQLite `:memory:`. Includes Add/Edit hydration, same and repeated Specific Region updates, genuine complete/incomplete geographic mapping changes, manual Access, and same/genuine OE/IB Company selection changes, plus location, reference snapshot, Push/Pull, and sync regression coverage.
- `vendor/bin/pint --test` passed on the changed Portal and Tablet files. `git diff --check` passed.
- **E2E impact:** No synchronization, serialization, Push, Pull, identity reconciliation, or persistence code changed in this correction. The accepted disposable Tablet → Portal → Tablet E2E path is unaffected; it was not rerun for this form-hook-only change.

No real mappings were activated. Remaining activation blockers are the approved Specific Region mapping manifest, reviewed personnel identities, missing location references/orphan Area Cluster parents, and Human review of this same-value correction. Work remains **ACTIVE** and stops here; Phase 4B is not started.
\

## Data-based acceptance demonstration — 2026-10-11

**State: ACTIVE. Mode: DATA-BASED ACCEPTANCE DEMONSTRATION.** The preceding terminal-readiness checkpoint is superseded for this review. Human acceptance of this demonstration is pending. No application code, reference records, Users, mapping rows, retained databases, or production systems were changed. The Portal MySQL check selected `ommc_henri_local_latest` and `SELECT DATABASE()` confirmed that exact database; reference inspection was SELECT-only. Tablet inspection used its configured local `database/database.sqlite` opened with SQLite `mode=ro`.

### A. Verified current data — Fleet / North Luzon sample

| Selected value | Current reference record | Result |
|---|---|---|
| Physical Region | ID 6, `PSGC-0300000000`, `Region III (Central Luzon)` | Present in Portal and Tablet |
| Province | ID 20, Bulacan; parent physical Region ID 6; no Specific Region parent | Present in Portal and Tablet |
| Municipality | ID 348, Santa Maria; parent Province ID 20 and Region ID 6 | Present in Portal and Tablet |
| Barangay | ID 9257, Guyong; parent Municipality ID 348 | Present in Portal and Tablet |
| Specific Region | Tablet ID 54, parent Region ID 6, `Region III (Central Luzon)` | Present in Tablet; absent from Portal's current reference snapshot |
| Area Clusters | Tablet IDs 19 `NL - Area 3`, 27 `NL - Area 1`, 31 `NL - Area 2`, all parent Specific Region ID 54 and enabled | Three options; no automatic cluster selection, manual choice required |
| Business-group mapping | No real mapping data activated in either application's current runtime data | No authoritative NL Access can be resolved |

Portal's current `region_specifics` has only ID 1 (`National Capital Region (NCR)`, parent physical Region ID 1). Its Specific Region dropdown for Region III therefore has no valid option; its Area Cluster selector is consequently blocked. Portal does offer Bulacan under Region III and the physical Province → Municipality → Barangay chain can be selected. Tablet has all three location values and Specific Region 54 locally, and offers the three Area Clusters above. Its real offline Access mapping cache has not been populated with approved assignments; with Specific Region 54 selected, automatic Access is incomplete/unavailable, existing/manual Access is preserved, and the warning is applicable. With no controller selected, the accepted neutral result shows no mapping warning.

### B. Proposed business mappings and identity candidates — not active

Specific Region 54 → **NL** is a candidate based on the Human-proposed NL crosswalk and the Tablet reference row. It is not yet approved as authoritative mapping data. The supplied 46-assignment manifest lists these seven NL users; the Portal local identity candidates are not confirmed assignments:

| Supplied name | Function (descriptive) | Local candidate | Reconciliation status |
|---|---|---|---|
| Gerardo P. Leonardo | RSM | User 29, Gerry Leonardo, `gerry.leonardo@motolite.com` | Ambiguous; nickname-only match; Human decision required |
| Carl P. Bernaldez | DRM | User 32, Carl Bernaldez, `carl.bernaldez@motolite.com` | Plausible name/surname candidate; Human confirmation required |
| Jerick P. Caday | DRM | User 30, Jerick Caday, `jerick.caday@motolite.com` | Plausible name/surname candidate; Human confirmation required |
| Juanito J. Bernardo | DRM | User 31, John Bernardo, `john.bernardo@motolite.com` | Given-name conflict; do not match without Human decision |
| Lee Marvin Samson | RSR | User 33, Lee Marvin Samson, `leemarvin.samson@motolite.com` | Exact displayed name candidate; Human confirmation required |
| Allan Tubongbanua | DRM - MCB | User 25, Allan Tubongban, `allan.tubongban@motolite.com` | Surname differs/truncated; ambiguous |
| Patrick Vibal | FLEET | User 48, Patrick Vibal, `patrick.vibal@motolite.com` | Exact displayed name candidate; Human confirmation required |

All candidates were non-deleted in the inspected Portal snapshot, but none is thereby approved for automatic Access. Functions remain descriptive; no role/RSM eligibility filter applies. The manifest does not establish User Access for this customer until identities and the complete NL mapping are approved. No real user is assigned by this demonstration.

### C. Disposable demonstration evidence

The Portal form suite constructs isolated test Users, Regions, Specific Regions, Area Clusters, and complete/incomplete mapping rows using `RefreshDatabase`; the Tablet suite does likewise in disposable SQLite. The Portal mapping tests passed **16 tests / 70 assertions**. The Tablet Customer diagnostic tests passed **35 tests / 278 assertions**. These prove behavior against synthetic fixtures, not the retained reference or real User assignments.

The existing isolated Tablet → Portal → Tablet contract E2E test contains synthetic Specific Region mappings for the Region IV-A split (IDs 52/53 in its fixture), a complete synthetic NL mapping for fixture Specific Region 54, incomplete mappings, OE and IB Company mappings, a manually added User, and negative local Customer ID reconciliation. The preserved prior E2E result was **1 passed / 67 assertions** on disposable databases. The currently repeated Tablet diagnostic command does not execute that connected E2E; the E2E requires `CUSTOMER_CONTRACT_PORTAL_URL` to point to an isolated disposable Portal API. No real-data E2E is claimed.

Expected synthetic behavior demonstrated by tests:

1. On a newly selected, fully mapped Specific Region, form Access becomes the exact synthetic mapped User set. A unique Area Cluster is selected; when multiple enabled clusters exist, the field stays unset until a human chooses one.
2. If mapping completeness is false or a referenced identity is unavailable, Access is not replaced by a partial list and Tablet displays the incomplete/offline warning. An already selected manual user remains in the form.
3. If the controller value is unchanged, including repeated identical Livewire updates or Edit hydration, manual Access remains. A genuine controller change uses the complete target mapping.
4. OE and IB resolve through synthetic Company mappings; incomplete Company mappings preserve Access and warn. No applicable controller is neutral: it preserves Access and shows no warning.
5. Tablet's disposable round-trip reconciles Access by email and preserves the negative local Customer ID while recording the canonical Portal ID.

### Step-by-step outcome for the requested real-data scenario

| Step | Form input / expected options or Access | Actual outcome | Status |
|---|---|---|---|
| 1 | Company Fleet; Physical Region Region III (Central Luzon) | Region exists as ID 6 in both snapshots | Pass |
| 2 | Province Bulacan filtered by Region III | Province ID 20, correctly parented to Region 6 | Pass |
| 3 | Specific Region filtered by Region III; expected Region III commercial Specific Region | Tablet offers ID 54; Portal snapshot has no matching Specific Region | Tablet pass; Portal blocked by missing reference |
| 4 | Area Cluster filtered by Specific Region 54 | Tablet offers IDs 19, 27, 31; all enabled; multiple choices means no auto-selection | Tablet pass; Portal blocked upstream |
| 5 | Municipality Santa Maria filtered by Bulacan; Barangay Guyong filtered by Santa Maria | IDs 348 and 9257 match the expected parents in both snapshots | Pass in references; form dependency behavior is synthetically tested |
| 6 | Changed controller with complete approved NL mapping; expected Access | Real mapping is absent/unapproved. Candidate names are the seven NL rows above, subject to identity review | Blocked; no real automatic assignment claimed |
| 7 | Changed controller with incomplete mapping | Synthetic Portal/Tablet tests retain existing/manual Access; Tablet warns for a selected controller whose mapping is unavailable/incomplete | Synthetic pass; real data remains unavailable |
| 8 | Re-select same Specific Region after manual Access edit | Synthetic Portal and Tablet tests preserve the manual selection | Pass in disposable tests |
| 9 | Select OE / IB Company | Synthetic tests resolve complete Company mappings; incomplete mappings preserve Access and warn | Pass in disposable tests; no real mapping activation claimed |

### D. Current limitations and reproducibility

- Portal's configured reference snapshot lacks Specific Region 54 and the 34 Area Cluster parents already recorded in the Phase 3 reconciliation. Do not infer or restore those records in this Work.
- Specific Region 54 → NL and the seven supplied identities remain candidate data only. The exact supplied names and Functions are retained; ambiguous identities are not silently matched.
- No real geographic or OE/IB mapping configuration is active. The synthetic pass establishes application behavior only.
- Real-data Tablet → Portal → Tablet completion and production activation are not established. Location reference restoration, orphan Area Cluster reconciliation, NCR/NIR decisions, and the separate location automation items remain dependencies.

Reproduce the isolated behavior from each repository root:

```sh
# Portal: disposable RefreshDatabase test connection
php artisan test --compact tests/Feature/CustomerAccessMappingFormTest.php

# Tablet: disposable SQLite RefreshDatabase fixtures
php artisan test --compact tests/Feature/CustomerCreatePageDiagnosticTest.php

# Connected round-trip only when CUSTOMER_CONTRACT_PORTAL_URL targets
# an isolated disposable Portal API (never a retained or production database)
php artisan test --compact tests/Feature/CustomerCreatePortalE2ETest.php
```

Current rerun results: Portal **16 passed / 70 assertions**; Tablet **35 passed / 278 assertions**. This acceptance demonstration remains **ACTIVE** pending Human review. Do not stage, commit, push, deploy, seed real mappings, provision Users, repair references, or access PROD under this checkpoint.


## Final Human acceptance evidence — 2026-10-11

**Current state: READY_FOR_TERMINAL_REVIEW.** Human requested these two final checks and explicitly withheld staging, commit, and push authorization. This checkpoint supersedes the earlier demonstration addendum's ACTIVE status. No implementation changes were made.

### 1. Data-based Customer demonstration

The verified Fleet sample is Region III / Central Luzon (physical Region ID 6, code `PSGC-0300000000`) → Bulacan (Province ID 20) → Santa Maria (Municipality ID 348) → Guyong (Barangay ID 9257). All four physical records exist in the inspected Portal snapshot and Tablet SQLite. Tablet additionally contains Specific Region ID 54, `Region III (Central Luzon)`, parent Region 6, with enabled Area Clusters ID 19 `NL - Area 3`, ID 27 `NL - Area 1`, and ID 31 `NL - Area 2`. Since three valid clusters exist, no unique-cluster auto-selection applies; the user chooses one.

Portal's current snapshot does not contain Specific Region 54: it contains only Specific Region ID 1 (NCR under legacy Region 1). Thus the Portal Add/Edit Specific Region step and following Area Cluster step are blocked for this real sample. Tablet Add/Edit can display the local Region III → Specific Region 54 → three Area Cluster choices, but no approved NL Access mapping is active.

Specific Region 54 → NL remains a proposed business mapping. Its supplied manifest users and local identity candidates are: Gerardo P. Leonardo (ID 29 Gerry Leonardo, ambiguous); Carl P. Bernaldez (ID 32 Carl Bernaldez, confirm); Jerick P. Caday (ID 30 Jerick Caday, confirm); Juanito J. Bernardo (ID 31 John Bernardo, given-name mismatch); Lee Marvin Samson (ID 33 Lee Marvin Samson, confirm); Allan Tubongbanua (ID 25 Allan Tubongban, surname discrepancy); Patrick Vibal (ID 48 Patrick Vibal, confirm). None is treated as approved or actually mapped. Functions remain descriptive and do not filter Access.

Synthetic Portal and Tablet tests establish the behavior separately from real data: Add/Edit apply a complete mapping on a genuine controller change; manual Access survives unchanged and repeated same-value updates; incomplete mappings retain Access (with a Tablet warning for a selected controller); no controller is warning-free; and complete/incomplete OE/IB Company mappings follow the same preservation rules. Portal mapping form suite: **16 passed / 70 assertions**. Tablet Customer diagnostic suite: **35 passed / 278 assertions**. Preserved connected disposable synthetic E2E evidence: **1 passed / 67 assertions**, including email reconciliation and stable negative local Customer identity. No real-data activation or real-data E2E is claimed.

Reproduction commands are recorded in the prior “Data-based acceptance demonstration” section. They use `RefreshDatabase` disposable test fixtures. Connected E2E additionally requires `CUSTOMER_CONTRACT_PORTAL_URL` to target an isolated disposable Portal API.

### 2. Broader Tablet CustomerLocationMapTest failures

Re-running `php artisan test --compact tests/Feature/CustomerLocationMapTest.php` against disposable SQLite reproduces **2 failed, 2 passed (18 assertions)**. Both failures assert `municipality_id === 1` (test lines 59 and 94); the result is null. The shared fixture inserts Municipality `Angeles` without `psgc_code` (line 20). The unchanged `PhilippineAddressResolver` requests PSGC-only matching for Municipality and applies `whereNotNull('psgc_code')`, so this fixture row is deliberately ineligible under that resolver contract. The assertions fail before the later Access mapping behavior is exercised.

At the pre-Work `HEAD`, `CustomerLocationMapTest.php` and `PhilippineAddressResolver.php` are byte-for-byte unchanged from the current worktree. The pre-Work `CustomerCreatePage::resolveLocation()` also applied the resolver result directly and therefore received the same null Municipality. This attributes the failures to the existing fixture/resolver PSGC contract, not to the current Work's changed mapping, form dependency, or Push/Pull code. The dedicated `PhilippineAddressResolverTest.php` passes **10 tests / 37 assertions**. The unrelated fixture and resolver were not changed. `CustomerLocationMapTest.php` is excluded from the proposed terminal paths.

### Terminal package confirmation

The existing proposed staging sets in section “Final terminal readiness review” remain the exact package: Portal **15 paths** and Tablet **12 paths**. `CustomerLocationMapTest.php` and all other unchanged files are excluded. No staging has occurred. The proposed commit boundary remains one Portal commit and one Tablet commit, only after separate Human `TERMINAL GO`; this update is not that authorization.


### Final accepted-scope reconciliation

Human accepted the Fleet / North Luzon demonstration as a **conditional pass for the implementation foundation**. The synthetic mapping behavior is accepted; real-data Access activation is expressly not accepted or claimed. No Work-owned implementation defect remains based on the accepted Portal/Tablet validation and the final evidence review. The two `CustomerLocationMapTest` failures reproduce from unchanged pre-Work test/resolver behavior and remain outside this Work; no unrelated test or code was modified.

Defer the following to the separate **Location Automation Work**: missing Portal Specific Regions 52–68; 34 orphan Area Cluster references; Province/Municipality-derived location automation; legacy NCR parent reconciliation; the Region IV-A commercial split; and NIR coverage.

Real Access activation still requires Human approval of the Specific Region-to-business-group mappings and reconciliation/confirmation of all 46 personnel assignments. No mapping rows or real assignments were populated.

**Terminal plan:** one Portal commit containing exactly the 15 Portal paths listed above, and one Tablet commit containing exactly the 12 Tablet paths listed above. Exclude `tests/Feature/CustomerLocationMapTest.php` and every unchanged/unlisted file. These are proposed paths only. Both worktrees remain unstaged; no commit, push, deployment, PROD access, or retained database write occurred. The Work is **TERMINAL_DELIVERED** under the separate Human TERMINAL GO recorded below.


## Terminal delivery record

- **State:** TERMINAL_DELIVERED
- **Human authorization:** TERMINAL GO for the exact Portal and Tablet path lists recorded above.
- **Acceptance:** Implementation foundation accepted; Fleet / North Luzon demonstration conditionally passed using synthetic mappings. Real-data Access activation was not accepted and was not activated.
- **Deferred dependencies:** The separate Location Automation Work owns missing Portal Specific Regions 52–68, 34 orphan Area Cluster references, Province/Municipality-derived location automation, legacy NCR parent reconciliation, Region IV-A split, and NIR coverage. Real Access activation still requires approved Specific Region-to-group mappings and confirmation of the 46 personnel assignments.
- **Delivery boundary:** The two repositories use separate commits on their existing `main` tracking branches. The authorized package excludes `CustomerLocationMapTest.php`; no unrelated files are included. Commit IDs are reported in the terminal delivery response rather than self-referenced here.
