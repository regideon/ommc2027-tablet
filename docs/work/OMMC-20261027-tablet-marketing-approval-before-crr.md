# OMMC-20261027-tablet-marketing-approval-before-crr

- **Title:** Tablet — require an approved Marketing Recommendation before creating a CRR
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `ACTIVE`
- **Design basis:** Bounded in-chat design approved 2026-10-27 ("implement").
  Human directive: reverse the current prerequisite — remove the
  CRR-before-Marketing gate, and require a Marketing Recommendation to be
  approved before a CRR can be created.
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

## Human-authorized scope

Confirmed 2026-10-27:

- Remove the Marketing CRR-first gate from the form page, service, view, and
  tests.
- Add the reversed Marketing-approval gate to CRR creation (locked form open
  and save-layer validation).
- Refresh and persist Marketing SPS/RAC status from the Portal pull; no new
  Portal endpoint or Portal code.
- Keep the Portal untouched; Tablet-side enforcement only.
- Implementation authorization: Human "implement" 2026-10-27.
  Terminal authorization: **none**. No commit or push.

## Task-owned files

- `app/Models/Forms/Marketing/Marketing.php` — approval helper.
- `app/Services/Forms/MarketingService.php` — remove CRR gate; add status
  refresh and approval-state lookup.
- `app/Services/Forms/CrrService.php` — enforce approved Marketing on create.
- `app/Filament/Pages/MarketingFormPage.php` — remove CRR-required state and
  gate.
- `app/Filament/Pages/CrrFormPage.php` — approval-required warning and
  pre-save refresh.
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

## Validation evidence

Pending.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- A Marketing Recommendation can be created from a sales call with no CRR.
- A CRR opened from a sales call without a Marketing shows the create-Marketing
  warning.
- A CRR opened while the Marketing is pending/returned/disapproved shows the
  approval-required warning.
- A CRR opens and saves after the Marketing is RAC-approved.
- Standalone CRRs and CRR edits still work.

## Blockers and remaining work

- Implementation in progress.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation only; no commits, pushes, or database mutations.
