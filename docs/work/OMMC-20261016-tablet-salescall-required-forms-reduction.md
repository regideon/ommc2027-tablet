# OMMC-20261016-tablet-salescall-required-forms-reduction

- **Title:** Tablet — Salescall required set: drop CRR Signage + Marketing, keep Photo indicator and Trade Request
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Bounded in-chat design approved 2026-10-16 ("implement").
  Human directive: "under the Salescall remove the required validation for
  CRRSignage and Marketing and also add indicator for the image required";
  clarified 2026-10-16 that the existing footer Photo indicator is kept as-is.
- **Related Work:** `OMMC-20261015-tablet-salescall-form-requirements`
  (superseded for the required set; its CRR-before-Marketing creation gate
  stays in force), `OMMC-20261014-tablet-salescall-image-submit-state` (Photo
  indicator, unchanged), `OMMC-20261013-tablet-salescall-form-redirects`
  (prior required-forms rule).

## Objective

A Completed visit is no longer blocked by a missing CRR Signage or Marketing
submission. The required set becomes Brands + Photo (when the rep's region
type requires one) + Trade Request. The footer "Requires:" checklist drops
the CRR Signage and Marketing items and keeps the existing red/green Photo
indicator, so a missing required image stays visible.

## Human-authorized scope

Confirmed 2026-10-16:

- Remove the required validation for CRR Signage and Marketing on the
  Salescall page (server blocker + Submit gating + "Requires:" list).
- Keep/verify the existing Photo indicator; no new UI for it.
- Trade Request remains required; Brands/Photo rules unchanged.
- The CRR-before-Marketing creation gate is not part of this change.
- Implementation authorization: Human in-chat design approval 2026-10-16
  ("implement"). Terminal authorization: **none**. No commit or push.

## Task-owned files

- `app/Filament/Pages/SalescallPage.php` — removed the `hasSignageForm` /
  `hasMarketingForm` properties and their queries; `loadFormRequirements()`
  tracks only `hasTradeRequestForm`; the 'forms' blocker and its notification
  now reference only the Trade Request; removed the now-unused `Signage` /
  `Marketing` imports; updated docblocks.
- `resources/views/filament/pages/salescall-page.blade.php` — dropped the two
  entanglements and checklist items; `canSubmitSalescall` is now
  `hasSavedBrands && (!photoRequired || hasPhoto) && hasTradeRequestForm`.
- `tests/Feature/SalescallFormRequirementsTest.php` — required set is Trade
  Request only; new coverage for "Signage/Marketing no longer block or
  unlock", rep/call scoping, and checklist absence; photo and partial flows
  unchanged.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- Worktree was clean at start (`git status --short` empty). Untracked
  `.opencode/` and `graphify-out/` are pre-existing user-owned
  infrastructure; `graphify-out/` was refreshed with `graphify update .` per
  `AGENTS.md` (11425 nodes / 32291 edges / 407 communities), not staged.
- `storage/framework/lsp-*.php` transient runtime cache files may appear
  during test runs and clean themselves up; not part of this Work.

## Decisions

- Removed the `hasSignageForm` / `hasMarketingForm` Livewire properties
  entirely (they existed only to drive the checklist and Submit gating).
- `hasAllRequiredForms()` keeps its name and now checks only the rep-scoped
  Trade Request for the call.
- Blocked-submit notification text names only the Trade Request
  ("Create the Trade Request form for this sales call before completing.").
- The CRR-before-Marketing creation gate in `MarketingService` /
  `MarketingFormPage` is intentionally untouched.

## Validation evidence

- TDD RED: the test file was updated first and watched fail — **5 failed /
  3 passed (21 assertions)**, each for the expected reason:
  - "completing a visit is blocked until the Trade Request form exists…":
    `finish-done` was not fired after attaching only a Trade Request (the old
    blocker still demanded Signage + Marketing).
  - "a Trade Check submission does not satisfy…": same reason.
  - "a Trade Request linked to a different sales call…": same reason at the
    final step.
  - "a Trade Request created by another rep…": same reason.
  - "the submit requirements list drops CRR Signage and Marketing…":
    `assertDontSeeHtml` failed because the CRR Signage item was still
    rendered.
- TDD GREEN: the same file → **8 passed (28 assertions)**.
- Focused suites (`SalescallFormRequirementsTest`,
  `RegionTypeImageRequiredTest`, `SalescallFinishLocationCorrelationTest`,
  `SalescallFormRedirectsTest`, `Forms/MarketingFormPageTest`) →
  **36 passed (178 assertions)**; the photo-indicator and CRR-gate suites are
  unchanged and green.
- Mutation checks (each applied, watched fail, reverted):
  - `hasAllRequiredForms()` without the `submitted_by` scope → "another rep"
    test failed (1 failed).
  - without the `salescall_id` scope → "different sales call" test failed
    (1 failed).
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` →
  **707 passed, 28 failed, 4 skipped (5265 assertions)**. The 28 failures are
  pre-existing environment-dependent families, proven by re-running the same
  failing files with this Work's tracked changes stashed → group 1:
  **20 failed / 105 passed (1845 assertions)**, group 2:
  **8 failed / 33 passed (175 assertions)**; the failure sets are identical
  and no salescall-related test fails (families: customer location/resolver,
  CustomerCodePage reservation-token, CustomerCreatePage diagnostics,
  CustomerInteraction reconciliation, iOS native artifacts, photo preview
  mirrors, `SalescallStatusesMigrationTest`, startup diagnostics,
  `ExampleTest`).
- `vendor/bin/pint --dirty --format agent` → passed.
- `graphify update .` → graph.json / graph.html / GRAPH_REPORT.md rebuilt.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- With Brands saved, a required Photo attached, and a Trade Request saved,
  Submit Salescall completes even when no CRR Signage or Marketing exists.
- The "Requires:" list shows Brands / Photo (only when the rep's region type
  requires one) / Trade Request; CRR Signage and Marketing items are gone.
- The Photo item still shows red "(missing)" and blocks Submit when the rep
  requires an image and none is attached.
- CRR Signage, Marketing, and Trade Check remain available as Visit Actions
  tiles; the Marketing form still asks for a CRR first.

## Blockers and remaining work

- No blockers. Implementation and validation complete; manual acceptance
  remains pending the Human's runtime check.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation only; no commits, pushes, or database mutations.
