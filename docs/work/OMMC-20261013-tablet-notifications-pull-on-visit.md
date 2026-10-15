# OMMC-20261013-tablet-notifications-pull-on-visit

- **Title:** Tablet — Pull notifications on page visit only, not during sync pull
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `ACTIVE`
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

Pending — implementation in progress.

## Manual acceptance

Pending.

## Blockers and remaining work

- No blockers.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None.
