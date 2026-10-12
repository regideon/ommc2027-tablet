# OMMC-20261012-tablet-forms-trade-check

- **Title:** Tablet Forms — Trade Check (dynamic form), end to end
- **Repository role:** Tablet `ommc2027-tablet` (branch `7-tablet-forms-crr`)
  with paired Portal changes (`ommc2027`, branch `8-be-admin-forms-crr`).
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`
  and the prior per-form Works.

## Objective

Deliver the **Trade Check** dynamic form end to end: pull the template
definitions (groups/items) and answers, render the dynamic form, create/edit
responses offline, sync (push + attachments), and reconcile deletions.

## Human-authorized scope

Confirmed 2026-10-12: Trade Check is dynamic — pull the fields and the answers;
per-item attachments included; accept and document the Portal's shared
answer-values model; and the Portal pull is intentionally **unscoped** (all
templates, all answers/values).

## Task-owned files

Tablet:

- `app/Services/Forms/TradeCheckService.php` — the form's single service:
  definition/answer caching, local + server merge, create/update/adopt,
  `isEditableBy` guard, required-item enforcement, per-item attachments,
  deletion reconciliation.
- `app/Models/Forms/TradeCheck/TradeCheck.php` — `attachments()`, `salescall()`,
  `isEditableBy()`.
- `app/Services/SyncService.php` — `readTradeChecksWithStatus()` and the Trade
  Check push + attachment push sections; `hasPendingChanges()` includes Trade
  Check and its attachments.
- `app/Enums/FormKey.php` — Trade Check available.
- `app/Filament/Pages/FormsPage.php` + `forms-trade-check.blade.php`, and
  `TradeCheckFormPage.php` + its view (dynamic rendering by `FieldType`).
- Tests: `tests/Feature/Forms/TradeCheckWorkflowTest.php`,
  `TradeCheckFormPageTest.php`, updated `FormsPageTest.php` and
  `FoundationEnumsTest.php`.
- Staged attachments:
  `database/migrations/2026_10_12_120000_create_staged_attachments_table.php`,
  `app/Models/StagedAttachment.php`, `app/Services/StagedAttachmentService.php`,
  `app/Filament/Concerns/StagesAttachments.php`,
  `app/Console/Commands/PruneStagedAttachmentsCommand.php`, and
  `tests/Feature/StagedAttachmentTest.php`.
- Server user mapping:
  `database/migrations/2026_10_12_130000_add_server_id_to_users_table.php`,
  `app/Models/User.php`, `app/Services/SyncService.php` (`refreshToken`), and
  `app/Console/Commands/SyncLoginCommand.php`.

Portal:

- `app/Http/Controllers/Api/Forms/TradeCheckController.php` — `pull` returns all
  templates with all answers and the item answer values (unscoped).
- `app/Http/Resources/Api/Forms/TradeCheckResource.php` — each item includes
  `answer`.
- `tests/Feature/Api/FormSyncTest.php` — the Trade Check pull test updated to the
  unscoped contract (all templates, all answers, values).

## Decisions

- **Unscoped Portal pull (Human-requested):** every template plus all answer
  rows/values are returned. The tablet caches definitions for all templates,
  but stores only the logged-in user's answer rows because local
  `trade_check_answers.submitted_by` is a `users` foreign key; the global item
  values are still available. The tablet lists the logged-in user's submissions.
- **Single-owner template caveat (accepted):** `trade_check_answer_items` has no
  `submitted_by`, so answer values are shared per template item and a push
  force-recreates groups/items (cascading everyone's answers). Documented; the
  tablet follows the contract.
- **Editability:** editable only while every one of the user's answer rows has
  RSM and TDH `Pending`.
- **Required items:** enforced locally before save.
- **Attachments:** per item, pushed after the parent with `item_ref` = the item
  name.
- **Staged attachments (2026-10-12):** captures/uploads are staged **one file
  per request** (`staged_attachments` table, `StagedAttachmentService`, a
  `StagesAttachments` Livewire trait) and the form holds only staging ids, so a
  save never carries image bytes and each request stays under the PHP
  upload limits (`post_max_size` 8M / `upload_max_filesize` 2M). Native captures
  stage straight from the device path (no base64 round-trip); on save the
  service moves each staged file into the form's attachment table. A
  `staged-attachments:prune` command removes uncommitted staging. CRR/Expense
  still use the inline-payload flow and can migrate later.
- **Server user mapping (2026-10-12):** the Portal identifies a submitter by its
  own user id, so the tablet now stores `users.server_id` from the
  `tablet-login` response (`refreshToken` / `sync:login`) and matches the
  Trade Check pull's `answers.submitted_by` against it. Previously it compared
  the Portal id to the local user id, so admin status changes were never
  cached. Existing devices must log in again to populate `server_id`.
- **Attachment replacement (2026-10-12):** the edit form hydrates an item's
  existing local attachments with previews; removing one passes
  `remove_attachments` and deletes the row + local file (local-only; the Portal
  keeps its copy). The detail view renders image thumbnails.
- **Form URL param (2026-10-12):** `FormsPage::$selectedForm` uses
  `#[Url(as: 'form', keep: true)]`, so switching forms via the rail updates the
  URL (e.g. `?form=trade-check`), the param is always present (initially
  `?form=crr`), and save/cancel redirects return to the originating form.
- **Deletion reconciliation:** on a successful pull, local templates whose
  `server_id` is absent are deleted (children cascade; attachment files
  removed).
- **Create uses the first template (2026-10-12):** the **Create Trade Check**
  button opens the form already rendering `TradeCheck::first()`'s fields — no
  template-selection step, since there is one template. `?template=` still
  targets a specific template. The list also shows every template
  ("Not submitted" + Start) for visibility.

## Validation evidence

- Portal `FormSyncTest`: **32 passed (178 assertions)**; Pint passed.
- Tablet forms suite: **102 passed (650 assertions)**.
- Tablet full suite: **377 passed, 16 failed, 4 skipped (2444 assertions)**; the
  16 failures are the pre-existing environment-dependent failures (no forms
  failures).
- Pint passed on all changed paths in both repositories.

## Manual acceptance

Pending. Requires the Human to confirm the Trade Check card, template picker,
dynamic rendering by field type, per-item attachments, edit-while-pending,
Sync, and admin-side deletion reconciliation in runtime.

## Blockers and remaining work

- No blockers.
- Remaining: Giveaway, Repair, Signage, Trade Request, Training; terminal
  delivery awaits separate Human authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed.
