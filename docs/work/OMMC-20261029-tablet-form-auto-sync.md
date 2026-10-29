# OMMC-20261029-tablet-form-auto-sync

- **Title:** Auto-sync all pending records after a form save
- **Repository role:** Tablet `ommc2027-tablet`
- **State:** `READY_FOR_TERMINAL_REVIEW`

## Objective

After any form saves successfully, keep the local record and automatically
push all pending records to the Portal without requiring the user to tap Sync.

## Human-authorized scope

Confirmed 2026-10-29:

- Save locally first; never lose the local record if upload fails.
- Auto-push all pending records, not only the saved form.
- Offline or failed sync leaves the record pending and shows a warning.

## Decisions

- Form saves set a one-shot session flag and redirect to the Forms page.
- `FormsPage::mount()` consumes the flag and runs the existing
  `SyncService::push()`.
- The save screen does not block on network uploads; the Forms page shows the
  sync result.
- Applied to all eight form pages: CRR, Marketing, Trade Check, Giveaway,
  Repair, Signage, Trade Request, Training.

## Owned files

- `app/Filament/Concerns/RedirectsWithFormSync.php` (new)
- `app/Filament/Pages/FormsPage.php`
- `app/Filament/Pages/CrrFormPage.php`
- `app/Filament/Pages/MarketingFormPage.php`
- `app/Filament/Pages/TradeCheckFormPage.php`
- `app/Filament/Pages/GiveawayFormPage.php`
- `app/Filament/Pages/RepairFormPage.php`
- `app/Filament/Pages/SignageFormPage.php`
- `app/Filament/Pages/TradeRequestFormPage.php`
- `app/Filament/Pages/TrainingFormPage.php`
- `tests/Feature/Forms/CrrFormPageTest.php`
- `tests/Feature/Forms/FormsPageTest.php`

## Validation

- Form page/Forms page targeted Pest — **119 passed (735 assertions)**.
- Pint passed.

Manual acceptance pending: save any form, confirm the Forms page
automatically pushes pending records and shows the sync result.

## Blockers and remaining work

No blockers. Terminal delivery awaits separate Human authorization.
