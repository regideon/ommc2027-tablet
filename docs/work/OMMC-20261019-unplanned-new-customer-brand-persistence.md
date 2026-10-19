# OMMC V2 — Unplanned New Customer Brand Persistence

- **Work ID:** `OMMC-20261019-unplanned-new-customer-brand-persistence`
- **State:** `READY_FOR_HUMAN_REVIEW`
- **Repository:** Tablet `ommc2027-tablet` only.
- **Authorization:** GO for investigation, disposable SQLite reproduction, the narrowly scoped Brand persistence correction, and scoped commit/push for QA validation. HOLD for terminalization, database repair, migrations, synchronization code changes, deployment, and unrelated changes.
- **Parent Work:** `OMMC-20261019-salescall-brand-sqlite-fk-investigation` (`TERMINAL_DELIVERED`, commit `6276750`).

## Objective

Make Brand saving work for a newly created Customer attached to an Unplanned Sales Call when Customer Push/Pull has made the persisted Sales Call customer reference use the portal ID.

## QA report

- Create a Customer, create an Unplanned Sales Call, select a Brand, and save.
- Save returns the controlled Customer-reference validation notification introduced by Work 1.
- QA reports Push, Pull, or Refresh does not resolve the failure.
- Preserve existing Sales Calls, Brand associations, Customer rows, and pending synchronization data. Do not modify sync behavior or device data.

## Confirmed root cause

- Customer creation assigns a unique negative local `customers.id`, a `local_uuid`, `server_id = null`, and `sync_status = pending`.
- Unplanned Sales Call creation loads the Customer by that ID and initially writes the same local primary key into `salescalls.customer_id`.
- Customer Push acknowledgement is handled by `CustomerAggregateReconciler` using the existing local identity and sets `server_id` and `sync_status` without changing `customers.id`.
- Schedule Pull reconciles the Customer to the existing negative local row by `local_uuid`, preserving its primary key and `server_id`. However, the schedule Pull path assigns the Portal's `salescalls.customer_id` directly into local `salescalls.customer_id` rather than translating it to the local Customer primary key.
- After Pull, `salescalls.customer_id` contains the Customer's positive server ID while the local Customer remains under its negative primary key. Work 1's validation fails because `Customer::whereKey($salescall->customer_id)` finds no Customer.
- Disposable integration coverage confirms the sequence through actual Customer Push and schedule Pull entry points. Brand and Material Group parents exist during the reproduction; they are not the rejected reference.

## Task-owned files

- `app/Filament/Pages/SalescallPage.php` — Brand-save Customer identity resolution for exactly one local primary key or unique server-ID match. Reject ambiguous local/server numeric collisions before deleting any Brand rows. Use the resolved local key for both Brand association tables.
- `app/Support/SalescallBrandDiagnostics.php` — read-only capped match count, unique local-ID hint, ambiguity flag, and local/server-ID collision flag for Customers matching the stored Sales Call reference.
- `tests/Feature/UnplannedNewCustomerBrandPersistenceTest.php` — isolated SQLite reproduction and regression coverage.
- This Work note.

## Validation and delivery

- Brand saving is fixed without modifying `SyncService` or rewriting `salescalls.customer_id`: it resolves one unambiguous Customer `server_id` match to the local Customer ID for CustomerBrand and SalescallBrand writes. This preserves existing Sales Call data and sync state.
- Missing Customers, duplicate server-ID matches, and local-ID/server-ID collisions are rejected before association deletion. The existing transaction and rollback protection remain intact.
- Combined focused suite (`SalescallBrandSaveSafetyTest`, `UnplannedNewCustomerBrandPersistenceTest`, `SalescallFormRequirementsTest`, and `RegionTypeImageRequiredTest`) — **33 passed, 239 assertions**. Includes new local Customer before Push, after Customer Push, after schedule Pull, no-op Push after Pull, existing Customer Unplanned and normal calls, unique server/local ID handling, collision rejection preserving pending associations, transactional rollback, and upstream Photo validation.
- PHP lint on changed PHP files — passed. `vendor/bin/pint --dirty --format agent` — passed. `git diff --check` — passed.
- No database writes outside disposable tests, migration, sync code change, commit, push, or deployment was made for Work 2.

## Remaining work

- QA must install/update a build containing Work 2 without uninstalling or clearing the existing app. Create a new Customer, create an Unplanned Sales Call, add a Brand, and verify Brand save succeeds before and after Push/Pull. Preserve local SQLite data and pending sync state, then share the diagnostic report and result.
- Commit and push only the four task-owned files for QA validation. Keep Work 2 open until device validation; do not terminalize it. Preserve existing local SQLite data and pending sync state during QA.


## Follow-up concern

Schedule Pull can store a portal Customer ID in local `salescalls.customer_id` while the Customer row retains a separate local primary key. Work 2 resolves that reference only when saving Brand associations. The broader Customer ID reconciliation inconsistency remains open for separate authorization; no synchronization code was changed here.
