# Tablet Forms — Foundation (Enums, Schemas, Models, Conventions) — Design

- **Date:** 2026-10-11
- **Work ID:** `OMMC-20261011-tablet-forms-foundation`
- **Status:** Design — awaiting Human review of this spec
- **Repositories:** Tablet `ommc2027-tablet` (branch `7-tablet-forms-crr`) only.
  No Portal changes in this Work.
- **State:** `PLANNED` (implementation not authorized yet)

## Objective

Build the shared tablet foundation for all eight offline-first forms so each
form can then be delivered end to end without re-inventing enums, schemas,
models, or conventions:

1. A ported `BaseEnum` trait and the full form enum set.
2. Local (offline) database tables mirroring the Portal form schemas, adapted
   to the tablet's local sync conventions.
3. Eloquent models for every form table.
4. Shared service and validation conventions that replace the Portal's HTTP
   controller / FormRequest layer with the tablet's existing pattern.

No UI. Each form's list/create/edit UI and its read/write orchestration are
delivered in separate per-form Works, starting with CRR.

## Human approval

Approved 2026-10-11 (design conversation):

1. Scope is **foundation first**, then one end-to-end Work per form, CRR first.
2. The tablet does **not** gain an HTTP controller/FormRequest layer. The
   existing pattern is adapted: Filament page actions are the controllers, and
   per-form validation rule classes replace FormRequests. (See the mapping
   table below.)
3. The enum set mirrors the Portal exactly (values, names, titles, colors).
4. Local migrations mirror the Portal schemas with tablet sync conventions and
   targeted typo/schema cleanups.
5. `FormKey` is an enum (not a config file) and drives the rail and per-form
   dispatch.
6. The shared sync convention replaces child rows **idempotently** (fixing the
   Portal's duplicate-answer behavior on re-push) rather than copying it.

Prior approved CRR design: `docs/superpowers/specs/2026-10-09-tablet-forms-crr-design.md`
(now the first per-form Work, built on this foundation).

## Current state (evidence)

- Portal inventory (source of truth for schemas/enums):
  - `app/Enums/Traits/BaseEnum.php`; 16 enums under `app/Enums` (6 global
    statuses, 10 per-form).
  - Eight form domains under `app/Models/Forms`, `database/migrations`,
    `app/Http/Controllers/Api/Forms`, `app/Http/Requests/Api/Sync`,
    `app/Services/FormSync`.
- Tablet has no `app/Enums`, no form tables, models, services, or requests.
- Tablet conventions to follow:
  - `app/Http` has only the abstract `Controller` and one middleware; there are
    no FormRequests. Validation is `Validator::make()` inside services
    (`app/Services/LocalExpenseCreationService.php`).
  - Thin page actions act as controllers
    (`ExpenseCreatePage::saveExpense()`, `CustomerCreatePage::saveCustomer()`,
    `SalescallPage::saveBrands()`).
  - Local tables use `local_uuid` (unique), nullable `server_id`, `sync_status`,
    `sync_attempts`, `sync_error`, `synced_at`, and a no-op `down()`
    (forward-only), e.g.
    `database/migrations/2026_09_24_000002_create_expenses_table.php`.
  - `region_types`, `roles` (Spatie), `users`, and `salescalls` already exist
    locally and can anchor foreign keys.

## Accepted decisions

1. **Enum parity:** every enum mirrors the Portal's backing type, case names,
   values, `metaProperties()` titles and colors.
2. **`FormKey` enum:** cases for the eight forms with `label`, `icon`,
   `available()`; unavailable forms render as "Coming soon" in the rail to be
   built in the per-form Works.
3. **Local schema adaptation:** master tables cache server rows by `server_id`;
   submission headers and attachments carry the local sync envelope; child
   tables keep only the parent foreign key (they are replaced wholesale).
4. **Name cleanups:** `crr_attachments` (not the Portal's `crr_attachements`),
   `giveaway_answers` (not `give_away_answers`), `giveaway_answer_items` (same
   name), `giveaway_signatures` (not `giveaway_signitures`), and
   `marketing_programs` (the Portal's generic `programs`, to avoid ambiguity).
   The push/pull services map to the Portal names.
5. **Attachment model:** locally stored as files on the `local` disk with
   `local_path` + metadata; the Portal `uploaded_file_id` is stored as the
   attachment's `server_id`. No `uploaded_files` table locally.
6. **Excluded tables:** the Portal's `form_approvals`, `uploaded_files`, and the
   legacy `form_types`/`forms`. Approval trails and notifications are out of
   scope (read-only status is a per-form UI concern).
7. **Status defaults:** every status column defaults to `Pending (1)` locally,
   including the Portal tables that have no default.
8. **Migrations are forward-only:** `down()` is a no-op, matching the Expense
   migration policy.
9. **Idempotent child replacement:** the shared convention deletes and recreates
   child rows for a submission snapshot inside a transaction; re-pushing the
   same `server_id` never duplicates answers.
10. **Validation scope:** the per-form `Rules` classes validate **user-entered
    create/edit input only**. Data pulled from the Portal during sync is
    authoritative and is never validated by the tablet. The Portal push request
    remains the final server-side validator.

### Controller / request adaptation

| Portal concept | Tablet equivalent |
|---|---|
| `Api\Forms\CrrController` | `FormsPage` / per-form form page Livewire actions (thin: authorize → service → notify) |
| `PushCrrRequest` | `App\Support\Forms\CrrRules::rules()` used by services via `Validator::make()` |
| `FormSync\CrrSyncService` | `App\Services\Forms\Crrs\CrrService` (local create/update) plus push mapping in `SyncService` |
| HTTP 401/422 | `ValidationException` inline errors; `SyncResult` token-expiry for pushes |

No HTTP routes, controllers, or FormRequest classes are added.

## Architecture and components (tablet)

### 1. Enums (`app/Enums`)

- `Traits/BaseEnum.php` — ported verbatim in behavior: abstract
  `metaProperties()`, `__invoke`, `__callStatic`, `meta`, `pill`,
  `renderSelect`, `values`, `randomValue`, `getImportOptionList`,
  `getFilteredMetaProperties`.
- Global statuses: `RSMStatus`, `SPSStatus`, `SPMStatus`, `RACStatus`,
  `TDHStatus`, `TDSStatus` (cases/values/titles/colors exactly as the Portal).
- Per-form: `Marketing\RealizationStage`, `Giveaway\GiveawayCategory`,
  `CRRSignage\SignageCategory`, `CRRSignage\SignageAttachmentType`,
  `TradeCheck\GroupCategory`, `TradeCheck\FieldType`,
  `Repair\RepairAttachmentType`, `TradeRequest\Size`,
  `TradeRequest\TradeRequestAttachmentType`, `Training\TrainingAttachmentType`.
- `FormKey` — cases `CRR`, `Marketing`, `TradeCheck`, `Giveaway`, `Repair`,
  `Signage`, `TradeRequest`, `Training`; each with a label, a Filament icon, a
  `slug` matching the API (`crr`, `marketing`, `trade-check`, …), and
  `available()`.

### 2. Local migrations (one per form, forward-only)

Conventions applied to the Portal columns:

- **Master tables** add `id`, `server_id` (unsigned bigint, unique), `name`,
  timestamps.
- **Submission headers** add `local_uuid` (char 36, unique), `server_id`
  (nullable, indexed), `submitted_by` → users, `salescall_id` → salescalls
  (nullable, nullOnDelete), `sync_status` (default `pending`), `sync_attempts`
  (default 0), `sync_error` (nullable), `synced_at` (nullable), and their status
  columns default to `1`.
- **Child tables** keep the Portal columns plus the parent foreign key; they are
  replaced wholesale, so no sync envelope.
- **Attachment tables** replace the Portal's `uploaded_file_id` with
  `local_uuid` (unique), `server_id` (nullable, indexed), `local_path`,
  `original_name`, `mime_type`, `extension`, `byte_size`, plus the sync envelope
  and any typed column (`type`, `item_id`, `answer_id`).

Tables by migration:

- `create_crr_form_tables`: `crr_programs` (master); `crrs`; `crr_attachments`.
- `create_marketing_form_tables`: `marketing_programs` (master);
  `marketings`; `marketing_volumes`; `marketing_realizations`;
  `marketing_mechanics`.
- `create_trade_check_form_tables`: `trade_checks`; `trade_check_groups`;
  `trade_check_items`; `trade_check_answers`; `trade_check_answer_items`;
  `trade_check_attachments`.
- `create_giveaway_form_tables`: `giveaways`; `giveaway_items`;
  `giveaway_answers`; `giveaway_answer_items`; `giveaway_signatures`.
- `create_repair_form_tables`: `repairs`; `repair_attachments`.
- `create_signage_form_tables`: `signage_products` (master);
  `signage_types` (master); `signages`; `signage_attachments`.
- `create_trade_request_form_tables`: `trade_products` (master);
  `trade_types` (master); `trade_requests`; `trade_request_items`;
  `trade_request_attachments`.
- `create_training_form_tables`: `training_topics` (master);
  `training_positions` (master); `trainings`; `training_participants`;
  `training_attachments`.

Thirty-five tables total.

### 3. Models (`app/Models/Forms/<Form>/…`)

Mirror the Portal models with the local schema: enum casts, relationships, and
sync-envelope casts. Form folder names match the Portal: `CRR`, `Marketing`,
`TradeCheck`, `Giveaway`, `Repair`, `CRRSignage`, `TradeRequest`, `Training`.
Thirty-five model classes. Attachment models expose the local file helpers used
by the per-form read/create services.

### 4. Shared service + validation conventions (`app/Services/Forms`, `app/Support/Forms`)

- `App\Contracts\ProvidesFormRules` — `rules(): array` and a static accessor, so
  a validator can be built without HTTP.
- `App\Support\Forms\<Form>Rules` — one per form, mirroring the Portal
  `Push*Request` rules (CRR, Marketing, Trade Check, Giveaway, Repair, Signage,
  Trade Request, Training), plus attachment rules where the form has them.
- `App\Services\Forms\AbstractFormService` — shared plumbing:
  - `resolveSalescall(?string $salescallUuid): ?int` by `salescalls.local_uuid`,
  - `storeAttachment(...)`: decode/normalize (JPEG/PNG/PDF, size/extension),
    write to the `local` disk, create the attachment row (`pending`),
  - `replaceChildren(Model $submission, string $childModel, array $rows)`:
    idempotent delete-and-recreate inside the caller's transaction.
  - Upsert-by-`server_id` helper shared by the per-form services.
- Per-form services (`App\Services\Forms\<Form>\<Form>Service`) are **not** built
  here; each form Work adds its own on top of the shared base.

### 5. `SyncService` dispatch (convention only)

This Work documents, but does not implement, the per-form push delegation:
`SyncService::push()` will call each registered form service's push mapping and
`hasPendingChanges()` will include each form's pending rows. The first concrete
implementation lands with CRR.

## Data flow (per-form, enabled by this foundation)

1. A form page pulls master data and the user's submissions, caching master rows
   by `server_id`.
2. Create/edit validates through the form's `Rules` class and persists locally
   via the form service (`pending`), replacing children idempotently.
3. `SyncService::push()` sends the snapshot to the matching
   `/api/sync/forms/{form}/push` endpoint, stores the returned `server_id`, then
   pushes attachments after the parent.
4. A later pull reconciles statuses and server rows.

## Error handling

- Validation failures surface as `ValidationException` field errors, matching
  `LocalExpenseCreationService`.
- Local file storage failure rolls back the surrounding transaction and removes
  any partially written files.
- Unknown `salescall_uuid` at push time is rejected by the Portal (404); the
  tablet records a retryable failure.

## Testing

- **Enums:** case names/values and `metaProperties()` titles/colors match the
  Portal for all 16 enums; `BaseEnum` helpers (`values`, `renderSelect`, `pill`,
  `__callStatic`) behave as expected; `FormKey` exposes the eight slugs and the
  available/coming-soon split.
- **Migrations:** every table and column exists with the expected type,
  nullability, default, and keys; the typo cleanups are present and the Portal
  names are absent.
- **Models:** relationships and enum casts resolve for each form; master models
  find by `server_id`.
- **Rules:** each `Rules` class accepts a valid Portal-shaped payload and rejects
  missing/typed-invalid fields.
- **Shared service:** `resolveSalescall`, attachment normalization/bounds, and
  idempotent `replaceChildren` (re-running the same snapshot does not duplicate
  rows).
- **Hygiene:** `vendor/bin/pint --dirty`, `php -l` on changed PHP, and the
  affected focused suite. Tests use an isolated/disposable database.

## Non-goals

- Filament pages, navigation, list/detail/create/edit UI (per-form Works).
- Per-form read/write orchestration services beyond the shared base.
- Portal changes (the CRR attachment-URL change stays with the CRR Work).
- Approval trails, notifications, delete/restore, and server-row caching.
- Backfilling or reconciling existing local data.

## Risks and open questions

- **Foundation breadth:** ~35 tables, ~35 models, 17 enums. It is large, but it
  is the user-accepted decomposition and avoids repeated rework.
- **Schema drift:** the Portal may still change before each form is built; the
  mirrored schema is captured now and reconciled per form if needed.
- **Idempotency divergence:** the tablet is intentionally more correct than the
  Portal's child replacement; any Portal-side change must not assume the tablet
  mirrors the duplicate-append behavior.
- **Naming divergence:** local table names differ from the Portal for four
  tables; every push/pull mapping must translate explicitly.

## Cross-repository workflow

- Tablet-only. No Portal Work note for this ID.
- Tablet changes land on `7-tablet-forms-crr`.
- No commit or push without separate explicit terminal authorization.

## Manual acceptance

- The enum set matches the Portal's values and labels.
- A fresh tablet database migrates cleanly and contains all 35 tables.
- A representative model per form resolves its relationships and enum casts.
- Re-running the shared snapshot helper does not duplicate child rows.
