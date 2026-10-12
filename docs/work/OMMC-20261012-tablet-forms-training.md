# OMMC-20261012-tablet-forms-training

- **Title:** Tablet Forms — Training, end to end
- **Repository role:** Tablet `ommc2027-tablet` (branch `21-tablet-forms-training`).
  No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`,
  the implementation conventions, and the per-form precedents (Trade Request
  for the masters + participant snapshot + typed attachments).

## Objective

Deliver **Training** end to end on the forms foundation: the submissions list,
create/edit with a topic (or free-text other topic), preferred date, volumes,
a participant repeater (name + position), typed attachments, offline-first
sync (masters + pull + push with the participant snapshot + typed attach), and
deletion reconciliation. This is the eighth and final form.

## Human-authorized scope

Confirmed 2026-10-12 by the Human:

- The form is the Portal shape: topic or other topic, preferred at, OMCC and
  competitor volumes, sales call, participants (`name` + `position_id`), and
  typed attachments (`TrainingAttachmentType`: Request / Completed).
- **Exactly one of `topic_id` / `other_topic` is required** — the picker
  unless "Others" is chosen, then the free text; both filled is rejected.
- **At least one participant is required**; each needs a name and a position.
- **At least one Request attachment is required**; Completed is optional.
- Attachments are captured as **images** (camera/gallery) through the staged
  one-file-per-request flow; the Portal's admin view displays image
  attachments only.
- The Portal's **scoped pull is kept** (own submissions). The Portal rebuilds
  participants on every push, so the tablet replaces its participant rows
  wholesale on edit.
- Editability: only while all three statuses (RSM/TDH/TDS) are `Pending` — a
  TDH return routes back to the RSM, so there is no DRM revision window.
- Implementation authorization: Human `1` GO on 2026-10-12. Terminal
  authorization: none. No commit or push performed.

## Task-owned files

Tablet:

- `app/Services/Forms/TrainingService.php` — the form's single service: master
  refresh (topics + positions) and caching, scoped pull caching (header +
  participant snapshot + statuses), create/update/adopt, exactly-one-topic
  enforcement, required-participant enforcement, wholesale participant
  replacement, Request-attachment enforcement, staged typed attachments,
  removal, deletion reconciliation.
- `app/Models/Forms/Training/Training.php` — `isEditable()`.
- `app/Services/SyncService.php` — master reads (topics/positions),
  `readTrainingsWithStatus()`, and the Training push (with the participant
  snapshot) + typed attachment attach sections; `hasPendingChanges()` includes
  Training and its attachments.
- `app/Enums/FormKey.php` — Training available (all eight forms live).
- `.gitignore` — ignores the `public/training_attachments` preview-mirror
  directory.
- `app/Filament/Pages/TrainingFormPage.php` +
  `resources/views/filament/pages/training-form-page.blade.php`,
  `resources/views/filament/pages/forms-training.blade.php`,
  `forms-page.blade.php` include, `FormsPage` dispatch.
- Tests: `tests/Feature/Forms/TrainingWorkflowTest.php` (17 tests),
  `TrainingFormPageTest.php` (9 tests), updated `FormsPageTest.php` and
  `FoundationEnumsTest.php`.
- This Work note.

No migrations, enums, or Portal files change; the foundation tables and
`TrainingRules` stay as they are.

## Decisions

- **Editable only while fully Pending:** `Training::isEditable()` requires RSM,
  TDH, and TDS `Pending`; the TDH return loops back to the RSM stage, so the
  tablet follows the established rule of going read-only once anyone acts.
- **Exactly one topic source:** `topic_id` XOR `other_topic`; the service
  rejects neither and both. The form page renders the picker with an
  "Others" option that reveals the free-text input.
- **Participants are a wholesale snapshot:** the Portal force-deletes and
  rebuilds participants on every push, so the tablet collects them and
  replaces them wholesale — as a **single delete + single insert** across all
  cached rows (the Trade Request query lessons applied up front; no per-row
  relation deletes, so the Query Detector stays quiet).
- **Masters are pull-only:** `programs()` refreshes the topic and position
  caches by `server_id` and returns the topics for the
  `FormSubmissionService` contract; the form page reads both cached lists.
  The push sends the masters' **server ids** (`topic_id` and each
  participant's `position_id`), which the Portal validates against its own
  tables.
- **Scoped pull is batched:** products/topics/positions/salescalls/existing
  rows resolve through one batched lookup each per pull; a pulled row whose
  masters are not cached locally is skipped until they are refreshed;
  unsynced local rows are never overwritten; synced rows take the server's
  fields, statuses, and participant snapshot as authoritative.
- **Attachments are local to the capturing device:** pulled attachment files
  and metadata are not cached; removal is local-only; each staged file carries
  its `TrainingAttachmentType` and is pushed after the parent via
  `/api/sync/forms/training/attach` with `server_id` + `type`. The deletion
  sweep eager-loads attachments.
- **Request attachment required:** at least one Request attachment must remain
  after removals and additions, counted across existing rows and staged files;
  Completed is optional.

## Unrelated/pre-existing worktree changes to preserve

The worktree was clean before this Work; nothing else was modified.

## Validation evidence

- Forms suite: `php artisan test --compact tests/Feature/Forms` → **229 passed
  (1329 assertions)**.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` → **511
  passed, 20 failed, 4 skipped (3150 assertions)**. All 20 failures are the
  pre-existing environment-dependent family recorded in the foundation and
  prior per-form notes (location resolver/Nominatim, iOS native artifacts,
  photo preview mirrors, first-login persistence, startup diagnostics, and
  `ExampleTest`); none are under `tests/Feature/Forms`. `php artisan test
  --compact` under the default 128M memory limit aborts mid-suite inside
  `SyncPullResponseSinkTest`'s >3MB decode, so Pest is invoked directly with a
  raised limit.
- `vendor/bin/pint --dirty --format agent` → passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime: the Training rail card,
create with topic/other topic, preferred date, volumes, participants, and a
Request attachment, the exactly-one-topic / participant-required /
Request-required rules, edit-while-pending participant replacement and
attachment add/remove, Sync (masters + header + participant snapshot + typed
attach), and admin-side deletion reconciliation. With this Work, all eight
forms are live on the rail.

## Blockers and remaining work

- No blockers.
- This completes the eight-form program; terminal delivery awaits separate
  Human authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed. Terminal delivery awaits separate explicit Human authorization.
