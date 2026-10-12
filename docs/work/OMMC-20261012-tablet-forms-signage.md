# OMMC-20261012-tablet-forms-signage

- **Title:** Tablet Forms — CRR Signage, end to end
- **Repository role:** Tablet `ommc2027-tablet` (branch `17-tablet-form-crrsignage`).
  No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`,
  the implementation conventions, and the per-form precedents (CRR for the
  masters + three statuses, Repair for typed staged attachments).

## Objective

Deliver **CRR Signage** end to end on the forms foundation: the submissions
list, create/edit with two master pickers, category and name, typed
attachments, offline-first sync (masters + pull + push + typed attach), and
deletion reconciliation.

## Human-authorized scope

Confirmed 2026-10-12 by the Human:

- The form is the flat Portal shape: product, type, category
  (`SignageCategory`: New / Replacement), name, sales call, and typed
  attachments (`SignageAttachmentType`: Request / Completed).
- **At least one Request attachment is required** to save; Completed is
  optional.
- Attachments are captured as **images** (camera/gallery) through the staged
  one-file-per-request flow; the Portal's admin view displays image
  attachments only.
- The Portal's **scoped pull is kept** (own submissions); the signage sync
  upserts the header without rebuilding attachments, so no re-queue is needed.
- Editability: only while all three statuses (RSM/TDH/TDS) are `Pending` —
  a TDH return routes back to the RSM, so there is no DRM revision window.
- Implementation authorization: Human `implement` GO on 2026-10-12. Terminal
  authorization: none. No commit or push performed.

## Task-owned files

Tablet:

- `app/Services/Forms/SignageService.php` — the form's single service: master
  refresh (products + types) and caching, scoped pull caching (header fields +
  statuses), create/update/adopt, Request-attachment enforcement, staged typed
  attachments, removal, deletion reconciliation.
- `app/Models/Forms/CRRSignage/Signage.php` — `isEditable()`.
- `app/Services/SyncService.php` — master reads (products/types),
  `readSignagesWithStatus()`, and the Signage push + typed attachment attach
  sections; `hasPendingChanges()` includes Signage and its attachments.
- `app/Enums/FormKey.php` — CRR Signage available.
- `.gitignore` — ignores the `public/signage_attachments` preview-mirror
  directory.
- `app/Filament/Pages/SignageFormPage.php` +
  `resources/views/filament/pages/signage-form-page.blade.php`,
  `resources/views/filament/pages/forms-signage.blade.php`,
  `forms-page.blade.php` include, `FormsPage` dispatch.
- Tests: `tests/Feature/Forms/SignageWorkflowTest.php` (14 tests),
  `SignageFormPageTest.php` (7 tests), updated `FormsPageTest.php` and
  `FoundationEnumsTest.php`.
- This Work note.

No migrations, enums, or Portal files change; the foundation tables and
`SignageRules` stay as they are.

## Decisions

- **Editable only while fully Pending:** `Signage::isEditable()` requires RSM,
  TDH, and TDS `Pending`; the TDH return loops back to the RSM stage, so the
  tablet follows the CRR/Repair rule of going read-only once anyone acts.
- **Masters are pull-only:** `programs()` refreshes both the product and type
  caches by `server_id` and returns the products for the
  `FormSubmissionService` contract; the form page reads both cached lists.
  The push sends the masters' **server ids** (`product_id`/`type_id`), which
  the Portal validates against its own tables.
- **Scoped pull cached by `server_id`:** a local row that is not `synced` is
  never overwritten; synced rows take the server's fields and statuses as
  authoritative. A pulled row whose product or type is not cached locally is
  skipped until the masters are refreshed.
- **Attachments are local to the capturing device:** pulled attachment files
  and metadata are not cached; the detail view shows locally captured
  attachments only (same rule as the other forms). Removal is local-only.
- **Typed staged attachments:** each staged file carries its
  `SignageAttachmentType`; pushed after the parent via
  `/api/sync/forms/crr-signage/attach` with `server_id` + `type`. Existing
  attachment sync state is left untouched on update (the Portal does not
  rebuild attachments).
- **Request attachment required:** at least one Request attachment must remain
  after removals and additions, counted across existing rows and staged files;
  Completed is optional.
- **Deletion reconciliation:** a successful pull deletes local synced signages
  whose `server_id` is absent, removing attachment files first; unsynced rows
  and failed pulls are never purged. Master rows are never deleted by a pull
  (CRR convention).

## Unrelated/pre-existing worktree changes to preserve

The worktree was clean before this Work; nothing else was modified.

## Validation evidence

- Forms suite: `php artisan test --compact tests/Feature/Forms` → **176 passed
  (1035 assertions)**.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` → **458
  passed, 20 failed, 4 skipped (2856 assertions)**. All 20 failures are the
  pre-existing environment-dependent family recorded in the foundation and
  prior per-form notes (location resolver/Nominatim, iOS native artifacts,
  photo preview mirrors, first-login persistence, startup diagnostics, and
  `ExampleTest`); none are under `tests/Feature/Forms`. `php artisan test
  --compact` under the default 128M memory limit aborts mid-suite inside
  `SyncPullResponseSinkTest`'s >3MB decode, so Pest is invoked directly with a
  raised limit.
- `vendor/bin/pint --dirty --format agent` → passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime: the CRR Signage rail card,
create with the product/type pickers + category + a Request attachment, the
Request-required rule, edit-while-pending add/remove, Sync (masters + header +
typed attach), and admin-side deletion reconciliation.

## Blockers and remaining work

- No blockers.
- Remaining: Trade Request, Training; terminal delivery awaits separate Human
  authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed. Terminal delivery awaits separate explicit Human authorization.
