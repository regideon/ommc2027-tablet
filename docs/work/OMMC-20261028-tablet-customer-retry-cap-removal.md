# OMMC-20261028-tablet-customer-retry-cap-removal

- **Title:** Tablet — remove the Customer sync retry cap
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Trigger:** Failed Customers at 15 sync attempts were excluded from normal
  sync and never retried automatically.
- **Design basis:** Human direction 2026-10-28: remove the sync cap in Customer.

## Objective

1. Select every pending or failed Customer for a normal sync, regardless of
   `sync_attempts`.
2. Keep failed Customer failures retryable instead of reporting an exhausted
   automatic retry limit.
3. Apply the same no-cap selection to Customer profile, profile-attachment, and
   note sync, and to the pending-work indicator.
4. Update the Customer UI so it no longer says automatic retries are exhausted.

## Human-authorized scope

- Tablet Customer retry behavior only.
- No Portal changes, migration, or data deletion.
- Terminal authorization: **none**.

## Task-owned files

- `app/Services/Sync/Push/CustomerPushService.php` — select and claim all
  pending/failed Customers; always report failed pushes as retryable.
- `app/Services/SyncService.php` — Customer profile, profile-attachment, and
  note selectors plus `hasPendingChanges()` no longer use the three-attempt cap.
- `app/Filament/Pages/CustomerEditPage.php` — correction notification no longer
  implies exhausted automatic retries.
- `resources/views/filament/pages/customer-page.blade.php` — failed-Customer
  guidance now says it retries automatically on the next sync.
- `tests/Feature/CustomerOnlyPushTest.php` — cap-removal coverage and updated
  indicator expectations.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- The validation/message work in
  `OMMC-20261028-tablet-form-validation-messages` is committed in Tablet
  `209a8de`; it is not part of this Work.
- The cross-repository sync idempotency work in
  `OMMC-20261028-tablet-sync-idempotency` is committed in Tablet `209a8de` and
  Portal `4cd0027`; it is not part of this Work.
- Untracked `.opencode/` and `graphify-out/` are user-owned infrastructure.
- This Work's five source/test files and this note are the only uncommitted
  changes in the Tablet worktree at validation time.

## Decisions

- `sync_attempts` remains a diagnostic counter; it no longer controls Customer
  eligibility.
- Customer profile, profile-attachment, and note pushes follow the same
  retry-until-synced rule, so no customer-owned row remains silently stuck.
- Customer push failures are always retryable, except identity conflicts and
  token expiry, which retain their dedicated handling.
- The explicit `Retry Customer` action remains available above the old
  threshold as an immediate retry after a saved correction; it is no longer
  required for automatic recovery.
- The UI no longer claims automatic retries are exhausted.

## Validation evidence

- `php artisan test --compact tests/Feature/CustomerOnlyPushTest.php tests/Feature/CustomerPushServiceTest.php tests/Feature/CustomerOperationalSyncTest.php tests/Feature/CustomerCodeSyncTest.php`
  → **30 passed** (142 assertions).
- `php artisan test --compact tests/Feature/CustomerOnlyPushTest.php`
  → **22 passed** (107 assertions), including a failed Customer with 9
  attempts retried and reset by a normal push.
- `vendor/bin/pint --dirty --format agent` → passed.
- `git diff --check` → passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- A failed Customer above the old three-attempt threshold retries on the next
  sync without requiring a correction.
- The Customer push indicator stays red while failed Customers remain.
- The Customer detail no longer shows the exhausted-retries message.

## Blockers and remaining work

- No blockers.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

Local implementation and validation only. No commit, push, deployment, or
database mutation was performed.
