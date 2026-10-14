# OMMC-20261013-tablet-notifications-pull-on-visit

- **Title:** Tablet — Pull notifications on page visit only, not during sync pull
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Human instruction, 2026-10-13: remove the notification
  pull from `SyncService` (the sync flow) and pull when the notifications
  page is visited.

## Objective

Stop the schedule/sync pull from pulling notifications. Notifications are
pulled when the notifications page is visited (existing
`wire:init="refresh"` → `SyncService::pullNotifications()`), so a slow or
unreachable notifications endpoint can no longer stall or kill the sync
pull request.

## Human-authorized scope

Confirmed 2026-10-13:

- Remove the `pullNotifications()` call from `SyncService::pull()`.
- Keep `pullNotifications()` in `SyncService`; the notifications page
  remains its caller (page visit keeps pulling).
- No other sync behavior changes.

Implementation authorization: Human instruction 2026-10-13. Terminal
authorization: none. No commit or push performed.

## Task-owned files

- `app/Services/SyncService.php` — remove the notifications pull from
  `pull()`.
- `tests/Feature/SyncNotificationsTest.php` — the schedule pull must not
  hit the notifications endpoint (new failing-first test).
- `tests/Feature/NotificationsPageTest.php` — page refresh pulls and
  stores new notifications (lock for the page-visit behavior).
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- The CRR/Giveaway redirect Work
  (`OMMC-20261013-tablet-salescall-crr-giveaway-redirects`) is complete in
  this worktree and awaits separate terminal authorization; its files must
  not be mixed into this Work's staging.

## Decisions

- The page-visit pull already exists (`wire:init="refresh"` on the
  notifications page); no new page wiring is needed.
- Keep `pullNotifications()` public in `SyncService`; the page and tests
  call it directly.

## Validation evidence

- TDD: the schedule-pull test was changed first to require that
  `/api/sync/pull/notifications` is never called during `pull()` and
  watched failing ("Unexpected request was recorded") before the removal.
- Focused: `php artisan test --compact
  tests/Feature/SyncNotificationsTest.php` → **6 passed (18 assertions)**;
  `tests/Feature/NotificationsPageTest.php tests/Feature/NotificationsBellTest.php`
  → **12 passed (22 assertions)** (includes the new page-visit pull test).
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` →
  **613 passed, 23 failed, 4 skipped (4685 assertions)**. The 23 failures
  are the same pre-existing set established on this branch tip
  (`c812058`): 13 documented environment-dependent failures plus 10
  post-merge `main` customer/sync failures that were reproduced on
  pristine `HEAD` during the CRR/Giveaway Work; none relate to this
  change.
- `vendor/bin/pint --dirty --format agent` → passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- Sales call / customers / login pulls no longer fetch notifications
  (the sync completes as before).
- Opening the notifications page pulls and displays new notifications.

## Blockers and remaining work

- No blockers. Implementation and validation complete; manual acceptance
  remains pending the Human's runtime check.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation only; no commits, pushes, or database
mutations performed for this Work.
