# OMMC-20261012-tablet-forms-marketing

- **Title:** Tablet Forms — Marketing Recommendation, end to end
- **Repository role:** Tablet `ommc2027-tablet` (branch `7-tablet-forms-crr`).
  No Portal changes in this Work.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`
  and the CRR Work (`docs/work/OMMC-20261009-tablet-forms-crr.md`); this slice
  follows the approved per-form pattern.

## Objective

Deliver the **Marketing Recommendation** form end to end on the forms
foundation: submissions list, create/edit with header and three child sets,
offline-first sync (pull + push), and deletion reconciliation.

## Human-authorized scope

Confirmed 2026-10-12: proceed with Marketing; one service per form; editable
only while SPS and RAC are both Pending; child values required in the local
form (no schema change); region type defaults to the logged-in user's
`region_type_id` and is selectable; no attachments (the Portal rejects them);
delete-missing reconciliation on a successful pull.

Implementation and terminal authorization: implementation only. No commit or
push performed.

## Task-owned files

- `app/Contracts/FormSubmissionService.php` — shared service contract
  (`programs`, `programReadFailed`, `submissions`, `globalReadFailed`,
  `create`, `update`, `adopt`) so the Forms page dispatches across forms.
- `app/Services/Forms/MarketingService.php` — the form's single service:
  program cache, local + server merge, create/update/adopt with idempotent
  child replacement, deletion reconciliation.
- `app/Services/Forms/CrrService.php` — implements the contract; methods renamed
  to `programs()` / `submissions()` (merged service from the prior Work).
- `app/Services/SyncService.php` — Marketing read methods and push section;
  `hasPendingChanges()` includes Marketing.
- `app/Models/Forms/Marketing/Marketing.php` — `isEditable()`.
- `app/Enums/FormKey.php` — Marketing is now available.
- `app/Filament/Pages/FormsPage.php` — generalized: generic `rows` /
  `selectedRowKey` / `selectRow` / `editSelectedRow` / `loadRows`, dispatching
  to the selected form's service and edit page.
- `app/Filament/Pages/MarketingFormPage.php` + view.
- Views: `forms-page.blade.php` (rail + per-form include),
  `forms-crr.blade.php` (extracted), `forms-marketing.blade.php` (new).
- Tests: `tests/Feature/Forms/MarketingWorkflowTest.php`,
  `MarketingFormPageTest.php`, updated `FormsPageTest.php`.

## Decisions

- **Editability:** editable while SPS and RAC are both `Pending`; the Portal
  resets both on push.
- **Children:** `volumes` (before/during, volume computed when omitted),
  `realizations` (stage required), `mechanics` (period/unit_price/sub_total)
  are required locally; `replaceChildren` keeps re-saves idempotent.
- **Role:** `role_id` is resolved from the logged-in user's role locally,
  mirroring the Portal's server-side resolution (not user-editable).
- **Region type:** defaults to the user's `region_type_id`, selectable from the
  local `region_types`.
- **Deletion reconciliation:** on a successful pull, local rows whose
  `server_id` is absent are deleted; child rows cascade. Local unsynced rows and
  failed pulls are never purged.
- **No attachments** for Marketing.

## Validation evidence

- Forms suite: `php artisan test --compact tests/Feature/Forms` →
  **87 passed (556 assertions)**.
- Full tablet suite: **362 passed, 16 failed, 4 skipped (2350 assertions)**.
  The 16 failures are the pre-existing, environment-dependent failures
  (location resolver / Nominatim / native iOS artifacts / photo mirrors /
  startup diagnostics); none are forms-related.
- Pint passed on all changed paths.
- `php -l` clean via the builders.

## Manual acceptance

Pending. Requires the Human to confirm the Marketing rail card, list, create
(header + three child repeaters), edit-while-pending, Sync/push, and
deletion-on-admin-side reconciliation in runtime.

## Blockers and remaining work

- No blockers.
- Remaining: Trade Check, Giveaway, Repair, Signage, Trade Request, Training;
  terminal delivery awaits separate Human authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed.
