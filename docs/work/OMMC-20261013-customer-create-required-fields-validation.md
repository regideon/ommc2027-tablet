# OMMC-20261013-customer-create-required-fields-validation

- **State:** READY_FOR_HUMAN_REVIEW
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Bounded implementation and disposable migration/test validation only. No retained database writes or synchronization; no commit, push, or deployment.

## Approved contract

New Customer Add submissions must complete every applicable user-editable field. Dropdowns require a valid current choice whenever applicable choices exist; only a confirmed empty option set permits null. Loading/sync failures block creation. Selected IDs must be valid for their current options and established parents. Coordinates are required and bounded. Boolean false is valid. System-generated Customer codes and synchronization metadata are not manual requirements. Existing Edit and Pull/update behavior remain compatible.

Tablet Landmass remains the expressly approved optional exception; Portal Add remains required. Tablet validates a supplied value, persists it locally, sends it on Push, and accepts omitted/null on Portal Push. This is a conflict with the latest universal wording and is flagged for Human confirmation.

## Validation matrix

| Field group | Required condition | Persistence / coverage |
|---|---|---|
| Name, Company, Active | Identity and valid Company required; boolean state required (false valid) | Customer row; create contract tests |
| General Category / Competitor Volume | Category required when choices exist; Competitor Volume required when category 1 is selected | Customer row; conditional validation |
| Region / Specific Region / Area Cluster | Current approved grouping and specific references required when options exist; Area Cluster parent must match | Existing Customer IDs; grouping and parent tests |
| Province / Municipality / Barangay | Required only if valid options exist; selected child must match physical Region and parent | Existing locality IDs; hierarchy tests |
| Address / Latitude / Longitude | Nonblank address ≤500; coordinates always required and bounded | Customer row; location tests |
| Access / Person in Charge | Required when Users are available; selected IDs must exist | Aggregate relationships; Access regression tests |
| Contact details / establishment date | Visible Add fields required and validated | Customer row; persistence tests |
| Profile controls / annual categories | Require all visible controls for the selected profile; validate enumerated values; conditional fields only when visible | Profile/history rows; profile contract tests |
| Landmass | Optional on Tablet; if supplied must be one of Portal-configured options | Nullable local column; v3 snapshot, Push/Pull coverage |

## Migration and sync contract

Add a nullable `customers.landmass_code` column in a new Tablet migration only. Snapshot v3 includes Portal-configured `landmass_options`; a Tablet with snapshot version <3 attempts refresh before sync completion. A failed refresh leaves the old snapshot version and blocks Add. Customer records already use the shared Push/Pull record payload, which now includes `landmass_code`. Older v2 snapshots remain readable but do not satisfy the Add reference-data readiness check.

## Implementation scope and validation

Tablet Add validation runs at `saveCustomer` before the existing local aggregate transaction. A complete validated aggregate is saved locally even when immediate Push fails. The nullable Tablet-only `customers.landmass_code` migration was exercised in disposable tests only. The v3 reference snapshot supplies approved Landmass options; stale or failed reference refresh does not satisfy Add readiness. Landmass is carried on Customer Push/Pull records.

Focused disposable Tablet tests passed: `CustomerCreatePageDiagnosticTest` and `LocationReferenceSnapshotTest` — **61 tests, 583 assertions**. Coverage includes complete/incomplete Add, access/PIC, invalid IDs and hierarchy, region-specific filtering, coordinates, optional Landmass persistence and Push payload, local aggregate on failed Push, Edit identity preservation, and offline snapshot readiness/options.

PHP lint passed for all changed Tablet PHP implementation and test files. Targeted Pint (`vendor/bin/pint --dirty --format agent`) passed. `git diff --check` passed. Portal focused tests are recorded in the matching Portal Work note.

## Files changed

- `app/Filament/Pages/CustomerCreatePage.php`
- `app/Filament/Pages/CustomerEditPage.php`
- `app/Services/CustomerProfileFormService.php`
- `app/Services/SyncService.php`
- `resources/views/filament/pages/customer-create-page.blade.php`
- `database/migrations/2026_10_13_090000_add_landmass_code_to_customers_table.php`
- `tests/Feature/CustomerCreatePageDiagnosticTest.php`
- `tests/Feature/LocationReferenceSnapshotTest.php`
- `docs/work/OMMC-20261013-customer-create-required-fields-validation.md`

## Human confirmation item and delivery boundary

The previously approved Tablet-optional / Portal-required Landmass exception conflicts with the new universal completeness rule. Implementation keeps that prior explicit exception and flags it for Human confirmation. All DB-backed validation used disposable databases. No retained migration or writes, Customer Pull/Push, staging, commit, push, or deployment occurred. State is `READY_FOR_HUMAN_REVIEW`.

## Required indicator amendment (2026-10-13)

Tablet Customer Add uses the same required conditions as its current `saveCustomer` validation. The custom Blade form renders accessible asterisks for required inputs; dependent location indicators follow applicable local option sets. If the location snapshot is unavailable or stale, the form reports the reference failure and keeps dependent fields marked as required rather than treating the error as an empty set. Changing Region and profile selections refreshes the indicators. Customer Code has no mark; Landmass is labeled `Landmass (optional)` without a mark. Customer Access and Person in Charge defaults are unchanged. Edit renders no Add-only Address required indicator.

The final validation amendment below resolves the prior mismatch: when these controls apply in the Outlet profile, Tablet requires them and marks them; when they do not apply, neither is required or marked.

Amendment-focused disposable tests passed: `CustomerCreatePageDiagnosticTest` indicator and Edit cases — **2 tests, 25 assertions**. PHP lint passed for `CustomerCreatePage.php` and `CustomerCreatePageDiagnosticTest.php`; targeted Pint passed for those PHP files; `git diff --check` passed. No Customer insertion suite was repeated for this amendment. No retained database operation or synchronization occurred.

## Conditional profile validation amendment (2026-10-13)

For Customer Add using the Outlet profile, Warehouse Code is required only while MOTIV User is enabled; Delivery Detail is required only when Delivery Type is Yes. These same predicates control the Blade asterisk and server-side validation. Each field now renders its own validation message. In other profiles or when the controlling selection makes the field inapplicable, the field is neither shown nor required. Existing Edit behavior, profile persistence keys, Access defaults, and location rules are unchanged.

Focused disposable Tablet tests passed: `CustomerCreatePageDiagnosticTest` required-indicator and conditional Warehouse Code / Delivery Detail cases — **6 tests, 48 assertions**. They cover blank applicable fields and visible field errors, populated applicable values, hidden/inapplicable fields, Edit indicators, and blocked Customer insertion. PHP lint, targeted Pint, and `git diff --check` passed. No migration, sync change, retained database operation, Customer Pull/Push, or Git delivery occurred. State remains `READY_FOR_HUMAN_REVIEW`.

## Customer Profile section visibility amendment (2026-10-13)

The Tablet's profile panel now renders only when the selected Company's existing profile mapping resolves to an applicable profile (`outlet`, `fleet`, `oe`, or `ib`). With no Company or an unmapped Company, the empty “Customer Profile” panel is omitted. The separate Owner Profile section and Company required validation remain. Company changes continue to reset profile-only state; shared Owner values are retained across profile changes, and tests verify stale hidden Outlet values do not create validation errors under the new mapped profile. The profile panel and required indicators recalculate with the Livewire Company selection.

Focused disposable Tablet tests passed: `CustomerCreatePageDiagnosticTest` profile visibility/company-switch and indicator cases — **7 tests, 63 assertions**. They cover no Company, an unmapped Company, a mapped Company, switching profile mappings, Owner visibility/value preservation, indicator recalculation, and stale hidden-field validation. PHP lint, targeted Pint, and `git diff --check` passed. No retained database operations, sync changes, migrations, Customer Pull/Push, or Git delivery occurred. State remains `READY_FOR_HUMAN_REVIEW`.

## Customer Create validation assurance (2026-10-13)

Added the cross-repository assurance report at [`OMMC-20261013-customer-create-validation-assurance.md`](OMMC-20261013-customer-create-validation-assurance.md). It inventories the six Company codes mapped to Outlet, Fleet, OE, and IB and records current Portal/Tablet evidence. Data-driven disposable tests traverse every configured Company code for complete representative profile state and representative missing profile-required selection. Tablet additionally verifies local persistence and profile type for complete states.

The assurance decision is **BLOCKED — INSUFFICIENT TEST EVIDENCE** under the Human's exhaustive criteria. The new cases pass, but tests do not yet omit every applicable required field or exercise all dropdown A–F states for all controls and Company transitions. This is a coverage gap, not a confirmed product defect. Focused assurance suite results and remaining cases are detailed in the linked report. No retained DB operation, migration, Customer Pull/Push, or Git delivery occurred.

## Assurance completion stopped on validation defect (2026-10-13)

The bounded assurance completion was stopped at the first defect as required. Tablet Add with `company_id = null` and Company options present exits through an HTTP 422 abort at `CustomerCreatePage.php:460` before Livewire validation, leaving no `company_id` field error. The temporary field-error assertion failed (`Component has no errors`); it was removed after diagnosis so the worktree has no intentionally failing test. No Customer was inserted, and no application code was changed. See the matching assurance report for exact partial denominators. Final assurance status: **FAIL — VALIDATION DEFECT FOUND**. The rest of the exhaustive field/dropdown matrix was not run and remains open pending separately authorized correction.


## Company validation defect and assurance continuation (2026-10-13)

The Tablet early Company/profile abort was moved after the base Livewire validator. Missing Company now reports a field error on `company_id`; invalid IDs remain rejected by `exists:companies,id`; a valid ID proceeds to the next validation rule. A focused disposable test passes all three cases and confirms no Customer is inserted on failure. No Add/Edit or profile mapping change was made.

Exhaustive continuation added data-driven required-field omissions and invalid dropdown selections for all six configured Company codes, including Outlet MCB annual categories. Results: Portal required omissions 295/295; Tablet 283/283. Invalid dropdown value checks: Portal 180/180; Tablet 180/180. Complete Company/profile baseline remains 6/6 in the earlier AB/Fleet/OE/IB baseline tests; dynamic profile and Owner behavior and stale Outlet state switching are covered. Empty General Category option behavior is exercised in both apps.

Portal profile/parent checks passed. Two older Tablet snapshot tests did not reach snapshot readiness because they submit incomplete forms and now stop at earlier required fields; they do not indicate an application defect, but do not count as snapshot-failure evidence. Other offline Add snapshot tests and six-selector dependency tests passed in the earlier focused suite.

Assurance remains **BLOCKED — INSUFFICIENT TEST EVIDENCE**: not every dropdown's no-selection/valid-selection/zero-options/stale-parent/reference-load-failure permutations are covered. No authoritative fixture proves a legitimate zero-option Province/locality case, and Portal's reference query-failure scenario was not exercised. Existing option sources and selected parent checks are tested, but this does not justify 100% dropdown coverage. Do not claim a full PASS.

No additional application defect was found. No retained DB operation, migration, Customer Pull/Push, production access, staging, commit, push, or deployment occurred. State remains `READY_FOR_HUMAN_REVIEW`.

Checks for this continuation: PHP lint passed for the changed PHP files; targeted Pint passed in Portal and Tablet; `git diff --check` passed. The matching assurance reports are byte-identical.


## Evidence closure (2026-10-13)

MCB contract verified from the existing `outlet` configuration: OMMC, LAST_MILE, and CAR_CLUBS use `entry_detail=MCB`, the 2018–2026 MCB annual category stream, and configured MCB values; AB is hidden/inapplicable for this branch. Portal complete MCB validation passed for all three companies (1 test, 18 assertions). Tablet local creation and nine MCB history rows per company passed for all three (1 test, 30 assertions), with no hidden AB errors.

The four stale/incomplete Add fixtures in Tablet `LocationReferenceSnapshotTest` now provide base required Add data. All four reach the snapshot guard and pass (4 tests, 30 assertions); production validation was not weakened.

Portal reference failure reproducer (1 test, 4 assertions) found a confirmed defect: removing `region_types` after the form loads causes an uncaught `Illuminate\Database\QueryException` during create validation. No field/form error is exposed; Customer count remains zero. No Portal production code was changed. The evidence task stops at this defect.

A legitimate zero-Province/locality scenario remains blocked. The restoration manifest does not supply Province/locality assignment evidence; null-Province municipalities in current tests are synthetic, and the old Tablet sample explicitly predates a Province table. No business assignment was inferred.

Previously established passing coverage remains unchanged: required omissions Portal 295/295, Tablet 283/283; invalid dropdown choices 180/180 each; Company inventory 6/6. The complete dropdown A–F matrix remains open. Final assurance decision: **FAIL — VALIDATION DEFECT FOUND**, with a separate zero-locality evidence blocker. PHP lint, targeted Pint, and diff checks are captured in the assurance report. No retained DB operation, sync, commit, push, or deployment occurred. State remains `READY_FOR_HUMAN_REVIEW`.


## Portal reference loading correction (2026-10-13)

Portal Add now verifies the approved Region option source at `beforeValidate`. The `RegionType` option provider and its dependent physical Region/Specific Region providers catch only `QueryException` raised inside those reference reads; Add receives a field-level error at `data.customer_region_type_id`, and the Filament create transaction halts before insertion. A healthy but genuinely empty options result remains distinguishable: it does not set the loading-failure state, so existing conditional required validation remains authoritative. Exceptions outside those option reads continue to propagate.

The controlled disposable `region_types` failure now exposes the error bag and rendered field message and leaves Customer count at zero. Focused Portal validation passed **6 tests, 75 assertions** across the reference failure, missing Region selection, valid and invalid Company selection, all six Company/profile baseline, MCB baseline, and parent filtering. No exhaustive suites were rerun. PHP lint, targeted Pint, and `git diff --check` passed. No retained operations, migration, synchronization, or Git delivery occurred.

Overall assurance remains **BLOCKED — INSUFFICIENT TEST EVIDENCE** solely for remaining evidence scope, including no authoritative zero-Province/locality scenario and incomplete dropdown A–F coverage. The reports are byte-identical. Work remains `READY_FOR_HUMAN_REVIEW`.


## Final dropdown assurance reconciliation (2026-10-13)

The focused synthetic empty-option checks passed: Tablet **2 tests / 23 assertions** and Portal **4 tests / 19 assertions**. General Category and locality nulls were accepted by the production validation path; invalid General Category / Province IDs with empty option sources were rejected. The fixtures prove option-set behavior only and do not establish any real Province-less geography.

The cross-repository assurance stopped after a new Portal reference-failure probe showed that dropping `provinces` after Add form load causes an uncaught `QueryException` from the Province option query at Portal `CustomerForm.php:632`, rather than a controlled validation error. No Customer was inserted. No application fix or further dropdown probing was performed. The reproduction test remains as evidence. Assurance decision is **FAIL — VALIDATION DEFECT FOUND**. Existing totals remain: required omissions 295/295 Portal and 283/283 Tablet; invalid selections 180/180 each; Company codes 6/6. The A–F family denominator remains unresolved because the prior report contains only aggregate invalid-selection totals and the defect required work to stop. See the matching byte-identical assurance report. No retained DB operation, migration, synchronization, staging, commit, push, or deployment occurred.


## Portal Customer Add reference-loading hardening (2026-10-13)

The Portal reference-loading defect is corrected within the existing Work. Its Add-only narrow error boundary covers all 10 inventoried database-backed dropdown sources: Company, Access/PIC Users, General Category, Region grouping, physical Region, grouping-specific Region relationship, Area Cluster, Province, Municipality, and Barangay. Each source failure presents an error on the related field and blocks Add before insertion. Empty results remain distinct from SQL failures. Portal Edit behavior and Tablet implementation are unchanged. Config-backed selectors are N/A for SQL loading failure.

Portal disposable checks passed **10/10 source failures** and focused validation passed **48 tests / 293 assertions** across reference handling, empty/invalid options, six Company profiles, MCB, Access, required indicators, and Edit behavior. PHP lint, targeted Pint, and `git diff --check` passed. The broader dropdown A–F matrix remains incomplete, so assurance stays **BLOCKED — INSUFFICIENT TEST EVIDENCE**. No retained database operations, migrations, synchronization, commit, push, or deployment occurred. See the matching byte-identical assurance report.

## Final dropdown A–F assurance reconciliation (2026-10-13)

The shared report now inventories 32 visible dropdown families and maps A–F evidence per application, including test names and N/A rationales. Combined denominator: 275 applicable family/scenario/application combinations; 213 verified and passed, 0 failed, 62 blocked. Portal: 138 applicable / 112 passed / 26 blocked. Tablet: 137 applicable / 101 passed / 36 blocked. Remaining gaps are 18 empty-option cases per app, 8 stale-dependency cases per app, and 10 Tablet reference-read failures. Synthetic locality empty-set tests do not establish an authoritative Province-less geography. Decision remains **BLOCKED — INSUFFICIENT TEST EVIDENCE**.

Focused reruns passed: Portal 14 tests / 118 assertions; Tablet Customer Add 7 tests / 885 assertions; Tablet location snapshot 3 tests / 23 assertions. No application behavior changed. The report copies are byte-identical. PHP lint, targeted Pint, and diff checks passed. No retained database operation, synchronization, migration, staging, commit, push, or deployment occurred. State remains `READY_FOR_HUMAN_REVIEW`.


## First remaining assurance scenario exposed a defect (2026-10-13)

The first approved gap test exercised Tablet empty options for Outlet Classifications at `CustomerCreatePage::saveCustomer()`. Although the configured option set loaded successfully as empty, `trade.classifications = []` was rejected with “The trade.classifications field is required.” This violates the conditional-null dropdown contract. The reproduction remains in `tests/Feature/CustomerCreatePageDiagnosticTest.php`. No production code was changed, and testing stopped as required. Proposed smallest correction: make required/minimum-selection rules conditional on non-empty available options, retaining validation of supplied values and aligning the required indicator.

Reconciled assurance: Portal 112/138 passed, 0 failed, 26 unverified; Tablet 101/137 passed, 1 failed, 35 unverified; combined 213/275 passed, 1 failed, 61 unverified. Decision: **FAIL — VALIDATION DEFECT FOUND**. PHP lint, targeted Pint, and diff check passed for the focused test and documentation; reports remain byte-identical. No retained database operations, synchronization, migration, or Git delivery occurred.

## Tablet Outlet classifications correction and assurance continuation (2026-10-13)

`CustomerCreatePage::validateProfileCompleteness()` now requires at least one Outlet classification only when the configured option list is non-empty; supplied selections remain checked against that list. The existing Blade marker already follows the same option predicate. Persistence mapping and Edit behavior were not changed. Focused validation passed **3 tests / 14 assertions** for empty options acceptance/persistence, empty-options indicator, required behavior with available options, and stale/invalid selection rejection. Existing valid/profile, invalid-option, and Edit regressions passed **4 tests / 827 assertions**.

Resuming the matrix exposed a second defect at Tablet Outlet Entry Detail Scenario C: with the successfully loaded option list empty and `trade.entry_detail = null`, `saveCustomer()` returns `The trade.entry detail field is required.` The test is preserved in `CustomerCreatePageDiagnosticTest.php`. No Entry Detail application change was made. Smallest proposed correction: condition required/allowed-value validation on whether the configured option list is non-empty. Testing stopped at this defect.

Updated assurance: Portal 112/138 passed, 0 failed, 26 unverified; Tablet 102/137 passed, 1 failed, 34 unverified; combined 214/275 passed, 1 failed, 60 unverified. Decision: **FAIL — VALIDATION DEFECT FOUND**. PHP lint, targeted Pint, and diff checks passed; reports are byte-identical. No retained database operation, migration, synchronization, or Git delivery occurred.


## Tablet option-dependent validation correction — 2026-10-13

Within the authorized Tablet scope, Add validation now uses the same availability condition as the required indicators for config-backed profile selectors. Corrected fields are Outlet/OE Entry Detail; Outlet, Fleet, OE, and IB classifications; Outlet Working Days and Conversion Program; and Outlet AB/MCB, Fleet, OE, and IB annual categories. Nonempty options retain required/minimum and allowed-value checks. Successfully empty options allow null/empty; supplied stale values are rejected. Empty arrays enforce size zero and empty scalar selectors use `prohibited`, since Laravel `Rule::in([])` did not reject nonblank values. These providers are in-code configuration, so reference-loading-failure scenarios are structurally N/A. Existing persistence mapping, profile behavior, and Edit behavior remain unchanged.

Disposable focused evidence: **5 tests / 89 assertions passed** for empty option acceptance/markers and stale supplied selections; **3 tests / 816 assertions passed** for six-Company valid/required coverage, invalid dropdown selections, and Edit indicators. Total correction-focused execution: **8 tests / 905 assertions passed**. PHP lint, targeted Pint, and `git diff --check` passed.

The shared A–F assurance report and Portal mirror record Tablet at **137 applicable / 114 passed / 0 failed / 23 unverified**. Portal remains **138 applicable / 112 passed / 0 failed / 26 unverified**. Combined result: **275 applicable / 226 passed / 0 failed / 49 unverified**. Remaining Tablet cases: five Scenario C shared user/geography selectors; eight Scenario E dependency changes; and ten Scenario F database-backed providers. Overall decision remains **BLOCKED — INSUFFICIENT TEST EVIDENCE**. No retained database operations, migrations, Customer Pull/Push, production access, commit, push, or deployment occurred. State: `READY_FOR_HUMAN_REVIEW`.


## Final assurance gap sweep — stopped at first defect (2026-10-13)

The paired A–F matrix lists Portal's remaining gaps as **18 C / 8 E / 0 F** and Tablet's as **5 C / 8 E / 10 F**. Portal Region grouping C passed in disposable test `Portal Add permits a null Region when Region options are successfully empty and rejects a supplied ID` (**1 test / 5 assertions**). Null was accepted with a successfully empty option set and a supplied nonexistent Region grouping ID received a field error.

The next case, Portal Specific Region C, found a defect and halted the sweep. Test `Portal Add rejects a physical Region that is incompatible with a successfully empty Specific Region option set` sets the approved GMA Specific Region options to a successfully loaded empty list, then supplies existing physical Region ID 1. `form->getState()` returns without error, and `physical_region_id` remains 1 in Livewire state. The test fails because no field validation error is raised. No persistence call was made.

Root cause: the Specific Region field's rules omit allowed-value validation when its options are empty; the field is not dehydrated, so the incompatible nonnull selection remains in UI state. Proposed smallest correction, pending separate Human authorization: allow null for an empty option set but reject or clear a supplied nonnull value at Add validation. No production code was changed.

Updated combined assurance: Portal **138 applicable / 113 passed / 1 failed / 24 unverified**; Tablet **137 applicable / 114 passed / 0 failed / 23 unverified**; combined **275 applicable / 227 passed / 1 failed / 47 unverified**. Portal still has 16 C and 8 E unverified; Tablet remains at 5 C, 8 E, and 10 F. Test sweep stopped at the first confirmed defect. Reports are byte-identical. Portal lint, targeted Pint, and diff check passed. No retained DB operations, migrations, sync, production access, commit, push, or deployment occurred. Delivery state: `READY_FOR_HUMAN_REVIEW`.


## Portal dependent dropdown correction and stopped assurance continuation (2026-10-13)

The Specific Region Scenario C defect is corrected at the Add validation boundary. Non-null physical Region selections are checked against current applicable options even when that successfully loaded list is empty; null remains allowed for empty options. The same Add-only allowed-value rule now covers hidden `region_specific_id`, Area Cluster, Province, Municipality, and Barangay. Edit validation remains unchanged. Parent filters, required indicators, physical IDs, reference failure handling, and persistence mappings were preserved.

Focused disposable geographic regressions passed: Specific Region empty/stale **1 test / 5 assertions**; stale selections across hidden Specific Region, Area Cluster, Province, Municipality, and Barangay **1 / 11**; empty Area Cluster/null **1 / 2**; existing parent-change cases **1 / 7**. Total: **4 tests / 25 assertions passed**. Scenario C continuation also passed for empty Access/PIC options with null values (**1 / 3**) and empty Outlet Entry Detail options (**1 / 2**). Existing six-Company valid baseline passed **1 / 37**; Specific Region required indicator regression passed **1 / 2**. Final selected focused rerun passed **7 tests / 67 assertions** plus the required-indicator case **1 / 2**. PHP lint, targeted Pint, and `git diff --check` passed. The preserved Outlet Classifications C regression fails separately as documented below.

The next matrix case, Portal Outlet Classifications C, exposed a separate confirmed defect and stopped the sweep. With the configured Outlet classification list successfully empty and `tradeProfile.classifications = []`, `form->getState()` rejects with `The classifications field must have at least 1 items.` Required indicator logic sees no options, but the Add `minItems()` still requires one. The failing test is retained; no application correction was made. No Customer was inserted.

Updated matrix: Portal **138 applicable / 118 passed / 1 failed / 19 unverified**; Tablet **137 / 114 / 0 / 23**; combined **275 / 232 / 1 / 42**. Portal remaining: 11 C and 8 E; Tablet remaining: 5 C, 8 E, 10 F. Assurance decision: **FAIL — VALIDATION DEFECT FOUND**. The paired assurance reports are byte-identical. PHP lint, targeted Pint, and diff checks passed. A broad test filter that also selected the exhaustive required-omission test was interrupted before returning results and is not counted. No retained DB changes, migrations, synchronization, Customer Pull/Push, commit, push, or deployment occurred. Work remains `READY_FOR_HUMAN_REVIEW`.


## Portal conditional multi-select correction and assurance continuation (2026-10-13)

Portal Customer Add contains three multi-selects: Access (`users`), Outlet Classifications, and Outlet Working Days. The Access minimum already followed the available User options. Outlet Classifications and Working Days had an unconditional Add minimum of one although their required marks followed option availability. Both `minItems()` rules now return zero for Edit or for a successfully empty applicable option list, and one when Add options exist. Existing allowed-value rules remain active. Edit behavior, Company/profile visibility, reference failure boundaries, persistence keys, and profile mappings are unchanged. Both corrected option providers are in-code configuration; a separate runtime reference-load failure scenario is N/A for these selectors.

Focused disposable tests passed for: options available plus missing selection rejected; valid selections accepted; empty options plus `[]` accepted; invalid nonempty values rejected at actual `create()` with zero Customer inserts; and required marks matching option availability (**3 tests / 18 assertions**, plus **1 indicator test / 4 assertions**). Portal company/profile switch compatibility passed (**1 / 16**); Edit required-indicator compatibility passed (**1 / 1**).

The A–F continuation then passed the remaining Portal C cases: Outlet Conversion Program, Outlet AB and MCB annual categories, Fleet Classification and annual category, OE Entry Detail/Classification/annual category, and IB Classification/annual category (**10 tests / 10 assertions**). Portal E tests passed for Competitor Volume (**1 / 2**), Province/Municipality/Barangay reset on physical Region change (**1 / 4**), Entry Detail reset on Company profile change (**1 / 7**), stale AB and MCB annual state after Entry Detail changes (**2 / 2**), and Delivery Detail after Delivery Type becomes No (**1 / 1**). No additional Portal defect was found.

The corrected Outlet Classifications C failure is now PASS, and Working Days C is PASS. Portal matrix: **138 applicable / 138 passed / 0 failed / 0 unverified**. Tablet remains **137 applicable / 114 passed / 0 failed / 23 unverified**. Combined: **275 applicable / 252 passed / 0 failed / 23 unverified**. Overall assurance: **BLOCKED — INSUFFICIENT TEST EVIDENCE** because the Tablet matrix still has 5 C, 8 E, and 10 F cases unverified. Reports are byte-identical. PHP lint, targeted Pint, and `git diff --check` passed. No retained DB operations, migrations, synchronization, Customer Pull/Push, commit, push, or deployment occurred. Work remains `READY_FOR_HUMAN_REVIEW`.


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

## Remote reconciliation and final pre-delivery validation (2026-10-13)

Preserved the accepted uncommitted Work in verified Git stashes before reconciliation: Portal stash `065cf3a6e90778722821b5f0e8d6a146f2245814`; Tablet stash `be669becc48075d730967dfeaf77ddef17e28cbf`. Both complete Work sets were restored, and the stashes remain as recoverable backups. Original Portal `0f4ef5e` and Tablet `a2ae2be` are ancestors of the reconciled branches.

Reviewed Portal PR #19 (`4fd43fb`, merge `7521d30`) and Tablet PR #23 (`a865a39`, merge `4752cba`). Portal changes are confined to Region Type image requirements, notification documentation, and their tests. Tablet changes are confined to Salescall completion and Region Type image requirements and their tests. No incoming file overlaps Customer Create validation, Customer persistence, reference snapshots, or Customer sync contracts. Neither PR changes Customer validation or persistence, Customer reference contracts, Customer migrations, or Customer synchronization. Both local `main` branches fast-forwarded to `origin/main`; no conflict resolution or force operation was used.

Post-reconciliation disposable focused suites passed: Portal **122 tests / 2,248 assertions** across Customer Create assurance, required indicators, Tablet creation contract, Access mapping, map location automation, and Region Type image regression; Tablet **120 tests / 1,863 assertions** across Customer Add diagnostics, offline location snapshots, Region Type image, and Salescall correlation. Portal's Access test fixture now uses approved Specific Region ID 52 explicitly; the map fixture now includes its required Region Type records. These were test-fixture corrections only. PHP lint, targeted Pint, and `git diff --check` passed in both repositories. The paired assurance reports remain byte-identical; accepted totals remain Portal 138/138, Tablet 137/137, combined 275/275.

At this checkpoint both local branches equal their reviewed `origin/main` heads (`7521d305873cf12f73e29b5b239b4b09efbea163` and `4752cba567f79040ecda8bc4dec2b507b14376d4`). Terminal status remains pending until scoped commits, ordinary pushes, and post-push verification succeed. No retained database or migration operation, Customer Pull/Push, or deployment occurred.
