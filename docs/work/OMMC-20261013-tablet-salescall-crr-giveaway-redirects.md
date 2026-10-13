# OMMC-20261013-tablet-salescall-crr-giveaway-redirects

- **Title:** Tablet — CRR and Giveaway redirects under the sales call (not required)
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Approved in-chat bounded design, 2026-10-13.

## Objective

Add CRR and Giveaway redirect buttons to a checked-in sales call's Visit
Actions grid, opened with this sales call preselected and locked — **not**
part of the completed-submit requirement.

## Human-authorized scope

Confirmed 2026-10-13 ("implement"):

- Two new buttons: **CRR** (`CrrFormPage`) and **Giveaway**
  (`GiveawayFormPage`), same Visit Actions pattern as the five existing
  actions.
- Both forms preselect and lock the sales call when opened from the sales
  call page (same ownership rules as CRR Signage, Marketing, Trade Check).
- The completed-submit required-forms rule is **unchanged**: only CRR
  Signage, Marketing, and Trade Check are required; CRR and Giveaway never
  block completion.
- Implementation authorization: Human `implement` GO on 2026-10-13.
  Terminal authorization: none. No commit or push performed.

## Task-owned files

- `app/Filament/Pages/CrrFormPage.php`,
  `app/Filament/Pages/GiveawayFormPage.php` — `LocksRequestedSalescall`
  concern + lock call.
- `resources/views/filament/pages/crr-form-page.blade.php`,
  `giveaway-form-page.blade.php` — locked sales-call rendering.
- `resources/views/filament/pages/salescall-page.blade.php` — CRR and
  Giveaway redirect buttons + form URLs.
- Tests: `tests/Feature/Forms/CrrFormPageTest.php`,
  `tests/Feature/Forms/GiveawayFormPageTest.php`,
  `tests/Feature/SalescallFormRedirectsTest.php`.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

None.

## Decisions

- Reuse the delivered `LocksRequestedSalescall` concern and
  `openSalescallForm()` helper; no new mechanism.
- No change to `SalescallPage::hasAllRequiredForms()`.
- Button order in Visit Actions: Upload Photo, CRR, CRR Signage, Marketing,
  Trade Check, Giveaway, Add Expense, Add Quick Note.

## Validation evidence

- TDD: every behavior was driven by a failing test first (CRR and
  Giveaway preselect/lock, unknown/foreign sales-call rejection, the two
  new redirect buttons and their URL mapping), then made green.
- Focused: `php artisan test --compact tests/Feature/Forms
  tests/Feature/SalescallFormRequirementsTest.php
  tests/Feature/SalescallFormRedirectsTest.php` → **249 passed
  (1412 assertions)**. The requirement test still completes a visit with
  only CRR Signage + Marketing + Trade Check, proving CRR and Giveaway
  remain non-required.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` →
  **612 passed, 23 failed, 4 skipped (4683 assertions)**. The branch tip
  (`c812058`, after the Human merged PR #25 and `main` back into the
  branch) raised the baseline: 13 failures are the documented
  environment-dependent family (location resolver/Nominatim, iOS native
  artifacts, photo preview mirrors, first-login persistence, startup
  diagnostics, `ExampleTest`), and 10 are post-merge `main` failures in
  customer/sync tests (`CustomerCodePageTest` ×3, `CustomerLocationMapTest`
  ×2, `CustomerPageDetailsTest` ×3, `CustomerPagedPullTest` ×1,
  `SalescallStatusesMigrationTest` ×1). All 10 were reproduced on pristine
  `HEAD` with this Work's changes stashed (10 failed / 35 passed at the
  same test files), so none are caused by this Work; +6 new tests over the
  tip, all passing.
- `vendor/bin/pint --dirty --format agent` → passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- After check-in, the Visit Actions grid also shows CRR and Giveaway
  buttons; tapping each opens its form with this sales call preselected,
  locked, and the note shown.
- Completing a visit still requires only CRR Signage, Marketing, and
  Trade Check — CRR and Giveaway submissions are optional.

## Blockers and remaining work

- No blockers. Implementation and validation complete; manual acceptance
  remains pending the Human's runtime check.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation only; no commits, pushes, or database
mutations performed for this Work.
