# OMMC-20261009-tablet-forms-crr

- **Title:** Tablet Forms module — CRR first, extensible shell
- **Repository role:** Tablet `ommc2027-tablet` (branch `7-tablet-forms-crr`);
  paired with Portal `ommc2027` (branch `8-be-admin-forms-crr`).
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design spec:** `docs/superpowers/specs/2026-10-09-tablet-forms-crr-design.md`
- **Sequencing:** First per-form Work, built on
  `OMMC-20261011-tablet-forms-foundation`.

## Objective

Add the tablet **Forms** module (navigation, eight-card rail, CRR submissions
list, standalone create with an optional Sales Call link, edit while pending,
attachments) end to end and offline-first, using the existing Portal CRR sync
API. Other forms appear as disabled "Coming soon" cards.

## Human-authorized scope

Approved 2026-10-11: CRR first with an extensible shell; standalone create with
optional Sales Call; edit only while all three statuses are Pending; attachments
included; pulled server data is never validated (validation applies to user
input only); the Portal CRR `pull` exposes attachment URLs.

Implementation authorization: Human `implement` on 2026-10-11. Terminal
authorization: none. No commit or push performed.

## Affected repositories

- Tablet `ommc2027-tablet` (primary).
- Portal `ommc2027` (CRR pull attachment URL mapping).

## Task-owned files (implemented)

Tablet:

- `app/Services/Forms/CrrService.php` — the CRR form's single service: program
  cache, local + server submission merge, create/update/adopt, validation, and
  attachments (merged from the former `CrrReadService` + `LocalCrrCreationService`).
- `app/Services/SyncService.php` — CRR/CRR-attachment read, push, and
  `hasPendingChanges`.
- `app/Models/Forms/CRR/Crr.php` — `isEditable()`.
- `app/Filament/Pages/FormsPage.php`, `app/Filament/Pages/CrrFormPage.php` and
  their Blades.
- Deleted the empty `CrrPage` stub and its Blade.
- `tests/Feature/Forms/{CrrWorkflowTest,FormsPageTest,CrrFormPageTest}.php`.

Portal:

- `app/Http/Controllers/Api/Forms/CrrController.php` — normalized attachment
  payload with `url`.
- `tests/Feature/Api/FormSyncTest.php`.

## Unrelated/pre-existing worktree changes to preserve

The tablet and Portal worktrees were clean before this Work. Nothing else was
modified.

## Significance / decisions log

- Server submissions are read live and merged over local rows by `server_id`;
  only locally created (or adopted) CRRs persist.
- An editable pulled server CRR is **adopted** into the local table before
  editing so the update can push back its existing `server_id`.
- `amount` is required, numeric, `> 0`, at most two decimals locally (the Portal
  remains the final validator).
- The Portal maps attachments in the controller (no `$appends` on the shared
  `UploadedFile` model) so unrelated serializations are untouched.
- **UI (revised 2026-10-11):** the Forms page mirrors the Expense reader's
  flexed split — the form names are a permanent vertical **left sidebar**
  (never a horizontal bar), the selected form's submissions render as a
  vertical list (not a table) beside it, and the detail pane is on the right;
  scroll is contained per pane and the list/detail pair collapses to a
  Back-navigable single pane on narrow widths.
- **Program-load diagnostics (2026-10-11):** `CrrReadService::programReadFailed()`
  exposes a failed master-data pull and `CrrFormPage` shows a visible warning
  instead of a silent empty program dropdown. Root cause of the observed empty
  dropdown was an unauthenticated pull (missing/blank `api_token` → HTTP 401),
  not a code defect; a valid token returns all programs.
- **DRM test account (2026-10-11):** the DRM user is seeded on the **Portal**
  (`ommc2027`) — not the tablet — so `tablet-login` can mint a server-valid
  token. Log in as `drm@example.com` / `password` (see the Portal Work note).
  A tablet-side seeder was added and then removed per Human direction.
- **Push trigger (2026-10-11):** the Forms module previously never called
  `SyncService::push()`, so saved CRRs stayed `pending`. `FormsPage::syncNow()`
  and a Sync button in the submissions header now push pending changes and
  reload the list. Saving a CRR still stores locally (offline-first); the user
  syncs explicitly. CLI `sync:push` can target a stale-token user when run
  without an authenticated session — the in-app path uses the logged-in user.
- **Deletion reconciliation (2026-10-12):** after a **successful** CRR pull,
  local CRRs that have a `server_id` absent from the response are deleted
  (row + attachment rows + local/preview files) — this propagates admin-side
  soft deletes, which the pull simply omits. Local unsynced rows (no
  `server_id`) and failed pulls are never purged. The CRR pull is unpaginated
  and scoped to the user's own submissions, so absence means deleted.

## Validation evidence

- Tablet forms suite: `php artisan test --compact tests/Feature/Forms` →
  **64 passed (455 assertions)** (re-run after Pint).
- Tablet full suite: **252 passed, 16 failed, 3 skipped (1535 assertions)**. The
  16 failures are the same pre-existing, unrelated failures proven on the clean
  baseline in the foundation Work.
- Tablet Pint on the changed/new paths → exit 0.
- Portal `FormSyncTest`: **23 passed (92 assertions)**; Portal Pint passed.
- `php -l` clean on the new/changed PHP files; `git diff --check` clean.

## Manual acceptance

Pending. No physical-device/runtime run was performed; requires the Human to
confirm the rail/list/create/edit/attachment flow and the offline
`Pending Sync` behavior on the tablet, and the attachment preview from a pulled
CRR.

## Blockers and remaining work

- No blockers.
- Remaining: the other seven forms (Marketing, Trade Check, Giveaway, Repair,
  Signage, Trade Request, Training) as their own Works; terminal delivery awaits
  separate Human authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed.
