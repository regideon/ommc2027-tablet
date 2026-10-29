# OMMC-20261029-tablet-crr-marketing-gate-removal

- **Title:** Tablet — remove the approved-Marketing prerequisite from CRR creation
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Bounded in-chat design approved 2026-10-29 ("yes").
  Human directive: a sales-call CRR must open and save regardless of the
  Marketing Recommendation approval state. Retain the approval-state lookup,
  drop the "Create a Marketing Recommendation first" gate, and keep
  pending/unverified Marketing messaging advisory only.
- **Related Work:** `OMMC-20261027-tablet-marketing-approval-before-crr`
  (introduced the approved-Marketing CRR gate; superseded here for that gate).

## Objective

1. Opening the CRR form from a sales call always shows the form, whether the
   linked Marketing Recommendation is missing, pending, returned,
   disapproved, unverified, or approved.
2. Saving a CRR linked to the rep's sales call succeeds without a Marketing
   Recommendation approval.
3. The Marketing approval lookup remains and still reports `approved`,
   `pending`, `none`, or `unknown`; pending/unverified states show advisory
   banners above an editable form.
4. The create sales-call picker lists all of the rep's sales calls, not only
   those with a locally approved Marketing.
5. Standalone CRRs and CRR edit mode remain unaffected.

## Human-authorized scope

Confirmed 2026-10-29 ("yes"):

- Remove the "Create a Marketing Recommendation first" gate and CTA from the
  CRR form.
- Keep the approval-state lookup and show pending/unverified banners as
  advisory only.
- Remove the CRR create-time approval guard and the approved-Marketing
  sales-call picker filter.
- Keep Marketing approval status syncing and Marketing form behavior
  unchanged.
- Implementation authorization: Human "yes" 2026-10-29. Terminal
  authorization: **none**.

## Task-owned files

- `app/Filament/Pages/CrrFormPage.php` — keep lookup, remove create-list
  filter and blocking behavior.
- `resources/views/filament/pages/crr-form-page.blade.php` — drop the
  create-Marketing gate; keep advisory banners above the form.
- `app/Services/Forms/CrrService.php` — remove the create-time Marketing
  approval guard.
- `tests/Feature/Forms/CrrFormPageTest.php`,
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

- The lookup remains authoritative for display only; it no longer gates the
  CRR form or save.
- The `none` state renders no banner because there is nothing to review yet.
- `pending` covers pending, returned, and disapproved Marketing states; its
  banner copy avoids claiming the CRR is blocked.
- The service guard is removed rather than relaxed so the save layer matches
  the form behavior.

## Validation evidence

- `php artisan test --compact tests/Feature/Forms/CrrFormPageTest.php tests/Feature/Forms/CrrWorkflowTest.php`
  → 44 passed (158 assertions).
- `vendor/bin/pint --dirty --format agent` → passed.
- Broader `php artisan test --compact tests/Feature/Forms` → 291 passed
  (1576 assertions), 2 failed in unrelated pre-existing coverage:
  `FormRuleClassesTest` (missing `items.*.name` attribute) and
  `FoundationEnumsTest` (`GiveawayCategory::GC` label expects "Gift Check",
  actual "Gift Certificates").
- The full suite was not run per Human direction 2026-10-29 ("specific test
  only").

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- Tapping CRR from a sales call without a Marketing opens the CRR form.
- Tapping CRR from a sales call with a pending Marketing opens the CRR form
  with an advisory banner.
- Saving a CRR without an approved Marketing succeeds and redirects.
- Standalone CRRs and CRR edits still work.

## Blockers and remaining work

- No blockers.
- Manual acceptance remains pending.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

Not delivered. Local implementation and validation only.
