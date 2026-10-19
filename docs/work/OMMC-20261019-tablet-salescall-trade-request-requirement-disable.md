# OMMC-20261019-tablet-salescall-trade-request-requirement-disable

- **Title:** Tablet — Salescall completion: disable the Trade Request requirement (phase 2)
- **Repository role:** Tablet `ommc2027-tablet` only. The Portal `ommc2027`
  admin/API was checked; no changes were required.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Bounded in-chat design approved 2026-10-19 ("implement").
  Human directive: "under the sales call can you comment the required for
  Trade check form this is not yet needed now since it is for phase 2 check
  the api as well ./ommc2027 in admin"; clarified that the form currently
  listed under the Salescall submit gate is the **Trade Request**, not the
  Trade Check, and that the Trade Request gate is the one to disable.
- **Related Work:** `OMMC-20261016-tablet-salescall-required-forms-reduction`
  (its Trade Request part is superseded by this Work),
  `OMMC-20261013-tablet-salescall-form-redirects` (prior required-forms rule).

## Objective

A Completed visit is no longer blocked by a missing Trade Request submission.
Brands and Photo (when the rep's region type requires one) remain the only
completion requirements. The Trade Request form remains available from Visit
Actions; it is simply not required until phase 2.

## Human-authorized scope

Confirmed 2026-10-19:

- Disable the Trade Request requirement on the Salescall page (server blocker
  and Submit gating) and remove its item from the "Requires:" checklist.
- Keep Brands and Photo requirements unchanged.
- Keep Trade Request tracking in place for the eventual phase 2 re-enable.
- Inspect the Portal `ommc2027` admin/API for a matching requirement; change
  it only if one exists.
- Implementation authorization: Human in-chat approval 2026-10-19
  ("implement"). Terminal authorization: **none**. No commit or push.

## Task-owned files

- `app/Filament/Pages/SalescallPage.php` — `hasAllRequiredForms()` now
  returns `true` with the Trade Request query commented and marked phase 2;
  `loadFormRequirements()` and `$hasTradeRequestForm` are retained for phase 2;
  docblocks updated.
- `resources/views/filament/pages/salescall-page.blade.php` —
  `canSubmitSalescall` no longer requires `hasTradeRequestForm`; the Trade
  Request row in the "Requires:" checklist is commented out and marked
  phase 2.
- `tests/Feature/SalescallFormRequirementsTest.php` — completion now succeeds
  without a Trade Request; signage, marketing, Trade Check, cross-call, and
  other-rep submissions neither block nor unlock; the requirements list test
  now asserts Trade Request is absent while Photo remains.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- `storage/framework/lsp-895011e23121fb89.php` was already deleted at start
  (transient runtime cache); not part of this Work.
- Untracked `.opencode/` and `graphify-out/` are pre-existing user-owned
  infrastructure; `graphify-out/` was refreshed with `graphify update .` per
  `AGENTS.md`, not staged.

## Decisions

- Commented the Trade Request gate instead of deleting it, so phase 2 can
  restore the query and checklist row with minimal churn.
- Kept `loadFormRequirements()` and the `hasTradeRequestForm` Livewire
  property so the flag remains accurate when the gate returns.
- Removed no tests; each existing case was repurposed to assert the new
  non-blocking behavior, preserving coverage.

## Portal API/admin check

No matching requirement exists in `ommc2027`:

- `routes/api.php` exposes salescall push endpoints
  (`/api/salescalls/push`, `push-image`, `push-brands`, `push-category`) and
  separate Trade Request endpoints; the Salescall push request validates only
  its own payload and does not require any form.
- `PushSalescallRequest` and `SalescallController` do not inspect Trade
  Request or Trade Check submissions.
- The admin Sales Call resource (`ViewSalescall`) is read-only and has no
  form-completion gate.
- `PushTradeRequestRequest` / `TradeRequestSyncService` only store the Trade
  Request payload; nothing ties it to completing a visit.

Therefore no Portal code change was made.

## Validation evidence

- TDD RED: `php artisan test --compact --filter=SalescallFormRequirementsTest`
  → **6 failed / 2 passed (12 assertions)**, each for the expected reason:
  completion still blocked without a Trade Request, and the Trade Request item
  still rendered in the requirements list.
- TDD GREEN: same command → **8 passed (18 assertions)**.
- Focused regression suites (`RegionTypeImageRequiredTest`,
  `SalescallFormRedirectsTest`, `SalescallFinishLocationCorrelationTest`,
  `SalescallResumeVisitTest`) → **18 passed (90 assertions)**; the Brands and
  Photo gates remain intact.
- `vendor/bin/pint --dirty --format agent` → passed.
- `graphify update .` → no code-graph topology changes detected; outputs
  left untouched.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- With Brands saved and any required Photo attached, Submit Salescall
  completes even when no Trade Request exists.
- The "Requires:" list shows Brands and Photo only; Trade Request is gone.
- The Trade Request tile still opens the form and saving it still works.
- A visit with no Brands, or a missing required Photo, still cannot be
  completed.

## Blockers and remaining work

- No blockers. Implementation and validation complete; manual acceptance
  remains pending the Human's runtime check.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation only; no commits, pushes, or database mutations.
