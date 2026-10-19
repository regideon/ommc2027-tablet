# OMMC V2 — Sales Call Brand SQLite FK safety and diagnostics

- **Work ID:** `OMMC-20261019-salescall-brand-sqlite-fk-investigation`
- **State:** `TERMINAL_DELIVERED`
- **Repository:** Tablet `ommc2027-tablet` only.
- **Authorization:** GO for terminalizing this Work's save-safety, read-only diagnostics, tests, and upstream reconciliation. HOLD for production deployment, database repair, migrations, and synchronization changes. The remaining Customer reference issue is tracked separately under `OMMC-20261019-unplanned-new-customer-brand-persistence`.
- **Branch:** `main`, based on upstream `27b8c9a` (PR #38 merged).

## Objective

Make Brand replacement atomic and validate local SQLite references before deleting existing associations. Provide a bounded, copyable on-device report for the currently selected Sales Call and Brand form values without customer personal data or database export.

## Task-owned files

- `app/Filament/Pages/SalescallPage.php` — transaction, local reference validation, safe failure notification/logging, and diagnostics action.
- `app/Support/SalescallBrandDiagnostics.php` — read-only build, schema, migration, FK, dynamic reference, and lookup-status report.
- `resources/views/filament/pages/salescall-page.blade.php` — copyable diagnostic report entry point in the Brands tab, wired to current form state.
- `tests/Feature/SalescallBrandSaveSafetyTest.php` — disposable SQLite coverage using actual migration constraints, Unplanned calls, negative local IDs, read-only query assertions, and an insert-failure trigger.
- This Work note.

## Findings and decisions

- QA reports that the new build no longer produces the original unhandled SQLite FK exception. Saving now returns a controlled validation failure. QA attributes the remaining failure to the Customer reference; the exact local-vs-server ID mechanism remains under Work 2.
- QA reports that the transactional save protection preserves previously saved Brand associations.
- The four `salescall_brands` parents are Sales Calls, Customers, Material Groups, and Brands. The selected Brand must also belong to the selected Material Group.
- Resolve submitted IDs only against local primary keys. Reject missing/mismatched selected references before deleting any existing Sales Call or Customer Brand associations.
- Keep existing replacement behavior on successful save. Put both association tables' delete/reinsert operations inside one transaction so a failure restores prior pending rows and all other prior associations.
- Report an actionable error to the user and write a redacted diagnostic log entry with a reference token and local reference identifiers; do not log customer names or contact fields.
- Device report uses the open Sales Call's local ID and stored customer_id, and the live Brand form's selected local IDs. It reports customer server_id when available, app version/build, SQLite schema version, relevant migration batch state, actual `salescall_brands` FK definitions, at most 25 FK violations, selected parent existence, Brand/group match, and lookup sync metadata when the schema has it. It does not export the database or mutate sync state.
- The UI exposes “Collect read-only Brand diagnostics” in the Brands panel. It renders a bounded, selectable text report for copying; the report omits customer name and contact details.
- Upstream reconciliation fetched two commits: `f646cc8` (`Adding validation for all Photo to be required`) and `27b8c9a` (merge of PR #38). PR #38 is merged: the merge commit is present on fetched `origin/main` and names PR #38.
- Upstream overlaps the Sales Call page, Sales Call Blade view, and Photo validation tests. The upstream `allPhotoTypesCovered()` completion rule and `photosComplete` UI behavior are retained. The Brand save overlap was resolved by retaining the dynamic reference validation/log context alongside the upstream Photo changes.

### Root-cause assessment

- Any of the four declared `salescall_brands` foreign keys can cause the reported SQLite exception: missing `salescalls.id`, `customers.id`, `material_groups.id`, or `brands.id`. A present Brand linked to a different Material Group is a separate invalid selection and is now rejected before insert.
- The repository sync path upserts Material Groups and Brands before it recreates Sales Call Brand rows. That ordering makes a normal complete pull succeed, but an omitted/incomplete lookup payload, interrupted/older synchronization, stale form state, or a device schema/migration difference can still leave a local reference absent. The existing pull code also catches incoming Sales Call Brand insert errors, so successful UI operation on another device does not prove identical local data.
- Lookup tables have no sync-status columns. The diagnostic reports that this status is unavailable instead of inferring sync completion. Device data and migration state are still required to distinguish missing lookup data from schema or stale-reference causes.

## Validation and manual acceptance

- After upstream reconciliation, `php artisan test --compact tests/Feature/SalescallBrandSaveSafetyTest.php tests/Feature/SalescallFormRequirementsTest.php tests/Feature/RegionTypeImageRequiredTest.php` — **28 passed, 182 assertions**. This includes complete/missing references, normal and Unplanned calls, negative local Customer ID, server/local ID distinction, mismatch detection, bounded diagnostics, diagnostic read-only SQL, transactional rollback after a trigger-induced insert failure, successful replacement, and upstream Photo coverage requirements.
- `php -l` on changed PHP files — passed. `vendor/bin/pint --dirty --format agent` — passed. `git diff --check` — passed. Graphify artifacts and command are not present, so graph update was unavailable.
- Focused disposable SQLite tests cover the accepted save-safety and diagnostics scope. Device-specific diagnosis remains pending until QA shares the dynamic report; that customer-reference investigation is separated into Work 2.
- No client database was accessed or modified. No repair, migration, sync modification, or deployment was performed.

## Remaining work

- The Customer reference problem remains unresolved and is not included in this terminalized scope. Work 2 will inspect local/server ID consistency and determine the smallest supported correction without migrations or broad sync changes.
- QA's reported controlled validation failure confirms the safety boundary is working, but the customer-reference rejection itself is not fixed in Work 1.
- No client database was accessed or modified. No repair, migration, sync modification, or deployment was performed.
