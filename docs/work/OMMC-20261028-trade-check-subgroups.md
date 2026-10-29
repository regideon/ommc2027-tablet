# OMMC-20261028-trade-check-subgroups

- **Title:** Trade Check sub-group rows with row-level photo evidence
- **Repository role:** Tablet `ommc2027-tablet` (paired with Portal `ommc2027`)
- **State:** `READY_FOR_TERMINAL_REVIEW`

## Objective

Add a sub-group level between Trade Check groups and items so each checklist row
(for example `DF Signage`) owns its criteria and its own photo gallery.

Hierarchy: `TradeCheck -> TradeCheckGroup -> TradeCheckSubgroup -> TradeCheckItem`.

## Human-authorized scope

Confirmed 2026-10-28:

- Add the sub-group level; each row is a sub-group.
- Criteria become items under the sub-group.
- Photos move from group to sub-group; multiple per sub-group, max 10.
- A flagged sub-group requires at least one photo before save.
- Reset existing Trade Check templates, answers, and photos in both apps and
  rebuild from the updated seeder.
- No commit, push, or terminal delivery without separate authorization.

## Decisions

- New `trade_check_subgroups` table/model in both apps.
- `trade_check_items.group_id` becomes `subgroup_id`.
- `trade_check_attachments.group_id` becomes `subgroup_id`.
- `trade_check_groups.is_image_required` is removed; the flag moves to
  `trade_check_subgroups`.
- Seeder rows become sub-groups; item names become the criterion only
  (`Available`, not `DF Signage - Available`).
- Tablet sends `group_ref` + `subgroup_ref` when pushing a photo.
- Existing Trade Check data is discarded by the migration (approved reset).

## Owned files

- `database/migrations/2026_10_28_000003_create_trade_check_subgroups.php`
- `app/Models/Forms/TradeCheck/TradeCheckSubgroup.php` (new)
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

Supersedes the Trade Check portion of
`OMMC-20261028-trade-check-group-media`.

## Validation

- Tablet targeted Pest: `TradeCheckFoundationTest`, `TradeCheckWorkflowTest`,
  `TradeCheckFormPageTest`, `FormsPageTest` — **47 passed (367 assertions)**.
- Portal targeted Pest: `TradeCheckSeederTest`, `TradeCheckResourceTest`,
  `TradeCheckApprovalServiceTest`, `FormSyncTest` — **67 passed (305
  assertions)**.
- Pint passed on changed files in both repositories.
- Runtime applied 2026-10-28: local Trade Check data was reset, the new
  structure pulled successfully, and `DF Signage` verified with
  `is_image_required = true` and items `Available` / `Good condition`.

Manual UI acceptance remains pending.

## Blockers and remaining work

No blockers. Terminal delivery awaits separate Human authorization.
