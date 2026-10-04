# OMMC-20261004-tablet-add-customer-dropdown-alignment

## Current state

`TERMINAL_DELIVERED`

## Objective

Align populated Customer single-select controls in the shared Tablet Add/Edit Customer form with the proven Sales Calls native-select interaction so native pickers display usable options on iPad.

## Affected repositories

- Tablet: `ommc2027-tablet` only.
- Portal: unchanged and outside this Work.

## Authorized scope and accepted boundary

This Work contains only the Customer single-select compatibility correction and focused regression coverage. Customer Add and shared Edit presentation use the correction; no separate Add-only view or Edit navigation exposure was introduced.

Preserve existing option sources, values, labels, ordering, conditional visibility, Customer validation, Customer Code reservation, persistence, offline and sync behavior, profile/category rules, access defaults/persistence, and geography semantics.

- Livewire remains authoritative form state; populated single-selects bind directly to the corresponding `$wire` property.
- Existing live update fields keep immediate updates and hooks; deferred fields remain deferred.
- Access, Outlet Classifications, and Working Days remain unchanged multi-selects.
- Barangay and Area Cluster remain unavailable/disabled.
- Add Expense and terminal-delivered Expense Phase 2 remain unchanged.
- No Portal, schema, reference-data sync, sync-contract, migration, database, or deployment changes.

## Deferred follow-up capability: Barangay and Area Cluster selectors

The Human requested enabling these fields, then withdrew that extension from this Work after the investigation established it requires a separate Tablet data-contract expansion. This capability is explicitly deferred to a separate future Work; that Work has not been created, and it is not a blocker for the accepted dropdown compatibility correction.

Tablet currently has no local `barangays` / `area_clusters` tables, models, relationships, reference-data ingestion, Customer ID fields, or save/hydration/sync support. Portal has canonical data and relationships (Barangay → Municipality; Area Cluster → Specific Region) and returns the lookup collections in its sync bootstrap. No cross-hierarchy mapping was inferred. Both Tablet form fields remain in their existing unavailable/disabled state.

## Worktree ownership and unrelated changes

Task-owned files:

- `resources/views/filament/pages/customer-create-page.blade.php`
- `tests/Feature/CustomerCreatePageDiagnosticTest.php`
- `docs/work/OMMC-20261004-tablet-add-customer-dropdown-alignment.md`

Preserve generated Expense attachments under `public/expense_attachments/`; they are unrelated and excluded from delivery. Portal's unrelated deleted `nativephp.json` and untracked `nativephp.lock` remain untouched.

## Implementation decisions

- Replaced `wire:model` on populated single-selects with native select `x-model` bindings directly to corresponding `$wire` properties, keeping Livewire as the sole authoritative form state.
- For `company_id`, `physical_region_id`, `province_id`, `trade.entry_detail`, and conditional Outlet `active.delivery_type`, preserve immediate update timing with `x-on:change` calling `$wire.$set(property, value, true)`. Deferred fields use direct `$wire` x-model bindings and remain deferred until the next Livewire action.
- Preserved the original option collections, literal options, conditions, labels, values, and ordering.

## Validation

- Focused Customer and related browser regression command: 11 passed, 1 skipped, 142 assertions. The existing WebKit morph test skipped because Playwright was not installed locally or in `/tmp`; no dependency was added.
- Changed PHP test file lint: passed.
- `vendor/bin/pint --dirty --format agent`: passed.
- `git diff --check`: passed.
- Customer diagnostic coverage checks populated options and `$wire` bindings on Add, shared-view options and hydrated values on Edit, live update wiring, preserved multi-select binding, independent Specific Region state, and physical dependent-field clearing. Existing focused Customer Code reservation, offline creation, Access default/persistence, and location picker regressions passed.

## Human acceptance

Human runtime acceptance was confirmed on the iOS 17.5 iPad Simulator. Human verified that a Customer dropdown picker displays its options and can be used as intended, resolving the reported native picker-with-no-visible-options defect. The accepted runtime comparison target was a known-working Sales Calls dropdown and Add Customer on the same runtime. Human acceptance applies to the original dropdown compatibility correction only; it does not include the deferred Barangay / Area Cluster capability.

## Authorization and delivery

Human authorized terminal delivery with `TERMINAL: GO`. Work state is `TERMINAL_DELIVERED`. The terminal commit includes the required Work-ID and Work-State trailers and was pushed to the existing Tablet branch `ommc-ipad-v2` upstream `origin/ommc-ipad-v2`. The resulting commit SHA and final delivery evidence are reported outside this note.

No deployment, migration, or application database mutation occurred. Automated tests used their configured `RefreshDatabase` test isolation.
