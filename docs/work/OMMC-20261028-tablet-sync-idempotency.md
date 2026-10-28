# OMMC-20261028-tablet-sync-idempotency

- **Title:** Tablet + Portal — idempotent form sync and duplicate prevention
- **Repository role:** Tablet `ommc2027-tablet` and Portal `ommc2027`.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Trigger:** Repeatedly tapping the Tablet sync button created a duplicate form
  entry.
- **Design basis:** Human direction 2026-10-28: make the stronger fix across the
  Tablet and the Portal.

## Objective

1. Serialize Tablet push runs so rapid sync taps cannot run concurrent pushes.
2. Send a stable client-generated submission UUID with every form push.
3. Make the Portal upsert each form by that UUID, so a repeated or racing push
   updates the same Portal row instead of creating another.
4. Reconcile pulled form rows by the same UUID so a lost push response does not
   create a duplicate local row.
5. Guard the sync buttons while a push is in flight.

## Human-authorized scope

- Tablet and Portal implementation and local validation.
- Additive Portal migration for form-table `client_uuid` columns.
- No commit, push, deployment, or migration against a retained/live database.
- Terminal authorization: **none**.

## Task-owned files

### Tablet

- `app/Services/SyncService.php` — global push lock, `client_uuid` payloads for
  CRR, Marketing, Trade Check, Giveaway, Repair, Signage, Trade Request, and
  Training.
- `app/Filament/Pages/FormsPage.php` — resolve a pulled server row to an
  existing local row by `client_uuid` before adopting a new row.
- `app/Services/Forms/CrrService.php` — map `client_uuid` on pulled CRR rows.
- `app/Services/Forms/MarketingService.php` — map `client_uuid` on pulled
  Marketing rows.
- `app/Services/Forms/GiveawayService.php`
- `app/Services/Forms/RepairService.php`
- `app/Services/Forms/SignageService.php`
- `app/Services/Forms/TradeCheckService.php` — client UUID lookup and protection
  against overwriting local pending work.
- `app/Services/Forms/TradeRequestService.php`
- `app/Services/Forms/TrainingService.php`
- `resources/views/filament/pages/salescall-page.blade.php` — sync guard and
  disabled state.
- `resources/views/filament/pages/forms-crr.blade.php`
- `resources/views/filament/pages/forms-giveaway.blade.php`
- `resources/views/filament/pages/forms-marketing.blade.php`
- `resources/views/filament/pages/forms-repair.blade.php`
- `resources/views/filament/pages/forms-signage.blade.php`
- `resources/views/filament/pages/forms-trade-check.blade.php`
- `resources/views/filament/pages/forms-trade-request.blade.php`
- `resources/views/filament/pages/forms-training.blade.php`
- `tests/Feature/SyncPushLockTest.php`
- `tests/Feature/Forms/MarketingWorkflowTest.php`
- `tests/Feature/Forms/FormsPageTest.php`
- This Work note.

### Portal

- `database/migrations/2026_10_28_000001_add_client_uuid_to_form_tables.php`
- `app/Models/Forms/CRR/Crr.php`
- `app/Models/Forms/Marketing/Marketing.php`
- `app/Models/Forms/TradeCheck/TradeCheck.php`
- `app/Models/Forms/Giveaway/Giveaway.php`
- `app/Models/Forms/Repair/Repair.php`
- `app/Models/Forms/CRRSignage/Signage.php`
- `app/Models/Forms/TradeRequest/TradeRequest.php`
- `app/Models/Forms/Training/Training.php`
- `app/Http/Requests/Api/Sync/PushCrrRequest.php`
- `app/Http/Requests/Api/Sync/PushMarketingRequest.php`
- `app/Http/Requests/Api/Sync/PushTradeCheckRequest.php`
- `app/Http/Requests/Api/Sync/PushGiveawayRequest.php`
- `app/Http/Requests/Api/Sync/PushRepairRequest.php`
- `app/Http/Requests/Api/Sync/PushSignageRequest.php`
- `app/Http/Requests/Api/Sync/PushTradeRequestRequest.php`
- `app/Http/Requests/Api/Sync/PushTrainingRequest.php`
- `app/Http/Controllers/Api/Forms/CrrController.php`
- `app/Http/Controllers/Api/Forms/MarketingController.php`
- `app/Http/Controllers/Api/Forms/TradeCheckController.php`
- `app/Http/Controllers/Api/Forms/GiveawayController.php`
- `app/Http/Controllers/Api/Forms/RepairController.php`
- `app/Http/Controllers/Api/Forms/SignageController.php`
- `app/Http/Controllers/Api/Forms/TradeRequestController.php`
- `app/Http/Controllers/Api/Forms/TrainingController.php`
- `app/Services/FormSync/AbstractFormSyncService.php`
- `tests/Feature/Api/FormSyncTest.php`
- Mirrored Work note in the Portal.

## Unrelated/pre-existing worktree changes to preserve

- Tablet: the uncommitted validation/message work recorded in
  `OMMC-20261028-tablet-form-validation-messages` (form rule classes, friendly
  messages, Marketing date-order validation, and their tests). Preserve it; this
  Work does not stage or commit it.
- Tablet: untracked `.opencode/` and `graphify-out/` remain user-owned
  infrastructure.
- Portal: untracked
  `docs/work/OMMC-20261026-crr-sap-doc-number-schema-drift.md` is pre-existing
  and must be preserved.

## Decisions

- The idempotency key is the Tablet form row's `local_uuid`, sent as
  `client_uuid`. Each Portal form table gets a nullable unique `client_uuid`
  column.
- Portal upsert order: `server_id` first for existing rows; otherwise
  `client_uuid`; otherwise legacy create. This keeps older Tablet builds
  working while making new pushes idempotent.
- The unique `client_uuid` index is the durable concurrency guard. It is
  stronger than a per-record `syncing` claim because it also protects after a
  crash, a lost response, an expired lock, or a push from another device.
- Each Portal form push controller wraps the sync service in a database
  transaction, so concurrent duplicate requests serialize on the parent form
  row before child rows are replaced.
- The Tablet global push lock is a cache lock (`sync:push`, 900 seconds). It
  prevents rapid taps from starting concurrent push runs; the UI guards prevent
  those taps from being sent in the first place.
- Pull reconciliation matches `client_uuid` to the Tablet `local_uuid` before
  matching `server_id`, so a lost response is repaired rather than duplicated.
- The Trade Check pull cache now refuses to overwrite a local row that is not
  synced, matching the other form caches.

## Validation evidence

- Tablet:
  - `php artisan test --compact tests/Feature/Forms tests/Feature/SyncPushLockTest.php`
    → **304 passed** (1626 assertions).
  - `vendor/bin/pint --dirty --format agent` → passed.
  - `graphify update .` → completed (11838 nodes, 33324 edges).
- Portal:
  - `php artisan test --compact tests/Feature/Api/FormSyncTest.php tests/Feature/FormSchemaDriftRepairTest.php tests/Feature/CrrSchemaDriftRepairTest.php`
    → **45 passed** (236 assertions).
  - `php artisan test --compact tests/Feature/Api/FormSyncTest.php --filter="client_uuid"`
    → **9 passed** (43 assertions).
  - `vendor/bin/pint --dirty --format agent` → passed.
- The Portal migration is additive and was exercised by the in-memory test
  database only. It has not been run against a retained/live database.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- Rapid sync taps on the Sales Calls page do not create duplicate form entries.
- Retrying the same unsynced form returns the same Portal `server_id`.
- A pull after a lost push response does not create a duplicate local row.
- Existing forms and attachments still sync normally.

## Blockers and remaining work

- The Portal migration must be applied through the authorized deployment path
  before this contract is active in a shared environment.
- Tablet and Portal changes are local and uncommitted.
- Terminal delivery requires separate explicit Human authorization.

## Delivery evidence status

Local implementation and validation only. No commit, push, deployment, or
retained-database migration was performed.
