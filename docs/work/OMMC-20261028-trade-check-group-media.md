# OMMC-20261028-trade-check-group-media

- **Title:** Trade Check group-level media and radio-button answers
- **Repository role:** Tablet `ommc2027-tablet` (paired with Portal `ommc2027`)
- **State:** `READY_FOR_TERMINAL_REVIEW`

## Objective

Move Trade Check evidence photos from individual checklist items to the
group section they document, add a group-level image requirement, and render
BOOLEAN/SELECT answers as radio buttons.

## Human-authorized scope

Confirmed 2026-10-28:

- Photos belong to `TradeCheckGroup`; multiple photos per group, max 10.
- `is_image_required` moves from items to groups.
- Groups flagged image-required must have at least one photo before save.
- BOOLEAN and SELECT answers render as radio buttons.
- No data backfill: existing item-level attachments are discarded and the
  attachment table is recreated keyed by group.
- Update the admin Portal (models, API, seeder, infolist) and the Tablet.
- No commit, push, or terminal delivery without separate authorization.

## Decisions

- `trade_check_attachments` is recreated with `group_id`; `item_id` removed.
- `trade_check_items.is_image_required` is removed; `trade_check_groups` gains
  `is_image_required` (default false).
- Seeder defines group flags directly and seeds group photos; no derivation
  from old item rows.
- Tablet sends `group_ref` (group name) on attachment push.
- Pull response exposes `group.attachments` and `group.is_image_required`;
  item attachments are removed.

## Owned files

Tablet:

- `database/migrations/2026_10_28_000002_move_trade_check_attachments_to_groups.php`
- `app/Models/Forms/TradeCheck/TradeCheckGroup.php`
- `app/Models/Forms/TradeCheck/TradeCheckItem.php`
- `app/Models/Forms/TradeCheck/TradeCheckAttachment.php`
- `app/Filament/Pages/TradeCheckFormPage.php`
- `app/Services/Forms/TradeCheckService.php`
- `app/Services/SyncService.php`
- `app/Support/Forms/TradeCheckRules.php`
- `resources/views/filament/pages/trade-check-form-page.blade.php`
- `resources/views/filament/pages/forms-trade-check.blade.php`
- `tests/Feature/Forms/TradeCheckFoundationTest.php`
- `tests/Feature/Forms/TradeCheckWorkflowTest.php`
- `tests/Feature/Forms/TradeCheckFormPageTest.php`
- `tests/Feature/Forms/FormsPageTest.php`

Portal:

- `docs/work/OMMC-20261028-trade-check-group-media.md`
- `database/migrations/2026_10_28_000002_move_trade_check_attachments_to_groups.php`
- `app/Models/Forms/TradeCheck/TradeCheckGroup.php`
- `app/Models/Forms/TradeCheck/TradeCheckItem.php`
- `app/Models/Forms/TradeCheck/TradeCheckAttachment.php`
- `app/Http/Controllers/Api/Forms/TradeCheckController.php`
- `app/Http/Requests/Api/Sync/PushTradeCheckAttachmentRequest.php`
- `app/Http/Requests/Api/Sync/PushTradeCheckRequest.php`
- `app/Http/Resources/Api/Forms/TradeCheckResource.php`
- `app/Services/FormSync/TradeCheckSyncService.php`
- `app/Filament/Resources/TradeChecks/Schemas/TradeCheckInfolist.php`
- `resources/views/filament/resources/trade-checks/infolists/checklist.blade.php`
- `database/seeders/TradeCheckSeeder.php`
- `database/factories/Forms/TradeCheck/*`
- `tests/Feature/TradeCheckSeederTest.php`
- `tests/Feature/TradeCheckResourceTest.php`
- `tests/Feature/Api/FormSyncTest.php`
- `docs/portal-form-resource-guide.md`

## Validation

- Tablet targeted Pest: `TradeCheckFoundationTest`, `TradeCheckWorkflowTest`,
  `TradeCheckFormPageTest`, `FormsPageTest` — **47 passed (358 assertions)**.
- Tablet full forms suite (before the final mapping regression test) —
  **307 passed (1641 assertions)**.
- Portal targeted Pest: `TradeCheckSeederTest`, `TradeCheckResourceTest`,
  `TradeCheckApprovalServiceTest`, `FormSyncTest` — **67 passed (305
  assertions)**.
- Pint passed on changed files in both repositories.

Runtime setup applied on 2026-10-28: Portal migration and `TradeCheckSeeder`
ran, the Tablet pulled the template again, and the local group flags verified
as true for External, Store Front, Store Merchandising, and Store Equipment.
Manual UI acceptance remains pending.

## Blockers and remaining work

No blockers. Terminal delivery awaits separate Human authorization.
