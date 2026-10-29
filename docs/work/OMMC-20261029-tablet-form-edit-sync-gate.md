# OMMC-20261029-tablet-form-edit-sync-gate

- **Title:** Hide form editing after sync unless returned to DRM
- **Repository role:** Tablet `ommc2027-tablet`
- **State:** `READY_FOR_TERMINAL_REVIEW`

## Objective

Show and allow form editing only while a submission has not been synced, or
when it has been returned to the DRM for revision. Synced submissions awaiting
approval, approved, or disapproved stay read-only.

## Human-authorized scope

Confirmed 2026-10-29:

- Use an Eloquent `Attribute::get()` computed attribute; do not add an
  `is_editable` database column.
- Returned-to-DRM means only returns that reopen the DRM/resubmit stage:
  - CRR: SPS Returned
  - Marketing: SPS Returned or RAC Returned to DRM
  - Trade Check: TDH Returned
  - Giveaway: none
  - Repair: SPS Returned
  - Signage: TDH Returned
  - Trade Request: TDH Returned
  - Training: TDH Returned
- Apply the rule to the Forms-page button, the action guard, direct edit
  routes, and service update guards.

## Implementation constraints

- Preserve the existing uncommitted work on branch
  `45-admin-forms-adjustments`.
- No schema, migration, commit, push, or deployment.
- Keep the existing manual row mappings; the model attribute is the single
  calculation source.

## Owned files

- `app/Models/Forms/CRR/Crr.php`
- `app/Models/Forms/Marketing/Marketing.php`
- `app/Models/Forms/TradeCheck/TradeCheck.php`
- `app/Models/Forms/Giveaway/Giveaway.php`
- `app/Models/Forms/Repair/Repair.php`
- `app/Models/Forms/CRRSignage/Signage.php`
- `app/Models/Forms/TradeRequest/TradeRequest.php`
- `app/Models/Forms/Training/Training.php`
- `app/Services/Forms/CrrService.php`
- `app/Services/Forms/MarketingService.php`
- `app/Services/Forms/TradeCheckService.php`
- `app/Services/Forms/GiveawayService.php`
- `app/Services/Forms/RepairService.php`
- `app/Services/Forms/SignageService.php`
- `app/Services/Forms/TradeRequestService.php`
- `app/Services/Forms/TrainingService.php`
- `app/Filament/Pages/CrrFormPage.php`
- `app/Filament/Pages/MarketingFormPage.php`
- `app/Filament/Pages/TradeCheckFormPage.php`
- `app/Filament/Pages/GiveawayFormPage.php`
- `app/Filament/Pages/RepairFormPage.php`
- `app/Filament/Pages/SignageFormPage.php`
- `app/Filament/Pages/TradeRequestFormPage.php`
- `app/Filament/Pages/TrainingFormPage.php`
- Focused form workflow, form page, and Forms-page tests.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- The CRR marketing-requirement work recorded in
  `OMMC-20261029-crr-program-marketing-requirement` and
  `OMMC-20261029-tablet-crr-marketing-gate-removal`.
- The master-data reconciliation work recorded in
  `OMMC-20261029-tablet-master-data-reconciliation`.
- Untracked `.opencode/` and `graphify-out/` infrastructure.

## Decisions

- `is_editable` remains a computed model attribute backed by `Attribute::get()`.
- The existing form services continue to put `is_editable` in their view rows.
- CRR pull now persists approval statuses on synced local rows, matching the
  existing Marketing behavior, so a returned CRR can open for editing.
- Trade Check and Giveaway keep `isEditableBy(int $userId)` for explicit
  viewer checks; their accessor delegates to the signed-in user.

## Validation

- Focused affected-file Pest run (17 form workflow, Forms-page, and form-page
  test files): **262 passed (1293 assertions)**.
- `vendor/bin/pint --dirty --format agent`: passed.
- `graphify update .`: completed (12,057 nodes, 33,827 edges).
- A broader `tests/Feature/Forms` run also reported two unrelated pre-existing
  failures: `FormRuleClassesTest` (`items.*.name` attribute) and
  `FoundationEnumsTest` (`Gift Check` vs `Gift Certificates`). Those files and
  their source are untouched by this Work.

## Manual acceptance

Pending. Confirm in runtime:

- An unsynced submission still shows Edit.
- A synced submission in Pending/Endorsed/Approved/Disapproved hides Edit.
- A synced submission returned to DRM shows Edit again.
- Opening a hidden submission's edit URL directly is refused.

## Blockers and remaining work

No blockers. Terminal delivery requires separate explicit Human authorization.
