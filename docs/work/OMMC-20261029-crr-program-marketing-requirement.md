# OMMC-20261029-crr-program-marketing-requirement

- **Title:** Per-program Marketing Recommendation requirement for CRRs
- **Repository role:** Tablet `ommc2027-tablet` (paired with Portal `ommc2027`)
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Bounded in-chat design approved 2026-10-29 ("yes").
  Human directive: consume `is_marketing_required` from the Portal CRR program
  payload; when a selected program requires Marketing, prompt for an approved
  Marketing Recommendation and block save.
- **Related Work:** `OMMC-20261029-tablet-crr-marketing-gate-removal` (removed
  the blanket gate; this Work restores it per program).

## Objective

1. Cache `is_marketing_required` on local `crr_programs`.
2. On a sales-call CRR, selecting a requiring program immediately shows the
   approval warning and keeps Save disabled until the Marketing is approved.
3. Service-level validation rejects linked CRR create/edit saves when the
   selected program requires Marketing and the sales call is not approved.
4. Non-requiring programs and standalone CRRs save normally.

## Human-authorized scope

Confirmed 2026-10-29:

- Add the local column/cast, sync handling, UI warning and save guard.
- Apply the guard to create and edit for linked sales calls only.
- Keep the existing approval lookup; retain advisory behavior for programs
  that do not require Marketing.
- No commit, push, or terminal delivery without separate authorization.

## Task-owned files

- `database/migrations/*_add_is_marketing_required_to_crr_programs_table.php`
- `app/Models/Forms/CRR/CrrProgram.php`
- `app/Services/Forms/CrrService.php`
- `app/Filament/Pages/CrrFormPage.php`
- `resources/views/filament/pages/crr-form-page.blade.php`
- `tests/Feature/Forms/CrrFoundationTest.php`
- `tests/Feature/Forms/CrrFormPageTest.php`
- `tests/Feature/Forms/CrrWorkflowTest.php`
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- The uncommitted `OMMC-20261029-tablet-crr-marketing-gate-removal` changes
  are pre-existing and behaviorally superseded by this Work; they must be
  delivered together. No commit or push has been made.
- Untracked `.opencode/` and `graphify-out/` are user-owned infrastructure;
  `graphify-out/` is refreshed with `graphify update .` per `AGENTS.md`.
- `storage/framework/lsp-*.php` transient runtime cache files may appear
  during tests and clean themselves up.

## Decisions

- The Portal flag defaults to required when missing from the API payload.
- The guard applies only when the CRR is linked to a sales call; standalone
  CRRs have no Marketing Recommendation to approve against.
- `approved` passes; `none`, `pending`, and `unknown` block with state-specific
  messaging.
- The service guard is authoritative; the disabled Save button is guidance.

## Validation evidence

- `php artisan test --compact tests/Feature/Forms/CrrFoundationTest.php tests/Feature/Forms/CrrFormPageTest.php tests/Feature/Forms/CrrWorkflowTest.php`
  → 56 passed (218 assertions).
- `vendor/bin/pint --dirty --format agent` → passed.
- Only the targeted CRR tests were run per Human direction ("specific test
  only"). The full suite was not run.
- Runtime 2026-10-29: the pending Tablet migration was applied, CRR programs
  were re-synced from the Portal, and a guarded create for GCI Reconsideration
  on a sales call with no Marketing returned `BLOCKED: Create a Marketing
  Recommendation for this sales call before saving a CRR with this program.`

## Manual acceptance

Pending. Requires the Human to confirm on the Tablet:

- A requiring program with no/pending Marketing shows the warning and cannot
  be saved.
- A requiring program saves after Marketing approval.
- A non-requiring program saves without Marketing.
- A standalone CRR saves without Marketing.

## Blockers and remaining work

- No blockers.
- Manual acceptance remains pending.
- The earlier uncommitted `OMMC-20261029-tablet-crr-marketing-gate-removal`
  changes are still present and must be delivered together with this Work.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

Not delivered. Local implementation and validation only.
