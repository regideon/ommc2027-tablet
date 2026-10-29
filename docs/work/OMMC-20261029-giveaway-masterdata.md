# OMMC-20261029-giveaway-masterdata

- **Title:** Giveaway item masterdata, single photo and recipient signature
- **Repository role:** Tablet `ommc2027-tablet` (paired with Portal `ommc2027`)
- **State:** `READY_FOR_TERMINAL_REVIEW`

## Objective

Replace freeform Giveaway item rows with Portal-managed masterdata, keep
`GiveawayCategory` as an enum, and store one photo and one recipient signature
per Giveaway submission.

## Human-authorized scope

Confirmed 2026-10-29:

- One masterdata CRUD for Giveaway items; categories remain the
  `GiveawayCategory` enum.
- Master `giveaway_items` table uses `category` (enum), `name`, `order`.
- Submission lines move to `giveaway_submission_items` with `giveaway_item_id`.
- One signature per Giveaway; one optional photo per Giveaway.
- No target date.
- Signature required; photo optional.
- All master items always render as number inputs; no special Add behavior.
- Existing Giveaway data resets and reseeds.
- No commit, push, or terminal delivery without separate authorization.

## Decisions

- `GiveawayItem` becomes the masterdata model; `GiveawaySubmissionItem` is the
  per-submission line model.
- Tablet caches masterdata with server ids and pulls it before submissions.
- Portal exposes masterdata at `/api/forms/giveaway/master-data`.
- Signature attaches to the Giveaway directly; photo uses a new `/photo`
  endpoint.
- Admin CRUD uses the `giveaway-items` permission module.

## Owned files

- `database/migrations/2026_10_29_000001_move_giveaway_items_to_masterdata.php`
- `app/Models/Forms/Giveaway/GiveawayItem.php` (masterdata)
- `app/Models/Forms/Giveaway/GiveawaySubmissionItem.php` (new)
- `app/Models/Forms/Giveaway/GiveawayPhoto.php` (new)
- `app/Models/Forms/Giveaway/Giveaway.php`
- `app/Models/Forms/Giveaway/GiveawayAnswer.php`
- `app/Models/Forms/Giveaway/GiveawayAnswerItem.php`
- `app/Models/Forms/Giveaway/GiveawaySignature.php`
- `app/Enums/Giveaway/GiveawayCategory.php`
- `app/Filament/Pages/GiveawayFormPage.php`
- `app/Services/Forms/GiveawayService.php`
- `app/Services/SyncService.php`
- `app/Support/Forms/GiveawayRules.php`
- `resources/views/filament/pages/giveaway-form-page.blade.php`
- `resources/views/filament/pages/forms-giveaway.blade.php`
- `tests/Feature/Forms/GiveawayFoundationTest.php`
- `tests/Feature/Forms/GiveawayWorkflowTest.php`
- `tests/Feature/Forms/GiveawayFormPageTest.php`
- `tests/Feature/Forms/FormsPageTest.php`

## Validation

- Tablet targeted Pest: `GiveawayFoundationTest`, `GiveawayWorkflowTest`,
  `GiveawayFormPageTest`, `FormsPageTest` — **35 passed (311 assertions)**.
- Portal targeted Pest: `FormMasterDataResourceTest`, `GiveawaySeederTest`,
  `GiveawayResourceTest`, `GiveawayApprovalServiceTest`, `FormSyncTest` —
  **114 passed (624 assertions)**.
- Pint passed in both repositories.
- Runtime applied 2026-10-29: local Giveaway data reset, masterdata pulled
  (9 items), demo submission pulled with 9 submission items, 1 signature and
  1 photo.

Manual UI acceptance remains pending.

## Blockers and remaining work

No blockers. Terminal delivery awaits separate Human authorization.
