# OMMC-20261011-tablet-forms-foundation

- **Title:** Tablet Forms foundation — enums, schemas, models, conventions
- **Repository role:** Tablet `ommc2027-tablet` (branch `7-tablet-forms-crr`).
  Tablet-only; no Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design spec:** `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`

## Objective

Build the shared foundation for all eight offline-first tablet forms: a ported
`BaseEnum` trait and the full form enum set, local database tables mirroring the
Portal form schemas, Eloquent models, and shared service/validation conventions
that replace the Portal's HTTP controller/FormRequest layer with the tablet's
existing pattern. No UI.

## Human-authorized scope

Approved 2026-10-11:

- Foundation first; per-form end-to-end Works after, CRR first.
- No HTTP controller/FormRequest layer on the tablet; Filament page actions are
  the controllers and per-form rule classes replace FormRequests.
- Enum parity with the Portal; `FormKey` is an enum.
- Local migrations mirror the Portal schemas with tablet sync conventions and
  deliberate typo cleanups; migrations are forward-only.
- Shared service/validation conventions, including idempotent child replacement.

Implementation authorization: Human `implement it` GO on 2026-10-11. Terminal
authorization: none. No commit or push performed.

## Affected repositories

- Tablet `ommc2027-tablet` only.

## Accepted decisions

See the design spec "Accepted decisions" and "Controller / request adaptation".
Implemented: 18 enum files (BaseEnum, 6 global statuses, 10 per-form, `FormKey`);
35 local tables in eight forward-only migrations; 35 models under
`App\Models\Forms\<Form>`; eight `App\Support\Forms\<Form>Rules` implementing
`App\Contracts\ProvidesFormRules`; `App\Services\Forms\AbstractFormService` with
salescall resolution, attachment normalization/storage, idempotent
`replaceChildren`, and `upsertByServerId`. No HTTP routes/controllers/
FormRequests.

## Implementation constraints

- Followed the existing tablet conventions (`local_uuid`/`server_id`/`sync_status`
  envelope; no-op `down()`; `Validator::make()` in services; thin page actions).
- No UI, per-form orchestration services, or Portal changes in this Work.
- No unrelated modules or existing APIs modified.
- Tests use an isolated in-memory database; no application or production
  database touched.
- No commit or push (no terminal authorization).

## Task-owned files (implemented)

- `app/Enums/Traits/BaseEnum.php`; `app/Enums/{RSMStatus,SPSStatus,SPMStatus,`
  `RACStatus,TDHStatus,TDSStatus,FormKey}.php`; per-form enums under
  `app/Enums/{Marketing/RealizationStage,Giveaway/GiveawayCategory,`
  `CRRSignage/SignageCategory,CRRSignage/SignageAttachmentType,`
  `TradeCheck/GroupCategory,TradeCheck/FieldType,`
  `Repair/RepairAttachmentType,TradeRequest/Size,`
  `TradeRequest/TradeRequestAttachmentType,Training/TrainingAttachmentType}.php`.
- `app/Contracts/ProvidesFormRules.php`
- `app/Services/Forms/AbstractFormService.php`
- `app/Models/Forms/**` (35 models across the eight form folders)
- `app/Support/Forms/{Crr,Marketing,TradeCheck,Giveaway,Repair,Signage,`
  `TradeRequest,Training}Rules.php`
- `database/migrations/2026_10_11_12000{1..8}_create_*_form_tables.php`
- `tests/Feature/Forms/` (11 files: 3 shared + 8 per-form)
- Docs: this note, the foundation spec, the CRR spec revision, and the
  implementation tracker/conventions under `docs/superpowers/`.

## Unrelated/pre-existing worktree changes to preserve

The worktree was clean before this Work. Nothing else was modified.

## Significance / decisions log

- Decomposed the all-forms request into a foundation Work plus one Work per
  form; keeps each Work reviewable and avoids rework.
- Rejected mirroring the Portal's HTTP controller/request layer; adapted the
  tablet's existing Filament-action + service-validation pattern instead.
- The shared snapshot convention (`replaceChildren`) is idempotent, fixing the
  Portal's duplicate-answer behavior on re-push rather than mirroring it.
- Trade Check answer-item class is `TradeCheckAnswerItems` (plural) to mirror
  the Portal and the conventions brief.
- Local table-name cleanups: `crr_attachments`, `giveaway_answers`,
  `giveaway_answer_items`, `giveaway_signatures`, `marketing_programs`.
- Validation applies only to user-entered create/edit input; pulled/synced
  server data is authoritative and is never validated by the tablet. The Portal
  push request remains the final validator.

## Validation evidence

- Forms suite: `php artisan test --compact tests/Feature/Forms` → **42 passed
  (356 assertions)**, re-run after Pint.
- Full suite: `php artisan test --compact` → 16 failed, 3 skipped, **230
  passed (1436 assertions)**. All 16 failures are pre-existing and unrelated:
  proven by `git stash push -u` (clean tree), re-running the same eight test
  files → **the same 16 failed, 28 passed**; then `git stash pop`.
  Failing files: `CustomerCreatePageDiagnosticTest`, `CustomerLocationMapTest`,
  `CustomerLocationSaveTest`, `ExampleTest`, `FirstLoginPersistenceTest`,
  `IosNativeRegenerationArtifactsTest`, `SalescallPhotoUploadTest`,
  `StartupMigrationClassicDiagnosticsTest`.
- `vendor/bin/pint` on the new paths/`app/Enums`/migrations → exit 0 (fixed 3
  test files).
- `php -l` on every new PHP file → no syntax errors (verified by each builder).
- `git diff --check` on all new files (intent-to-add, then reset) → exit 0.
- Counts: 18 enum files, 1 contract, 1 shared service, 35 models, 8 rules,
  8 migrations, 11 tests.

## Manual acceptance

Not applicable yet: the foundation has no UI. Runtime acceptance belongs to the
per-form Works (CRR first).

## Blockers and remaining work

- No blockers.
- Remaining: per-form end-to-end Works (CRR first), then the rest; terminal
  delivery awaits separate Human authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed.
