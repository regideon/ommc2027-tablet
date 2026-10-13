# Tablet Notifications — User-Filtered Form Status Updates (Design)

- **Work ID:** `OMMC-20261013-tablet-notifications`
- **Repositories:** Portal `ommc2027` (admin) and `ommc2027-tablet` (tablet)
- **Date:** 2026-10-13
- **Status:** Design revised in session (receiver reuse per Human correction); implementation not started
- **Authorization:** Local implementation and validation only. Commit, push, and
  terminal delivery require separate explicit Human authorization.

## Context

The Portal already ships a notification system built in `OMMC-20261013-*` works:

- `notification_templates` catalog (`config/notification-template.php` + stubs),
  seeded by `NotificationTemplateSeeder`.
- `App\Services\NotifierService` + `App\Jobs\Notifications\SendTemplateNotificationJob`
  + `App\Notifications\TemplatedNotification` producing Filament-compatible
  database payloads (title, body, actions, format, duration, tag).
- `App\Services\Notifications\FormNotificationService` fires on form decisions
  for the eight form modules, addressed to admin roles only
  (`NotificationReceiver::ADMIN`).
- The admin panel shows them via Filament `databaseNotifications()`.
- `App\Support\AdminRole::roles()` includes `drm`, so the tablet users
  (admin users with the DRM role) already receive these notifications; no new
  receiver, template, or `FormNotificationService` change is needed.

The tablet has no notification feature. Its `notifications` table (standard
Laravel schema) is unused, and `App\Models\User` already uses `Notifiable`.
Sync (`App\Services\SyncService`) pulls schedule, locations, and customers; it
has no notification channel. Tablets are used by DRM users who submit forms.

## Goal

Deliver each user's existing Portal notifications (form status updates) to
their tablet through sync, filtered per user. The tablet gets a topbar
notification button (bell with unread badge) and a Notifications page with
local read/unread state.

## Accepted decisions

1. **Audience:** reuse the existing `admin` notification templates and their
   role-based recipients. Tablet users are admin users with the DRM role
   (`AdminRole::roles()` includes `drm`), so there is no `tablet` receiver,
   no new template, and no `FormNotificationService` change.
2. **Per-user delivery:** the sync endpoint returns only the authenticated
   user's rows (`$request->user()->notifications()`), and the tablet stores
   rows against the local logged-in user. No extra on-device filter UI.
3. **Content:** every notification the user already has in the Portal is
   delivered; the tablet mirrors the user's admin bell.
4. **Read state:** local to the tablet only; never pushed back.
5. **UI:** topbar bell + full Notifications page; tap = mark read + expand;
   "mark all as read".

## Non-goals (deliberately deferred)

- Read-state sync back to the Portal.
- Deep links / action buttons from a notification to a record.
- Native push notifications (FCM/APNS).
- Admin announcement/broadcast composition UI.
- Local-history pruning.

## Architecture

```text
Form decision (Portal)                 Tablet (offline-first)
┌─────────────────────────┐            ┌──────────────────────────────┐
│ *ApprovalService::decide│            │ SyncService::pull()          │
│        ↓                │            │   └─ pullNotifications()     │
│ FormNotificationService │            │        ↓                     │
│        ↓                │            │ local `notifications` table  │
│ NotifierService         │            │        ↓                     │
│        ↓                │  GET       │ NotificationsPage (Livewire) │
│ notifications table ────┼──────────► │ NotificationsBell (Livewire) │
│ (notifiable = the user) │ /api/sync/ │                              │
└─────────────────────────┘ pull/     └──────────────────────────────┘
                            notifications
```

Filtering is structural: the sync endpoint queries only the authenticated
user's rows, and the tablet stores rows against the local logged-in user, so a
different user on the same device never sees them. The existing Portal
notification pipeline is unchanged; this work adds delivery and presentation.

## Portal `ommc2027` changes

### 1. New sync endpoint

Route inside the existing `SyncTokenMiddleware` group:

```php
Route::get('/pull/notifications', [SyncController::class, 'pullNotifications']);
```

Behavior:

- Auth: `tablet-api` guard via `SyncTokenMiddleware`; unauthenticated → 401.
- Query only the authenticated user's rows:
  `$request->user()->notifications()` — no client-supplied user id is accepted.
- Optional `since` (ISO 8601): return rows with
  `created_at >= since - 5 minutes` (overlap absorbs second-precision ties).
- Order by `created_at` ascending; page size fixed at 200; `page` parameter.
- Maps each row: `id`, `title` (`data.title`), `body` (`data.body`),
  `module` (`data.tag`), `created_at` (ISO 8601).

Response:

```json
{
  "notifications": [
    {
      "id": "9b1c4c1e-8b0a-4b6a-9c1e-2f6a1d0e5f11",
      "title": "CRR approved",
      "body": "CRR SC-0001 for Mercury Drug was approved by Juan Dela Cruz (RSM).",
      "module": "crr",
      "created_at": "2026-10-13T01:02:03+00:00"
    }
  ],
  "has_more": false
}
```

Rows without a title/body/module in `data` are still returned with nulls for
the missing fields; the tablet renders safe fallbacks.

## Tablet `ommc2027-tablet` changes

### 5. `SyncService::pullNotifications(): SyncResult`

Follows existing pull conventions:

1. Guard: authenticated user with `api_token`, else
   `SyncResult::fail('No API token found. Please log in first.', 'no_token')`.
2. Read watermark from `sync_states` key
   `notifications.pulled_through.{server_id}` (value:
   `{ "last_created_at": ISO8601|null, "pulled_at": ISO8601 }`).
3. Loop pages via the existing `fetchPullPayload()` helper
   (`/api/sync/pull/notifications?since=...&page=N`) until `has_more` is false.
   Any HTTP/parse failure returns `SyncResult::fail(...)`.
4. Insert rows with `insertOrIgnore` into `notifications`:
   - `id` = server UUID, `type` = `'templated-notification'`,
     `notifiable_type` = `App\Models\User`, `notifiable_id` = local user id,
   - `data` = `{"title": ..., "body": ..., "tag": module}`,
   - `read_at` = null, `created_at`/`updated_at` = server `created_at`.
   - `insertOrIgnore` guarantees re-pulls never overwrite local `read_at`.
5. Watermark = max `created_at` seen; persist via `putSyncState`.
6. Return `SyncResult::ok("Pulled {N} notifications.", N)`.

Integration: called at the end of `SyncService::pull()`. A notification failure
is logged and ignored so it can never fail the schedule pull (login and the
existing auto/manual syncs therefore refresh notifications too).

### 6. Notifications page

`App\Filament\Pages\NotificationsPage` + view
`resources/views/filament/pages/notifications-page.blade.php`, following the
existing custom-page pattern (`SettingsPage`):

- Route `/app/notifications`; `$shouldRegisterNavigation = false`; title
  "Notifications"; reached from the bell.
- Shows the current user's notifications, newest first, 20 at a time with a
  "Load more" control.
- Each row: module chip, title, body preview, relative time, unread dot. The
  chip maps the slug to a display label: `crr` → CRR, `crr-signage` → CRR
  Signage, `giveaway` → Giveaway, `marketing` → Marketing, `repair` → Repair,
  `trade-check` → Trade Check, `trade-request` → Trade Request, `training` →
  Training; unknown slugs fall back to a headline-cased form of the slug.
- **Tap:** marks read (`read_at = now()` if unread) and expands to the full
  body; tapping again collapses. Expanded state is a Livewire property.
- **Mark all as read** action for the current user.
- **Refresh on mount:** calls `pullNotifications()` (silent failure offline,
  preserving local rows). A loading state covers the pull.
- Empty state: no notifications yet.

### 7. Topbar bell

`App\Livewire\NotificationsBell` + view `resources/views/livewire/notifications-bell.blade.php`:

- Renders a bell with a red unread-count badge (hidden at zero), styled to
  match the existing topbar refresh button; links to the Notifications page.
- Refreshes the count by re-querying the local table: `wire:poll.60s` (local
  DB only, no network) and an `On('notifications-updated')` listener that the
  page dispatches after mark-read/mark-all-read.

Injection: `panels::user-menu.before` render hook (same hook as the refresh
button) renders a Blade partial that mounts the component.

*Implementation risk:* mounting a Livewire component inside a Filament render
hook is standard but must be verified early in implementation. If it conflicts
with Filament v5, the fallback is a server-rendered bell plus a small
CustomEvent bridge that updates the badge DOM identically (same UX, no
behavioral change).

## Data and scoping contracts

- Portal content: existing `admin-{module}-{event}-in-app` templates and
  role-based recipients, unchanged.
- Endpoint: `GET /api/sync/pull/notifications?since&page`, authenticated
  per-user, `{ notifications[], has_more }`.
- Tablet local `data` JSON: `{title, body, tag}`; `read_at` device-local.
- No Portal read-state write path exists or is planned in this work.

## Error handling

- Portal: no new failure modes; the existing notification flow is unchanged.
- Endpoint: invalid or absent `since` → full (unwatermarked) page 1; bad auth
  → 401.
- Tablet: pull failure → `SyncResult::fail`; `pull()` swallows it with a log;
  page refresh failure → silent, local data remains; offline → all reads from
  local DB.

## Testing

Portal (`ommc2027`):

- New `tests/Feature/Api/NotificationsPullTest.php`:
  - 401 unauthenticated;
  - returns only the caller's rows;
  - honors `since` with the five-minute overlap;
  - orders oldest first;
  - paginates at 200 rows with `has_more`;
  - maps `title`/`body`/`module` from the stored payload and returns nulls for
    payloads without them.

Tablet (`ommc2027-tablet`):

- New `tests/Feature/SyncNotificationsTest.php` (`Http::fake`): rows inserted
  and mapped to the logged-in user; second pull is idempotent and preserves
  local `read_at`; watermark advances; failure paths; `pull()` still succeeds
  when the notifications call fails.
- New `tests/Feature/NotificationsPageTest.php`: only the current user's
  notifications render; tap marks read + expands; mark all read; unread badge
  count.
- New `tests/Feature/NotificationsBellTest.php`: unread count renders; refresh
  listener updates it.

Manual acceptance: a DRM-role user receives a form notification through the
existing Portal flow (decision + queue worker) → logs in on the tablet/pulls
→ bell badge appears → page lists the item → tap marks read → mark-all clears
the badge; a second user on the same device sees only their own.

## Work tracking

- Cross-repo Work ID `OMMC-20261013-tablet-notifications`; durable notes at
  `docs/work/OMMC-20261013-tablet-notifications.md` in both repositories,
  materially equivalent per `docs/agent-workflow.md`.
- Spec: this document (tablet repo `docs/superpowers/specs/`).
- Implementation plan follows the written-spec review.
- No commit or push without separate explicit terminal authorization.
