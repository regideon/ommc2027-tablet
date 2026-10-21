# OMMC V2 — Tablet Expense Push to Portal Correction

- **Work ID:** `OMMC-20261021-tablet-expense-portal-sync`
- **State:** `ACTIVE`
- **Repositories:** Tablet `ommc2027-tablet`; Portal `ommc2027` (mirrored note).
- **Authorization:** Human `GO` to investigate, reproduce with disposable local databases, implement the bounded correction, and deliver scoped commits/pushes for QA. Human `HOLD` for production deployment or database writes, migrations, and unrelated changes.
- **QA report:** Build 62 created an Expense on Tablet, but it was absent from the reported Portal Expenses list, which showed “No Expenses”. Customer Access and Person in Charge concerns are out of scope.
- **Constraints:** No production records or database operations, migrations, client reset/reinstall, broad synchronization redesign, unrelated Customer Push/Pull changes, or deployment. Preserve pending Expenses and attachments. Do not terminalize before QA verification.
- **Starting worktree:** At investigation start, both repositories were clean on `main`. The delivery worktrees contained only the approved Expense sync source, tests, and Work note changes. Portal was fast-forwarded from `origin/main` by three upstream commits (Customer CSV/import work) before delivery; Tablet was already aligned.

## Existing contract traced

- Tablet Expense creation persists a local `Expense` with a `local_uuid`, local `salescall_id`, `customer_id`, creator, `server_id = null`, and `sync_status = pending`. Local attachments are separate pending rows.
- Tablet Push posts pending or retryable failed Expenses to `/api/expenses/push` only after the related Sales Call has `server_id`. Payload includes the stable Expense `local_uuid`, `salescall_server_id`, type code, expense fields, form data/schema version, and attachment count. It does not replace local primary keys with Portal IDs.
- A successful response must include `server_id` before Tablet marks the Expense synchronized. Rejections/exceptions are recorded as failed and retryable up to the existing attempt limit; the local Expense is retained. Pending attachments are uploaded after Expense acknowledgment using the returned Portal Expense ID.
- Portal requires the authenticated API user, validates the Sales Call server ID, resolves that Sales Call, and derives its Customer and creator. `ExpenseService` validates fields and authorization, creates with the Tablet `local_uuid`, and returns the persisted ID. A retry with the same UUID reuses the existing record after checking payload consistency. The Portal expenses table has a unique local UUID.
- Portal list visibility comes from `ExpenseResource::getEloquentQuery()`: privileged roles see all rows; other roles are scoped through the owning Sales Call. The table has no default approval-status exclusion; submitted Expenses are included.

## Confirmed behavior and correction

- Before correction, `ExpenseCreatePage::saveExpense()` saved the Expense locally, showed a local-save success notice, and returned to the Sales Call. It did not invoke Push as part of submission. The Sales Call page's connectivity-triggered auto-sync was indirect and did not guarantee a push at the point of Expense submission.
- The existing Expense Push path was present and preserved local IDs, dependencies, retry state, attachment ordering, and acknowledgment handling. Connected reproduction exposed its Portal rejection: SQLite stores the Expense amount with four decimal places and Tablet sent that value verbatim; Portal's currency validator accepts at most two decimal places. The response was HTTP 422, so the Expense was recorded as failed/retryable and never marked synced.
- Bounded correction: after the local save commits, submission checks connectivity and invokes the existing `SyncService::push()`. Offline saves remain local and pending. Push serializes Expense amounts to two decimal places. Existing push sequencing waits for Customer and Sales Call server IDs; once confirmed, the existing mechanism pushes the Expense. Tablet reports synchronized, waiting, or failed based on the row's acknowledged status and server ID. It only marks synced after a successful response containing `server_id`.
- Portal continues to derive the Expense Customer, Sales Call, and creator from the validated Sales Call and authenticated user. Duplicate submission/retry uses `local_uuid`; the Portal returns the same row on retry. The Expenses list includes the accepted row for an authorized user under the existing ownership query.

## Validation

- Connected localhost test: Tablet Livewire submission against a disposable Tablet test database and isolated Portal SQLite service **passed (18 assertions)**. Verified local save, actual `/api/expenses/push`, confirmed server ID and synced status, idempotent retry returning the same row, Customer/Sales Call/creator relationships, authorized `/api/expenses/list` visibility, and no pending sync state.
- Tablet `php artisan test --compact tests/Feature/SalescallExpenseReadTest.php`: **24 passed, 230 assertions**. Covers submission push, offline pending behavior, dependency wait and later delivery, failed push/retry, amount and attachment compatibility, and Expense reads.
- Final delivery rerun of the opt-in connected test skipped because `OMMC_EXPENSE_PORTAL_URL` was unset and the disposable Portal service had been shut down; the connected localhost run above had passed with 18 assertions.
- Portal `php artisan test --compact tests/Feature/SalescallExpensesApiTest.php`: **6 passed, 43 assertions**. Covers authenticated Expense push persistence, relationship ownership, duplicate UUID acknowledgment, and list visibility.
- `SyncServiceSalescallPushTest`: **5 passed, 1 pre-existing failure**. The failing test expects an exhausted Sales Call (`sync_attempts = 3`) to be pushed again, while the implementation excludes exhausted rows (`sync_attempts < 3`). This is unrelated to Expense synchronization and remains unchanged.
- Tablet and Portal Pint passed. Changed PHP files passed `php -l`; `git diff --check` passed in both repositories. Test data and the connected service used disposable local SQLite only; no migrations or production endpoints/data were used.

## Files changed

- Tablet `app/Filament/Pages/ExpenseCreatePage.php`: invoke existing push after save and provide truthful sync feedback.
- Tablet `app/Services/SyncService.php`: format Expense currency payload to the Portal's two-decimal contract.
- Tablet `tests/Feature/SalescallExpenseReadTest.php`: submission, connectivity, dependency, failed retry, and acknowledgment coverage.
- Tablet `tests/Feature/ConnectedExpensePortalSubmissionTest.php`: opt-in localhost integration coverage (requires `OMMC_EXPENSE_PORTAL_URL`; refuses non-local hosts).
- Portal `tests/Feature/SalescallExpensesApiTest.php`: endpoint persistence, relationships, idempotency, and authorized visibility coverage.

## QA and remaining work

- Work remains open pending QA verification on a tablet build containing this correction. Verify an existing Customer/normal Sales Call, then a newly created Customer/Unplanned Sales Call after Customer confirmation; confirm offline and failed request feedback; confirm the Portal acknowledgment/server ID, relationships and creator, authorized Expenses list visibility, retry without duplicates, and attachment delivery.
- No device reset/reinstall or data repair is needed for these checks. No separate migration/deployment prerequisite has been identified.
- **Delivery status:** `READY_FOR_QA_VALIDATION`; Work remains `ACTIVE` pending QA verification. Scoped commit/push delivery is authorized; no deployment or QA sign-off is recorded.
