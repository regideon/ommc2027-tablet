# OMMC-20261013-customer-region-grouping-correction

- **State:** READY_FOR_TERMINAL_REVIEW
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Human accepted the implementation and authorized terminalization, separate commits, and ordinary pushes to `origin/main` in Portal and Tablet. No retained database writes, Customer Pull/Push, migrations, force-push, rebase, unrelated changes, or deployment.

## Objective and accepted behavior

Correct the Customer Location forms in Portal and Tablet to show exactly two Region-related controls: `Region` is the five approved geographic `region_types` groupings; `Specific Region` is the existing physical `regions` selector. Keep `region_specific_id` internal for Customer persistence and Area Cluster filtering, using the approved grouping crosswalk when a unique approved mapping can be derived. Preserve physical locality/map behavior and stored Customer IDs. Tablet must work offline from its existing local reference snapshot.

Specific Region ID 1 is legacy/unresolved: do not assign it to Metro Manila, do not expose it as a new choice, clearly identify it on Edit, and preserve it on save. Do not change its physical parent or silently remap/clear it.

## Approved crosswalk

| Customer Region | Access Group | Specific Region IDs |
|---|---|---|
| Metro Manila | GMA | 52 |
| North Luzon | NL | 54, 61, 62, 63 |
| South Luzon | SL | 53, 64, 65 |
| Visayas | VIS | 66, 67, 68 |
| Mindanao | MIN | 55, 56, 57, 58, 59, 60 |

These assignments are explicitly approved for this Work. Specific Region 1 remains unassigned. No Region Type outside the five geographic entries is offered in the Customer Region selector.

## Implementation and constraints

Reuse `region_types`, the approved grouping crosswalk, `region_specific_id`, and existing Area Cluster parent filtering. No new mapping table or migration. Do not modify retained reference or Customer records. Keep Add/Edit state for Customer Region transient so existing persisted Customer IDs remain unchanged.

## Validation and delivery

Focused disposable SQLite coverage, PHP lint, targeted Pint, and `git diff --check` were run. No Git delivery or deployment was performed. Stop at `READY_FOR_HUMAN_REVIEW`.

## Human-requested UI correction

The Human clarified that the visible `region_specific_id` dropdown was an unnecessary third Region-related control. It has been removed from the UI; the field remains internal and continues to drive persistence and Area Cluster parent filtering. The visible physical Region control is labeled `Specific Region` and retains Province, Municipality, Barangay, and Map relationships.

## Current status

Implementation is complete and ready for Human review. The approved crosswalk is supported by the October 12 mapping activation Work and the Human decision for this Work. Specific Region ID 1 remains unassigned, is labeled legacy/unresolved on Edit, and is preserved on save. The new visible controls are `Region` (approved five `region_types`) and `Specific Region` (physical `regions`); the third visible dropdown is removed while internal `region_specific_id` persistence and Area Cluster filtering remain.

Validation: Tablet focused suites passed, 77 tests / 526 assertions, 1 skipped. Portal focused suites passed, 43 tests / 354 assertions. PHP lint passed for changed PHP files, targeted Pint passed, and `git diff --check` passed. Offline location snapshot coverage passed. The existing Tablet lookup pull already supplies `region_types` for offline form options; no snapshot contract or migration adjustment was needed. No retained database writes or Customer synchronization occurred.

No mapping ambiguity remains under the explicit Human-approved crosswalk. No reference data change, migration, commit, push, or deployment was made.

Tablet Work files: `app/Filament/Pages/CustomerCreatePage.php`, `app/Filament/Pages/CustomerEditPage.php`, `app/Services/LocationAreaClusterMappingResolver.php`, `config/customer_region_grouping.php`, `resources/views/filament/pages/customer-create-page.blade.php`, this Work note, `tests/Feature/CustomerCreatePageDiagnosticTest.php`, `tests/Feature/LocationReferenceSnapshotTest.php`, and `tests/Feature/PhilippineAddressResolverTest.php`.

## Human amendment: map state and grouped physical Region options (October 13)

Root cause: map resolution updated only fields returned as non-null. When a later point resolved a different province but not a municipality/barangay, previous child values could remain selected. The resolver now checks retained children against the resolved parents, preserves those that remain valid, and clears only values whose parent relationship is disproven. Mapped physical Region, internal Specific Region ID, Area Cluster, address, and coordinates update together; legacy ID 1 remains unchanged.

Specific Region had no high-level Region filter. Its local options now derive from the approved `region_specifics` IDs and their `region_id` links to physical `regions`, preserving the distinction among `region_types`, `region_specifics`, and physical `regions`. Edit may retain its existing saved physical option for hydration; Add presents only the selected grouping's options. Region changes leave unrelated locality state intact. The existing snapshot already includes the needed references; no offline contract change or migration is required.

Tablet map root cause: pin selection/drag called `resolveLocation()` in `setCoordinates()`, then confirmation called it again without awaiting. Pin movement now only updates coordinates and preview; `confirmLocation()` awaits one resolver call before closing the picker.

Validation for this amendment: Tablet focused suites passed, 60 tests / 482 assertions, 1 skipped; they cover all five group option sets, Region III under North Luzon, parent-filtered options, confirmation dispatch once, legacy/Edit preservation, Area Cluster hierarchy, and offline snapshot behavior. Portal focused suites passed, 37 tests / 169 assertions, including repeated map selection replacing stale locality and mapping state. The skipped test is the optional external Portal contract E2E requiring `CUSTOMER_CONTRACT_PORTAL_URL`. PHP lint, targeted Pint, and `git diff --check` passed in both repositories. No retained writes, synchronization, migration, Git delivery, or deployment occurred. No mapping ambiguity or implementation blocker remains. Delivery state: READY_FOR_HUMAN_REVIEW.

## Human amendment: preserve provider display address (October 13)

Investigation traced the truncation to `PhilippineAddressResolver`: the provider response included `display_name`, but parsing preferred `house_number` + `road` when present. The Tablet Livewire assignment used the returned resolver address; the loss occurred before form state assignment. Portal uses the same resolver and had the same behavior. The resolver now prefers provider `display_name`; if absent, it joins only address components actually returned and flags the value as partial. Tablet shows a limitation message for partial data and leaves the current Address untouched if no address data was returned. The bound `address` property receives the full selected result, and manual edits remain available. Geographic IDs are still resolved and assigned independently of address text.

Disposable focused validation: Tablet 71 tests / 533 assertions passed; 1 optional external Portal contract test skipped because `CUSTOMER_CONTRACT_PORTAL_URL` was not configured. Portal 47 tests / 207 assertions passed. Tests cover full display address, street/component-only fallback, no address components, repeated selections, manual editing, and geographic ID preservation. PHP lint, targeted Pint, and `git diff --check` passed in both repositories. No retained writes, synchronization, migration, Git delivery, or deployment occurred. Delivery state: READY_FOR_HUMAN_REVIEW.

## Final terminal delivery decision (October 13)

Human manually tested and accepted the Customer Location behavior and authorized terminalization, commits, and pushes to each configured `origin/main`. This authorization excludes retained database writes, Customer Pull/Push, migrations, force-push, rebase, unrelated changes, and deployment.

Final focused disposable validation before staging: Tablet 71 tests / 533 assertions passed, with 1 optional external Portal contract test skipped because `CUSTOMER_CONTRACT_PORTAL_URL` was not configured; Portal 54 tests / 406 assertions passed. PHP lint, targeted Pint, and diff checks passed in both worktrees. Accepted scope includes the approved five-group crosswalk, physical Region filtering, preserved locality and Area Cluster relationships, stable Customer IDs, protected legacy Specific Region 1, map replacement behavior, and complete provider display addresses. Reference relationships and the offline Tablet snapshot contract are preserved; no reference data or migration change was made.

Known limitations: map reverse geocoding requires a provider response/network; component-only or missing display addresses are reported as partial, and the existing 500-character Customer Address limit applies. Terminal delivery remains pending verification of the commit and push; do not mark TERMINAL_DELIVERED before post-push checks succeed.
