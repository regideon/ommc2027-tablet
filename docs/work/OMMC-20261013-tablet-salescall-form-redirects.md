# OMMC-20261013-tablet-salescall-form-redirects

- **Title:** Tablet — Sales call form redirects and required-forms submit rule
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `TERMINAL_DELIVERED`
- **Design basis:** Approved in-chat bounded design, 2026-10-13.

## Objective

1. Under a checked-in sales call, add redirect buttons (Visit Actions grid)
   to the CRR Signage, Marketing (the user's "CRR Local Store Marketing"),
   and Trade Check forms, opened with this sales call preselected and locked.
2. Block a **Completed** visit submit — at the same level as the existing
   brands/photo blockers — until a CRR Signage, a Marketing, and a Trade
   Check submission exist for that sales call by the submitting rep.

## Human-authorized scope

Confirmed 2026-10-13:

- The three forms: **CRR Signage** (`SignageFormPage`), **Marketing**
  (`MarketingFormPage`; user calls it "CRR Local Store Marketing"), and
  **Trade Check** (`TradeCheckFormPage`).
- Redirect buttons live in the Visit Actions grid on the Overview tab.
- Tapping a form button preselects **and locks** the sales call in the form
  (same ownership scope as the existing dropdown). Save still returns to the
  Forms list.
- The required-forms rule gates **Completed** submissions only, enforced
  like the brands/photo blockers with a danger notification. Partially
  Completed / Cancelled submits stay unblocked.
- No renames of the Marketing form; no per-form status badges on buttons.
- Implementation authorization: Human design approval 2026-10-13
  ("yes this looks right"). Terminal authorization: **none**. No commit or
  push performed.

## Task-owned files

- `app/Filament/Pages/SalescallPage.php` — required-forms submit blocker.
- `app/Filament/Concerns/LocksRequestedSalescall.php` — shared
  preselect-and-lock concern for the three form pages.
- `app/Filament/Pages/SignageFormPage.php`,
  `app/Filament/Pages/MarketingFormPage.php`,
  `app/Filament/Pages/TradeCheckFormPage.php` — `?salescall=` preselect +
  lock.
- `resources/views/filament/pages/salescall-page.blade.php` — redirect
  buttons in Visit Actions + `openSalescallForm()` helper.
- `resources/views/filament/pages/signage-form-page.blade.php`,
  `marketing-form-page.blade.php`, `trade-check-form-page.blade.php` —
  locked sales-call rendering.
- Tests: `tests/Feature/Forms/SignageFormPageTest.php`,
  `MarketingFormPageTest.php`, `TradeCheckFormPageTest.php`,
  `tests/Feature/SalescallFormRequirementsTest.php` (new),
  `tests/Feature/SalescallFormRedirectsTest.php` (new),
  `tests/Pest.php` (shared form-submission fixtures), updated
  `tests/Feature/RegionTypeImageRequiredTest.php`,
  `tests/Feature/SalescallFinishLocationCorrelationTest.php`.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

None — the worktree was clean at start (`git status --short` empty).

## Decisions

- Redirect passes `?salescall=<local_uuid>`; the form accepts it only when
  the uuid belongs to the logged-in user's own sales calls (same scope as
  the existing dropdown). Invalid/foreign values are ignored, not a 404.
- The form page exposes a `salescallLocked` flag; the view disables the
  dropdown and shows a "Started from this Sales Call" note.
- The submit blocker requires, per form, a row with
  `salescall_id = <call>` and `submitted_by = <rep>`.
- Edit mode (`?submission=`) and Forms-page direct opens are unchanged.
- The three form pages share the preselect-and-lock logic through the
  `LocksRequestedSalescall` concern instead of three inline copies.

## Validation evidence

- TDD: every behavior was driven by a failing test first (form page
  preselect/lock, foreign-sales-call rejection, submit-blocker gating per
  form, wrong-sales-call and other-rep exclusions, partial-outcome
  exemption, redirect button wiring), then made green.
- Focused suites:
  - `php artisan test --compact tests/Feature/Forms` → **238 passed
    (1359 assertions)**; the three form-page suites alone → **28 passed
    (151 assertions)**.
  - `php artisan test --compact
    tests/Feature/SalescallFormRequirementsTest.php
    tests/Feature/SalescallFormRedirectsTest.php
    tests/Feature/RegionTypeImageRequiredTest.php
    tests/Feature/SalescallFinishLocationCorrelationTest.php` → the new
    requirement suite 4 passed (12 assertions); redirect suite 1 passed
    (10 assertions); the two updated suites 15 passed (63 assertions)
    together with the requirement suite.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` →
  **543 passed, 20 failed, 4 skipped (3249 assertions)**. Exactly the same
  20 pre-existing environment-dependent failures recorded in
  `OMMC-20261013-tablet-notifications` (location resolver/Nominatim, iOS
  native artifacts, photo preview mirrors, first-login persistence,
  startup diagnostics, `ExampleTest`); +14 tests over that delivered
  baseline, all new tests passing.
- `vendor/bin/pint --dirty --format agent` → passed; the three new PHP
  files also pass a direct Pint run.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- After check-in, the Visit Actions grid shows CRR Signage, Marketing
  (Local Store Marketing), and Trade Check buttons; tapping each opens its
  form with this sales call preselected, locked, and the note shown.
- Saving a form returns to the Forms list with the submission linked to
  that sales call.
- Completing a visit without any of the three forms shows the danger
  notification and does not complete; creating all three allows
  completion.
- Partially Completed / Cancelled submits are not blocked by the rule.

## Blockers and remaining work

- No blockers. Implementation and validation complete; manual acceptance
  remains pending the Human's runtime check.

## Delivery evidence status

Delivered in the terminal commit with `Work-ID:
OMMC-20261013-tablet-salescall-form-redirects` and `Work-State:
TERMINAL_DELIVERED` trailers (not in this note, which cannot contain the
commit's own hash). Commit SHA and push result are reported externally
after delivery.
