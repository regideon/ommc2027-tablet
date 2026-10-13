# OMMC-20261013-tablet-notifications

- **State:** TERMINAL_DELIVERED
- **Repositories:** Tablet `ommc2027-tablet` and Portal `ommc2027`
- **Authorization:** Human authorized local implementation and validation of per-user tablet notifications: the tablet pulls the authenticated user's Portal notifications into the local app, showing a topbar bell with unread badge and a Notifications page with local read state. Terminal delivery was authorized on 2026-10-13 ("Push both branches").

## Objective and accepted decisions

Deliver each user's existing Portal notifications (form status updates) to their tablet through sync, filtered per user. Per Human correction, there is no `tablet` receiver: tablet users are admin users with the DRM role and the existing `admin` templates with role-based recipients (which include `drm`) already notify them.

- Portal: new user-scoped sync pull endpoint only (`GET /api/sync/pull/notifications`, validated by `App\Http\Requests\Api\Systems\NotificationRequest`); notification catalog, `FormNotificationService`, and seeder are untouched.
- Tablet: incremental watermark pull into the existing `notifications` table (`insertOrIgnore`, local read state), a custom Filament Notifications page, and a topbar Livewire bell with an unread badge.
- Notification rows are returned oldest-first 200 per page; `since` (with a five-minute overlap) advances a per-user watermark.

## Constraints

- No schema or dependency changes; reuse the existing notifications tables and sync conventions.
- Notifications must never break a sync or a page render; endpoint/pull failures degrade silently.
- Tablet read state is local; re-pulls must never overwrite `read_at`.
- Filtering is structural: the endpoint returns only the caller's rows; the tablet stores rows against the local logged-in user.

## Task-owned files

- Portal: `routes/api.php`, `app/Http/Controllers/Api/SyncController.php` (`pullNotifications()`), `app/Http/Requests/Api/Systems/NotificationRequest.php`, `tests/Feature/Api/NotificationsPullTest.php`
- Tablet: `app/Services/SyncService.php`, `app/Filament/Pages/NotificationsPage.php`, `app/Livewire/NotificationsBell.php`, `resources/views/filament/pages/notifications-page.blade.php`, `resources/views/livewire/notifications-bell.blade.php`, `resources/views/filament/partials/notifications-bell.blade.php`, `app/Providers/Filament/SaleshubPanelProvider.php`, `resources/views/filament/pages/settings-page.blade.php`, `tests/Feature/SyncNotificationsTest.php`, `tests/Feature/NotificationsPageTest.php`, `tests/Feature/NotificationsBellTest.php`, `tests/Feature/SyncPullResponseSinkTest.php` (notifications stub), `tests/Feature/CustomerPagedPullTest.php` (pull request-set assertions)
- Spec: `docs/superpowers/specs/2026-10-13-tablet-notifications-design.md` (tablet repo)
- Plan: `docs/superpowers/plans/2026-10-13-tablet-notifications.md` (tablet repo)

## Validation

- Focused: `--filter=SyncNotifications` — 6 passed (18 assertions); `--filter=NotificationsPage` — 7 passed (13); `--filter=NotificationsBell` — 4 passed (7); pull regressions `--filter="SyncServicePullFreshInstall|SyncPullResponseSink"` — 4 passed; `CustomerPagedPullTest` salescall-pull request-set test updated and passing.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` — 529 passed, 20 failed, 4 skipped (3194 assertions). All 20 failures are the pre-existing environment-dependent family recorded in `OMMC-20261012-tablet-forms-training` (location resolver/Nominatim, iOS native artifacts, photo preview mirrors, first-login persistence, startup diagnostics, `ExampleTest`); none are in notification areas. The default **128M** `php artisan test` aborts mid-suite inside `SyncPullResponseSinkTest` — pre-existing; Pest is invoked directly with a raised limit (same command as the prior Work).
- Pint `--dirty` — passed.
- Final review (fresh-context subagent): no Critical findings; the two Important findings were fixed with RED→GREEN tests (portal pagination tiebreaker; `CustomerPagedPullTest` request-set assertions now genuinely enforced via `Http::recorded` counts); six Minor findings deferred by design and listed in the final report.

## Manual acceptance

Pending Human review: on a device or local run, confirm the topbar bell badge appears for the logged-in user when notifications exist, the page lists them newest-first with tap-to-read/expand and mark-all-read, and offline behavior keeps local rows visible. Confirm a second user on the same device sees only their own notifications.

## Delivery evidence

- Terminal authorization: Human, 2026-10-13 ("Push both branches").
- Tablet branch `21-tablet-forms-training`, work commit `beb2aa2` (`feat(notifications): add per-user notifications with bell and page`).
- Portal branch `18-fixes-requiring-image-for-specific-region-type`, work commit `d27448a` (`feat(sync): add per-user notifications pull endpoint`).
- Delivery record: the `docs(work)` commit on the same branch (hash reported in the delivery report).

## Blockers and remaining work

None. Terminal delivery completed 2026-10-13.
