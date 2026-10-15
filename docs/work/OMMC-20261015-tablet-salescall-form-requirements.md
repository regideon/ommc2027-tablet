# OMMC-20261015-tablet-salescall-form-requirements

- **Title:** Tablet — Sales call required forms (Signage, Marketing, Trade Request) and CRR-before-Marketing gate
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Approved in-chat bounded design, 2026-10-15 ("yes" to the
  presented short design).
- **Related Work:** `OMMC-20261013-tablet-salescall-form-redirects` (delivered
  required-forms rule; superseded by this Work for the required set),
  `OMMC-20261013-tablet-salescall-crr-giveaway-redirects` (CRR/Giveaway
  redirects; CRR is now a prerequisite for Marketing),
  `OMMC-20261014-tablet-salescall-image-submit-state` (same
  blocker/checklist pattern).

## Objective

1. A **Completed** visit now requires a CRR Signage, a Marketing, and a
   **Trade Request** submission for that sales call by the submitting rep.
   **Trade Check** is no longer required; it remains available as a form.
2. A rep cannot **create a Marketing** linked to a sales call until they have
   created a **CRR** for that same sales call (gate on open + on save).
3. The sales call footer "Requires:" checklist and the Submit Salescall
   button reflect the three required forms exactly like Brands/Photo.

## Human-authorized scope

Confirmed 2026-10-15:

- Required set for Completed submit: CRR Signage + Marketing + Trade Request
  (Trade Request replaces Trade Check).
- CRR gate on Marketing: gate on open (locked sales call without a CRR shows
  a warning instead of the form) + save rejection; the rep's own CRR for the
  same sales call counts. Marketing without a sales call and edit mode are
  unaffected.
- Footer checklist gains the three form items (red/green) and Submit is
  disabled until they exist, like Brands.
- Recommended Trade Request redirect tile under Visit Actions (preselect +
  lock), matching the other forms.
- Implementation authorization: Human design approval 2026-10-15 ("yes").
  Terminal authorization: **none**. No commit or push.

## Task-owned files

- `app/Filament/Pages/SalescallPage.php` — required-forms swap to
  Trade Request; form-requirement flags for the checklist.
- `resources/views/filament/pages/salescall-page.blade.php` — Requires list
  items, `canSubmitSalescall`, Trade Request tile + URL.
- `app/Filament/Pages/TradeRequestFormPage.php` +
  `resources/views/filament/pages/trade-request-form-page.blade.php` — the
  `LocksRequestedSalescall` preselect/lock.
- `app/Filament/Pages/MarketingFormPage.php` +
  `resources/views/filament/pages/marketing-form-page.blade.php` — CRR gate
  on open.
- `app/Services/Forms/MarketingService.php` — CRR gate on create.
- Tests: `tests/Pest.php` (fixtures), `tests/Feature/SalescallFormRequirementsTest.php`,
  `tests/Feature/SalescallFormRedirectsTest.php`,
  `tests/Feature/Forms/MarketingFormPageTest.php`,
  `tests/Feature/Forms/TradeRequestFormPageTest.php`.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- `AGENTS.md` modified (graphify section) and untracked `.opencode/`,
  `graphify-out/` — pre-existing, not owned by this Work. `graphify-out/`
  was refreshed with `graphify update .` per `AGENTS.md` after the code
  changes; it is user-owned infrastructure, not staged.
- `storage/framework/lsp-*.php` transient runtime cache files may appear
  during test runs and clean themselves up; not part of this Work.

## Decisions

- CRR ownership scoping mirrors the required-forms rule:
  `submitted_by = <rep>` + `salescall_id = <call>`.
- The create-time CRR gate lives in `MarketingService::create()` (the
  authoritative offline write path); the form page mirrors it on open for
  the locked-salescall path.
- No Portal/sync payload changes; no renames.

## Validation evidence

- TDD: every behavior was driven by a failing test first, each watched RED
  for the expected reason:
  - `SalescallFormRequirementsTest`: 4 failed / 3 passed — required set
    still demanded Trade Check, `loadFormRequirements` did not exist
    (MethodNotFoundException), checklist markup absent.
  - `SalescallFormRedirectsTest` + `TradeRequestFormPageTest`: 4 failed /
    7 passed — no `openSalescallForm('tradeRequest')` wiring, lock property
    and disabled select absent.
  - `MarketingFormPageTest`: 5 failed / 8 passed after strengthening two
    non-strict `assertSet(..., false)` assertions to strict — gate-on-open
    flag/markup missing, save without CRR succeeded instead of erroring,
    foreign-CRR guard missing. (The save-with-CRR guard passed from the
    start by design: it protects against over-blocking.)
- Mutation checks (each applied, watched fail, reverted):
  - `SalescallPage::loadFormRequirements()` without the `submitted_by`
    scope → tracked-forms test failed;
  - `MarketingService::crrExistsFor()` without `submitted_by` → other-rep
    test failed;
  - `crrExistsFor()` without `salescall_id` → other-rep/other-call test
    failed.
- Focused suites (7 files: requirements, redirects, Marketing form page,
  Trade Request form page, RegionTypeImageRequired, finish-location
  correlation, Marketing workflow) → **56 passed (272 assertions)**.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` →
  **670 passed, 20 failed, 4 skipped (5078 assertions)**. The 20 failures
  are pre-existing environment-dependent families, proven by re-running
  the same 11 failing files with this Work's tracked changes stashed →
  **20 failed / 40 passed**, an identical failure set (customer
  location/resolver, iOS native artifacts, photo preview mirrors,
  first-login persistence, startup diagnostics, `ExampleTest`,
  `SalescallStatusesMigrationTest`). No regressions; this Work adds 11
  tests (requirements +3, Trade Request form page +3, Marketing form page
  +5), all passing.
- `vendor/bin/pint --dirty --format agent` → passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- Completing a visit without CRR Signage / Marketing / Trade Request is
  blocked; the Requires list shows the missing items in red and Submit stays
  disabled until each exists.
- Trade Check no longer blocks completion.
- Tapping Marketing from a sales call without a CRR shows the
  create-a-CRR-first warning instead of the form; after creating a CRR the
  Marketing form opens locked to that visit.

## Blockers and remaining work

- No blockers. Implementation and validation complete; manual acceptance
  remains pending the Human's runtime check.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation only; no commits, pushes, or database mutations.
