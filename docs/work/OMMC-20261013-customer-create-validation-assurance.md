# OMMC V2 — Customer Create Validation Assurance

**Work ID:** `OMMC-20261013-customer-create-required-fields-validation`
**State:** `READY_FOR_HUMAN_REVIEW`
**Decision:** **BLOCKED — INSUFFICIENT TEST EVIDENCE**

## Scope and safety

This bounded correction closes the confirmed Portal reference failure; it also preserves the MCB baseline and Tablet snapshot fixture evidence from the preceding closure. Tests use disposable `RefreshDatabase` databases only. No retained database operation/migration, Customer Pull/Push, production access, staging, commit, push, or deployment occurred. The six Company codes and approved mapping remain unchanged.

## Previously established coverage (preserved)

| Measure | Portal | Tablet | Result |
|---|---:|---:|---|
| Required field omissions at actual create boundary | 295/295 | 283/283 | PASS |
| Invalid supplied dropdown values | 180/180 | 180/180 | PASS |
| Company codes exercised | 6/6 | 6/6 | PASS |

The focused sweeps check field-level errors and no Customer insertion after invalid submissions. Those suites were not rerun during this evidence closure.

## MCB baseline closure

The authoritative configuration maps `OMMC`, `LAST_MILE`, and `CAR_CLUBS` to the `outlet` profile. The Outlet profile contract uses `entry_detail = MCB`, requires all nine annual MCB categories for years 2018–2026, and validates each category against `customer_trade_form.profile_category_options.outlet.mcb`. The AB stream is inapplicable for the MCB selection. Outlet shared/conditional fields and Owner Profile are populated in each baseline.

- Portal form validation: **1 test, 18 assertions passed**, covering all three Outlet Company codes. `getState()` succeeded, MCB state and all annual rows were present, no AB rows were supplied, and the Livewire error bag was empty.
- Tablet Add boundary and persistence: **1 test, 30 assertions passed**, creating all three disposable Customers with `profile_type=outlet` and nine MCB history rows each. No hidden AB validation errors occurred.

## Portal reference-loading failure correction

The same disposable scenario now removes `region_types` after the form loads. `CreateCustomer::beforeValidate()` rechecks the Region option provider; the narrowly scoped provider catches its `QueryException`, attaches a controlled error to `data.customer_region_type_id`, and halts Filament before data persistence. Dependent Region/Specific Region option providers use the same boundary so a rerender remains controlled while the reference source is unavailable. Empty option sets still return normally and continue through ordinary conditional validation. The test verifies the error bag and visible message, and zero Customer insertion (**1 test, included in the 6-test / 75-assertion focused Portal run**). No arbitrary persistence `QueryException` is swallowed.

## Tablet snapshot fixture correction

Only the four affected Add fixtures in `LocationReferenceSnapshotTest` were completed with base required fields (Company, Active Boolean, Access/PIC, General Category, address, coordinates, contact fields, and date). Production validation was unchanged. The four tests now reach their intended snapshot assertions and pass: **4 tests, 30 assertions**. They prove incomplete/stale location snapshots prevent insertion and expose the existing `landmass_code` readiness error.

## Province/locality zero-option evidence

No authoritative repository source was found for a real selected physical Region whose valid Province option set is empty. `CustomerLocationReferenceRestorationSeeder::manifest()` documents approved Region/Specific Region/Area Cluster relationships only; it contains no Province/locality assignments. Portal fixtures for a null-Province independent municipality are synthetic test rows. `TabletSampleSeeder` says its null `province_id` was used because the Tablet schema at that time had no Province table, which does not establish the modern Add contract's business scenario. Therefore the genuine zero-Province/locality scenario is **BLOCKED**, not simulated or counted as covered. Existing valid hierarchy and invalid-parent checks remain evidence only for their covered cases.

## Focused execution and quality checks

- Portal MCB baseline: **1 test, 18 assertions passed**.
- Tablet MCB baseline: **1 test, 30 assertions passed**.
- Portal reference failure correction, missing Region error, Company validation, six-code baseline, MCB baseline, and parent filtering: **6 tests, 75 assertions passed**.
- Tablet snapshot fixture tests: **4 tests, 30 assertions passed**.
- PHP lint, targeted Pint, and `git diff --check`: recorded after these focused changes in both repositories.
- Assurance report copies remain byte-identical.

## Final decision and remaining scope

The confirmed Portal reference failure defect is corrected and its focused regression passes. Overall assurance remains **BLOCKED — INSUFFICIENT TEST EVIDENCE** because zero-option Province/locality behavior has no authoritative reference evidence, and the global A–F matrix is still not complete for every dropdown. Preserve earlier passing denominators; do not claim full assurance.

Exact files changed for this correction: Portal `app/Filament/Resources/Customers/Pages/CreateCustomer.php`, `app/Filament/Resources/Customers/Schemas/CustomerForm.php`, and `tests/Feature/CustomerCreateValidationAssuranceTest.php`; both Work notes and the byte-identical assurance report. Earlier Tablet fixture evidence remains unchanged. Pre-existing unrelated Worktree changes were preserved. No terminal delivery or deployment occurred.


## Final dropdown reconciliation — stopped on a newly exposed defect (2026-10-13)

This addendum supersedes the prior overall decision above. It records evidence gathered before stopping at the first newly confirmed defect, as required by the Human decision.

### Focused empty-option evidence

Synthetic disposable fixtures removed all General Category rows, then separately removed Province, Municipality, and Barangay rows while leaving the tables queryable. They establish successful empty results, not a real geographic assignment:

| Scenario C contract case | Portal | Tablet |
|---|---|---|
| General Category null accepted by field validation | PASS (`form->getState()`) | PASS (actual `saveCustomer`, persisted null) |
| Province, Municipality, Barangay null accepted | PASS (`form->getState()`) | PASS (actual `saveCustomer`, persisted nulls) |
| Non-null invalid General Category / Province rejected with empty source | PASS (actual create validation, zero inserts) | PASS (actual save validation, zero inserts) |

These synthetic tests do not establish that a retained physical Region legitimately lacks Province/locality records. No claim about such a real geographic scenario is made. No authoritative Province/locality mapping evidence was found in the repository.

Focused runs: Portal zero-option cases **4 tests / 19 assertions passed**; Tablet zero-option cases **2 tests / 23 assertions passed**. The Portal General Category and locality null cases validate through Filament `getState()`; the Tablet cases additionally reach `saveCustomer` and local persistence.

### Confirmed Portal reference failure

After the Add form loads successfully, dropping the `provinces` table and submitting causes an uncaught `Illuminate\Database\QueryException` from `CustomerForm.php:632` while the Province options query runs (`select name, id from provinces where region_id = 1 and enabled = 1`). The exception escapes Livewire before a field-level or form-level validation error can be shown. No Customer was inserted. The existing controlled failure guard covers `region_types` and its dependent region option providers, but does not cover the Province option provider.

The focused reproduction test **fails**, confirming the defect. Per the Human instruction, no further dropdown probing was performed and no application fix was made. This failure prevents a PASS.

### Exact coverage reconciliation status

Previously established totals remain unchanged: Company codes **6/6**, required omissions Portal **295/295** and Tablet **283/283**, invalid selections **180/180 per app**. New synthetic Scenario C null cases: **4/4 families per app** (General Category, Province, Municipality, Barangay); accompanying invalid-ID rejection checks passed in both apps. The prior controlled `region_types` failure remains covered; Province reference failure is **FAIL**.

The existing assurance evidence does not enumerate every dropdown family against A–F applicability; its 180 invalid-selection totals are aggregate counts. Therefore exact total family/scenario denominators, N/A combinations, and remaining unresolved combinations cannot be established from the recorded evidence without continuing the scenario inventory. That continuation stopped at the newly confirmed defect as instructed. No 100% coverage claim is made.

Final decision: **FAIL — VALIDATION DEFECT FOUND**. Required omissions and invalid-selection totals above are preserved; complete A–F reconciliation remains open. Portal and Tablet assurance report copies are byte-identical. No application implementation, retained database operation, migration, Customer Pull/Push, staging, commit, push, or deployment occurred. Delivery remains `READY_FOR_HUMAN_REVIEW`.


## Portal Customer Add reference-loading hardening (2026-10-13)

This bounded correction supersedes the prior Province-reference failure outcome. The Add form now catches `QueryException` only around identified reference reads, attaches an error to the related visible field, and halts before record creation. The pre-validation reference probe executes the same scoped option-list readers used by the form; it continues checking the other sources so errors are associated with the affected fields. Empty query results remain ordinary empty option sets. Edit components rethrow reference-query exceptions as before.

### Database-backed dropdown inventory

| Provider | Source and dependency | Error field | Failure result |
|---|---|---|---|
| Company | `companies`, unscoped | `company_id` | PASS |
| Access and Person in Charge | `users`; Access creator mapping and selected labels | `users` / `person_in_charge_id` | PASS |
| General Category | `general_categories`, unscoped | `general_category_id` | PASS |
| Region grouping | approved geographic `region_types` rows | `customer_region_type_id` | PASS |
| Specific Region | `regions`, filtered through approved `region_specifics` mapping | `physical_region_id` | PASS |
| Grouping-to-Specific Region relationship | `region_specifics`, filtered by grouping and physical Region | `physical_region_id` | PASS |
| Area Cluster | `area_clusters`, filtered by `region_specific_id` | `area_cluster_id` | PASS |
| Province | `provinces`, filtered by physical `region_id` | `province_id` | PASS |
| Municipality | `municipalities`, filtered by physical `region_id` and `province_id` | `municipality_id` | PASS |
| Barangay | `barangays`, filtered by `municipality_id` | `barangay_id` | PASS |

Each failure case removed its source after the Add form had loaded, then exercised Add submission and verified the field error and zero inserted Customers. The selected User / General Category / locality labels and Access guidance were included where their renderer also reads the affected source. Region, Province, Municipality, Barangay, and Area Cluster use the existing parent scopes.

Config-backed selectors (Landmass options, Competitor Volume, Company-profile entry/classification/category choices, and other fixed profile enums) are **N/A** for SQL reference-loading failure: their option providers read configuration arrays and execute no database query. They remain subject to their existing validation. Customer Access mapping configuration tables are not dropdown option sources and were not fault-injected.

### Focused validation

- Reference failure cases: **10/10 distinct database-backed providers PASS**.
- Focused assurance run: **20 tests / 147 assertions passed**, including Region and Province error rendering, empty General Category/locality options, invalid values, stale Region/Area Cluster selections, valid complete forms for all six Companies, and MCB baselines.
- Customer Access mapping regression: **23 tests / 100 assertions passed**.
- Required indicator and Edit regression: **5 tests / 89 assertions passed**.
- Total focused execution: **48 tests / 293 assertions passed**.
- PHP lint passed for the three changed PHP files; targeted Pint passed; `git diff --check` passed.

No Customer Add/Edit persistence mapping, reference IDs, migration, synchronization, or retained data was changed. The previous synthetic zero-option findings remain contract-only and do not establish a real Province-less geographic assignment. The exhaustive per-family A–F dropdown matrix is still incomplete, so the overall assurance state remains **BLOCKED — INSUFFICIENT TEST EVIDENCE**; no full-matrix PASS is claimed.

## Final Customer Add dropdown A–F reconciliation (2026-10-13)

This is the current matrix for visible Customer Add dropdown families in Portal and Tablet. It supersedes earlier statements that the exact family/scenario denominator was unknown. A scenario is counted once per dropdown family and application, not once per Company code or annual row. Company/profile variation remains exercised by the cited data-driven tests. `P` means directly evidenced PASS, `N/A` means the scenario does not apply for the stated structural reason, and `B` means applicable but evidence is still missing. `—` means the scenario does not apply to that family.

**Scenario rules:** A missing selection with available options; B valid selection; C successful empty options; D invalid selection; E selection made stale by a parent/dependency change; F reference-loading/snapshot failure. The base test evidence is: Portal A/D `Portal Add rejects every applicable required field omission for all six Company codes` and `Portal Add rejects invalid values for every applicable dropdown family under all six Company codes`; Portal B `Portal Add validates complete forms for every Company code...` plus MCB baseline. Tablet A `Tablet Add validates complete and incomplete forms for every Company code in the authoritative profile map` (the field-by-field omission loop); Tablet D `Tablet Add rejects invalid values for every available dropdown family...`; Tablet B six-code and MCB baselines. Synthetic C evidence is the named General Category empty-set test and locality empty-set test in each app. Portal F is the `reports controlled errors for unavailable database-backed dropdown references` data provider. Tablet snapshot tests cover an incomplete/offline location snapshot as a global Add readiness gate, not provider-specific failed reads; they are not counted as F for individual dropdowns.

| Dropdown family | Portal A | Portal B | Portal C | Portal D | Portal E | Portal F | Tablet A | Tablet B | Tablet C | Tablet D | Tablet E | Tablet F |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Company | P | P | N/A (required company catalog; no empty-catalog null contract) | P | N/A (no parent dropdown) | P | P | P | N/A (same contract) | P | N/A (no parent dropdown) | P |
| Customer Access users | P | P | P | P | N/A (not parent-filtered) | P | P | P | P | P | N/A (not parent-filtered) | P |
| Person in Charge | P | P | P | P | N/A (not parent-filtered) | P | P | P | P | P | N/A (not parent-filtered) | P |
| General Category | P | P | P | P | N/A (no dropdown parent) | P | P | P | P | P | N/A (no dropdown parent) | B |
| Competitor Volume | P | P | N/A (fixed nonempty enum, conditional on category 1) | P | P | N/A (fixed in-code enum) | P | P | N/A (fixed nonempty enum, conditional on category 1) | P | P | N/A (fixed in-code enum) |
| Region grouping | P | P | P | P | N/A (root selector) | P | P | P | P | P | N/A (root selector) | P |
| Specific Region (physical Region) | P | P | P | P | P | P | P | P | P | P | P | P |
| Area Cluster | P | P | P | P | P | P | P | P | P | P | P | P |
| Province | P | P | P | P | P | P | P | P | P | P | P | P |
| Municipality | P | P | P | P | P | P | P | P | P | P | P | P |
| Barangay | P | P | P | P | P | P | P | P | P | P | P | P |
| Landmass | P | P | N/A (Portal Add remains required even if configuration is empty) | P | N/A (root selector) | N/A (configuration-backed, no DB read) | N/A (approved Tablet optional exception) | P | N/A (optional by contract) | P | N/A (root selector) | N/A (configuration stored in sync snapshot; snapshot readiness tested globally, no per-selector read-fault path) |
| Outlet Entry Detail | P | P | P | P | P | N/A (configuration-backed) | P | P | P | P | P | N/A (configuration-backed) |
| Outlet Classifications | P | P | P | P | N/A (no dependent selector) | N/A (configuration-backed) | P | P | P | P | N/A (no dependent selector) | N/A (configuration-backed) |
| Outlet Conversion Program | P | P | P | P | N/A (no dependent selector) | N/A (configuration-backed) | P | P | P | P | N/A (no dependent selector) | N/A (configuration-backed) |
| Outlet Working Days | P | P | P | P | N/A (no dependent selector) | N/A (configuration-backed) | P | P | P | P | N/A (no dependent selector) | N/A (configuration-backed) |
| Outlet AB annual category | P | P | P | P | P | N/A (configuration-backed) | P | P | P | P | P | N/A (configuration-backed) |
| Outlet MCB annual category | P | P | P | P | P | N/A (configuration-backed) | P | P | P | P | P | N/A (configuration-backed) |
| Outlet Delivery Type | P | P | N/A (fixed nonempty enum) | P | N/A (controls Delivery Detail; stale-state case not covered as invalid selection) | N/A (fixed in-code enum) | P | P | N/A (fixed nonempty enum) | P | N/A (controls Delivery Detail; stale-state case not covered as invalid selection) | N/A (fixed in-code enum) |
| Outlet Delivery Detail | P | P | N/A (fixed nonempty enum; conditional visibility) | P | P | N/A (fixed in-code enum) | P | P | N/A (fixed nonempty enum; conditional visibility) | P | P | N/A (fixed in-code enum) |
| Outlet ULAB | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) |
| Fleet Account Type | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) |
| Fleet Classifications | P | P | P | P | N/A (no parent dropdown) | N/A (configuration-backed) | P | P | P | P | N/A (no parent dropdown) | N/A (configuration-backed) |
| Fleet Battery Class | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) |
| Fleet Status | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) |
| Fleet annual category | P | P | P | P | N/A (no dropdown parent) | N/A (configuration-backed) | P | P | P | P | N/A (no dropdown parent) | N/A (configuration-backed) |
| OE Entry Detail | P | P | P | P | N/A (root selector; no dropdown parent) | N/A (configuration-backed) | P | P | P | P | N/A (root selector; no dropdown parent) | N/A (configuration-backed) |
| OE Classifications | P | P | P | P | N/A (no parent dropdown) | N/A (configuration-backed) | P | P | P | P | N/A (no parent dropdown) | N/A (configuration-backed) |
| OE Battery Class | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) | P | P | N/A (fixed nonempty enum) | P | N/A (no parent dropdown) | N/A (fixed in-code enum) |
| OE annual category | P | P | P | P | N/A (no dropdown parent) | N/A (configuration-backed) | P | P | P | P | N/A (no dropdown parent) | N/A (configuration-backed) |
| IB Classifications | P | P | P | P | N/A (no parent dropdown) | N/A (configuration-backed) | P | P | P | P | N/A (no parent dropdown) | N/A (configuration-backed) |
| IB annual category | P | P | P | P | N/A (no dropdown parent) | N/A (configuration-backed) | P | P | P | P | N/A (no parent dropdown) | N/A (configuration-backed) |

The profile families above are separate because they have distinct option providers and validation fields. Annual rows within a family are tested by the full row/year loops; Outlet AB and MCB are separate option contracts. A/D are supported by actual Add validation paths and field-specific error assertions in the cited data-driven tests. B uses valid complete Add baselines; Portal's form validation baseline asserts an empty error bag, while Tablet's baseline reaches local aggregate persistence. Invalid sweeps assert zero Customer rows after rejection. For profile switching, Tablet's `Tablet Add profile sections, required indicators, Owner values, and stale profile state follow every mapped Company code` and Portal's matching required-indicator Company-switch test prove hidden stale profile state is cleared/ignored; they do not prove stale dropdown IDs are rejected after a dependency change and are not counted as scenario E.

### Exact scenario totals

| Measure | Portal | Tablet | Combined |
|---|---:|---:|---:|
| Dropdown families inventoried | 32 | 32 | 32 shared contract families |
| Applicable A–F combinations | 138 | 137 | 275 |
| Verified and passed | 138 | 114 | 252 |
| Failed | 0 | 0 | 0 |
| Blocked / applicable but unverified | 0 | 23 | 23 |
| N/A combinations with rationale | 54 | 55 | 109 |

Denominator basis: A applies to all families except Tablet Landmass; B and D apply to all 32 families. C applies to 22 option-conditional families (9 shared user/reference/locality selectors, 8 config-driven profile selectors, and 5 annual-category selectors). Tablet now verifies the config-backed profile C cases listed in the correction addendum; five shared user/reference C scenarios remain unverified. E applies to 10 families; Portal has direct evidence for all applicable E scenarios, while Tablet has 8 E cases unverified. Specific Region and Area Cluster have direct stale-parent tests in both apps. F applies to 10 database-backed dropdown families. Portal covers all 10 through provider fault injection; Tablet per-provider failure cases remain unverified. Config-backed choices are N/A for database reference-load failure because their option readers use in-code configuration rather than a loadable reference source. Exact totals: **Portal 138 applicable / 138 verified / 0 blocked / 0 failed**, **Tablet 137 applicable / 114 verified / 23 blocked / 0 failed**, **combined 275 applicable / 252 verified / 23 blocked / 0 failed**. N/A totals are respectively 54 and 55 (109 combined).
### Remaining evidence gaps and decision

Portal has no unverified applicable A–F scenarios. Unverified Tablet cases are: C for Customer Access, Person in Charge, Region grouping, Specific Region, and Area Cluster (5); E for Province, Municipality, Barangay, Competitor Volume, Outlet Entry Detail, Outlet AB annual category, Outlet MCB annual category, and Outlet Delivery Detail (8); and F for all 10 database-backed providers. Synthetic C locality cases prove the conditional-null validator contract only; they do not establish a real physical Region with no Province/locality references. The config-backed option selectors corrected here have no runtime reference-load failure path, so F is structurally N/A for them. Overall assurance remains blocked by the remaining applicable scenarios.

Focused reruns for this reconciliation: Portal `CustomerCreateValidationAssuranceTest.php` selected scenarios **14 tests / 118 assertions passed**. Tablet `CustomerCreatePageDiagnosticTest.php` selected scenarios **7 tests / 885 assertions passed**; `LocationReferenceSnapshotTest.php` selected scenarios **3 tests / 23 assertions passed**. No application tests or behavior were added or changed: the remaining gaps are evidence gaps, and an observed defect would require stopping for a separate Human decision. PHP lint, targeted Pint, and `git diff --check` passed for the documentation-only update; no PHP source was modified. Assurance remains **BLOCKED — INSUFFICIENT TEST EVIDENCE**. State remains `READY_FOR_HUMAN_REVIEW`; no retained database operation, sync, migration, staging, commit, push, or deployment occurred.


## Historical checkpoint — initial 62-scenario execution (superseded 2026-10-13)

The approved first gap probe was Tablet Scenario C for **Outlet Classifications**. The test `Tablet Add permits null Outlet classifications when the successfully loaded option set is empty` changes only the disposable in-memory config option array to `[]`, supplies an otherwise complete valid Add state with `trade.classifications = []`, and calls the actual `CustomerCreatePage::saveCustomer()` boundary. It fails with the field error `trade.classifications => The trade.classifications field is required.` The option provider loaded successfully and returned no choices; this is not a reference-loading failure. The assertion fails before insertion.

This is a confirmed validation defect under the approved rule that null is allowed when a successfully loaded dropdown has zero valid options. The current Tablet `validateProfileCompleteness()` applies unconditional `required|array|min:1` rules to `trade.classifications` (and related profile selectors), even when their configured option list is empty. The smallest proposed correction is to apply required/minimum-selection rules only when the corresponding options are non-empty, while retaining array and allowed-value validation for supplied selections; keep the visible required indicator tied to the same predicate. **No production code was changed.** Per instruction, execution stopped at this first defect; the other 61 scenarios were not run.

### Reconciled result

| Measure | Portal | Tablet | Combined |
|---|---:|---:|---:|
| Applicable scenarios | 138 | 137 | 275 |
| Passed | 112 | 101 | 213 |
| Failed | 0 | 1 | 1 |
| Unverified | 26 | 35 | 61 |

The matrix marks Tablet Outlet Classifications C as **FAIL**. Remaining unverified scenarios are Portal: 18 empty-option, 8 stale-dependency; Tablet: 17 empty-option, 8 stale-dependency, 10 reference-read failures. Existing passed evidence and denominators are preserved. Decision: **FAIL — VALIDATION DEFECT FOUND**.

Focused execution: the new disposable regression **1 test / 1 assertion failed** with the required-validation error above; no Customer was inserted. Testing stopped immediately. PHP syntax lint and targeted Pint were run on the added test; `git diff --check` passed. The assurance report copies are byte-identical. No app behavior, retained database, migration, Customer synchronization, commit, push, or deployment was changed or performed. State remains `READY_FOR_HUMAN_REVIEW`.


## Historical checkpoint — Entry Detail defect reproduction (superseded 2026-10-13)

### Outlet Classifications correction

The Tablet Add validator now conditions required/minimum-one validation for `trade.classifications` on the Outlet classification option list being non-empty. It still validates the value as an array and checks every supplied item against the current allowed list. The Blade required marker already used the same config availability condition and remains aligned. Classification persistence in `CustomerProfileFormService::saveAggregate()` is unchanged; the empty-set test verifies persisted `[]`. The selector is config-backed, so there is no independent database/network reference-loading failure mode for this family (F is N/A). Edit uses its existing persistence/hydration path and remains unchanged.

Focused tests passed: empty successful option set, no required marker, empty-array persistence; available options with missing selection rejected; and a previously selected value rejected after the available options changed to empty — **3 tests / 14 assertions passed**. The existing Company/profile required and valid baseline, invalid-dropdown, and Edit regressions passed **4 tests / 827 assertions**.

### Next matrix case: Outlet Entry Detail, Scenario C

Execution then tested the next remaining empty-option case: set the successfully loaded in-memory `customer_trade_form.profile_entry_details.outlet` list to `[]`, submit an otherwise complete Outlet Add with `trade.entry_detail = null`, and call `CustomerCreatePage::saveCustomer()`. The test failed because `validateProfileCompleteness()` still reports `trade.entry_detail` as required. Since `entry_detail` validation happens before the local Customer aggregate transaction, the failed submission does not enter persistence. This is a second confirmed defect under the approved empty-options contract.

**No correction was made for Entry Detail.** The smallest proposed correction is to condition its required and allowed-value rule on the existing Outlet Entry Detail option list being non-empty, preserving rejection of supplied values that are outside a non-empty list. The current Blade asterisk already follows that list's non-empty state. Per the instruction to stop at the first newly discovered defect, no further matrix scenarios were executed.

### Current totals

| Measure | Portal | Tablet | Combined |
|---|---:|---:|---:|
| Applicable scenarios | 138 | 137 | 275 |
| Passed | 112 | 102 | 214 |
| Failed | 0 | 1 | 1 |
| Unverified | 26 | 34 | 60 |

Tablet Outlet Classifications C is now PASS; Tablet Outlet Entry Detail C is FAIL. Remaining Tablet scenarios: 16 empty-option cases, 8 stale-dependency cases, and 10 reference-read failures. Portal remains at 112/138 with 26 unverified. The current decision is **FAIL — VALIDATION DEFECT FOUND**.

The Entry Detail focused test was **1 test / 1 assertion failed** with `trade.entry_detail => The trade.entry detail field is required.` This is the stop point. PHP lint, targeted Pint, and `git diff --check` passed for the bounded changes. Reports remain byte-identical. No retained database operations or migrations, Customer Pull/Push, commit, push, or deployment occurred. State remains `READY_FOR_HUMAN_REVIEW`.


## Tablet option-dependent validation correction (2026-10-13)

The Tablet Add validator now applies the same loaded-option condition as the visible required markers across config-backed profile dropdowns: Outlet and OE Entry Detail; Outlet, Fleet, OE, and IB classifications; Outlet Working Days and Conversion Program; and annual category fields for Outlet AB/MCB, Fleet, OE, and IB. When the matching option set is nonempty, required/minimum-selection and allowed-value checks remain active. When it is successfully empty, null/empty selections pass and nonempty values are rejected. For empty arrays, validation enforces size zero; for empty scalar selectors, it uses the prohibited rule because Laravel `Rule::in([])` did not reject nonblank values. Option providers are configuration-backed, so a separate database/network loading failure case is structurally N/A for these fields. Edit persistence and profile mappings are unchanged.

Executed evidence: `Tablet Add permits null Outlet Entry Detail...`, `Tablet Add rejects a supplied Outlet Entry Detail...`, `Tablet Add allows null profile dropdowns and annual categories...`, and `Tablet Add rejects supplied values that are stale against successfully empty profile option sets`, together with the existing Outlet Classifications empty-set case: **5 tests / 89 assertions passed**. The test covers no insertion for rejected stale values and checks markers disappear for empty Outlet options; existing six-Company valid/required baseline, invalid dropdown sweep, and Add-only/Edit indicator regression passed **3 tests / 816 assertions**. Combined correction-focused execution: **8 tests / 905 assertions passed**.

The matrix now records Tablet Scenario C as passing for Outlet Entry Detail, Outlet Conversion Program, Outlet Working Days, Outlet AB and MCB annual categories, Fleet Classification/category, OE Entry Detail/Classification/category, and IB Classification/category. The earlier Outlet Classifications C pass remains. Tablet totals are **137 applicable / 114 verified and passed / 0 failed / 23 unverified**. Portal totals remain **138 applicable / 112 passed / 0 failed / 26 unverified**. Combined: **275 applicable / 226 passed / 0 failed / 49 unverified**. Remaining Tablet cases are C for Customer Access, Person in Charge, Region grouping, Specific Region, and Area Cluster; E for Province, Municipality, Barangay, Competitor Volume, Outlet Entry Detail, Outlet AB annual category, Outlet MCB annual category, and Outlet Delivery Detail; and F for 10 database-backed providers. No new defect was found beyond the same empty-options contract. Final assurance remains **BLOCKED — INSUFFICIENT TEST EVIDENCE**.

No retained DB operation, migration, Customer Pull/Push, production access, commit, push, or deployment occurred.


## Remaining assurance sweep — stopped at first confirmed defect (2026-10-13)

The matrix identifies Portal's remaining gaps as **18 Scenario C** and **8 Scenario E**, with no unresolved Portal F cases; Tablet's are **5 C, 8 E, and 10 F**. The matrix was used as the checklist; no counts were inferred.

Portal Scenario C for Region grouping passed: `Portal Add permits a null Region when Region options are successfully empty and rejects a supplied ID` passed **1 test / 5 assertions**. A successfully empty Region grouping list accepted null; a supplied nonexistent grouping ID was rejected with a field error.

The next applicable case, Portal Specific Region Scenario C, exposed a defect. In a disposable fixture, the GMA-to-Specific-Region option mapping was successfully empty while physical Region ID 1 remained an existing record and was supplied. `form->getState()` completed without a validation exception, the Livewire state still held `physical_region_id = 1`, and the error bag was empty. The preserved regression `Portal Add rejects a physical Region that is incompatible with a successfully empty Specific Region option set` fails because it expected a field validation error. The null/empty option case for Region grouping was independently verified. No persistence call was made in the failing probe.

Root cause: `CustomerForm` makes Specific Region non-required when its option provider returns an empty list, and its `rules()` callback returns no allowed-value rule for that same empty list. Since the visible field is not dehydrated, the incompatible value remains in Livewire state without a validation error. Smallest correction for separate Human authorization: allow null when options are empty, but reject or clear a supplied nonempty value at the Add validation boundary. No application behavior was changed.

Reconciled A–F totals: Portal **138 applicable / 113 passed / 1 failed / 24 unverified** (remaining: 16 C, 8 E, 0 F); Tablet **137 applicable / 114 passed / 0 failed / 23 unverified** (remaining: 5 C, 8 E, 10 F); combined **275 applicable / 227 passed / 1 failed / 47 unverified**. The failed Specific Region C case is excluded from both passed and unverified counts.

Focused execution: Region grouping C **1 test / 5 assertions passed**; Specific Region C regression **1 test / 4 assertions, failed** with no exception/error raised for the incompatible supplied value. Testing stopped at this first confirmed defect; remaining scenarios were not run. Portal targeted Pint, PHP lint, and `git diff --check` passed. No retained DB operations, migrations, sync, production access, commit, push, or deployment occurred. Final assurance decision: **FAIL — VALIDATION DEFECT FOUND**; delivery state remains `READY_FOR_HUMAN_REVIEW`.


## Portal dependent dropdown correction and assurance continuation (2026-10-13)

The physical Specific Region Add field now validates every nonempty value against its current allowed physical Region options, including when the successfully loaded option set is empty. Null remains valid when options are empty; when options exist, the existing required condition remains active. The same Add-only allowed-value boundary now applies to its hidden `region_specific_id` relationship, Area Cluster, Province, Municipality, and Barangay. This closes the equivalent geographic dependent-selector gap where rules were omitted for empty scoped options. Edit paths still bypass these Add-only rules. Parent-scoped providers, physical IDs, required indicators, and reference failure handling are unchanged.

Disposable tests passed for the correction: Specific Region empty options with stale physical Region ID **1 test / 5 assertions**; stale values in hidden Specific Region, Area Cluster, Province, Municipality, and Barangay under empty current options **1 / 11**; null Area Cluster under an empty successful set **1 / 2**; existing physical Region and Area Cluster parent-change tests **1 / 7**. Geographic correction run: **4 tests / 25 assertions passed**. The tests exercise `form->getState()`; no Customer insertion is attempted.

Portal Scenario C continuation passed: Customer Access and Person in Charge with successfully empty user options and null selections **1 test / 3 assertions**; Outlet Entry Detail with successfully empty configured options and null selection **1 / 2 assertions**. The next case, Outlet Classifications, exposed another validation defect. With `customer_trade_form.profile_classifications.outlet = []` and `tradeProfile.classifications = []`, actual Filament `form->getState()` rejects with `The classifications field must have at least 1 items.` The Add `minItems()` remains 1 while `required()` is false and the option set is successfully empty. No Customer was inserted; no correction was made. The preserved failing regression is `Portal Add accepts an empty Outlet Classifications selection when its configured option set is successfully empty`. The matrix records C as FAIL and the sweep stops here.

Current assurance: Portal **138 applicable / 138 passed / 0 failed / 0 unverified**; Tablet **137 applicable / 114 passed / 0 failed / 23 unverified** (5 C, 8 E, 10 F); combined **275 applicable / 252 passed / 0 failed / 23 unverified**. Decision: **BLOCKED — INSUFFICIENT TEST EVIDENCE** due to Tablet gaps. Files changed for this continuation: Portal `app/Filament/Resources/Customers/Schemas/CustomerForm.php` and `tests/Feature/CustomerCreateValidationAssuranceTest.php`; the paired assurance reports and Work notes in Portal and Tablet. No retained database change, migration, sync, commit, push, or deployment occurred. Delivery remains `READY_FOR_HUMAN_REVIEW`.


## Portal conditional multi-select correction and matrix continuation (2026-10-13)

Portal Customer Add has three multi-selects: Access (`users`), Outlet Classifications, and Outlet Working Days. Access already conditions `minItems` on nonempty User options. Outlet Classifications and Working Days both had unconditional Add minimums of one despite option-dependent required indicators. Their Add `minItems` now uses the same applicable-options condition as `required()`. Existing allowed-value rules still reject supplied unavailable values. Edit minimums remain zero; persistence mappings, Company/profile visibility, and reference-loading handling are unchanged. These two selectors use in-code configuration, so independent reference-load failure (F) is structurally N/A.

Focused correction evidence passed: available options with omitted selections rejected; valid selections passed; successfully empty options with `[]` passed; unavailable nonempty values rejected through `create()` with zero Customer inserts; and both required indicators followed option availability — **3 validation tests / 18 assertions** and **1 indicator test / 4 assertions**. Both corrected fields were exercised. The existing Outlet Classification Scenario C failure is reconciled to PASS; Working Days Scenario C is also PASS.

Portal A–F continuation used the existing matrix. Additional Scenario C cases passed for Outlet Conversion Program, Outlet AB/MCB annual categories, Fleet Classification and annual category, OE Entry Detail/Classification/annual category, and IB Classification/annual category. Additional Scenario E tests passed for Competitor Volume; Province/Municipality/Barangay clearing after a physical Region change; Entry Detail clearing on Company profile change; stale AB/MCB annual rows being ignored when Entry Detail changes; and Delivery Detail being ignored after Delivery Type changes to No. Company/profile switch compatibility passed **1 test / 18 assertions**; Edit required-indicator compatibility passed **1 / 1**. Final selected Portal matrix suite passed **19 tests / 44 assertions**, plus **3 indicator/profile/Edit tests / 23 assertions**. No further Portal defect was found.

Updated exact matrix totals: Portal **138 applicable / 138 passed / 0 failed / 0 unverified**; Tablet **137 applicable / 114 passed / 0 failed / 23 unverified**; combined **275 applicable / 252 passed / 0 failed / 23 unverified**. Portal has no remaining applicable A–F gaps. Tablet’s recorded 5 C, 8 E, and 10 F scenarios remain uncovered. Final assurance is **BLOCKED — INSUFFICIENT TEST EVIDENCE** overall.


## Tablet final assurance continuation — Company reference failure (2026-10-13)

The baseline checklist was reconciled: Tablet had 5 remaining Scenario C cases, 8 Scenario E cases, and 10 Scenario F cases (23 total). Portal remained complete at 138/138. Before the failure probe, focused existing C/E regression selections passed 5 tests / 101 assertions; those tests cover locality empty sets, profile option empties, and physical Region/Area Cluster behavior, and do not close the five named remaining C families or all eight named E families.

The first Scenario F test fault-injected the Company reference source after the Customer Add component loaded by dropping `companies` in the disposable SQLite database. Calling `saveCustomer()` throws an uncaught `Illuminate\Database\QueryException` (`no such table: companies`) from `CustomerProfileFormService::profileForCompany()` at line 17, called by `CustomerCreatePage::profileType()` at line 192. This happens before normal `company_id` validation, so no controlled field/form error is attached. The preserved regression is `Tablet Add reports a controlled Company reference error when the source disappears after load` in `tests/Feature/CustomerCreatePageDiagnosticTest.php`. Its no-insertion assertion passed before the exception was rethrown; execution result: **1 test failed / 1 assertion**, as expected for the confirmed defect.

Per the Human stop rule, no additional C/E/F scenarios were run after this defect and no production behavior was changed. Of the previous 23 unverified combinations, Company F is now **FAIL**; 22 remain unverified (5 C, 8 E, 9 F). Totals are Tablet **137 applicable / 114 passed / 1 failed / 22 unverified**; Portal **138 / 138 passed / 0 failed / 0 unverified**; combined **275 / 252 passed / 1 failed / 22 unverified**. N/A totals remain 55 Tablet, 54 Portal, 109 combined. Decision: **FAIL — VALIDATION DEFECT FOUND**. Smallest correction proposal: guard the Company lookup at the Add save boundary and translate only its reference-read `QueryException` into a `company_id` validation error before continuing; retain ordinary validation when the source is available.

No retained database operations, migrations, Customer Pull/Push, production access, application changes, commit, push, or deployment occurred. Delivery remains `READY_FOR_HUMAN_REVIEW`. PHP lint passed, targeted Pint passed for the preserved regression test, and `git diff --check` passed.


## Tablet reference-read hardening and assurance continuation (2026-10-13)

The prior Company Scenario F failure is corrected within the authorized Tablet Add boundary. The Add page now probes each database-backed Customer reference source before profile resolution/validation and catches `QueryException` only for those identified source reads. A failure sets field-level errors and prevents Save; the subsequent render uses a controlled empty view model while retaining the failure state, so a failed read is not accepted as a successfully empty option set. Profile display values formerly queried directly from the Blade template now come from guarded view data. Customer Edit keeps its existing rendering and save behavior.

Disposable source-failure matrix passed **10 tests / 52 assertions**: Company (`company_id`), Access/PIC Users (both fields), General Category, Region grouping, physical Region, Specific Region relationship, Area Cluster, Province, Municipality, and Barangay. Each source table was made unavailable after the Add component loaded; Save exposed the expected field error and inserted no Customer. Valid-profile and offline checks passed: selected baseline run **8 tests / 706 assertions**, covering six Company profiles, MCB, Company required validation, invalid dropdown values, and location snapshot behavior. Empty-option/locality, stale parent, and Edit checks passed in further focused runs.

Remaining Scenario C coverage passed for all five dropdown families (Access, Person in Charge, Region grouping, Specific Region, Area Cluster): successfully empty sources accepted null/empty selections and removed required marks (**4 data-provider cases / 21 assertions**, covering five fields); supplied invalid/stale selections were rejected with no Customer insertion (**4 cases / 18 assertions**). The existing General Category and locality C evidence also passed again (**4 tests / 32 assertions**).

Scenario E passed for Province after physical Region change (**1 test / 5 assertions**), Municipality after Province change and Barangay after Municipality change (**1 / 9**), Competitor Volume after General Category change (**1 / 5**), and hidden Outlet Entry Detail after switching from Outlet to Fleet (**1 / 7**). Each exercised `saveCustomer()`; the location cases rejected stale child IDs, and the other two persisted only the current applicable state.

The next Scenario E case exposed a distinct defect. Test `Tablet Add does not persist stale AB annual categories after switching Entry Detail to MCB` submits the valid AB annual values already in state, changes `trade.entry_detail` to MCB, supplies valid MCB annual values, and invokes actual Add Save. Validation succeeds and the Customer is created, but **9 AB annual history rows are persisted** alongside the active MCB rows. The failing assertion is at `CustomerCreatePageDiagnosticTest.php:2128`. `validateProfileCompleteness()` skips validation for the now-hidden AB stream, while `CustomerProfileFormService::saveAggregate()` still iterates and persists every submitted stream. This violates stale dependent dropdown handling. No correction was made; the Human stop rule halted the matrix at this first distinct defect. Proposed smallest correction for separate review: omit inactive Outlet category streams from Add persistence (or clear their stale state when Entry Detail changes), without changing Edit/history behavior.

Final reconciled matrix: Portal **138 applicable / 138 passed / 0 failed / 0 unverified**. Tablet **137 applicable / 134 passed / 1 failed / 2 unverified** (AB annual category E failed; MCB annual category E and Delivery Detail E remain unverified). Combined **275 applicable / 272 passed / 1 failed / 2 unverified**. Tablet Scenario F is now **10/10 passed**, and Scenario C is **5/5 passed**. Final decision: **FAIL — VALIDATION DEFECT FOUND**. Focused execution for this continuation: **34 passing tests / 855 assertions**, plus the preserved annual-category regression **1 failed test / 5 assertions**. PHP lint, targeted Pint, and `git diff --check` passed. No retained database operations, migrations, remote sync/Customer Push, production access, commit, push, or deployment occurred. Work remains `READY_FOR_HUMAN_REVIEW`.


## Tablet Entry Detail persistence reconciliation (2026-10-13)

The Add page now computes applicable annual category streams from the final Outlet Entry Detail and uses that same stream set for validation and the transaction payload. AB persists only `ab`, MCB only `mcb`, and AB and MCB persists both. Inactive streams retained in Livewire state are excluded at the Add persistence boundary. `CustomerProfileFormService` and Customer Edit were not changed; aggregate insertion remains within the existing transaction.

Disposable database regression `Tablet Add persists annual categories for only the final Outlet Entry Detail` passed **3 cases / 21 assertions** for AB→MCB, MCB→AB, and AB→MCB→AB. Each test read back `customer_category_histories`, confirmed exactly the expected final stream(s), all configured year rows, and no duplicate years. This closes the prior AB annual-category E failure and verifies MCB annual-category E.

The next remaining E case, `Tablet Add does not persist stale Delivery Detail after Delivery Type changes to No`, exposed a separate confirmed defect. With `delivery_type=yes` and `delivery_detail=own_delivery`, changing the parent to `no` leaves the stale child in component state; Add Save succeeds and persists `active.delivery_detail=own_delivery`. The preserved regression fails at `tests/Feature/CustomerCreatePageDiagnosticTest.php:2178` (**1 test / 5 assertions**, four setup/save assertions passed and the persistence assertion failed). No Customer Add or Edit fix was made for this distinct issue; assurance stopped at this first new defect as instructed. Proposed smallest correction for separate Human review: omit `active.delivery_detail` from Add persistence when the final `delivery_type` is `no`, with validation and visibility tied to that same condition.

Reconciled matrix: Portal **138/138 passed**. Tablet **136/137 passed, 1 failed, 0 unverified**. Combined **274/275 passed, 1 failed, 0 unverified**. Tablet C remains 5/5 and F remains 10/10; of the prior E cases, AB annual categories, MCB annual categories, and the corrected AB/MCB switching case now pass; Delivery Detail E is the single failure. Overall decision: **FAIL — VALIDATION DEFECT FOUND**. Focused correction test passed **3 tests / 21 assertions**; the required stop-point reproduction failed **1 test / 5 assertions**. PHP lint, targeted Pint, and `git diff --check` passed. No retained DB operations or migrations, Customer Pull/Push, production access, commit, push, or deployment occurred. State remains `READY_FOR_HUMAN_REVIEW`.


## Tablet Delivery Detail state correction (2026-10-13)

Root cause: the Add validator always applied the `own_delivery` / `meh` allowed-value rule, even when final Delivery Type was No, and the complete `active` state was passed into aggregate persistence. Thus a hidden stale value could be accepted and stored. The correction computes applicability from the final Add state: Delivery Detail is required and enum-validated only for the Outlet profile when Delivery Type is Yes. Otherwise the hidden field is nullable for validation and removed from the Add persistence payload. Required indicators already use the same final Delivery Type condition. Customer Edit, the aggregate transaction boundary, profile keys for applicable values, and synchronization contract were not changed.

Disposable regression results: the Delivery Detail focused suite passed **6 tests / 42 assertions**, covering Yes with missing/valid detail, Yes→No stale-value exclusion, Yes→No→Yes restoration and persistence, No with stale invalid detail, required-marker visibility, and zero related inserts after failed validation. Read-back from `customer_trade_profiles.profile_data` confirmed applicable `own_delivery` persists for Yes and the `delivery_detail` key is absent for No. The existing annual-category Add/Edit history compatibility test and Delivery Detail required-error case passed **2 tests / 28 assertions**. Combined focused run: **8 tests / 70 assertions passed**.

The prior Tablet Delivery Detail Scenario E failure is reconciled to PASS. Final A–F totals: Portal **138/138 passed**, Tablet **137/137 passed**, combined **275/275 passed**, with zero failed or unverified applicable scenarios. Decision: **PASS — ALL APPLICABLE VALIDATION CONTRACTS VERIFIED**. PHP lint, targeted Pint, and `git diff --check` passed. No retained DB operations or migrations, Customer Pull/Push, production access, commit, push, or deployment occurred. State remains `READY_FOR_HUMAN_REVIEW`.


## Human acceptance and terminal preflight (2026-10-13)

Human acceptance is recorded for this Work: Portal **138/138 PASS**, Tablet **137/137 PASS**, combined **275/275 PASS**. No unresolved defect remains within the accepted dropdown assurance matrix. Final targeted validation is being completed before the authorized scoped commits and ordinary pushes to the existing `main` branches. Current state remains `READY_FOR_TERMINAL_REVIEW`; it will be marked `TERMINAL_DELIVERED` only after both pushes and post-push verification succeed.

Deferred separate Work items, excluded from this delivery: (A) Tablet repeated Pick on Map state reconciliation; (B) Tablet Customer Details cannot be opened; (C) Tablet field-level validation UX improvements. No implementation for these items is included here.

Delivery boundary: commit and push only paths reviewed as belonging to this Work; no force push, deployment, retained database write, migration execution, Customer Pull/Push synchronization, or production operation. The Tablet nullable `landmass_code` migration may be delivered as source but remains unauthorized for execution against retained data. Disposable-database tests do not authorize retained migration execution.

## Reconciliation regression rerun (2026-10-13)

After fast-forwarding both repositories to their reviewed `origin/main` commits, the post-reconciliation affected suites passed: Portal **122 tests / 2,248 assertions** and Tablet **120 tests / 1,863 assertions**. Portal coverage included Customer Create assurance, required indicators, Tablet creation contract, Access mapping, map location automation, and the incoming Region Type image regression. Tablet coverage included Customer Add diagnostics, offline reference snapshots, the incoming Region Type image regression, and Salescall correlation. Portal's two previously failing focused assertions were caused by disposable fixtures: the Access fixture had not inserted the approved fixed Specific Region ID 52, and the map fixture omitted its required Region Type rows. Fixture-only corrections were made; application behavior was unchanged. The accepted assurance matrix remains Portal 138/138, Tablet 137/137, combined 275/275. PHP lint, targeted Pint, and diff checks passed. Remote reconciliation introduced no Customer contract overlap. Delivery remains pending successful scoped commits, pushes, and post-push verification.
