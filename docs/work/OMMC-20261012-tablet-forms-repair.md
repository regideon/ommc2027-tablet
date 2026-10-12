# OMMC-20261012-tablet-forms-repair

- **Title:** Tablet Forms — Repair (typed attachments), end to end
- **Repository role:** Tablet `ommc2027-tablet` (branch `15-tablet-forms-repair`).
  No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`,
  the implementation conventions, and the per-form precedents (CRR for the flat
  form + three statuses, Trade Check/Giveaway for the staged attachment flow).

## Objective

Deliver the **Repair** form end to end on the forms foundation: the
submissions list, create/edit with a flat header and typed attachments,
offline-first sync (pull + push + typed attachment attach), and deletion
reconciliation.

## Human-authorized scope

Confirmed 2026-10-12 by the Human:

- The form is the flat Portal shape: plate number, invoice number, details,
  amount, remarks, and typed attachments
  (`RepairAttachmentType`: Invoice / CM Preparations / RAC Approval / Request).
- **At least one attachment of any type is required** before saving.
- Attachments are captured as **images** (camera/gallery) through the staged
  one-file-per-request flow; the Portal's admin view displays image
  attachments only.
- The Portal's **scoped pull is kept** (own submissions), and the repair sync
  upserts the header without rebuilding attachments, so no re-queue is needed.
- Editability: only while all three statuses are `Pending` (the CRR rule; the
  approval flows are identical, and returned repairs are resubmitted through
  the Portal action).
- Implementation authorization: Human `implement` GO on 2026-10-12. Terminal
  authorization: none. No commit or push performed.

## Task-owned files

Tablet:

- `app/Services/Forms/RepairService.php` — the form's single service: scoped
  pull caching (header fields + statuses), create/update/adopt, required
  attachment enforcement, staged typed attachments, removal, deletion
  reconciliation.
- `app/Models/Forms/Repair/Repair.php` — `isEditable()`.
- `app/Services/SyncService.php` — `readRepairsWithStatus()` and the Repair
  push + typed attachment attach sections; `hasPendingChanges()` includes
  Repair and its attachments.
- `app/Enums/FormKey.php` — Repair available.
- `.gitignore` — ignores the `public/repair_attachments` preview-mirror
  directory.
- `app/Filament/Pages/RepairFormPage.php` +
  `resources/views/filament/pages/repair-form-page.blade.php`,
  `resources/views/filament/pages/forms-repair.blade.php`,
  `forms-page.blade.php` include, `FormsPage` dispatch.
- Tests: `tests/Feature/Forms/RepairWorkflowTest.php` (14 tests),
  `RepairFormPageTest.php` (7 tests), updated `FormsPageTest.php` and
  `FoundationEnumsTest.php`.
- This Work note.

No migrations, enums, or Portal files change; the foundation tables and
`RepairRules` stay as they are.

## Decisions

- **Editable only while fully Pending:** `Repair::isEditable()` requires RSM,
  SPS, and SPM `Pending`, matching the shipped CRR behavior for the identical
  approval flow.
- **Scoped pull cached by `server_id`:** a local row that is not `synced` is
  never overwritten; synced rows take the server's content, statuses, and
  `sap_doc_number` as authoritative. `salescall_id` is resolved from the
  pulled server id when the local salescall exists.
- **Attachments are local to the capturing device:** pulled attachment files
  and metadata are not cached; the detail view shows locally captured
  attachments only (same rule as Trade Check/Giveaway). Removal is
  local-only; the Portal keeps its copy.
- **Typed staged attachments:** each staged file carries its
  `RepairAttachmentType`; pushed after the parent via
  `/api/sync/forms/repair/attach` with `server_id` + `type`. Re-pushing the
  header does not rebuild attachments server-side, so existing attachment
  sync state is left untouched on update.
- **Required attachment:** at least one attachment must remain after removals
  and additions, counted across existing rows and staged files.
- **Staging action must not collide with the trait:** the page's Livewire
  staging action is named `stageTypedAttachment` because a same-named method
  would shadow the `StagesAttachments` trait's private `stageAttachment()`
  helper, breaking its internal dispatch (fixed 2026-10-12; covered by a test
  that stages through the Livewire action, not just by setting state).
- **Deletion reconciliation:** a successful pull deletes local synced repairs
  whose `server_id` is absent, removing attachment files first; unsynced rows
  and failed pulls are never purged.
- **No master data:** `programs()` returns an empty collection.

## Unrelated/pre-existing worktree changes to preserve

The worktree was clean before this Work; nothing else was modified.

## Validation evidence

- Forms suite: `php artisan test --compact tests/Feature/Forms` → **154 passed
  (918 assertions)**.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` → **436
  passed, 20 failed, 4 skipped (2739 assertions)**. All 20 failures are the
  pre-existing environment-dependent family recorded in the foundation,
  Marketing, Trade Check, and Giveaway notes (location resolver/Nominatim,
  iOS native artifacts, photo preview mirrors, first-login persistence,
  startup diagnostics, and `ExampleTest`); none are under `tests/Feature/Forms`.
  `php artisan test --compact` under the default 128M memory limit aborts
  mid-suite inside `SyncPullResponseSinkTest`'s >3MB decode, so Pest is invoked
  directly with a raised limit.
- `vendor/bin/pint --dirty --format agent` → passed (fixed two files).

## Manual acceptance

Pending. Requires the Human to confirm in runtime: the Repair rail card,
create with typed image attachments, the at-least-one-attachment rule, edit
while pending with attachment add/remove, Sync (header + typed attach), and
admin-side deletion reconciliation.

## Blockers and remaining work

- No blockers.
- Remaining: Signage, Trade Request, Training; terminal delivery awaits
  separate Human authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed. Terminal delivery awaits separate explicit Human authorization.
