# OMMC V2 — Unplanned Sales Call Pull Customer Reference

- **Work ID:** `OMMC-20261020-unplanned-salescall-pull-customer-reference`
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Repository:** Tablet `ommc2027-tablet` only.
- **Authorization:** Local investigation, implementation, and validation only. No commit, push, terminal delivery, device-data repair, migration, or deployment was performed.
- **Related Work:** `OMMC-20261019-unplanned-new-customer-brand-persistence` (Brand-save resolution) and `OMMC-20261019-salescall-brand-sqlite-fk-investigation` (save safety and diagnostics).

## Objective

Restore an Unplanned Sales Call created from an offline/unsynced Customer after Schedule Pull, so the Sales Call keeps a valid local Customer reference, its Brand rows survive the Pull, and the visit can be completed without the false "Save brands before submitting" blocker.

## QA report

- A Customer is created offline, then an Unplanned Sales Call is created for that Customer.
- After Schedule Pull, the Sales Call shows no Customer name (shown as `—`).
- Completing the visit reports "Save brands before submitting." even after Brands were saved.
- The existing Work 2 Brand-save fix resolves the Customer reference only while saving Brands; it does not correct the Sales Call row written by Pull.

## Confirmed root cause

1. A Customer created on the Tablet receives a negative local primary key and later receives a Portal `server_id` without changing that local key.
2. Schedule Pull writes the Portal `customer_id` from the Sales Call payload directly into `salescalls.customer_id`.
3. The local Customer still has the negative primary key, so the Sales Call's `customer` relation and push lookup cannot resolve it.
4. Pull also deletes and recreates `salescall_brands` when no local Brand rows are pending. The recreation used the unresolved Portal Customer ID, failed the local `customers.id` foreign key, and the exception was swallowed by the existing per-row safety handler.
5. The result is zero Brand rows for the visit. `salescallSubmitBlocker()` therefore returns `brands`, which produces the reported notification.

A disposable SQLite regression test reproduced both failures before the correction: `salescalls.customer_id` became `55001` instead of the local `-94001`, and the Brand row count became `0`.

## Task-owned files

- `app/Services/SyncService.php` — resolve the Portal Customer ID to the local Customer primary key while applying Schedule Pull; use the resolved ID for Sales Calls, their Brand/Category children, and Customer Notes; repair a dangling Customer reference on a pending Sales Call without overwriting other pending changes.
- `tests/Feature/UnplannedSalescallCustomerPullTest.php` — regression coverage for synced and pending Sales Calls, a Customer absent from the received Customer page, and Customer Notes.
- `tests/Feature/UnplannedNewCustomerBrandPersistenceTest.php` — updated the prior Work 2 Pull expectation to the corrected local-reference behavior.
- This Work note.

## Decisions

- The incoming Customer page is resolved first; its Portal-to-local identity map is reused for Sales Calls in the same payload.
- If the Customer is not on the received page, resolve it by the existing local `server_id` mapping.
- If the Portal ID already equals a local primary key, keep it only when there is no conflicting local `server_id` match. Ambiguous local/server identity collisions fail the Pull instead of attaching data to the wrong Customer.
- A pending Sales Call is never overwritten by Pull. Only a dangling `customer_id` is repaired to the resolved local Customer ID; all other pending fields remain untouched.
- A Sales Call Customer that cannot be resolved at all is logged and retains the previous fallback behavior instead of guessing a different Customer.

## Validation

- New regression suite: `php artisan test --compact tests/Feature/UnplannedSalescallCustomerPullTest.php` — **5 passed, 14 assertions**.
- Related suite (`UnplannedSalescallCustomerPullTest`, `UnplannedNewCustomerBrandPersistenceTest`, `SalescallBrandSaveSafetyTest`, `SyncServicePullFreshInstallTest`, `CustomerPagedPullTest`) — **59 passed, 628 assertions**.
- Broader Pull/Customer suite (`SyncPullReferenceBulkUpsertTest`, `SyncPullResponseSinkTest`, `CustomerOperationalSyncTest`) also passed.
- `php -l` on changed PHP files — passed.
- `vendor/bin/pint --dirty --format agent` — passed.
- `git diff --check` — passed.
- No device or client database was accessed or modified. Tests use disposable/in-memory SQLite.
- Pre-existing, unrelated failure confirmed on clean `HEAD` via a temporary stash: `SyncServiceSalescallPushTest` → "failed salescalls are pushed again on the next sync regardless of attempts" expects 2 requests but receives 1. It is not caused by this Work and was not changed.
- The full suite is not a clean signal in this environment: it exhausted the 128 MB PHP limit, and `CustomerCreatePageDiagnosticTest` reports unrelated Customer create-page region failures. Both are outside this Work's changed path; the focused and broader Pull/Customer suites above are green.

## Manual acceptance and remaining work

- QA must install/update a build containing this Work without uninstalling or clearing existing app data.
- Create an offline Customer, create an Unplanned Sales Call, save Brands, then Push and run Schedule Pull. Verify the call still shows the Customer, Brands remain, and the visit can be completed.
- For a device already in the broken state, run Schedule Pull once to repair the Customer reference. If the Portal has no Brand rows for that visit, save Brands once more after the Pull.
- Commit and push only the three task-owned code/test files plus this Work note after separate explicit Human terminal authorization.
