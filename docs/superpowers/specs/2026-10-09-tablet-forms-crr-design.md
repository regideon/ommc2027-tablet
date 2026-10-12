# Tablet Forms — CRR First, Extensible Shell — Design

- **Date:** 2026-10-09 (revised 2026-10-11)
- **Work ID:** `OMMC-20261009-tablet-forms-crr`
- **Status:** Design — awaiting Human review of this spec
- **Repositories:** Portal `ommc2027` (branch `8-be-admin-forms-crr`) and Tablet
  `ommc2027-tablet` (branch `7-tablet-forms-crr`); cross-repository Work with the
  same ID in both.
- **State:** `PLANNED` (implementation not authorized yet)
- **Sequencing:** This is now the **first per-form Work**, built on the
  foundation Work `OMMC-20261011-tablet-forms-foundation`
  (`docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`). See
  the "Revision 2026-10-11" section at the end for the reconciled conventions.

## Objective

Add a **Forms** module to the tablet: a navigation item that opens a rail of the
eight form names as cards, a submissions table for the selected form, a Create
action scoped to that form, and the ability to edit a submission's response —
offline-first, using the existing Portal form sync API.

The first deliverable implements **CRR** end to end and makes the shell
extensible so Marketing, Trade Check, Giveaway, Repair, Signage, Trade Request,
and Training can be added one at a time without reworking the navigation or
list.

## Human approval

Approved 2026-10-09 (design conversation):

1. Scope is CRR first, with an extensible shell; the other seven forms are
   non-functional cards marked "Coming soon".
2. CRR is created standalone from the Forms module, with an **optional** Sales
   Call link (matching the API's optional `salescall_uuid`).
3. A CRR is editable only while it is still Pending (all three statuses
   `Pending`).
4. Attachments are included in this Work.
5. The left rail shows all eight form names as cards; only CRR is active.
6. The list merges the local cache with a server pull on page open.
7. Storage mirrors the Expense module: typed per-form local tables plus
   read/creation/sync services.
8. The Portal CRR `pull` is extended to expose attachment URLs so pulled
   attachments preview like Expense.
9. Offline behavior matches Expense: server submissions are read live on page
   open; only locally created CRRs persist on the tablet.

## Current state (evidence)

### Portal (`ommc2027`, branch `8-be-admin-forms-crr`)

- Form API routes: `routes/api.php` lines 43–122, under
  `SyncTokenMiddleware` and prefix `api/sync/forms`. CRR exposes
  `GET crr/pull`, `GET crr/programs`, `POST crr/push`, `POST crr/attach`.
- `app/Http/Controllers/Api/Forms/CrrController.php`: `pull` returns
  `Crr::with('attachments')->where('submitted_by', $request->user()->id)
  ->orderBy('id')->get()`; `programs` returns `CrrProgram::orderBy('name')`;
  `push` delegates to `CrrSyncService` and returns `{ server_id }`; `attach`
  attaches an `UploadedFile` and returns `{ server_id, uploaded_file_id }`.
- `app/Http/Requests/Api/Sync/PushCrrRequest.php`: `server_id` nullable integer,
  `salescall_uuid` nullable string max 36, `crr_program_id` required existing,
  `date_implemented` nullable string, `amount` required numeric, `details` and
  `remarks` nullable string.
- `app/Http/Requests/Api/Sync/PushCrrAttachmentRequest.php`: `server_id`
  required integer, `file` required file.
- `app/Services/FormSync/CrrSyncService.php`: upserts on `server_id` (create when
  absent, update when present) and **always resets `rsm_status`, `sps_status`,
  and `spm_status` to Pending** on every push.
- `database/migrations/2026_10_07_023358_create_crrs_table.php`: `crrs` columns
  `salescall_id` (nullable), `submitted_by`, `crr_program_id`, `date_implemented`
  (string), `amount` decimal(10,2), `details`, `remarks`, `sap_doc_number`,
  `rsm_status`, `sps_status`, `spm_status`, `synced_at`, soft deletes; plus the
  `crr_attachements` pivot to `UploadedFile`.
- Status enums: `App\Enums\RSMStatus` (Pending 1, Approved 2, Disapproved 3);
  `SPSStatus` (Pending 1, Returned 2, Endorsed 3, Completed 4); `SPMStatus`
  (Pending 1, Endorsed 2, Returned 3, Approved 4, Disapproved 5).
- **Gap:** `App\Models\Systems\UploadedFile` has a `url()` accessor
  (`temporaryUrl` for S3, `url()` otherwise) but no `$appends`, so the CRR
  `pull` serializes raw attachment rows **without a URL**. Compare
  `SyncController::visibleExpenses` (lines 293–303), which maps each attachment
  to a normalized array including `url`. The form pull needs the same treatment.

### Tablet (`ommc2027-tablet`, branch `7-tablet-forms-crr`)

- No Forms work exists: `CrrPage` and `TsfPage` are empty stubs with
  `$shouldRegisterNavigation = false`. No form tables, models, services, or
  pages.
- Established patterns to mirror:
  - `app/Services/ExpenseReadService.php` merges local rows with a live server
    read and reports `globalReadFailed`.
  - `app/Services/LocalExpenseCreationService.php` validates and persists a
    local record plus attachments in a transaction, using a local-disk
    `expense_attachments/` directory.
  - `app/Services/SyncService.php`: `push()` iterates pending
    (`pending` or `failed` with `sync_attempts < 3`) models, `markSynced()`,
    `hasPendingChanges()` gates auto-sync; attachments are pushed only after the
    parent has a `server_id` (see the Expense parent-then-attachment ordering).
  - `app/Filament/Pages/ExpensePage.php` + `resources/views/filament/pages/
    expense-page.blade.php` and `expense-reader.blade.php` for the split-view
    list/detail.
  - `app/Filament/Pages/ExpenseCreatePage.php` + `expense-create-page.blade.php`
    for the create form, including the native camera/gallery/files attachment UX.
- `database/migrations/2026_09_24_000002_create_expenses_table.php` is the local
  table convention: `local_uuid` unique, nullable `server_id`, `sync_status`,
  `sync_attempts`, `sync_error`, `synced_at`, and a no-op `down()` (forward-only
  policy).
- `config/sync.php` holds the server URL and timeouts; the tablet authenticates
  with the user's `api_token` bearer token via `SyncService::client()`.

## Accepted decisions

1. Local tables are typed per form (`crr_programs`, `crrs`,
   `crr_attachments`), not a generic JSON envelope.
2. The form catalog that drives the card rail lives in `config/forms.php`; it
   does not drive storage or validation.
3. CRR identity is the Portal `server_id`; the tablet adds `local_uuid` only as a
   local key. A push sends `server_id` when known; the returned `server_id` is
   stored and reused.
4. `crr_programs` is cached locally (with `server_id`) and refreshed on page
   open, so the Program select works offline after a first pull. The push sends
   the selected program's `server_id`.
5. Editability is derived: `rsm_status = PENDING && sps_status = PENDING &&
   spm_status = PENDING`. Because the Portal resets all three to Pending on
   every push, editing a Pending row is safe; non-Pending rows are read-only.
6. Attachments are captured locally and pushed only after the parent CRR has a
   `server_id`. The attach response's `uploaded_file_id` is stored as the local
   attachment's `server_id`.
7. The Portal gains a small change: the CRR `pull` maps each attachment to a
   normalized array including `url` (S3 temporary URL or disk URL), matching
   Expense reads.

## Data model (tablet)

One migration, `create_crr_form_tables`, forward-only (`down()` no-op).

### `crr_programs`

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `server_id` | unsigned bigint, nullable, unique | Portal `crr_programs.id` |
| `name` | string | |
| timestamps | | |

### `crrs`

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `local_uuid` | char(36), unique | local identity |
| `server_id` | unsigned bigint, nullable, index | Portal `crrs.id` |
| `created_by` | FK users | submitting user |
| `salescall_id` | FK salescalls, nullable, nullOnDelete | optional link |
| `crr_program_id` | FK crr_programs | local program row |
| `date_implemented` | string, nullable | |
| `amount` | decimal(10,2), nullable | validated on save |
| `details` | text, nullable | |
| `remarks` | text, nullable | |
| `sap_doc_number` | string, nullable | pulled, read-only |
| `rsm_status` | unsigned tinyint, default 1 | |
| `sps_status` | unsigned tinyint, default 1 | |
| `spm_status` | unsigned tinyint, default 1 | |
| `sync_status` | string(20), default `pending` | |
| `sync_attempts` | unsigned tinyint, default 0 | |
| `sync_error` | text, nullable | |
| `synced_at` | timestamp, nullable | |
| timestamps | | |

Indexes: `server_id`, `created_by`, `salescall_id`, `crr_program_id`,
`sync_status`.

### `crr_attachments`

Mirrors `expense_attachments`: `id`, `local_uuid` (unique), `server_id`
(nullable, index; Portal `UploadedFile` id), `crr_id` (FK crrs,
cascadeOnDelete), `local_path`, `original_name`, `mime_type`, `extension`,
`byte_size`, `sync_status`, `sync_attempts`, `sync_error`, `synced_at`,
timestamps.

### Models and enums

- `App\Models\Crr`, `App\Models\CrrProgram`, `App\Models\CrrAttachment`
  (tablet `App\Models` namespace; no collision with the separate Portal repo).
- `App\Enums\CrrRsmStatus`, `CrrSpsStatus`, `CrrSpmStatus` — int-backed enums
  with titles and Filament color names matching the Portal enums exactly.

## Architecture and components (tablet)

### `config/forms.php`

The eight-form catalog driving the rail: `key`, `label`, `icon`,
`available` (`crr` true, the rest false).

### `CrrReadService`

Mirrors `ExpenseReadService`:

- `crrPrograms()` refreshes `crr_programs` from
  `GET /api/sync/forms/crr/programs` (upsert by `server_id`) and returns the
  local collection.
- `crrForCurrentUser()` builds local rows (scoped to `created_by = auth user`),
  reads the server with `GET /api/sync/forms/crr/pull`, merges by `server_id`
  (server representation wins) then `local_uuid`, sorts newest-first, and maps
  to view arrays.
- Reports `globalReadFailed()` when the server read fails, so the page can show
  an alert while keeping local rows.

### `LocalCrrCreationService`

- `create(User, array $input): Crr` and `update(Crr, array $input): Crr`.
- Validation mirrors `PushCrrRequest`: `crr_program_id` required (local row),
  `amount` required numeric > 0, `date_implemented` nullable string,
  `details`/`remarks` nullable string, `salescall_id` nullable (must belong to
  the user).
- Persists the CRR and local attachments in a transaction; attachment
  normalization reuses the Expense rules (JPEG/PNG/PDF, at most 10, local disk).
  Writes `sync_status = pending`, `sync_attempts = 0`.
- `update` is only permitted while the record is Pending (all three statuses
  `PENDING`).

### `SyncService` integration

- New reads: `readCrrPrograms()` and `readCrrsWithStatus()`, following
  `readExpensesWithStatus()`, including the bearer token, timeout, and
  `failed` flag.
- `push()` gains, after the Expense section:
  1. Pending/failed `Crr` rows: ensure the program has a `server_id`; `POST
     /api/sync/forms/crr/push` with `server_id` (when known),
     `salescall_uuid` (from the linked Salescall's `local_uuid`, when present),
     `crr_program_id` (the program's `server_id`), `date_implemented`, `amount`,
     `details`, `remarks`. On success, `markSynced` with the returned
     `server_id`.
  2. Pending/failed `CrrAttachment` rows whose CRR has a `server_id`: multipart
     `POST /api/sync/forms/crr/attach` with `server_id` (CRR) and `file`; store
     the returned `uploaded_file_id` as the attachment `server_id`.
  - Reuse the existing 401 token-expiry and retryable-failure handling.
- `hasPendingChanges()` includes `Crr` and `CrrAttachment`.

## UI (tablet)

### `FormsPage`

- Filament page, navigation label **Forms**, icon `OutlinedDocumentText`, sort
  ≈250, registered in the existing panel.
- `resources/views/filament/pages/forms-page.blade.php`:
  - **Left rail:** the eight cards from `config/forms.php`. CRR is tappable;
    the other seven render a disabled "Coming soon" state.
  - **Right pane:** when CRR is selected, the submissions table:
    Program, Date Implemented, Amount, Sales Call (ref number or customer name),
    Status (derived), Sync (a `Pending Sync` badge for local pending/failed
    rows). A **Create CRR** action heads the table.
  - Selecting a row opens the detail: the header fields, the three status
    badges, attachments (local previews and pulled server URLs), and **Edit**
    only while Pending.
  - Split-view at the large breakpoint; list/detail with Back on narrow widths,
    matching the Expense page's responsive approach.

### `CrrFormPage` (create and edit)

- Routes `forms/crr/create` and `forms/crr/{crr}/edit`, reusing one Blade form.
- Fields: Program (select from the local cache), Date Implemented (date input),
  Amount, Details, Remarks, optional Sales Call (select from the user's
  salescalls), and attachments using the Expense camera/gallery/files UX.
- Saves through `LocalCrrCreationService`, then returns to the Forms page with a
  success notification. Validation errors render inline like Expense.
- The empty `CrrPage` stub is removed; `TsfPage` is left untouched.

## Data flow

1. Open **Forms** → `CrrReadService` refreshes `crr_programs` and reads CRR
   submissions from the Portal, then renders merged rows. Offline, cached
   programs and local pending CRRs still render with an alert.
2. **Create CRR** → validate → persist locally (`pending`) → return to the list.
3. Background/global `SyncService::push()` pushes the CRR (create or update by
   `server_id`), then its attachments.
4. A later read pulls the server representation, including statuses and
   attachment URLs; syncs collapse local rows into their server rows by
   `server_id`.
5. A Pending CRR can be edited; edits stay Pending on push. Non-Pending rows are
   read-only.

## Error handling

- Server read failure → keep local rows and show "Some submissions could not be
  loaded. Refresh to try again."
- Push rejection → `recordItemFailure` with the Portal status and body; retried
  while `sync_attempts < 3`.
- 401 → surface the existing session-expiry result and stop the push.
- Missing local program at push time → skip the row until the program is pulled
  (it cannot be created without a program anyway).
- Attachment push before the parent has a `server_id` → skipped, not failed.

## Testing

- **Migration/schema:** tables and columns exist; `crr_programs.server_id` is
  unique.
- **`LocalCrrCreationService`:** required program and positive amount; optional
  fields; optional Sales Call ownership; attachment bounds and MIME rules;
  `update` rejected when not Pending; transaction rollback removes stored files.
- **`CrrReadService`:** merges local + server by `server_id`; server wins;
  offline returns local rows with the failure flag; programs upsert by
  `server_id`.
- **`SyncService`:** push create (no `server_id`) versus update (with
  `server_id`); program `server_id` mapping; `salescall_uuid` sent when linked;
  `markSynced` on success; 401 handling; attachment pushed only after the parent;
  `hasPendingChanges` true for pending CRR/attachment.
- **UI:** Forms page renders the rail with CRR active and the others disabled;
  the submissions table renders; the create/edit form renders and saves; Edit is
  hidden for non-Pending rows; the edit route rejects non-Pending records.
- **Portal:** the CRR `pull` returns attachments with a `url` key and normalized
  fields.
- **Hygiene:** `vendor/bin/pint --dirty`, `php -l` on changed PHP, `npm run
  build` for view changes, and the affected focused suites in both repositories.
  Tests use an isolated/disposable database; no application or production
  database is touched.

## Non-goals

- Implementing the other seven forms' list/create/edit (shell only).
- Approval actions, the approval trail, notifications, or status changes from
  the tablet (read-only status display).
- Deleting or restoring CRRs.
- Pagination or caching of pulled server submissions for offline viewing.
- Changing any other existing API or module.

## Risks and open questions

- **Offline server rows:** matching Expense means previously-synced submissions
  are not viewable offline. Accepted; revisit if field use requires it.
- **Status reset on edit:** the Portal resets statuses to Pending on push. The
  edit-only-while-Pending rule contains the blast radius, but a race (status
  changes between read and edit) could still reset a just-approved CRR; the
  push response/pull reconciles it.
- **Attachment URL lifetime:** S3 temporary URLs expire (~15 minutes) and are
  refreshed on each read; the detail page must read at open rather than cache
  the URL.
- **Portal API note status:** the API Work is `READY_FOR_TERMINAL_REVIEW`; the
  attachment-URL addition must be added to its scope (same Work ID) before
  delivery.

## Cross-repository workflow

- Same Work ID and materially equivalent Work notes in `ommc2027` and
  `ommc2027-tablet`.
- Tablet changes land on `7-tablet-forms-crr`; Portal changes land on
  `8-be-admin-forms-crr`.
- Both repositories must be ready and agree before delivery. No commit or push
  without separate explicit terminal authorization (`TERMINAL: GO`).

## Manual acceptance

- **Tablet:** Forms appears in navigation; the rail shows the eight cards with
  CRR active; opening CRR lists submissions; a CRR created offline shows
  `Pending Sync` and pushes on the next sync; editing a Pending CRR persists
  locally and pushes; a non-Pending CRR is read-only; attachments added locally
  preview and push after the parent syncs.
- **Portal:** a tablet-created CRR appears with the user as `submitted_by`,
  statuses Pending, and its attachments; the pull returns attachment URLs.

## Revision 2026-10-11 — foundation-first sequencing and conventions

The all-forms request was decomposed into a shared foundation Work plus one
end-to-end Work per form. This CRR spec remains valid as the first per-form
Work, with these reconciliations against the foundation:

- **Builds on** `OMMC-20261011-tablet-forms-foundation`. The CRR enums
  (`CrrRsmStatus`/`CrrSpsStatus`/`CrrSpmStatus` in this spec) are superseded by
  the shared `App\Enums\RSMStatus`, `SPSStatus`, and `SPMStatus` from the
  foundation; do not create duplicate CRR-specific status enums.
- **Models** live under the foundation's namespace
  `App\Models\Forms\CRR\Crr`, `App\Models\Forms\CRR\CrrProgram`, and
  `App\Models\Forms\CRR\CrrAttachment` (not `App\Models\Crr`).
- **The card rail** is driven by the shared `App\Enums\FormKey` enum, not a
  `config/forms.php` file. This spec's "config/forms.php" references are replaced
  by `FormKey`.
- **No HTTP layer.** `LocalCrrCreationService` validation is provided by
  `App\Support\Forms\CrrRules` through the foundation's shared base; the create
  and edit entry points are thin Filament page actions, per the foundation's
  controller/request adaptation.
- **Shared plumbing** (salescall resolution, attachment storage, idempotent
  child/snapshot replacement, upsert-by-`server_id`) comes from
  `App\Services\Forms\AbstractFormService`.
- Local tables `crr_programs`, `crrs`, and `crr_attachments` are created by the
  foundation's `create_crr_form_tables` migration; this Work adds the read,
  create/edit, push, and UI layers on top.
- The Portal attachment-URL change remains in this Work (cross-repo).
