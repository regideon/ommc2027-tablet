# OMMC-20261027-tablet-marketing-approval-before-crr

- **Title:** Tablet — require an approved Marketing Recommendation before creating a CRR
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Bounded in-chat design approved 2026-10-27 ("implement").
  Human directive: reverse the current prerequisite — remove the
  CRR-before-Marketing gate, and require a Marketing Recommendation to be
  approved before a CRR can be created. Follow-up approved 2026-10-28 ("yes"):
  the CRR create sales-call picker lists only sales calls with a locally
  persisted RAC-approved Marketing Recommendation.
- **Related Work:** `OMMC-20261015-tablet-salescall-form-requirements`
  (introduced the CRR-before-Marketing gate; superseded here for that gate),
  `OMMC-20261016-tablet-salescall-required-forms-reduction` (kept the gate
  intentionally), and `OMMC-20261012-tablet-forms-marketing` (Marketing form
  foundation).

## Objective

1. A rep may create a Marketing Recommendation for a sales call without first
   creating a CRR.
2. A CRR linked to a sales call may be created only when the same rep's
   Marketing Recommendation for that same sales call is RAC-approved
   (`rac_status = Approved`).
3. Approval state is persisted from successful Portal pulls so the rule works
   offline after the last successful pull; an unknown state blocks creation
   with a reconnect instruction instead of falsely asking for a duplicate
   Marketing.
4. Standalone CRRs and CRR edit mode remain unaffected.
5. The CRR create sales-call picker lists only sales calls with a locally
   persisted RAC-approved Marketing Recommendation from the same rep. A
   sales call requested through `?salescall=` stays selectable so the
   approval warning still opens for it; CRR edit mode keeps the full list.

## Human-authorized scope

Confirmed 2026-10-27:

- Remove the Marketing CRR-first gate from the form page, service, view, and
  tests.
- Add the reversed Marketing-approval gate to CRR creation (locked form open
  and save-layer validation).
- Refresh and persist Marketing SPS/RAC status from the Portal pull; no new
  Portal endpoint or Portal code.
- Keep the Portal untouched; Tablet-side enforcement only.
- Follow-up 2026-10-28: filter the CRR create sales-call picker to sales
  calls with a locally persisted approved Marketing; no extra Portal pull
  when the form opens; preserve the requested-salescall warning and edit
  behavior.
- Implementation authorization: Human "implement" 2026-10-27 and "yes"
  2026-10-28. Terminal authorization: **none** for the 2026-10-28 changes.

## Task-owned files

- `app/Models/Forms/Marketing/Marketing.php` — approval helper.
- `app/Services/Forms/MarketingService.php` — remove CRR gate; add status
  refresh, approval-state lookup, and locally approved sales-call IDs.
- `app/Services/Forms/CrrService.php` — enforce approved Marketing on create.
- `app/Filament/Pages/MarketingFormPage.php` — remove CRR-required state and
  gate.
- `app/Filament/Pages/CrrFormPage.php` — approval-required warning, pre-save
  refresh, and approved-Marketing create-list filter.
- `resources/views/filament/pages/marketing-form-page.blade.php` — remove the
  Create-CRR-first warning.
- `resources/views/filament/pages/crr-form-page.blade.php` — add the
  Marketing-approval warning states.
- `tests/Feature/Forms/MarketingFormPageTest.php`,
  `tests/Feature/Forms/MarketingWorkflowTest.php`,
  `tests/Feature/Forms/CrrFormPageTest.php`,
  `tests/Feature/Forms/CrrWorkflowTest.php` — updated/new coverage.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- Worktree was clean at start (`git status --short` empty).
- Untracked `.opencode/` and `graphify-out/` are pre-existing user-owned
  infrastructure; `graphify-out/` is refreshed with `graphify update .` per
  `AGENTS.md`, not staged.
- `storage/framework/lsp-*.php` transient runtime cache files may appear
  during tests and clean themselves up; not part of this Work.

## Decisions

- "Approved" means RAC final approval (`RACStatus::APPROVED`), not SPS
  endorsement.
- Approval state is scoped to the same rep (`submitted_by`) and the same sales
  call (`salescall_id`), mirroring the removed gate's ownership rule.
- The Marketing status refresh only updates existing local rows matched by
  `server_id`; it does not create, delete, or alter child rows, and does not
  change sync status.
- A successful pull is also inspected directly for a matching approved
  Marketing row so a submission from another device is not missed.
- A failed pull falls back to last-known local state; with no known Marketing
  the CRR form shows a reconnect/unknown state rather than a duplicate-create
  instruction.
- The CRR save-layer guard is authoritative; the form warning is guidance.
- Follow-up 2026-10-28: the create sales-call picker uses last-synced local
  approval state only; it does not pull Marketing when the form opens.
- A sales call named by `?salescall=` remains in the create picker even when
  unapproved so the warning path stays reachable; edit mode keeps the full
  sales-call list.

## Validation evidence

- `php artisan test --compact tests/Feature/Forms` → 274 passed
  (1535 assertions).
- `php artisan test --compact tests/Feature/Forms/CrrFormPageTest.php` →
  22 passed (96 assertions) after Pint.
- `vendor/bin/pint --dirty --format agent` → passed.
- The full suite was not completed on 2026-10-28 per Human direction
  ("specific test only"). An earlier attempt hit the 128 MB memory limit in
  the unrelated `CustomerCreatePageDiagnosticTest`.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- A Marketing Recommendation can be created from a sales call with no CRR.
- A CRR opened from a sales call without a Marketing shows the create-Marketing
  warning.
- A CRR opened while the Marketing is pending/returned/disapproved shows the
  approval-required warning.
- A CRR opens and saves after the Marketing is RAC-approved.
- Standalone CRRs and CRR edits still work.
- The CRR create sales-call list shows only sales calls with a locally
  approved Marketing; an unapproved sales call opened through the sales-call
  page still shows its existing warning.

## Blockers and remaining work

- No blockers for the 2026-10-28 follow-up.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

The prior implementation was committed by the Human in `99cd29f`
("Fixes: Change of Business Rules", 2026-10-27), which includes this Work
note. The 2026-10-28 follow-up remains local and uncommitted; no push or
database mutations were performed.
