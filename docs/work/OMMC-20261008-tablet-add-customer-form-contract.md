# OMMC-20261008-tablet-add-customer-form-contract

## Work state

`TERMINAL_DELIVERED`

## Objective and boundary

Audit and correct the complete Tablet Add Customer contract from rendered form state through local rows, shared Customer push, Portal validation/persistence, canonical V2 representation, Profile display, and pull round-trip. The QA symptom was **“New customer - category did not reflect in portal.”** This Work is TERMINAL_DELIVERED and includes the approved Tablet-only reference membership migration (retained as historical schema compatibility), bounded Portal/Tablet sync changes, and validation coverage. No existing Customer data or reconciliation artifacts were changed. The Work changes were committed and pushed separately in Portal and Tablet; no deployment or PROD access occurred.

Affected repositories are Portal V2 `ommc2027` and Tablet V2 `ommc2027-tablet`. Portal current `main` is the schema and behavior authority. This note and its matrix are mirrored into both repositories to retain the repository Work record; the matrix has 101 rows and contains no Customer names or codes.

## Repository state at audit start

- Portal: branch `main`, HEAD `c2b8ab1575bd7d83bb1a0fabf41c83df9b44fb2a`. Tracked worktree clean. Pre-existing untracked reconciliation artifacts were preserved.
- Tablet: branch `main`, HEAD `0cec51af7d4cfbbb69cca1a504e1879d942dfd0c`. Tracked worktree clean. Pre-existing untracked `public/expense_attachments/` was preserved.
- Both repositories were already on current local `main`; no branch switching or fetching occurred.

## Audit artifacts and inventory

- Field matrix: `docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv` (101 rows; one row per distinct control/value destination, including 43 annual stream/year fields, 7 system-owned values, and 2 Portal-only comparison fields).
- Tablet Add form has 92 user-editable controls across the company profile variants and conditional sections: common identity/responsibility/location/profile fields, 20 company-specific profile controls, 8 owner fields, and 43 annual category stream/year cells. The Customer Code control is read-only and is recorded as a system-owned value. The same Address property is presented in two controls.
- Profiles: OMMC/LAST_MILE/CAR_CLUBS → outlet; FLEET → fleet; OE → oe; IB → ib. Person in Charge is shown for outlet; profile-specific controls and annual category streams are recorded individually in the matrix.
- The actual form has no Contact Number, RSM, DRM/RSR assignment, serving outlet, Landmass, house number, or separate company/account discriminator beyond Company-derived profile type. Do not treat the list in the request as exhaustive; the matrix follows the current Blade and Livewire form.

## Data-flow map

1. **Reservation and form state:** `CustomerCreatePage::mount()` initializes outlet categories and defaults the authenticated local User into editable `access_user_ids`. `updatedCompanyId()` clears the prior code/token, requests `POST /api/sync/reserve-customer-code`, and resets profile state when the mapped profile changes. The Blade view binds controls to public page properties and nested `trade`, `active`, and `categories` state. The map resolver can populate coordinates, address, location IDs, and physical Region state.
2. **Tablet local insertion:** `CustomerCreatePage::saveCustomer()` validates name, Company, local reference IDs and the category option vocabulary. In one transaction it generates a negative local Customer ID and `local_uuid`, fills the `customers` row, marks it pending, then calls `CustomerProfileFormService::saveAggregate()`. That service persists Access to local `customer_user`, typed values to `customer_trade_profiles`, active/owner values to `profile_data.active`, and annual categories to `customer_category_histories` keyed by customer/profile/stream/year. `physical_region_id` is not included in the Customer fill and has no Tablet Customer column.
3. **Push and retry:** After the local transaction, Add invokes the shared `SyncService::pushCustomer()`. The same `pushCustomerRecord()` path is used for Customer-only/manual and pending Customer push. It serializes scalar Customer properties, `trade_profile`, `profile_data`, category histories/events, optional province/barangay/area-cluster IDs, the local UUID, and the reservation token to `POST /api/sync/push/customer`. It omits `access_user_ids` and `physical_region_id`. A failed push leaves the local Customer pending/retryable; there is no local rollback of creation.
4. **Portal validation and persistence:** `SyncController::pushCustomer()` validates the request; normalizes legacy `AB and MCB` Entry Detail to `AB/MCB`; derives/validates profile type against Company; checks municipality/province/barangay hierarchy and annual category profile/stream/year/options. Inside a transaction it consumes the reservation token (or allocates through the Portal allocator when no token was supplied), upserts the Customer by `local_uuid`, sets `created_by` to the authenticated Portal API actor on create, conditionally syncs Access only when the request contains `access_user_ids`, upserts `customer_trade_profiles`, stores annual streams through `CustomerProfileService::saveCategoryStreams()`, and records category timeline events.
5. **Portal display:** `CustomerResource` uses the Portal Customer form for create/edit. `ViewCustomer` renders General Category from `generalCategory.name`; annual categories come from `annualCategoryTableState()` and `resources/views/filament/resources/customers/infolists/annual-category-table.blade.php`. Profile trade/active/owner values read the `customer_trade_profiles` columns and `profile_data.active` paths.
6. **Pull/round-trip:** `TabletSyncPayload::customerRecords()` returns Customer rows plus trade profiles, category histories, and category events. Tablet `SyncService::applyPullPayload()` maps Portal Customer IDs to local IDs, updates scalar Customer fields and child profile/history/event rows, while protecting pending/failed/conflicted local Customers. General Category lookup and ID are pulled. Current paginated pull does not include `customer_user` or Access Users, so the Portal Access result is not round-tripped.

## Contract findings

### Category and the QA symptom

- “General Category” is the Tablet scalar `general_category_id` (Portal GeneralCategory FK). Tablet local save persists it; push uses the same `general_category_id` key; Portal validates `exists:general_categories,id` and fills `customers.general_category_id`; the Portal Profile reads `generalCategory.name`; paginated pull returns the Customer FK and lookup records. **Current static contract: COMPLETE.**
- The profile’s annual categories are a different concept. Tablet options are selected by profile and stream. Local save creates `customer_category_histories` rows; push serializes `profile_type`, `stream`, `category_year`, and `category`; Portal validates all four dimensions and stores the canonical `customer_category_histories` representation. Portal Profile renders those rows by profile stream and year; pull returns and re-upserts the history rows. It does not flatten annual history into a Customer scalar. **Current static contract: COMPLETE.**
- The two category vocabularies, streams, years, and option lists in the checked-in Tablet/Portal `customer_trade_form.php` match for annual categories. Portal additionally normalizes the legacy Tablet outlet value `AB and MCB` to canonical `AB/MCB` before validating expected streams.
- A read-only query of the configured local Portal database found a stored example (Portal Customer ID 23409): outlet profile, AB entry, General Category FK present, and `customer_category_histories` row `stream=ab`, `category_year=2025`, `category=AB Company Owned`. Current Profile code selects that row for the 2025 AB cell. No Customer name/code was recorded.
- **Root cause of the reported runtime symptom is not established.** Current source shows no first failing boundary for either category representation. Safe end-to-end reproduction was not run: Portal `.env` selects local MySQL database `ommc_henri_reconcile_customers`, which contains reconciliation/customer data, and one Portal migration is pending. A normal create/push would mutate that database; the GO explicitly excludes mutation of existing business/Customer data. No isolated disposable Portal database or running normal-flow app against one was available. This remains a static conclusion, not runtime proof.

### Defects and ambiguities

- **Access silently disappears at Tablet push.** Local Access is persisted, including the authenticated local User default, but the shared Customer push payload has no `access_user_ids` key. Portal accepts the omission and only creates a `customer_user` relation when the key exists; it assigns API actor to `created_by`, which is not equivalent to Access. If Access is added to the request unchanged, local User IDs are not proven Portal User IDs. In addition, Portal sync validation rejects any supplied Access User with null `rsm_id`, conflicting with the accepted Tablet Add behavior that does not require RSM. Root grouping: `MULTI_BOUNDARY` (first break is `TABLET_PUSH_CONTRACT`; subsequent identity/validation rules are `PORTAL_SYNC_VALIDATION` and `SEMANTIC_POLICY`).
- **Person in Charge can send the wrong identity.** Tablet persists `person_in_charge_id` from the local users table and sends it as if it were a Portal User ID. Login upserts the local User by email without assigning the Portal primary key to the local primary key; there is no verified ID mapping in this contract. Portal validates against `users.id`, so a coincidentally matching integer can select the wrong person and an unmatched integer can reject the push. The paginated pull also copies the Portal User ID into the local scalar FK without mapping. The Tablet form makes this field optional. `config/customer_trade_form.php` says it is required for outlet, but no executable Portal form or API rule enforces that declaration. Root grouping: `MULTI_BOUNDARY` / `SEMANTIC_POLICY`.
- **Physical Region is lost and Portal display semantics are wrong.** Tablet presents a separate `physical_region_id`, but that value is not stored in its Customer table or included in push. When Municipality is set, physical Region can be derived from `municipalities.region_id`; when it is not, the selection is lost. Portal API has no physical Region request/customer destination. Portal Profile’s Region entry currently reads `regionSpecific.region.name`, which is the commercial Specific Region parent and can disagree with physical geography. Portal’s own create form also marks this field dehydrated(false), relying on Municipality for physical geography. Root grouping: `MULTI_BOUNDARY` (`TABLET_LOCAL_PERSISTENCE`, `TABLET_PUSH_CONTRACT`, `PORTAL_CANONICAL_MODEL`, `PORTAL_DISPLAY`, `SEMANTIC_POLICY`).
- **The current Add Customer Blade contains two identical opening `<form>` tags and one closing tag.** Browser parsing treats the second form start as invalid while a form is already open; the actual DOM and Livewire submission were not runtime-verified. Record this as a `TABLET_FORM` structural anomaly for the future normal-flow validation, not as the established Category cause.
- **Two conditional profile values can be locally accepted then rejected by Portal.** Tablet has no local conditional required rule for Warehouse Code when MOTIV User is true or Delivery Detail when Delivery Type is yes. Portal sync aborts with 422 for either missing value. Local Customer remains retryable, but creation does not complete on Portal. Root grouping: `MULTI_BOUNDARY` (`TABLET_FORM`, `PORTAL_SYNC_VALIDATION`).
- **Portal-required scheduling data is absent from Tablet.** Portal’s own Customer form requires `landmass_code`; helper copy says a Customer without it is not scheduled. Tablet has no Landmass control, local field, payload, validation, or pull. Sync API currently permits it to be absent. Its necessity for the accepted Tablet creation definition is not established by sync validation, so classify as `AMBIGUOUS` / `POSSIBLY_MISSING_FROM_TABLET`, not a proven required API field. Root grouping: `SEMANTIC_POLICY` / `PORTAL_ONLY_BY_DESIGN` pending resolution.
- No annual or General Category value is silently dropped or mis-keyed in the inspected current source. No incorrectly mapped category value was found. The QA symptom may refer to a different build, display context, selected field meaning, or runtime state; those possibilities are unverified.

## Portal versus Tablet Customer creation

| Portal field/behavior | Classification | Finding |
|---|---|---|
| Name, Company, General Category, competitor volume, address, coordinates, contacts, established date, Province, Municipality, Barangay, Specific Region, Area Cluster, profile trade fields, owner values, annual category histories | `TABLET_PRESENT_AND_EQUIVALENT` | Same meaningful values and canonical destinations except separately listed gaps below. Physical Region is not equivalent. |
| Access | `TABLET_PRESENT_DIFFERENT_SEMANTICS` | Tablet has editable local Access, but does not push it; Portal actor attribution is not Access. Portal Access sync rule also requires selected users have an RSM. |
| Person in Charge | `TABLET_PRESENT_DIFFERENT_SEMANTICS` | Tablet sends local User primary key without verified Portal identity mapping. |
| Physical Region | `TABLET_PRESENT_DIFFERENT_SEMANTICS` | Tablet field is transient; Portal canonical physical region depends on Municipality, while Profile labels a Specific Region-derived value as Region. |
| Landmass | `POSSIBLY_MISSING_FROM_TABLET` | Required in Portal’s own create form and scheduling guidance, absent from Tablet and optional in sync API. |
| Legacy `contact_number` | `PORTAL_ONLY_BY_DESIGN` | API/schema retain it, but current Portal form/Profile and Tablet Add form use Contact Person, Business Landline, and Business Mobile instead. Do not alias them without product authority. |
| Serving Outlet / DRM-RSR assignment | `PORTAL_ONLY_BY_DESIGN` or unavailable | Current Tablet Add form does not expose these. Portal config documents serving outlet as unavailable; Portal form has Access and derived RSM rather than a dedicated DRM/RSR field. |
| RSM, profile type, creator, Customer Code, IDs, sync state, timestamps | `DERIVED_OR_SYSTEM_OWNED` | RSM derives from Access Users’ `rsm_id`; profile type from Company; creator from authenticated Portal API actor; Customer Code from Portal reservation/allocator; identities and sync timestamps are system-owned. Keep these concepts distinct. |

## Completeness assessment

**`FORM_CONTRACT_INCOMPLETE`**

Access is silently omitted, Person in Charge identity is not safely mapped, physical Region has no persistence/push destination and the Portal Profile Region source is semantically wrong, and Portal’s conditional requirements can reject Tablet-created records. Landmass is a material ambiguity because Portal create requires it and scheduling guidance says it is needed, while sync accepts absence. Customer creation success alone is not sufficient for completeness.

## Minimal proposed implementation after Human review

1. Resolve the Access identity contract once in the shared push path: serialize the editable/default Access set using verified Portal identities; reconcile Portal validation with the accepted no-RSM Tablet rule without manufacturing RSM relationships. Preserve negative local IDs as local identities and fail retryably when a selected identity cannot be mapped.
2. Use that verified identity mapping for Person in Charge, or block unresolved selections from being sent; reconcile executable requiredness with the existing outlet `field_rules` declaration.
3. Establish one physical geography contract. Preserve Region through Municipality when Municipality exists, define behavior for Region without Municipality, push/persist only the canonical physical representation, and make Portal Profile Region read physical geography. Keep Specific Region and Area Cluster independent.
4. **Completed in this bounded execution:** enforce Portal’s Warehouse Code and Delivery Detail conditional requirements on Tablet before local persistence/push; retain the pending/retry path for later network/server failures.
5. Decide whether Landmass is required for a valid Tablet-created Customer. If yes, add the minimum canonical collection/derivation and sync support; if not, document why the Portal form/scheduling requirement differs. Do not add other Portal-only fields.
6. Do not change either Category representation unless runtime evidence identifies a defect; preserve General Category as its FK and annual category history by profile/stream/year.

## Candidate follow-on implementation surfaces

- Tablet: `app/Filament/Pages/CustomerCreatePage.php`, `app/Services/CustomerProfileFormService.php`, and `app/Services/SyncService.php`; the Customer model/migration may be needed only if Human-approved physical Region or Landmass semantics require canonical local storage. Add focused Customer create/push contract coverage.
- Portal: `app/Http/Controllers/Api/SyncController.php` for verified Access/PIC identity and accepted validation, `app/Filament/Resources/Customers/Pages/ViewCustomer.php` for physical Region display, and the Portal Customer form only if Landmass/conditional requirements are resolved as in-scope. A schema migration is conditional on the chosen canonical geography contract.
- No Category code change is proposed without runtime evidence.

## Future implementation validation

Use a dedicated disposable local Portal database and Tablet SQLite copy with current migrations, run the normal Tablet Add Customer UI/Livewire save and shared Customer push, and produce a machine-readable field result artifact. For every populated field verify form state → local row/related row → captured outbound request → Portal stored canonical row → Portal Customer Profile → pull response/local round-trip. Include all relevant profile variants and conditional values, and verify:

- Portal Code reservation token consumption, company change invalidation, no Tablet sequence allocation, and response reconciliation.
- Access default/edit/add behavior, verified ID mapping, negative local IDs, and no manufactured RSM.
- Physical Region → Province → Municipality → Barangay and independent Specific Region → Area Cluster behavior, including Region without Municipality.
- General Category FK and annual Category history display/round-trip; existing history is not flattened or overwritten outside the matching profile/stream/year slot.
- Conditional MOTIV/Warehouse Code and Delivery Type/Detail validation; retryable Customer push and manual failed/retryable push visibility.
- A before/after database diff proving no existing Customer or unrelated records changed.

## Investigation validation and limitations

- Static traces covered the current Add Customer Blade, `CustomerCreatePage`, `CustomerProfileFormService`, Tablet Customer migrations/model, shared `SyncService` push/pull paths, Portal `SyncController`, `TabletSyncPayload`, Portal Customer model/form/create page, and Portal `ViewCustomer`/annual-category table.
- Read-only local Portal query inspected only stable ID 23409 and non-PII profile/category facts. Tablet local SQLite read-only customer count: 707. No Customer row was inserted, updated, or deleted.
- Matrix validation: 101 data rows, 20 columns consistently shaped, no blank statuses or evidence cells. Status counts: 90 `COMPLETE`, 2 `NOT_PUSHED`, 1 `PUSHED_WRONG_VALUE`, 1 `NOT_PERSISTED_LOCALLY`, 2 `PORTAL_REJECTED`, 2 `DERIVED_BY_PORTAL`, 2 `INTENTIONALLY_NOT_SYNCED`, 1 `AMBIGUOUS`.
- No application tests were run; no application files changed. Investigation validation was static source/database inspection and TSV structure/status validation.
- Portal local DB has pending migration `2026_10_06_000001_add_deleted_at_to_users_table`; this also makes it unsuitable for safe normal-flow reproduction during this Work.

## Worktree evidence at audit completion

Portal `main` HEAD remains `c2b8ab1575bd7d83bb1a0fabf41c83df9b44fb2a`. Tracked files remain clean. Existing unrelated untracked paths, plus the two authorized Work artifacts:

```text
?? docs/reconciliation/
?? docs/work/OMMC-20261007-customer-master-data-reconciliation-v2-ledger.tsv
?? scripts/reconciliation/customer-master-data-v2/README.md
?? scripts/reconciliation/customer-master-data-v2/candidates.jsonl
?? scripts/reconciliation/customer-master-data-v2/manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153526Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153602Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/municipality-corrections-review.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-audit-artifacts.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-category-history-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export-manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-after.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-before.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-drm-user-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-exceptions.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-full-data-audit-summary.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-municipality-corrections.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-province-exclusions.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-qa-comparison.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-trade-profile-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-identities.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-user-pivots.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-excluded-province.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-municipality-corrections.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-user-rsm.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-verification-manifest.json
?? scripts/reconciliation/customer-master-data-v2/run.php
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
```

Tablet `main` HEAD remains `0cec51af7d4cfbbb69cca1a504e1879d942dfd0c`. Tracked files remain clean. Existing unrelated attachments were preserved; the two authorized Work artifacts are added:

```text
?? public/expense_attachments/
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
```

## Authorization and remaining work

- The initial investigation snapshot was `PLANNED`; after authorized bounded corrections the current state is `ACTIVE`. It is not `READY_FOR_TERMINAL_REVIEW`.
- Stop for Human review and further implementation authorization. No further fixes are authorized by this bounded GO; do not commit, push, deploy, or access PROD.


## Bounded implementation execution (2026-10-08)

### Authorization gates and decisions

- The required stable User identity gate failed: Portal `tabletLogin()` returns Portal `users.id`, but Tablet `SyncService::refreshToken()` upserts by email and discards the returned ID; Tablet `users` has no Portal ID mapping. Email is mutable and is not treated as a stable canonical identity. Access and Person in Charge changes were therefore not implemented. No identity mapping or architecture was invented.
- Physical Region-only persistence remains blocked: Tablet exposes transient `physical_region_id`, but neither Tablet Customer persistence nor Portal Customer canonical schema has a standalone physical Region FK. No schema or migration was authorized. Portal Profile display was corrected to derive physical Region from `municipality.region.name`, while Specific Region continues to display from `regionSpecific.name`. Region-only values still have no canonical destination.
- General Category / annual Category implementation was not changed. No safe isolated Portal database was configured for normal-flow reproduction; the configured Portal DB is the excluded reconciliation database. The QA symptom’s runtime first failure boundary therefore remains unproven.
- Landmass semantics remain unresolved and unchanged.

### Corrections applied

- Removed the duplicate opening form from Tablet Add Customer Blade; a focused render assertion verifies one opening and one closing form.
- Added pre-save validation for outlet MOTIV → Warehouse Code and Delivery Type `yes` → Delivery Detail, including accepted delivery detail values. Focused Livewire tests verify each validation error occurs before local Customer persistence and before Customer push.
- Changed Portal Customer Profile Region source from commercial `regionSpecific.region.name` to physical `municipality.region.name`. Specific Region remains independently displayed. Added a focused regression contract assertion.
- No Access, PIC, Category, schema, migration, reconciliation, or existing business data changes.

### Validation evidence

- Tablet: `php artisan test --compact --filter='rejects missing|scoped customer-create-form' tests/Feature/CustomerCreatePageDiagnosticTest.php` — 3 passed, 14 assertions.
- Tablet full focused file: 6 passed and 2 failed. Both failures are existing expectations for `x-model="$wire.company_id"` in Add/Edit rendering even though those controls currently use `wire:model`; failures are outside these corrections.
- Portal: `php artisan test --compact tests/Feature/CustomerLocationMappingTest.php` — 6 passed, 21 assertions.
- Portal Pint `--test` reports pre-existing formatting differences in `ViewCustomer.php` (ordered imports/brace/indentation/strict types). No broad formatter rewrite was applied.
- `git diff --check` passed for Tablet changes. No Portal/Tablet normal-flow E2E ran.

### Current completeness and boundary

`FORM_CONTRACT_INCOMPLETE`. The corrected conditional fields and Portal physical Region display are covered by automated checks. Access and PIC still lack safe cross-system User identity mapping; exposed Physical Region still cannot represent Region without Municipality; Landmass remains semantically unresolved. Category’s static path remains connected, but the reported symptom has no runtime-confirmed first failure boundary. Do not mark `READY_FOR_TERMINAL_REVIEW`; stop for Human review and implementation authorization.

### Minimal remaining implementation plan

1. Establish a canonical stable Portal User ID mapping through an explicitly approved identity design, then separately implement/test Access sync and PIC mapping and reconcile Access no-RSM policy.
2. Decide whether Region without Municipality is a supported Tablet Customer state. If so, approve canonical storage/schema contract before implementing; otherwise make the UI contract explicit.
3. Resolve Landmass requiredness for Tablet creation and scheduling.
4. Reproduce General Category and annual Category through normal UI→push→Portal→Profile→pull on isolated disposable environments before changing Category code.
5. Run the future end-to-end matrix described below with before/after data diff on disposable environments only.

### Worktree state after bounded execution

Both repositories remain on their recorded `main` HEADs (`ommc2027` `c2b8ab1`; `ommc2027-tablet` `0cec51a`). Portal tracked modifications: `app/Filament/Resources/Customers/Pages/ViewCustomer.php`, `tests/Feature/CustomerLocationMappingTest.php`. Tablet tracked modifications: `app/Filament/Pages/CustomerCreatePage.php`, `resources/views/filament/pages/customer-create-page.blade.php`, `tests/Feature/CustomerCreatePageDiagnosticTest.php`. Both have the two mirrored Work artifacts untracked. Portal also retains its pre-existing untracked reconciliation artifacts listed in “Worktree evidence at audit completion”; Tablet retains pre-existing untracked `public/expense_attachments/`. No commit, push, deploy, PROD access, schema change, migration, or existing business-data mutation occurred.

## Scope-revision implementation and validation (2026-10-08)

This execution record supersedes the earlier bounded-execution section above where it conflicts. The active approved scope is the clarified Tablet Add Customer persistence/sync implementation plan. Work state remains `ACTIVE`; Human functional acceptance is pending. The complete 101-row matrix is authoritative for the field inventory and per-field status.

### Results and root-cause groups

- **Category:** General Category is the canonical `customers.general_category_id` FK to Portal `general_categories`; it is distinct from annual history. Annual values remain in `customer_category_histories`, keyed by Customer/profile/stream/year. No category-specific implementation defect was found in the source; the isolated normal-flow E2E now proves General Category and all outlet AB years 2018–2026 across local save, HTTP payload/push, Portal persistence/API representation, and Tablet pull. A separate Portal test verifies changing one year does not overwrite another history slot. The original QA report has no supplied failing customer/build to reproduce, so its tenant-specific cause remains **unresolved**; this synthetic proof establishes the current implementation path only.
- **Commercial geography and stale references (`REFERENCE_REFRESH` + `HIERARCHY_VALIDATION`):** later Customer pulls previously skipped reference refresh. Each Customer pull now refreshes Portal references before customer pages. Tablet and Portal both reject Area Clusters whose `region_specific_id` differs from the selected Specific Region. Tablet validates Province→physical Region, Municipality→Province/Region, Barangay→Municipality, while commercial Specific Region→Area Cluster stays independent. No label mapping or ID synthesis was added. A read-only snapshot from the configured Portal local DB (not used for writes or reproduction) showed 18 Specific Regions on Tablet versus 1 on Portal; 17 Tablet Specific Region IDs have no Portal match, and some Area Cluster parents likewise do not match. It also showed 3 Provinces with null physical `region_id`. These are reference-data/environment limitations: affected selections cannot be offered/validated from an authoritative parent and remain blocked pending source-data resolution.
- **Access and Person in Charge (`CROSS_SYSTEM_IDENTITY`):** push previously omitted Access and sent Tablet numeric PIC IDs as if they were Portal IDs; pull lacked these relations and could copy Portal IDs directly. Push now sends current exact email strings. Portal resolves each email to exactly one Portal User, rejects unknown/ambiguous/numeric identity inputs, and never falls back to name or Tablet ID. Access is persisted even when its user has no RSM; this operation does not create or change RSM assignment. Pull sends emails and user references; Tablet maps them to local User IDs. New local reference users use the exact email and name and receive no RSM/role/token assignment. Ambiguous local email or missing pull identity reference fails closed.
- **Physical Region-only (`CANONICAL_STORAGE_LIMIT`):** the selected Region remains transient because the current Tablet Customer schema and Portal canonical Customer schema have no standalone physical Region FK. This plan authorizes no schema change. Region is validated against selected physical descendants, but a Region selected without Province/Municipality cannot be persisted or round-tripped; the matrix marks it `NOT_PERSISTED_LOCALLY`/`LIMITED`. Province and its descendants are canonical persisted IDs.
- **Other conditional controls:** outlet MOTIV→Warehouse Code and delivery type `yes`→allowed Delivery Detail validations remain implemented and focused Livewire tests cover invalid submission before local insert/push. The duplicate Add form boundary was fixed. Portal Profile UI was not modified; the earlier out-of-scope `ViewCustomer` display edit and regression test were removed before this scope began.

### Changed boundaries and artifacts

Portal changes are limited to `SyncController` push validation, exact User resolution, Area Cluster parent validation, and `TabletSyncPayload` pull fields for PIC/Access identity references. Tablet changes are limited to `CustomerCreatePage` local conditional/geography validation, Add form validation display, and `SyncService` refresh/push/pull contract. Focused tests were added/updated for Portal identity and hierarchy rules, no-RSM Access behavior, annual-history preservation, Tablet Add form behavior, reference refresh, identity pull mapping, and optional isolated normal-flow E2E.

No schema or migrations were added. No existing Customer/history or reconciliation database rows were written. No PROD, commit, push, or deployment occurred. Customer Code remains Portal-reserved/Portal-owned; the E2E verified an OMMC code was assigned and returned. Push failure retry behavior remains pending/retryable through the existing local SyncService path; no failed network submission was injected into the E2E.

### Validation evidence

- Portal: `php artisan test --compact tests/Feature/TabletCustomerCreationContractTest.php` — **4 passed, 33 assertions**.
- Tablet: `php artisan test --compact tests/Feature/CustomerCreatePageDiagnosticTest.php tests/Feature/CustomerLocationSyncTest.php tests/Feature/CustomerPagedPullTest.php tests/Feature/CustomerCreatePortalE2ETest.php` — **20 passed, 260 assertions, 1 skipped**. The skipped E2E in that combined invocation had no endpoint variable set. It was then explicitly run against the isolated disposable local Portal HTTP server: `CUSTOMER_CONTRACT_PORTAL_URL=http://127.0.0.1:18089 php artisan test --compact tests/Feature/CustomerCreatePortalE2ETest.php` — **1 passed, 46 assertions**.
- Isolated E2E used a fresh disposable Portal SQLite database and Tablet test database. It submitted a normal Livewire Add flow, captured/verified the resulting Portal API representation and Portal DB-backed pull, then pulled to Tablet and checked Access/PIC local mapping. Before/after snapshots showed all pre-existing Portal Customers, profiles, and histories unchanged. The one fixture covered outlet AB history, not MCB/Fleet/OE/IB end-to-end runtime.
- Tablet paginated pull tests include later-run location refresh: **9 passed, 110 assertions**.
- Pint ran with `--dirty --format agent` in both repos. `git diff --check` and matrix shape/count checks are recorded at completion. Tablet required repo bootstrap `search-docs` capability was unavailable in this tool session; relevant existing source/tests and repository guidance were inspected directly.
- The Portal `--dirty` Pint invocation also reformatted the pre-existing untracked `scripts/reconciliation/customer-master-data-v2/run.php`. Its original untracked contents were not available to restore from Git; no reconciliation data/artifact content was deliberately edited. This incidental formatting change is disclosed for Human review. Other reconciliation files and Tablet expense attachments remain untouched.

### Contract outcome and next steps

`FORM_CONTRACT_INCOMPLETE`. Category and the exercised outlet data paths now pass isolated end-to-end evidence. Cross-system Access/PIC, reference refresh, and hierarchy validation defects have evidence-backed corrections. Remaining limits are (1) physical Region-only storage without an authorized canonical column; (2) missing/mismatched source reference rows in the configured local Portal snapshot; (3) no reproduction of the original tenant-specific category symptom; (4) no runtime E2E for MCB/Fleet/OE/IB and unpopulated conditional/variant controls; and (5) no injected failed-push retry E2E. Do not mark `READY_FOR_TERMINAL_REVIEW`.

Smallest evidence-backed follow-up: Human should functionally accept/reject the exercised changes; the reference-data owner should repair/confirm canonical parent mappings without label substitution; product/schema authority should decide if Region-only is a supported state; then run the matrix on an isolated build matching the reported QA app/data and add focused E2E for the remaining profile streams and failure retry. No further code or data action is authorized by this implementation pass.

### Final repository status

Final complete `git status --short` output for both repositories is included in the handoff. Portal remains on `main` at `c2b8ab1575bd7d83bb1a0fabf41c83df9b44fb2a`; Tablet remains on `main` at `0cec51af7d4cfbbb69cca1a504e1879d942dfd0c`. Tracked/untracked changes and preserved unrelated paths are listed there; no commit, push, deployment, or READY transition was made.


#### Complete final `git status --short` snapshots

**ommc2027**

```text
 M app/Http/Controllers/Api/SyncController.php
 M app/Services/TabletSyncPayload.php
?? docs/reconciliation/
?? docs/work/OMMC-20261007-customer-master-data-reconciliation-v2-ledger.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? scripts/reconciliation/customer-master-data-v2/README.md
?? scripts/reconciliation/customer-master-data-v2/candidates.jsonl
?? scripts/reconciliation/customer-master-data-v2/manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153526Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153602Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/municipality-corrections-review.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-audit-artifacts.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-category-history-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export-manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-after.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-before.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-drm-user-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-exceptions.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-full-data-audit-summary.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-municipality-corrections.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-province-exclusions.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-qa-comparison.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-trade-profile-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-identities.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-user-pivots.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-excluded-province.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-municipality-corrections.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-user-rsm.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-verification-manifest.json
?? scripts/reconciliation/customer-master-data-v2/run.php
?? tests/Feature/TabletCustomerCreationContractTest.php
```

**ommc2027-tablet**

```text
 M app/Filament/Pages/CustomerCreatePage.php
 M app/Services/SyncService.php
 M resources/views/filament/pages/customer-create-page.blade.php
 M tests/Feature/CustomerCreatePageDiagnosticTest.php
 M tests/Feature/CustomerLocationSyncTest.php
 M tests/Feature/CustomerPagedPullTest.php
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? public/expense_attachments/
?? tests/Feature/CustomerCreatePortalE2ETest.php
```


#### Complete final `git status --short` snapshots

**ommc2027**

```text
 M app/Http/Controllers/Api/SyncController.php
 M app/Services/TabletSyncPayload.php
?? docs/reconciliation/
?? docs/work/OMMC-20261007-customer-master-data-reconciliation-v2-ledger.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? scripts/reconciliation/customer-master-data-v2/README.md
?? scripts/reconciliation/customer-master-data-v2/candidates.jsonl
?? scripts/reconciliation/customer-master-data-v2/manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153526Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153602Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/municipality-corrections-review.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-audit-artifacts.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-category-history-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export-manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-after.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-before.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-drm-user-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-exceptions.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-full-data-audit-summary.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-municipality-corrections.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-province-exclusions.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-qa-comparison.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-trade-profile-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-identities.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-user-pivots.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-excluded-province.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-municipality-corrections.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-user-rsm.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-verification-manifest.json
?? scripts/reconciliation/customer-master-data-v2/run.php
?? tests/Feature/TabletCustomerCreationContractTest.php
```

**ommc2027-tablet**

```text
 M app/Filament/Pages/CustomerCreatePage.php
 M app/Services/SyncService.php
 M resources/views/filament/pages/customer-create-page.blade.php
 M tests/Feature/CustomerCreatePageDiagnosticTest.php
 M tests/Feature/CustomerLocationSyncTest.php
 M tests/Feature/CustomerPagedPullTest.php
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? public/expense_attachments/
?? tests/Feature/CustomerCreatePortalE2ETest.php
```

## Human validation authority and required-field audit (2026-10-09)

Human clarification is authoritative for this audit: for iPad Add Customer, the only user-entered required fields for every supported Company variant are **Customer Name** and **Company**. Every other form field is optional. When an optional value is supplied, normal type, option, reference, relationship, identity, Company/profile, and synchronization integrity checks still apply. This clarification supersedes any earlier scope-note statement that treated conditional requiredness as accepted behavior. Work remains `ACTIVE`; investigation only; no application source or schema changed in this continuation.

### Reported `active.delivery_detail` error

- The exact Tablet rule is `Rule::requiredIf(fn () => $profileType === 'outlet' && ($this->active['delivery_type'] ?? null) === 'yes')` at `CustomerCreatePage::saveCustomer()`. Its nullable and enum rules are in the same rule entry.
- Company mapping is OMMC→`outlet` and LAST_MILE→`outlet`; therefore those two variants activate the rule only when Delivery Type equals the string `yes`. FLEET→`fleet`, OE→`oe`, IB→`ib`; none activates the rule.
- The Blade condition uses `@if(($active['delivery_type'] ?? null) === 'yes')`. When active, it renders an enabled select with a blank placeholder and values `own_delivery` (“Own Delivery”) and `meh` (“MEH”), using `x-model="$wire.active.delivery_detail"`. That matches the validated Livewire path `active.delivery_detail`. Delivery Type’s change handler commits `active.delivery_type` live; Livewire’s nested reactive state is included in the next submit diff. A temporary isolated Livewire probe confirmed the detail is rendered for outlet+yes, blank state fails, selecting `own_delivery` satisfies the validator, and switching to FLEET clears the outlet state and hides the control/error.
- Portal API independently enforces the same condition inside the outlet branch: if active `delivery_type === yes`, it aborts unless `delivery_detail` is filled. Thus the behavior is not a Blade-hidden-but-server-required mismatch for outlet+yes, and there is no evidence of a property-name mismatch. The field is satisfiable through the rendered control. However, this **does conflict with the clarified Human policy**, which makes Delivery Detail optional even when Delivery Type is Yes.
- Classification: `CONDITIONAL_REQUIRED_RULE` (Tablet and Portal API), a policy conflict. It is not evidence of stale Company state: changing to a non-outlet Company resets active profile state, and the isolated probe no longer showed the error. If the error appeared, current server state was outlet + `delivery_type=yes` + blank detail (or an earlier error had not yet been resubmitted); the unavailable iPad interaction history prevents identifying which values the Human selected. No browser attached to that iPad was available for direct event capture.

### Required-field conflict inventory by Company/profile

| Company | Profile | Active required rule(s) beyond Name + Company | Current behavior |
|---|---|---|---|
| OMMC | outlet | Warehouse Code when MOTIV User is true; Delivery Detail when Delivery Type is `yes` | Both rules exist in Tablet and Portal API. Warehouse Code is shown when MOTIV is checked; Delivery Detail is shown when Delivery Type is Yes. Both conflict with Human policy. |
| Last Mile (`LAST_MILE`) | outlet | Same two outlet rules | Same conflicts and visibility behavior as OMMC. |
| Fleet (`FLEET`) | fleet | None found | Shared fields, fleet profile fields, owner fields, and Fleet annual categories are optional. |
| OE | oe | None found | Shared fields, OE profile fields, owner fields, and OE annual categories are optional. Sulfuric Acid is shown only for Entry Detail Acid, but is optional. |
| IB | ib | None found | Shared fields, IB classification, owner fields, and IB annual categories are optional. |

The repository config also maps `CAR_CLUBS` to `outlet`; this extra Company code would inherit the same two outlet rules if present, although it is outside the five variants requested here. The config `field_rules.person_in_charge_id.required=true` is stale declarative metadata: repository search found no consumer of `field_rules`/`profile_contract`; executable Tablet validation uses `nullable|integer|exists`, the control offers an empty option, and Portal’s PIC email API rule is nullable. It does not currently block saves, but should be reconciled during an authorized implementation.

The complete 101-row matrix now records Company applicability, Human-required policy, Tablet validator, Portal API validator, rule classification, and visibility/satisfiability. It covers common fields, each profile-specific section, conditional controls, every annual stream/year cell, and system/Portal-only comparisons.

### Complete validation path inventory

- **Tablet Livewire:** `name` is `required|string|max:255`; `company_id` is `required|exists:companies,id`. All common scalar fields are nullable with types/formats, and IDs use `exists`. General Category and competitor volume use FK/enum checks. Access/PIC references are optional and validated when supplied. Conversion Program is optional with an allowed-value rule. The only other active required rules are the two conditional outlet rules above. Geography custom checks enforce Province/Region when both are present, Municipality/Region and Municipality/Province when those values are present, Barangay/Municipality, and Area Cluster/Specific Region. Nonblank annual category values must match options for the active profile/stream.
- **Dormant Tablet validation:** `validateScopedPortalRules()` contains an Access-user RSM requirement but is not called by `saveCustomer()`. It does not presently reject a no-RSM selection. Human-approved runtime policy and the Portal API implementation allow Access without an RSM.
- **Tablet Company/profile applicability:** Add uses Company code mapping, shows outlet controls for OMMC/Last Mile, and switches profile blocks for Fleet/OE/IB. When profile changes, `updatedCompanyId()` clears `trade`, `active`, categories, and PIC; switching between two outlet Companies preserves outlet state because the profile is unchanged. Conditional outlet controls therefore do not run for Fleet/OE/IB. The server aborts if Company has no supported profile mapping.
- **Portal API:** `name` and `company_id` are required. `local_uuid` is a required system/idempotency key; `created_by` is derived from the authenticated Portal API actor. Company/profile must match. The API duplicates the Warehouse Code and Delivery Detail conditional requiredness, causing the second policy boundary. Most business-field rules are nullable; category-history `category_year` and `category` are required only inside a submitted history row, and category events/user-email list entries similarly require their structural member fields only when those records are sent. These are record-shape/data-integrity rules, not mandatory blank form cells. Customer Code/token are nullable; a provided reservation token must resolve to the selected Company/code, and Portal can allocate a code when absent.
- **Local persistence and Portal database:** Tablet `customers.name` is non-null; `company_id` and optional profile/reference values are nullable. Portal `customers.name` is non-null; `company_id`, `region_specific_id`, and `municipality_id` are nullable in the current migrations. Portal `created_by` is a non-null User FK satisfied by the authenticated API actor. `customer_trade_profiles.customer_id` is non-null, and the Add aggregate creates that related row even when all profile fields are blank. Category history `category_year`/`category` and User/Access pivot foreign keys are non-null only for rows actually inserted; Add skips blank categories and an empty Access list creates no pivot rows. No inspected DB constraint requires Delivery Detail, Warehouse Code, PIC, address, geography, annual categories, or profile-specific values.
- **Portal UI distinction:** the Portal Filament CustomerForm separately requires `landmass_code` in its standalone UI, while the Portal column is nullable and `/api/sync/push/customer` does not require it. Landmass is not an iPad field and is not an API/database required conflict for this Tablet flow. If Human intends the two-field rule to govern Portal Filament creation too, that separate UI rule needs inclusion in later authorization.

### Optional-field value integrity findings

The current error is not caused by local insertion or a database NOT NULL constraint; it is the two conditional request validators. Safe test-database probes with only Name and Company succeeded for all five requested variants:

- Tablet Livewire Add component saved all five Customers and related profile rows with optional form properties empty: **1 temporary probe passed, 21 assertions**.
- Portal sync API accepted the minimal `local_uuid` + Name + Company + mapped profile payload for all five variants, generated Customer Codes, inserted Customer/profile rows, and satisfied DB constraints: **1 temporary Portal probe passed, 26 assertions**.
- The earlier isolated normal-flow outlet E2E (46 assertions) provides positive-value persistence evidence for a representative set of populated optional fields: General Category, geography, contact values, Access/PIC, outlet trade/profile/owner values, and AB histories. It does not exercise every field or all five profiles.
- Both temporary probe test files were removed; no durable test or source file was added or changed in this investigation.

Separate from requiredness, the trace found optional-value validation gaps to retain for implementation planning: Tablet `saveCustomer()` does not explicitly validate most `trade.*` and profile-specific `active.*` values against their configured option lists/types; Portal validates only a subset of nested `trade_profile` fields and treats `profile_data.active` as a generic array, so delivery detail option membership is not enforced by API. Browser controls constrain normal selection but are not server-side integrity rules. Latitude/longitude are numeric but not bounded to geographic ranges or validated as a pair. These do not cause a required error for blank values; they matter only when values are supplied or requests are malformed. Category histories and location/User relationships do have dedicated validation paths as summarized above.

### Smallest coordinated implementation proposal (not applied)

1. Tablet: remove only `requiredIf` from `active.warehouse_code` and `active.delivery_detail`. Retain `nullable`, string/length, and Delivery Detail allowed-value checks; blank values then pass even when MOTIV=true or Delivery Type=yes.
2. Portal API: remove the matching two `abort_unless(filled(...))` checks. Add/retain nullable nested value validation for `profile_data.active.warehouse_code`, `delivery_type`, and `delivery_detail` (including allowed delivery type/detail values), plus the existing `trade_profile.motiv_user` boolean rule. Keep all Company/profile, category, Customer Code, FK, identity, and hierarchy invariants.
3. Reconcile/remove the unused `person_in_charge_id` required metadata so it cannot later reintroduce a conflict. Do not change Portal Filament Landmass UI unless Human expands scope.
4. In a separately scoped value-integrity pass, add symmetric type/allowed-option validation for profile-specific optional controls and coordinate bounds/pair rules. This is not necessary to remove the current required-field conflict, but the current nested validation gaps should not be mistaken for verified server-side integrity.

No implementation was made in this audit. The reported `active.delivery_detail` rule is a confirmed policy conflict, and the same defect exists for conditional Warehouse Code. The implementation proposal remains pending Human review/authorization.

## Bounded optional-field validation implementation (2026-10-09)

**Work state: ACTIVE.** Human acceptance remains pending, including physical iPad form validation. This implementation satisfies the authorized local code scope; it does not claim production or shared-environment synchronization acceptance. No schema/migration, existing business-data mutation, commit, push, deployment, or PROD access occurred.

### Changes made

- **Tablet validation:** `CustomerCreatePage::saveCustomer()` now validates `active.warehouse_code` as nullable string up to 255 characters and `active.delivery_detail` as nullable `own_delivery|meh`. The `requiredIf` conditions were removed. Their controls, bindings, option lists, profile applicability, serialization, and local JSON persistence remain intact.
- **Company-switch validation state:** `updatedCompanyId()` now resets validation errors before the form transitions. A failing outlet Delivery Detail value no longer leaves a stale error when switching to Fleet; the profile change still clears outlet `trade`/`active` values, keeping them out of the Fleet record.
- **Portal sync API:** request validation now accepts omitted/null nested `profile_data.active.warehouse_code`, `delivery_type`, and `delivery_detail`; supplied values must satisfy string/max-255, `yes|no`, and `own_delivery|meh` respectively. The two post-validation 422 aborts that required Warehouse Code for MOTIV and Delivery Detail for Delivery Type `yes` were removed. Valid optional values remain stored in `customer_trade_profiles.profile_data`.
- **Metadata:** `config/customer_trade_form.php` now marks Person in Charge optional and represents Warehouse Code and Delivery Detail as optional outlet fields. This metadata has no runtime validator consumer; actual user relationships and authorization logic were not changed.
- **Company variants:** minimum Name + Company Tablet local creation and Portal API creation are covered for OMMC, Last Mile, Fleet, OE, and IB. CAR_CLUBS is also covered as the extra outlet mapping regression case. Company-specific forms and profile persistence were retained.

### Files changed by this implementation

- Tablet: `app/Filament/Pages/CustomerCreatePage.php`, `config/customer_trade_form.php`, `tests/Feature/CustomerCreatePageDiagnosticTest.php`.
- Portal: `app/Http/Controllers/Api/SyncController.php`, `tests/Feature/TabletCustomerCreationContractTest.php`.
- Both repositories: this mirrored Work note and `docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv`.

Existing unrelated Tablet changes (including expense attachments and earlier sync/location tests) and Portal reconciliation artifacts were preserved. Focused formatting was run only for the implementation PHP paths to avoid touching unrelated modified files.

### Validation evidence

- Tablet `php artisan test tests/Feature/CustomerCreatePageDiagnosticTest.php`: **14 passed, 163 assertions** after implementation. Coverage includes minimum Name + Company across six mapped company IDs; blank Warehouse Code with MOTIV enabled; blank Delivery Detail with Delivery Type `yes`; valid values persisted locally and serialized in Customer push; invalid supplied values rejected; Company switching clears outlet values and validation errors; existing offline persistence and failed-push behavior.
- Portal `php artisan test tests/Feature/TabletCustomerCreationContractTest.php`: **10 passed, 69 assertions**. Coverage includes minimum Name + Company across all five supported company profiles plus CAR_CLUBS; blank conditional values accepted while their controlling selections are enabled; both populated values persisted; invalid delivery type/detail and invalid/oversized Warehouse Code rejected before Customer insertion; existing identity, Category, geography, and pull tests remain passing.
- Disposable automated test databases were used through `RefreshDatabase`/SQLite. No integrated Tablet-to-running-Portal request was performed; Portal API persistence and Tablet push serialization were validated in their respective isolated feature tests. No physical iPad validation was available.

### Remaining integrity gaps and manual acceptance

- Warehouse Code has no authoritative option/reference table in this contract; supplied values are checked for string type and length only. Confirm the expected business format with the Human before adding stricter rules.
- Other optional profile JSON values retain the pre-existing API validation depth. This bounded change adds nested integrity checks only for Delivery Type, Delivery Detail, and Warehouse Code; the 101-row matrix remains the inventory of other known optional-value gaps.
- Confirm on a physical iPad that the conditional controls render as expected, blank values can be saved for MOTIV/Delivery Type selections, valid selected values persist, and switching all Company variants shows only the applicable fields.
- Verify on an approved isolated integrated environment that a real local Customer push containing these optional omissions/values persists on Portal and returns the expected pull representation. This was not claimed by the isolated tests.

The matrix's Warehouse Code and Delivery Detail rows now have `IMPLEMENTED_TESTED` status, describe the optional validation rules and test evidence, and record that physical/integrated acceptance remains pending. The Work remains ACTIVE until Human review and acceptance.


### Final git status snapshot

### `/Users/highlite/Workspace-Kaisa/PHP/ommc2027-tablet`

```text
 M app/Filament/Pages/CustomerCreatePage.php
 M app/Services/SyncService.php
 M config/customer_trade_form.php
 M resources/views/filament/pages/customer-create-page.blade.php
 M tests/Feature/CustomerCreatePageDiagnosticTest.php
 M tests/Feature/CustomerLocationSyncTest.php
 M tests/Feature/CustomerPagedPullTest.php
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? public/expense_attachments/
?? tests/Feature/CustomerCreatePortalE2ETest.php
```

### `/Users/highlite/Workspace-Kaisa/PHP/ommc2027`

```text
 M app/Http/Controllers/Api/SyncController.php
 M app/Services/TabletSyncPayload.php
?? docs/reconciliation/
?? docs/work/OMMC-20261007-customer-master-data-reconciliation-v2-ledger.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? scripts/reconciliation/customer-master-data-v2/README.md
?? scripts/reconciliation/customer-master-data-v2/candidates.jsonl
?? scripts/reconciliation/customer-master-data-v2/manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153526Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153602Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/municipality-corrections-review.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-audit-artifacts.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-category-history-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export-manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-after.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-before.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-drm-user-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-exceptions.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-full-data-audit-summary.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-municipality-corrections.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-province-exclusions.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-qa-comparison.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-trade-profile-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-identities.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-user-pivots.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-excluded-province.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-municipality-corrections.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-user-rsm.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-verification-manifest.json
?? scripts/reconciliation/customer-master-data-v2/run.php
?? tests/Feature/TabletCustomerCreationContractTest.php
```


## Sync acceptance investigation — reported FLEET Customer (2026-10-09)

**Work state: ACTIVE. Investigation only.** No application code, schema, or business data was changed. Read-only queries were limited to the current workspace Tablet database and the current non-PROD Portal runtime database. The integrated reproduction used fresh disposable SQLite databases and its temporary server, test file, and database artifacts were removed. No commit, push, deployment, or PROD access occurred.

### Target record and push evidence

The workspace Tablet database contains the Human-reported FLEET Customer (the name is omitted here):

- Tablet local primary key: `-4719491882048255748`; Company code: `FLEET`; `region_specific_id`: `53`.
- Current local state: `sync_status=failed`, `sync_attempts=3`, `synced_at=null`, `server_id=null`; the Customer Code reservation token is still present. The record’s last update was `2026-10-09 10:45:52` in the database’s local timestamp format.
- The stored last sync error is HTTP **422**, key `region_specific_id`, message “The selected region specific id is invalid.” Tablet logs contain three matching Customer-push failures, at `10:45:42`, `10:45:50`, and `10:45:52`; each records HTTP 422. No request body or credentials are copied into this note.
- The payload builder includes non-null `region_specific_id` from the local Customer. The observed 422 proves the push request reached authenticated API validation. It was not an HTTP 401/403/500 failure and was not a successful Customer push.
- A failed Customer with three attempts is outside `pendingCustomerPushQuery()` (`pending`, or `failed` with attempts `< 3`). It is no longer selected by the Push Customers bulk action. `hasPendingCustomerPushWork()` therefore does not consider this row pending/retryable.

Customer Code reservation and Customer push are separate operations. The local Customer holds a reservation token; the current Portal database has a matching unused FLEET reservation for that code, owned by Company code FLEET. A secret-free comparison confirmed the stored token matches that reservation. Reservation succeeded, but this only allocated the code. It did not create the Customer. The API rejected the request during validation before reservation consumption and before the Customer transaction; the reservation remains unused.

### Exact failure boundary and reference divergence

Tablet’s local `region_specifics` table contains ID `53`, associated with Region ID `7`, named **Region IV-A (Laguna, Batangas, Quezon)**. The configured current Portal runtime database has no `region_specifics.id=53` and no row with that same name. The push validator applies `exists:region_specifics,id`, so it rejected the value before `DB::transaction()`.

Portal’s full location payload reads `RegionSpecific::all()`. Tablet applies incoming `region_specifics` with an ID-preserving upsert. That pull path does not remove local rows absent from a later snapshot. A stale local row retained after a Portal reference was removed is therefore consistent with the evidence; an older/different reference snapshot is also possible. The available evidence does not establish when ID 53 entered the Tablet database or whether the physical iPad is attached to this exact workspace database.

**Root-cause classification:** `REFERENCE_DATA_DIVERGENCE` → `PORTAL_API_FOREIGN_KEY_VALIDATION_REJECTION` → `RETRY_EXHAUSTED`. FLEET-specific business validation is not the failing boundary: Company ID `4` resolves to code `FLEET` and profile `fleet`; the API rejects `region_specific_id` in the common request validator before reaching its company/profile handling.

### Tablet push selection, response, and status handling

- Add Customer writes the local aggregate with `sync_status=pending`, then calls `SyncService::pushCustomer()` immediately. The separate Push Customers button calls `pushPendingCustomers()`, which uses the same pending/retryable selection.
- `pushCustomerRecord()` serializes `local_uuid`, Company/profile, `unique_id`, reservation token, Customer fields, trade profile, profile JSON, categories, and non-null location IDs to `POST /api/sync/push/customer`.
- A non-401/non-conflict response that is not successful is recorded as `failed`; `sync_attempts` increments and the response status/body is retained in `sync_error` (trimmed). Here that is the recorded 422 above. Customer detail displays the sync error, so this failure was not silently discarded.
- The exact success condition in current Tablet code is `$response->successful()` (any 2xx), after which it marks the record `synced`, stores `server_id`, `updated_at`, response `unique_id`, and `synced_at`, and clears the reservation token. The actual record did **not** meet that condition. A latent invariant gap remains: Tablet does not require a non-null `server_id` in a 2xx response before marking synced. The current Portal handler returns the required metadata in its normal response, but a malformed/empty 2xx from another runtime could mark a row synced without a server ID.
- Retry messaging is also too broad at the attempt limit: a rejected push reports one retryable item even when the update raises attempts to three; the next bulk push excludes it. The initial local-save message is generic, while the Customer detail stores the validation error.

### Portal route, authentication, transaction, and FLEET behavior

`POST /api/sync/push/customer` is inside the `SyncTokenMiddleware` route group. That middleware resolves the bearer token to a Portal User and sets the authenticated actor. The reservation record confirms the current configured Tablet reservation call reached this Portal database; the subsequent 422 confirms the push passed authentication and reached validation.

Portal request validation requires valid references such as `region_specific_id` before company/profile handling. A validation exception returns 422 without entering the Customer transaction. On the success path, Company code maps `FLEET` to profile `fleet`; FLEET code allocation uses its configured prefix, and the controller performs Customer, user relationship, trade profile, category, and event writes in `DB::transaction()`. The 200 JSON response is constructed only after that transaction returns, and contains `server_id`, `local_uuid`, `unique_id`, and `updated_at`. No FLEET-specific push route or branch caused this 422.

### Portal database, Customer presence, and search

The current workspace Portal API and Filament Customer list use the same Laravel default database connection. The inspected current runtime is local/non-PROD; the Tablet workspace uses a separate local SQLite database. Sensitive host/database identifiers are omitted. The Tablet endpoint configuration is loopback HTTP and its hostname differs from the Portal app URL hostname, but the matching reservation token proves the reservation was created in the current Portal database. The separate database target used by the Human’s database client was not provided, so that external target cannot be independently matched.

Read-only queries against the configured current Portal database found no Customer by the reported code/name, no code substring match, and no non-deleted name match for the reported search phrase. The matching code reservation is unused and is not a Customer row. Portal’s Customer table defines both Customer Name and Customer Code (`unique_id`) as searchable columns; the active-status and landmass filters have no default restriction. The absent row therefore explains the current list result; search configuration is not the root cause. The Portal API’s pull reads from the same application database, so there is no Customer for Tablet to pull. Push rejection, Portal visibility, and Tablet pull are distinct boundaries here.

### Isolated minimum-field FLEET reproduction

A temporary integration test outside both repositories used a fresh disposable Portal SQLite database and Tablet `RefreshDatabase` SQLite. It exercised the normal Livewire Add Customer path with only Customer Name and Company entered, then real local HTTP calls to the disposable Portal server:

1. `POST /api/sync/reserve-customer-code` returned 200 and a FLEET-prefixed code.
2. Tablet persisted `pending` before the push; `POST /api/sync/push/customer` returned **200**. Tablet then stored `synced`, a Portal `server_id`, the returned code, and no reservation token.
3. A Portal pull read returned the persisted FLEET Customer/profile from the disposable database.
4. Tablet `pullCustomersStep()` completed successfully and retained the server ID and Access relationship.

The isolated test passed **28 assertions**. A separate read-only query of the disposable Portal database confirmed Customer and trade-profile rows with Company code FLEET, profile `fleet`, authenticated `created_by`, and a consumed reservation. All disposable files and the temporary server were removed. This proves the minimum FLEET path can succeed when the Portal references and Tablet references agree; it does not reproduce the stale ID 53 condition.

### Smallest evidence-backed correction proposal and remaining evidence

Do not map ID 53 by label or invent a replacement ID. First resolve the reference authority: determine whether the Region IV-A row should exist in the intended Portal reference database or whether the Tablet row is stale. Then refresh/retire the stale local option without deleting or rewriting existing Customer geography blindly, and have a Human select the canonical Portal ID if the correct mapping cannot be established from authoritative data. After that reference correction, the already-reserved code may be retried only through an authorized retry/recovery path because the row has exhausted the normal three-attempt query limit.

A subsequent implementation scope should also decide how complete reference snapshots retire absent Tablet options, make success require a valid Portal server ID, and align retry counts/messages with actual retry eligibility. These are proposals only; nothing was implemented in this investigation.

Remaining runtime evidence: confirm whether the physical iPad is attached to the inspected workspace Tablet instance; compare the Human’s Portal database target to the current runtime using a non-secret identifier; identify why its Tablet reference snapshot contains ID 53; and, after an authorized reference resolution, validate the physical iPad flow. This investigation did not access the physical iPad or any PROD system.

### Final git status snapshot

### `/Users/highlite/Workspace-Kaisa/PHP/ommc2027-tablet`

```text
 M app/Filament/Pages/CustomerCreatePage.php
 M app/Services/SyncService.php
 M config/customer_trade_form.php
 M resources/views/filament/pages/customer-create-page.blade.php
 M tests/Feature/CustomerCreatePageDiagnosticTest.php
 M tests/Feature/CustomerLocationSyncTest.php
 M tests/Feature/CustomerPagedPullTest.php
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? public/expense_attachments/
?? tests/Feature/CustomerCreatePortalE2ETest.php
```

### `/Users/highlite/Workspace-Kaisa/PHP/ommc2027`

```text
 M app/Http/Controllers/Api/SyncController.php
 M app/Services/TabletSyncPayload.php
?? docs/reconciliation/
?? docs/work/OMMC-20261007-customer-master-data-reconciliation-v2-ledger.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? scripts/reconciliation/customer-master-data-v2/README.md
?? scripts/reconciliation/customer-master-data-v2/candidates.jsonl
?? scripts/reconciliation/customer-master-data-v2/manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153526Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153602Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/municipality-corrections-review.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-audit-artifacts.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-category-history-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export-manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-after.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-before.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-drm-user-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-exceptions.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-full-data-audit-summary.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-municipality-corrections.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-province-exclusions.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-qa-comparison.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-trade-profile-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-identities.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-user-pivots.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-excluded-province.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-municipality-corrections.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-user-rsm.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-verification-manifest.json
?? scripts/reconciliation/customer-master-data-v2/run.php
?? tests/Feature/TabletCustomerCreationContractTest.php
```

## Reference consistency and bounded sync recovery investigation (2026-10-09)

**Work state: ACTIVE. Investigation/design only.** No application source, schema, reference rows, Customer rows, or reservation rows were changed in this continuation. No commit, push, deployment, or PROD access occurred. This section is mirrored in both repositories.

### Reference source-of-truth and snapshot behavior

Portal’s authenticated `GET /api/sync/pull/locations` returns one streamed JSON document from `TabletSyncPayload::streamLocations()`. It has no request filters or pagination. The response has no snapshot ID, schema/version marker, declared row counts, or completeness checksum. The source queries return:

- all Regions;
- all Specific Regions;
- enabled Area Clusters only;
- enabled Provinces only;
- all Municipalities;
- enabled Barangays only, emitted by ID-keyset chunks of 2,000 into the same response.

Thus it is a complete response of the endpoint’s selected rows if the stream completes, but it is not a complete dump of every reference database row: four tables are intentionally active/filtered subsets. “Current/selectable” must mean present in this API snapshot, not simply present in a Portal table. The API currently does not expose a last-updated/version value for these reference sets.

Primary keys are database auto-increment IDs and Tablet preserves them in upserts. The code establishes that IDs are treated as canonical across systems, but there is no inspected contract guaranteeing IDs are never reused or that rows are immutable. Region Specific has no enabled or soft-delete field in the inspected schema. Area Cluster, Province, and Barangay have enabled flags. The current absence of Specific Region 53 therefore does **not** prove it was deleted, retired, disabled, or even created in the same environment: no deletion/audit history or source-environment provenance was found. The prior query only established that it was in the inspected Tablet DB and absent from the inspected Portal DB at investigation time. It cannot be traced to a specific environment or lifecycle event from available evidence.

Tablet upserts incoming references by ID and intentionally does not delete absent rows, preserving FKs used by local Customers and offline work. Customer-pull runs refresh references before customer pages; a failed HTTP pull leaves the prior local set unchanged. However, response integrity is currently weak: a 2xx payload is decoded as JSON, and invalid JSON decodes to `[]`; the apply path treats absent sections as empty no-op writes, then reports success. The payload also has no declared counts to distinguish a fully received snapshot from a syntactically valid but incomplete document. There is no persisted per-reference “current in latest Portal snapshot” marker or last successful snapshot generation.

### Smallest safe reference design

Use an explicit, versioned snapshot contract rather than deleting local reference rows:

1. Portal adds snapshot metadata to the same response: protocol version, unique snapshot ID/time, and row counts for each of the six required reference sections. Counts describe the endpoint’s existing enabled/active filter semantics. Streaming remains a single response; no pagination or label-based reconciliation is introduced.
2. Tablet validates HTTP success, JSON object shape, all required sections as arrays, row shape/IDs/parent keys, and observed counts before applying anything. Decode errors, missing sections, truncation, and incomplete counts fail the refresh. Upserts plus validity-marker changes happen in one local DB transaction; a failed/incomplete refresh changes neither markers nor references.
3. Track snapshot presence separately from the canonical reference rows (preferred minimal schema: one Tablet-only reference-snapshot membership table keyed by snapshot generation + reference type + canonical ID, plus last-completed snapshot state). Only after complete validation does Tablet atomically make that generation current. Do not delete or rewrite reference rows or Customer FKs. An ID absent from the latest complete snapshot is marked unavailable for *new selections* while historical Customer relationships continue resolving through retained rows.
4. For a reference with no completed snapshot evidence yet, its status is unknown, not stale. Recommended fail-closed policy: before the first complete snapshot, do not offer unknown location references for new Customer selection; geography is optional under the Human contract, so an offline Customer can still be saved with those values blank. After a complete snapshot exists, a failed/incomplete refresh keeps the last completed generation authoritative and selectable; it must not turn all references stale. Explain when local references are not yet verified.
5. Dropdowns filter to enabled/current membership for new selections. Keep a currently stored historical FK renderable as a selected “no longer available for new Customers” value in view/edit contexts; do not erase it. A pending Customer with an unavailable FK remains intact and receives an actionable conflict/error; never substitute by label or clear the FK silently.
6. Preserve independent graphs: physical Region → Province → Municipality → Barangay and commercial Specific Region → Area Cluster. Snapshot checks validate each table’s own canonical ID and parent links and do not cross-map the graphs.

This needs a Tablet migration for snapshot-membership/completion metadata, but no Portal or Tablet business/reference-row rewrite and no Customer schema/FK change. If a later database engine or retention strategy makes snapshot generations too large to retain, cleanup can be separately designed after proving no in-flight pull depends on them; do not add speculative pruning to the first correction.

### Failed Customer recovery and Customer Code

The normal push selector admits `pending` rows and `failed` rows with `sync_attempts < 3`. A row at the limit is excluded from bulk push and from the Push Customers pending indicator. Current UI displays `sync_error` in Customer details, but has no exhausted-failure count/action or explicit per-Customer manual retry. A failed response increments attempts and stores status/body; the current message still says it “will retry later” even if that response consumes attempt three. This can imply retryability when the selector will no longer include the row.

Proposed bounded recovery: expose an explicit **Retry Customer** action on an exhausted failed Customer’s detail, showing the last error and a confirmation/status message that exactly one push attempt will be made. Require the operator to correct the optional field/reference through an authorized local correction path first; show the failed FK and current reference availability. Do not automatically modify any value. Claim the row atomically before network I/O (e.g. compare-and-set failed → in-flight using its current status/attempt count); a concurrent bulk/manual request then cannot send a duplicate. Send one normal authenticated push through the existing validation path. On failure, restore `failed`, increment the attempt history, and retain the remote error; do not auto-loop. On success, mark synced only after validating response identity/server ID. Keep the operation available as a deliberate retry even when historic attempts are ≥3; retain attempt count as evidence rather than resetting it. A small sync-state value/claim is preferable to a schema migration if existing status handling can represent it safely; otherwise stop and scope a narrow Tablet migration rather than racing two pushers.

Customer Code reservation behavior is favorable for this failed create: Portal consumes a reservation only inside the successful Customer transaction, after request validation. The confirmed 422 occurred before that transaction, leaving the reservation unused and its token on Tablet. Retry should send the same token and code, without reserving a new code. Portal’s allocator checks the token, company, and authenticated user; push consumption intentionally disables session binding. If a previous 2xx committed but the response was lost, Portal upserts by `local_uuid` and reuses the already-created Customer’s existing `unique_id`, avoiding another reservation/code. Preserve that idempotency. If the reservation has expired/been consumed inconsistently, fail with a clear recovery state; do not silently reserve another code or create a duplicate.

### Root cause, classification, and scope

For FLEET3737 the established chain remains: stale/unknown local reference row 53 was submitted → Portal’s common FK validator found no current Portal `region_specifics.id=53` → HTTP 422 before transaction and reservation consumption → local Customer retained as failed at three attempts and excluded from normal retries. Classification: `REFERENCE_SNAPSHOT_COMPLETENESS_GAP` + `REFERENCE_DATA_DIVERGENCE` → `PORTAL_API_FOREIGN_KEY_VALIDATION_REJECTION` → `RETRY_EXHAUSTED_WITHOUT_MANUAL_RECOVERY`. It is not evidence of a FLEET-specific branch defect, lost Portal transaction, or list-search issue.

Smallest coordinated correction is: (a) complete/validated Portal location snapshot metadata and Tablet non-destructive current-membership tracking; (b) exclude known-absent references from new selections while preserving all old local rows/Customer FKs and failed submissions; (c) a deliberate single-row retry path after explicit correction, with an atomic duplicate-send guard and same reservation token; (d) truthful retry messaging and a strict response identity check before marking synced. No label mapping, FK clearing, Portal validation bypass, automatic retry loop, reservation replacement, or existing Customer mutation is justified.

### Proposed tests

- Complete snapshot, including nonzero and valid empty sections, records one current generation and offers only API-eligible rows.
- HTTP/auth failure, malformed/truncated JSON, absent section, bad row shape/parent, or count mismatch leaves prior generation and all reference rows unchanged.
- A complete later snapshot marks absent ID unavailable without deleting it or altering historical Customer FKs; a failed/incomplete later snapshot does not stale any references.
- Unknown-before-first-snapshot behavior matches the chosen UX policy.
- Enabled filtering and retained disabled/historical selection are correct for Specific Region, Area Cluster, Province, Municipality, and Barangay; disabled source rows are not falsely presented as current where endpoint excludes them.
- Physical and commercial hierarchies remain independent; mismatched parent references fail closed.
- Pending Customer with stale reference remains stored, visibly actionable, and is never rewritten or pushed until user correction.
- Failed Customer at attempts 3+ is excluded from automatic/bulk sync, has a manual retry affordance, and one explicit retry causes at most one HTTP request.
- Concurrent manual and bulk attempts cannot both claim/send the same Customer.
- Failed retry increments/retains attempts and error; 422 does not alter reservation or mark synced; successful retry stores response server ID and synced state.
- Existing unused reservation token/code is reused; no new reservation or duplicate Customer. A committed response-loss retry is idempotent by local UUID.
- Test pull after success maps the same Customer, without duplicate creation.

### Physical iPad verification after an authorized implementation

1. On a QA iPad/build, record app version and non-secret Portal environment identity; trigger a complete reference refresh and verify displayed snapshot completion time/ID.
2. Confirm ID 53 is shown as unavailable for new selection if absent from the latest complete snapshot; confirm existing local Customer/history that refers to it remains visible and unchanged.
3. After reference owner confirms the correct canonical Specific Region ID (no name-only guess), correct the failed Customer through the approved UI and inspect the outbound reference ID in a safe test environment.
4. Tap explicit Retry once. Verify the same reserved FLEET code is used, exactly one push occurs, Portal API returns a server ID only after persistence, and Tablet becomes synced only on that response.
5. Search Portal by Customer Code, then pull on Tablet and confirm one Customer record, same server ID/code, unchanged physical geography, commercial parent hierarchy, and no duplicate reservation/Customer.

This turn did not access or operate the physical iPad. No isolated reproduction was appropriate for this design-only investigation.

### Full Git status at investigation completion

Tablet `/Users/highlite/Workspace-Kaisa/PHP/ommc2027-tablet`:
```
 M app/Filament/Pages/CustomerCreatePage.php
 M app/Services/SyncService.php
 M config/customer_trade_form.php
 M resources/views/filament/pages/customer-create-page.blade.php
 M tests/Feature/CustomerCreatePageDiagnosticTest.php
 M tests/Feature/CustomerLocationSyncTest.php
 M tests/Feature/CustomerPagedPullTest.php
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? public/expense_attachments/
?? tests/Feature/CustomerCreatePortalE2ETest.php
```

Portal `/Users/highlite/Workspace-Kaisa/PHP/ommc2027`:
```
 M app/Http/Controllers/Api/SyncController.php
 M app/Services/TabletSyncPayload.php
?? docs/reconciliation/
?? docs/work/OMMC-20261007-customer-master-data-reconciliation-v2-ledger.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? scripts/reconciliation/customer-master-data-v2/README.md
?? scripts/reconciliation/customer-master-data-v2/candidates.jsonl
?? scripts/reconciliation/customer-master-data-v2/manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153526Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153602Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/municipality-corrections-review.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-audit-artifacts.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-category-history-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export-manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-after.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-before.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-drm-user-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-exceptions.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-full-data-audit-summary.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-municipality-corrections.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-province-exclusions.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-qa-comparison.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-trade-profile-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-identities.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-user-pivots.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-excluded-province.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-municipality-corrections.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-user-rsm.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-verification-manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/run.php
?? tests/Feature/TabletCustomerCreationContractTest.php
```

The Work remains **ACTIVE** and awaits Human review/implementation authorization.


## Bounded reference consistency and exhausted retry implementation (2026-10-09)

**Work state: ACTIVE — awaiting Human functional acceptance.** This implementation is local and bounded to reference response validation/membership and explicit recovery of a failed Customer push. No Portal schema or Customer/reference foreign keys changed. No reconciliation database, PROD, commit, push, or deployment was used.

### Architecture and behavior

- Portal `/api/sync/pull/locations` now emits `reference_contract_version: 1`, all six existing location sections, and `reference_counts` computed from the actual emitted arrays/streamed barangays. Existing Portal filters remain unchanged; there is no snapshot ID or new endpoint.
- Tablet validates the entire response before writes: supported version, exact required sections, exact count keys and integer counts, list/row shape, positive unique canonical IDs, required names/codes, enabled value types where emitted, and parent IDs/relationships. Malformed JSON decodes to an invalid empty payload and fails. Apply/upserts and membership replacement run in one Tablet transaction; failed/incomplete refresh leaves the last complete membership and all canonical reference rows unchanged.
- Tablet-only migration `2026_10_09_000001_create_location_reference_memberships_table` adds a composite-key table `(reference_type, reference_id)` plus index. The existing `sync_states` table records whether a complete snapshot has ever succeeded. Membership does not delete or rewrite reference rows or Customer foreign keys. Before first success, new selections fail closed while blank optional geography remains valid. New Customer controls use snapshot membership and their established enabled/parent filters; historical rows remain available for context/editing but cannot be resubmitted unless current and structurally valid. Physical Region→Province→Municipality→Barangay and Specific Region→Area Cluster remain independent.
- An exhausted failed Customer can be corrected in the existing Customer edit page and retried by an explicit single-Customer action. Retry requires a changed persisted push payload, validates any location IDs against the current snapshot, claims the row with a compare-and-set `syncing` transition, and makes one normal Portal API request. It retains the local ID, `local_uuid`, reserved code/token, attempt count and prior error history; it does not bypass Portal validation or auto-loop. A stale interrupted claim is surfaced as failed for review. The Portal's existing local-UUID upsert and reservation-token flow protect against duplicate Customer creation and unnecessary code replacement.

### Changed files

Portal: `app/Services/TabletSyncPayload.php`, `tests/Feature/TabletSplitPullTest.php`.

Tablet: `app/Services/SyncService.php`, `app/Filament/Pages/CustomerCreatePage.php`, `app/Filament/Pages/CustomerEditPage.php`, `app/Filament/Pages/CustomerPage.php`, `resources/views/filament/pages/customer-create-page.blade.php`, `resources/views/filament/pages/customer-page.blade.php`, `database/migrations/2026_10_09_000001_create_location_reference_memberships_table.php`, and the named feature-test files in the repository status. The existing 101-row matrix is updated for reference membership and honest E2E status. Existing untracked reconciliation outputs/scripts and `public/expense_attachments/` were preserved.

### Validation and remaining limits

- Portal full feature suite: **160 passed, 5 deprecated** (SQLite/RefreshDatabase); `git diff --check` clean at the last check.
- Tablet focused reference/retry/form/pull tests: **47 passed, 386 assertions**. Focused coverage includes complete/malformed/missing/count-mismatched/invalid-parent responses, preservation after refresh failure, stale membership exclusion, historical Customer FK preservation, blank optional geography, claim concurrency, correction gate, exactly-one request, and reservation reuse.
- Tablet full suite: **216 passed, 3 skipped, 7 failed** after updating legacy snapshot fixtures. Remaining failures are two `CustomerLocationMapTest` cases whose reverse-geocoding fixture no longer resolves a municipality, one `CustomerCodePageTest` assertion where the failed automatic push remains `syncing` instead of `failed`, one root `ExampleTest` response expectation, and three `FirstLoginPersistenceTest` calls to missing `SyncService::lastError()`. The latter four appear unrelated to this change but were not baseline-tested in this run; the automatic-push state regression is within the touched sync path and remains unresolved. Do not treat Tablet validation as complete. No integrated disposable Portal DB E2E was available; the existing gated E2E test was skipped. No claim is made that Portal persistence/pull was proven end to end by Tablet HTTP fakes.
- Remaining acceptance: resolve or explicitly disposition the Tablet suite failures; run full normal Add→local DB→push→Portal DB→pull against isolated disposable databases; exercise OMMC, Last Mile, Fleet, OE, IB and CAR_CLUBS where behavior differs; verify one exhausted retry and its concurrency behavior; test physical iPad refresh, stale option presentation, correction, retry, and visible result. Work remains ACTIVE until Human acceptance.

### Git/worktree delivery boundary

Complete current `git status --short` was captured at delivery. This Work adds only its authorized implementation/tests/migration and mirrored note/matrix; unrelated pre-existing untracked reconciliation artifacts and Tablet expense attachments remain untouched. No commit, push, deploy, PROD access, or reconciliation database write occurred.


## Regression stabilization (2026-10-09)

**Work state: ACTIVE.** This pass investigated the seven prior failures without resetting the active repositories. A baseline source archive from HEAD `0cec51af7d4cfbbb69cca1a504e1879d942dfd0c` was created under `/private/tmp`, tested with an explicit non-production fake URL and test key, then removed. The committed baseline files and the active worktree were not reset or overwritten.

### Seven-failure classification

| Failing case | Classification | Evidence and disposition |
|---|---|---|
| `CustomerCodePageTest` automatic push leaves `syncing` | `TEST_ENVIRONMENT_OR_FIXTURE_ISSUE` | The test stubbed `portal.test` but did not configure `sync.server_url`; local `.env` config pointed to `127.0.0.1`. On the reproducer that did not match its fake, no controlled 503 response was established, so it observed the in-flight claim. The same test passes against the committed baseline and active implementation when `sync.server_url` is explicitly set to `http://portal.test`. Fixture now pins that fake host. Under the controlled 503 response, terminal state is `failed`, attempt count is one, and the reserved token is retained. No product sync code change was needed. |
| `CustomerLocationMapTest` reverse lookup with provided coordinates | `PRE_EXISTING_BASELINE_FAILURE` | Both failing assertions reproduce on the committed baseline. The returned municipality match is null against the test’s synthetic reference rows; this failure is in the unchanged address-resolver path. Reference membership and snapshot code is not called by the resolver. No test expectation or production mapping was weakened. |
| `CustomerLocationMapTest` reverse lookup with direct coordinates | `PRE_EXISTING_BASELINE_FAILURE` | Same committed-baseline failure and same unchanged resolver boundary as the prior case. |
| `ExampleTest` root route expects HTTP 200 | `PRE_EXISTING_BASELINE_FAILURE` | Reproduces at baseline and active HEAD: guest `/` redirects with HTTP 302. This is the existing route/auth behavior, unrelated to Customer sync. |
| `FirstLoginPersistenceTest` expects `lastError()` after HTTP 500 | `TEST_EXPECTATION_OUTDATED` | Reproduces at baseline and active HEAD: `SyncService::lastError()` does not exist. Repository search found these test calls, but no established application caller/contract. No unrelated compatibility method was added. |
| `FirstLoginPersistenceTest` expects `lastError()` after connection error | `TEST_EXPECTATION_OUTDATED` | Same baseline absence of the method; the connection check itself returns false. |
| `FirstLoginPersistenceTest` expects `lastError()` for blank URL | `TEST_EXPECTATION_OUTDATED` | Same baseline absence of the method; no URL detail accessor exists in the committed contract. |

### Push state investigation and correction

The compare-and-set claim is `pending`/eligible `failed` → `syncing`; only the claimant builds/sends the request. The normal response path returns a valid 2xx with a positive Portal server ID to `synced`, HTTP 401 to the original pending/retryable state, conflict response to `conflict`, and other HTTP responses or caught exceptions to `failed` with incremented attempt/error evidence. Exhausted retry remains excluded from automatic selection; explicit retry requires a changed saved payload and issues one normally validated request. A duplicate concurrent claimant sends no second request. Interrupted claims are lazily recovered to `failed` when a later push entry point runs, with an operator-review error.

The observed `syncing` row came from an unmatched HTTP fake caused by the test's missing server URL setup, not from a completed controlled failed response. The narrow correction is only in `tests/Feature/CustomerCodePageTest.php`: set `sync.server_url` to the fake host. It now proves a controlled 503 ends in `failed`. No retry architecture change was needed. The prior unqualified test invocation used a loopback URL with a non-matching fake; it may have attempted a localhost request. The request's acceptance by any local listener cannot be determined from the test result. The destination was `127.0.0.1`, not PROD, and subsequent tests force the fake host. No PROD endpoint was targeted.

### Validation after stabilization

- Previously failing CustomerCode case, Customer push/retry tests, reference snapshot tests, form tests and location/pull tests: **54 passed, 425 assertions** with `SYNC_SERVER_URL=http://portal.test`.
- Relevant Portal contract tests (`TabletCustomerCreationContractTest`, `TabletSplitPullTest`): **18 passed, 132 assertions**.
- Full Tablet suite, with `SYNC_SERVER_URL=http://portal.test`: **217 passed, 3 skipped, 6 failed**. The six remaining cases are the two baseline map failures, baseline root 302 expectation, and three baseline missing-`lastError()` expectations listed above.
- Reference tests still cover malformed/missing/count-mismatched snapshots, failed-refresh preservation, canonical-row/Customer-FK retention and selection filtering. Retry tests cover exhausted correction gating, one request, claim concurrency, same identity/code/token, and successful response state. Integrated disposable Portal DB E2E remains unavailable; physical iPad acceptance remains pending.
- Both repositories pass `git diff --check`. No Portal or Tablet production source changed in this stabilization pass; the sole stabilization change is the CustomerCode test fixture URL.

### Current full Git status

Tablet (`/Users/highlite/Workspace-Kaisa/PHP/ommc2027-tablet`):
```text
 M app/Filament/Pages/CustomerCreatePage.php
 M app/Filament/Pages/CustomerEditPage.php
 M app/Filament/Pages/CustomerPage.php
 M app/Services/SyncService.php
 M config/customer_trade_form.php
 M resources/views/filament/pages/customer-create-page.blade.php
 M resources/views/filament/pages/customer-page.blade.php
 M tests/Feature/CustomerCodePageTest.php
 M tests/Feature/CustomerCreatePageDiagnosticTest.php
 M tests/Feature/CustomerLocationMapTest.php
 M tests/Feature/CustomerLocationSaveTest.php
 M tests/Feature/CustomerLocationSyncTest.php
 M tests/Feature/CustomerOnlyPushTest.php
 M tests/Feature/CustomerOperationalSyncTest.php
 M tests/Feature/CustomerPagedPullTest.php
 M tests/Feature/RegionTypeImageRequiredTest.php
 M tests/Feature/SalescallStatusesMigrationTest.php
 M tests/Feature/SyncPullReferenceBulkUpsertTest.php
 M tests/Feature/SyncPullResponseSinkTest.php
 M tests/Feature/SyncServicePullFreshInstallTest.php
 M tests/Feature/TabletBaseLocationProfileTest.php
 M tests/Feature/TabletBaseLocationSyncTest.php
 M tests/Pest.php
?? database/migrations/2026_10_09_000001_create_location_reference_memberships_table.php
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? public/expense_attachments/
?? tests/Feature/CustomerCreatePortalE2ETest.php
?? tests/Feature/LocationReferenceSnapshotTest.php
```

Portal (`/Users/highlite/Workspace-Kaisa/PHP/ommc2027`):
```text
 M app/Http/Controllers/Api/SyncController.php
 M app/Services/TabletSyncPayload.php
 M tests/Feature/TabletSplitPullTest.php
?? docs/reconciliation/
?? docs/work/OMMC-20261007-customer-master-data-reconciliation-v2-ledger.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract-matrix.tsv
?? docs/work/OMMC-20261008-tablet-add-customer-form-contract.md
?? scripts/reconciliation/customer-master-data-v2/README.md
?? scripts/reconciliation/customer-master-data-v2/candidates.jsonl
?? scripts/reconciliation/customer-master-data-v2/manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/after-state-verification.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153526Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/apply-audit-20261007T153602Z.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/municipality-corrections-review.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-audit-artifacts.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-category-history-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export-manifest.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-customer-export.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-after.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-db-table-fingerprint-before.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-drm-user-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-exceptions.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-full-data-audit-summary.json
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-municipality-corrections.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-province-exclusions.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-qa-comparison.csv
?? scripts/reconciliation/customer-master-data-v2/reports/post-apply-trade-profile-audit.csv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-identities.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-customer-user-pivots.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-excluded-province.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-before-state.jsonl
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-dry-run-report.json
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-reviewed-municipality-corrections.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-user-rsm.tsv
?? scripts/reconciliation/customer-master-data-v2/reports/preapply-verification-manifest.json
?? scripts/reconciliation/customer-master-data-v2/run.php
?? tests/Feature/TabletCustomerCreationContractTest.php
```

No reconciliation artifacts or Tablet expense attachments were modified or removed. No commit, push, deployment, migration against a retained database, or PROD access occurred. Work remains ACTIVE for Human review.

## Add Customer page HTTP 500 investigation (2026-10-09)

**Classification: `MIGRATION_NOT_APPLIED`. Investigation only; no correction or migration was applied.** The available local `laravel.log` records `SQLSTATE[HY000]`, SQLite `no such table: location_reference_memberships`, at 2026-10-09 11:43:00. The query originates at `app/Filament/Pages/CustomerCreatePage.php:124` while rendering the Region options. The database exception is wrapped as a view-render exception. The page builds this membership subquery even when snapshot completion is false; SQL's `1 = 0` clause does not avoid resolving a missing table.

Read-only `php artisan migrate:status` against the configured local Tablet SQLite database reported `2026_10_09_000001_create_location_reference_memberships_table` as **Pending**. The inspected migration only creates the membership table and its key/index; it does not rewrite Customer or canonical reference rows. The log and database are local Tablet development state. The Human's exact iPad request timestamp/build was not provided, so the local log establishes the same failure mechanism but cannot be uniquely correlated to a particular iPad request.

An isolated `RefreshDatabase` reproduction with the membership table dropped failed during Add Customer render with `ViewException` whose previous exception is `QueryException` for the missing table. The temporary test file was removed. The normal isolated Add Customer/reference suite passed (21 tests, 203 assertions), covering fresh test schema, blank geography before the first snapshot, failed refresh, and retention of historical canonical rows/Customer foreign keys. No business data was changed. The runtime migration gate is implemented in `StartupMigrationService` and invoked by the iOS runtime startup source; whether the Human's installed build ran that gate is unverified.

**Smallest proposed correction / operational check:** ensure the installed Tablet runtime contains this migration and successfully completes `app:startup-migrate` before opening Add Customer; verify its startup migration artifact reports the migration applied. For a development web runtime that does not run the native startup gate, run the normal migration only after explicitly confirming the target is the intended local Tablet database. This investigation did not run it. No Portal change is indicated. Regressions to retain: fresh-schema Add Customer render, pre-snapshot optional blank geography, table-missing diagnosis, and historical Customer/reference preservation after migration. Physical iPad build/version and startup artifact remain needed to correlate and close the issue.

## Location dropdown UX restoration (2026-10-09)

**Work state: ACTIVE.** In the shared Add/Edit Customer Blade view, all six selectors now render the clean canonical names with no “historical; unavailable” suffix. The technical first-refresh notice is replaced with a short message only when no complete local reference dataset exists: “Location choices cannot be loaded right now. You can leave these optional fields blank.” The inline stale-reference error is likewise user-facing: “This location is no longer available. Choose another location or clear this field.” Edit Customer inherits the same view, so existing selected canonical rows remain visible by ID.

Membership filtering, snapshot completion checks, local membership data, stale-reference validation, and Portal validation are retained. Thus confirmed local options remain available offline after a successful pull; a later failed refresh leaves membership intact. Unconfirmed/stale references remain excluded from new dropdown options and are rejected if posted manually. The physical and commercial dependent dropdowns are unchanged and independent.

The page now also fails closed if the membership table is absent: it renders empty location options and the same concise optional-fields message, and treats posted references as unconfirmed. This prevents the previously observed Add Customer render 500 without making any location selectable or substituting for the required migration and a complete reference pull. The migration remains pending in the inspected local database; it was not run.

Validation: isolated Tablet tests for reference snapshots, Customer Add/Edit form, location save/persistence, Customer Code and push: **49 passed, 343 assertions**. Coverage includes all six clean option names, known-stale exclusion/rejection, first installation with blank optional geography, missing membership table safely rendering and saving without geography, successful pull followed by failed refresh while confirmed options remain displayed, existing Edit Customer reference display, independent hierarchies, dependency resets, save, and push behavior. Pint passed and `git diff --check` passed. No live iPad was available; Human acceptance still needs to verify the six selectors, saved historical values, offline use after Pull, blank geography, and resulting push on the target iPad/build.

Task-owned changes in this continuation: Tablet `app/Filament/Pages/CustomerCreatePage.php`, `resources/views/filament/pages/customer-create-page.blade.php`, `tests/Feature/LocationReferenceSnapshotTest.php`, and `tests/Feature/CustomerCreatePageDiagnosticTest.php`; this section is mirrored in the Portal Work note. All other current Tablet/Portal worktree changes, including reconciliation artifacts and Tablet expense attachments, were preserved. No PROD, reconciliation database, commit, push, or deployment was used. Work remains ACTIVE for Human review.


## Restore original location loading (2026-10-09)

**Work state: ACTIVE.** Human direction supersedes the prior location-membership UI gate. Add and Edit now populate location selectors from the canonical local reference tables without requiring snapshot completion or membership rows. Region, Specific Region, Area Cluster, Province, City/Municipality, and Barangay use clean names; physical and commercial parent-child relationships remain separate. Existing selected rows are retained for display. The technical location-loading warning has been removed; geography remains optional when local references are unavailable.

Snapshot response validation, transactional membership updates, non-destructive canonical row retention, and Portal-side reference validation remain in place. Membership still records snapshot inclusion but no longer blocks local option loading, form save, or explicit retry. This preserves offline selection from local data while Portal rejects stale IDs. A stale-reference rejection is surfaced as a readable Portal HTTP error, leaves the same Customer failed and retryable, preserves its local UUID and reserved Customer Code/token, and requires a saved correction before a single explicit retry. No local reference ID is automatically replaced.

A retry transition defect was also fixed: after the database compare-and-set claim, Eloquent now synchronizes its original `sync_status` value to `syncing`. Without that, Eloquent could treat a later `failed` value as unchanged against its stale original and leave a completed HTTP 422 attempt stranded in `syncing`. The claim and concurrent duplicate-push guard remain unchanged.

**Validation:** Pint passed. Tablet focused location, Add/Edit, persistence, Customer Code, Customer push/retry, and gated E2E tests: **50 passed, 387 assertions, 1 skipped**. The skip is integrated Portal DB E2E because no isolated Portal test URL was configured. Relevant Portal creation/pull contract tests: **18 passed, 132 assertions**. Tests cover six selectors from local rows without membership metadata, dependent selection, existing selected values, blank geography, HTTP 422 stale reference handling, same identity/code/reservation reuse, one corrected explicit retry, and no duplicate Customer. Physical iPad and integrated DB acceptance remain pending; no end-to-end success is claimed for this pass.

Changed for this correction: Tablet `app/Filament/Pages/CustomerCreatePage.php`, `app/Filament/Pages/CustomerEditPage.php`, `app/Services/SyncService.php`, `resources/views/filament/pages/customer-create-page.blade.php`, `tests/Feature/LocationReferenceSnapshotTest.php`, `tests/Feature/CustomerOnlyPushTest.php`, and `tests/Feature/CustomerCreatePortalE2ETest.php`. No Portal application file changed. The mirrored matrix was updated to record local-table option loading and Portal-authoritative stale-reference rejection. Existing Portal reconciliation artifacts and Tablet expense attachments were preserved. No migration, PROD access, reconciliation write, commit, push, or deployment occurred. Stop for Human review; do not terminalize the Work.


## Terminal review — human acceptance and blocker (2026-10-09)

**Work state: ACTIVE; terminal delivery paused for Human review.** The Human reports successful end-to-end validation using the IB Company and accepts the current Work. This is recorded as work-level manual acceptance only; it does not establish per-field population evidence beyond what the run exercised. OMMC, Last Mile, Fleet, OE, and CAR_CLUBS remain pending Human functional validation.

Current automated validation: Tablet focused location/Add/Edit/persistence/push/retry suite **50 passed, 387 assertions, 1 skipped** (isolated Portal DB E2E endpoint unavailable); Portal Customer creation/pull contract suite **18 passed, 132 assertions**. The last full Tablet run was **217 passed, 3 skipped, 6 failed**; all six were reproduced against the recorded baseline: two CustomerLocationMap fixtures, ExampleTest's 200-vs-302 expectation, and three FirstLoginPersistence expectations for nonexistent `SyncService::lastError()`. These are recorded as baseline/outdated-test failures, not as manual acceptance.

**Pre-terminal architecture finding:** `location_reference_memberships` is created by the Tablet migration and rewritten transactionally during a complete location pull, but production code no longer reads the table after local option loading and explicit retry were changed to use local canonical rows and Portal validation. Snapshot completion metadata still gates the initial reference pull; full-response parsing/validation remains active. Thus the approved membership table has no current production consumer and is residual schema/write infrastructure from the superseded UI gate. The Human's earlier approval explicitly retained membership tracking, while this terminal review requires that obsolete infrastructure be absent. Its deployment state on the accepted iPad is unknown; the configured local database previously reported this migration pending. Removing the migration file alone would not safely remove the table from any device where it ran, and decommissioning could require a separate drop migration. This is a material unresolved schema lifecycle decision.

Per the terminal instruction, no files have been staged and no commit or push has been made. Do not terminalize until Human review decides whether to retain this table as an intentional future/audit contract or authorize a deployment-aware decommission, including whether the migration has run on the accepted iPad. Branches remain `main`, tracking their existing `origin/main`; before this review both were equal to upstream. No PROD, database write, migration, or deployment occurred. Unrelated Portal reconciliation artifacts and Tablet expense attachments remain untouched.

## Membership lifecycle resolution (2026-10-09)

**Work state: ACTIVE.** The Human resolved the architecture question: retire unused membership behavior, preserve the original local-reference dropdown contract, retain the historical Tablet migration, and do not delete existing device data or add a drop migration.

The full source scan found no active production reader of `location_reference_memberships`. Before this correction, `SyncService::pullLocations()` was the only production writer: after validating the complete Portal response it deleted and repopulated the table in the same transaction. Add/Edit Customer option queries read the local Region, Specific Region, Area Cluster, Province, Municipality, and Barangay tables directly. No Customer/reference foreign key, startup migration gate, or other schema migration depends on membership row contents; the existing migration remains available to old/new installs as historical schema compatibility. The migration is intentionally retained. Removing the Pull writes leaves any rows already on devices untouched and also allows Pull when the migration has not run or the table is empty.

The bounded Tablet correction removes only the membership delete/insert work. Complete location response structure, row, count, duplicate-ID, and parent hierarchy validation remains before the canonical upsert. Canonical reference rows continue to be upserted transactionally with completion metadata; invalid responses still fail without partial reference updates. Portal-side validation, explicit failed-Customer retry, and Customer Code reservation reuse are unchanged.

Human functional acceptance is recorded as: **IB PASS; CAR_CLUBS PASS; OMMC PASS; Last Mile PASS; OE PASS; Fleet TESTED, SYNC NOT ACCEPTED.** Fleet sync remains outstanding and is not represented as accepted. This acceptance update is repeated for every applicable row in the mirrored 101-row matrix.

The previously recorded membership schema lifecycle blocker is resolved by this Human decision. No migration was added, removed, or run; no membership or Customer/reference rows were deleted or rewritten. Work remains ACTIVE pending validation and Human review; no commit, push, or deployment is authorized by this continuation.

**Validation for this correction:** `LocationReferenceSnapshotTest` passed **9 tests / 83 assertions**, including retained preexisting membership rows, an empty membership table, successful Pull with the membership table absent, canonical-reference upsert, historical Customer FK retention, and rejection of malformed/incomplete Pull responses. The focused Tablet location/Pull/push/retry group passed **60 tests / 516 assertions** with **2 known baseline failures** in `CustomerLocationMapTest` (reverse-geocoding fixture did not resolve Municipality; these same failures are recorded by the earlier full-suite baseline). Relevant Portal Customer creation and split-Pull contracts passed **18 tests / 132 assertions**. Pint passed; `git diff --check` passed in both repositories. No isolated/live Portal DB or physical iPad was used in this correction. Customer Code reservation reuse and explicit retry remain covered by the focused group; no Portal application source was changed.

**Files changed by this lifecycle correction:** Tablet `app/Services/SyncService.php`; Tablet tests `tests/Feature/LocationReferenceSnapshotTest.php`, `CustomerCreatePageDiagnosticTest.php`, `CustomerLocationMapTest.php`, and `CustomerLocationSaveTest.php`; mirrored Work notes and 101-row matrices in Tablet and Portal. No migration file changed. Existing unrelated reconciliation artifacts and Tablet expense attachments remain untouched. The six Company acceptance statuses in the matrix reflect the latest Human clarification; Fleet sync remains outstanding. Work remains ACTIVE.

## Controlled terminal delivery (2026-10-09)

**Work state: TERMINAL_DELIVERED.** The Human authorized delivery after resolving the membership lifecycle decision. Portal changes were integrated in a clean temporary worktree based on `origin/main` at `51c48021b4f59e7be5087a14d4851ee96e48fbc0`; Tablet changes were integrated in a clean temporary worktree based on `origin/main` at `0cec51af7d4cfbbb69cca1a504e1879d942dfd0c`. The original dirty worktrees and unrelated artifacts were preserved.

Final Human acceptance is recorded exactly: **IB PASS; CAR_CLUBS PASS; OMMC PASS; Last Mile PASS; OE PASS; Fleet TESTED, SYNC NOT ACCEPTED.** Fleet is not represented as Human E2E accepted. Automated Portal Customer creation/split-Pull validation passed **18 tests / 132 assertions**. Tablet focused validation passed **80 tests / 612 assertions**, with **1 integrated Portal DB E2E skip** and **2 known baseline `CustomerLocationMapTest` failures** due to reverse-geocoding fixture resolution; the same failures were recorded before this delivery. The last recorded full Tablet suite was **217 passed, 3 skipped, 6 baseline failures**. Portal Pint passed. Tablet Pint passed on the changed PHP files except existing formatting findings in `CustomerPage.php`, retained to avoid broad unrelated reformatting; PHP syntax and `git diff --check` passed for the delivery diff. No new regression was identified. The isolated cross-repository E2E was unavailable in this final run.

The delivered Tablet changes include Add/Edit validation and persistence, Customer payload/pull mapping, reference response validation, membership-write retirement while retaining the migration, and explicit failed-Customer correction/retry with reservation reuse. Portal changes include Customer sync identity/reference validation, Customer/profile/history persistence and response/pull contract. The location Pull contract is validated; no remaining code-level location Pull defect was identified. Physical/runtime integration outside the reported Human acceptance remains unverified. Reconciliation datasets, scripts, reports, auth-isolation duplicate copies, Tablet expense attachments, and unrelated Customer/base-location files were excluded.

No database writes, PROD access, deployment, force push, or destructive cleanup occurred. Delivery commits are scoped separately to this Work in each repository; commit hashes are reported outside this note to avoid self-reference.

### Final clean-target validation addendum (2026-10-09)

This addendum supersedes any earlier terminal-review validation counts above. The integrated Tablet test selection passed **76 tests / 600 assertions**, with **1 skipped isolated Portal E2E** and **2 known baseline failures** in `CustomerLocationMapTest` (the reverse-geocoding fixture did not resolve Municipality). Those same two failures are documented in the pre-delivery full-suite baseline; no new regression was identified. The focused group includes Add form and profile validation, Customer Code reservation/reuse, local save, push/retry state, all six local-reference selectors, pull validation/atomicity, retained historical Customer references, and both physical/commercial hierarchy dependencies. Portal customer contract plus split-Pull tests passed **18 tests / 132 assertions**.

Pint passed for the final Portal Work PHP paths and Tablet Work PHP paths after formatting the two modified Tablet files. PHP syntax checks and `git diff --check` passed. Integrated Portal database E2E remains skipped because no isolated Portal endpoint is configured in this terminal run; no database writes were performed. The original worktrees remain preserved. Human acceptance remains: IB PASS; CAR_CLUBS PASS; OMMC PASS; Last Mile PASS; OE PASS; Fleet TESTED, SYNC NOT ACCEPTED.

The final source scan confirms there are no production references to `location_reference_memberships`: only the historical Tablet migration and its compatibility tests reference it. Tablet location Pull performs no membership reads or writes. The migration file is retained unchanged, canonical reference rows and existing Customer foreign keys are not rewritten, all six selectors read the local reference tables, and complete Portal response validation remains. No code-level location Pull defect remains identified; Fleet sync remains an outstanding Human acceptance issue.
