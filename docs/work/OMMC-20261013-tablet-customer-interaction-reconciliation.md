# OMMC V2 — Tablet Customer Interaction Reconciliation

- **Work ID:** `OMMC-20261013-tablet-customer-interaction-reconciliation`
- **Repository:** `ommc2027-tablet`
- **Reference repository:** `ommc2027` (read-only inspection)
- **Work state:** `TERMINAL_DELIVERED`
- **Delivery state:** `TERMINAL_DELIVERED`
- **Human authorization:** Bounded Tablet implementation and disposable validation were authorized. Human has now explicitly authorized scoped commit and push of these eight Customer Interaction files. Deployment, retained database operations, migrations, Customer Pull/Push, production access, Portal application changes, and unrelated changes remain unauthorized.
- **Preserved baseline:** Customer Create Required Fields Validation remains terminal delivered; Portal 138/138 and Tablet 137/137 from that separate Work. The current correction does not change the accepted Create completeness contract.
- **Retained operations:** None. Tests use `RefreshDatabase` disposable databases. No retained database operation, migration, synchronization, production access, or deployment occurred. The scoped commit and push are recorded below.

## Track A — map selection reconciliation

### Root cause

`CustomerCreatePage::resolveLocation()` assigned resolver output directly to Livewire state and used null-coalescing fallback to retain the previous Province, Municipality, and Barangay when the latest response omitted those components. Its Municipality check only compared Province when one was selected, and did not consistently verify the Municipality against the newly resolved physical Region. A second map response could therefore move the physical Region while leaving an unrelated old child in the form. This is a resolver-to-form reconciliation defect; the update did not depend on a missing Livewire callback.

The Tablet snapshot permits nullable `municipalities.region_id` and `municipalities.province_id`. A Municipality with null physical Region is now retained only when its selected Province relationship is valid and that Province belongs to the final physical Region. Without that proof, the Municipality is cleared. The dropdown and create validator use the same nullable-Region rule. Parent changes continue to invalidate children through the existing manual form callbacks.

Each successful selection now determines the final physical Region from the provider match or the resolved Province/Municipality reference relationships. Retained Province, Municipality, Barangay, Specific Region, and Area Cluster values are checked against their actual parents. An incompatible value is cleared. Specific Region grouping is derived only from the approved crosswalk; a unique approved mapping in the final physical Region can hydrate the grouping and Specific Region. Address is replaced when the provider returns a value; latitude and longitude are always replaced with the confirmed coordinates. If the provider omits an address, existing manual address text remains, consistent with the existing partial-result behavior.

### Tests and evidence

`CustomerInteractionReconciliationTest` uses disposable synthetic references and sequential mocked provider responses. It covers first and repeated picks, a changed physical Region with omitted Province/Municipality, stale Province/Municipality/Barangay clearing, address and coordinate replacement, an approved unique grouping, a nullable Municipality Region proven through its Province, and rejection when Province and Municipality parents conflict. The exact Human-observed Valenzuela/Lingunan case was not claimed as reproduced: no matching reference fixture exists in repository files, and no retained/device snapshot was read. The nullable relationship test is synthetic contract evidence, not evidence about Valenzuela.

An Add-path regression submits valid Create fields with a Municipality and Barangay belonging to a different Region. The actual `saveCustomer()` path rejects `municipality_id` and inserts no Customer. Existing tests still cover invalid physical Region/Area Cluster state and map-to-cluster assignment.

### Portal parity

Portal's `PhilippineAddressResolver.php` is byte-identical to Tablet's. Portal's `ResolvesCustomerLocation` uses the same prior-value fallback and only conditionally verifies Municipality against Province; it has the same repeated-selection risk. Portal application code was not changed, as required. This parity finding should be considered for a separate Portal Work item.

## Track B — Customer Details

### Root cause and correction

The constrained eager load for the `users` many-to-many relationship selected unqualified `id` while joining `users` to `customer_user`, both of which expose `id`. SQLite reproduced `ambiguous column name: id`. The selected User columns now use `users.*` qualification, including the optional `last_name` and `rsm_id` fields. Existing eager-loaded Customer relationships and detail content remain in place.

The same active Customer visibility query now backs both list rendering and detail retrieval. It preserves the existing role rules: `rsm_approver` sees all active Customers; `rsm` and `drm_approver` see Customers assigned to their DRMs plus their pulled scopes; other users see their own assigned and pulled Customers. Direct out-of-scope Livewire detail requests are treated as not found and do not disclose a Customer or raise a load-failure notification. No authorization model or sync behavior was added.

`viewCustomer()` now has a loading-state guard, resets it in `finally`, and the list button disables while that action is in flight. Genuine failures still use the existing generic notification, UUID reference, and privacy-limited error log. An existing injected failure regression confirms that logging/reporting remains active.

### Tests and evidence

`CustomerPageDetailsTest` passes all four existing tests: full detail plus User relationship hydration, optional relationships, large IDs, and controlled reporting of genuine failures. `CustomerInteractionReconciliationTest` additionally verifies an authorized DRM can open an assigned Customer, an out-of-scope Customer is inaccessible through a direct request, the list uses the same scope, and repeated in-flight requests are ignored with the UI button disabled.

## Legacy map-suite failure classification

The four previously reported failures were reviewed individually:

1. `CustomerMapLocationAutomationTest — Customer Add persists map-derived location and manual Access through its sync path`: its old Add fixture omits fields required by the accepted Customer Create contract and fails before redirect. The initial broad focused command this turn included the case; validation stopped it before Customer insertion or the Push call. It does not demonstrate a location mapping regression. It was excluded from final focused runs.
2. `CustomerLocationMapTest — tablet resolveLocation fills coordinates, address and hierarchy`: expected the former street-only address. The accepted resolver prefers the provider display address (`123 Roxas St, Angeles, Pampanga`); the stale assertion does not match current behavior.
3. `CustomerLocationMapTest — tablet resolveLocation resolves directly from passed coordinates`: same outdated street-only expectation.
4. `CustomerLocationSaveTest — a location resolved from the map can be saved on the create page`: its old Add fixture omits fields required by the accepted completeness contract. This successful-save test was not rerun; no sync path was run.

No production rules were weakened to satisfy obsolete fixtures. The focused new map test explicitly verifies that repeated selections replace a prior map-derived address.

## Files changed

- `app/Filament/Pages/CustomerCreatePage.php` — map result reconciliation, approved mapping validation, nullable locality relationship validation.
- `app/Filament/Pages/CustomerPage.php` — qualified User eager-load columns, shared list/detail visibility scope, in-flight guard.
- `resources/views/filament/pages/customer-create-page.blade.php` — include nullable-Region Municipality only when the selected Province proves its physical parent.
- `resources/views/filament/pages/customer-page.blade.php` — disable Customer detail button while the request is loading.
- `tests/Feature/CustomerInteractionReconciliationTest.php` — new disposable map/detail regressions.
- `tests/Feature/CustomerCreatePageDiagnosticTest.php` — actual Add-save rejection for stale Municipality/Barangay with no insertion.
- `tests/Feature/CustomerMapLocationAutomationTest.php` — complete the map resolver fixture with the five required geographic grouping reference records.
- `docs/work/OMMC-20261013-tablet-customer-interaction-reconciliation.md` — this Work record.

No Portal files were changed.

## Validation

- Focused combined run: **19 tests passed, 135 assertions**. Covered the new repeated-map and detail regressions, existing detail hydration/failure tests, existing map cluster/Access/Edit behaviors, and stale geographic Create validation. Successful Customer Add sync-path tests were excluded from the final run.
- PHP lint: passed for both changed PHP application files and both changed PHP test files.
- Targeted Pint: passed after formatting the two flagged files.
- `git diff --check`: passed.
- Tests used disposable database fixtures. No retained database operation, migration, Customer Pull/Push request, or production access occurred. The legacy map Add test was accidentally included once in an earlier broad command; its strict validation failed before the Customer insert and before SyncService was invoked. All final runs excluded that case.

## Limitations and readiness

- The exact Valenzuela/Lingunan relationship values remain unverified against an authoritative device snapshot.
- Portal has the equivalent map reconciliation pattern and needs separate authorization for a correction.
- In-flight protection is the requested bounded UI/action guard; this does not introduce a cross-request distributed lock.
- Legacy successful-save fixtures were classified but not refreshed because doing so would run the Customer Push path, outside this Work's boundary.
- Customer Create validation baseline remains unchanged.

**Recommendation:** `PASS — BOUNDED CORRECTION VALIDATED`. The eight files listed above are the complete authorized delivery scope. No synchronization, migration, or deployment is included.

## Terminal delivery reconciliation

- **Human decision:** Scoped Customer Interaction cleanup, commit, and push authorized on 2026-10-13.
- **Branch:** `main`; immediately before delivery, local `HEAD` and fetched `origin/main` both resolved to `fb7606034495af378c4a777f70c7a9ffcf7192a7`.
- **Scope review:** All eight remaining paths match this Work's implementation, regression tests, and note. The previously preserved Customer Sync Packages A/B/C files are already upstream or otherwise excluded; no SyncService, identity resolver, notification, or Sales Call changes are staged for this delivery.
- **Current focused validation:** `CustomerInteractionReconciliationTest.php` — 4 passed / 45 assertions; `CustomerPageDetailsTest.php` — 4 passed / 40 assertions; selected applicable `CustomerMapLocationAutomationTest.php` cases — 8 passed / 35 assertions; stale Municipality/Barangay Add validation regression — 1 passed / 4 assertions. Total: **17 passed / 124 assertions**.
- **Static checks:** PHP lint passed for the five changed PHP files; targeted Pint passed; staged and unstaged `git diff --check` passed.
- **Implementation commit:** `1cbb2c6ba22c285ee321ff4659ab9271643859a2` (`Deliver Tablet Customer interaction reconciliation`).
- **Remote delivery:** Pushed to `origin/main`; post-push fetch confirmed local `HEAD` and `origin/main` both equal `1cbb2c6ba22c285ee321ff4659ab9271643859a2`.
- **Final state:** `TERMINAL_DELIVERED`. No database migration, Customer synchronization, or deployment was run.
