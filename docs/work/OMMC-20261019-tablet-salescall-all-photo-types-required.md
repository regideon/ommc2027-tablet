# OMMC-20261019-tablet-salescall-all-photo-types-required

- **Title:** Tablet — Salescall submit requires a photo for every category and type
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Bounded in-chat design approved 2026-10-19 ("yes").
  Human directive: "in uploading photos under salsecall we need to required
  all the category and type of the photos before able to submit the salescall
  kindly add in validations"; clarified that every photo type is required and
  the existing IB Team / OE Team exemption stays.
- **Related Work:** `OMMC-20261014-tablet-salescall-image-submit-state`
  (introduced the current at-least-one-photo gate),
  `OMMC-20261016-tablet-salescall-submit-requirements`, and
  `OMMC-20261019-tablet-salescall-trade-request-requirement-disable`
  (Brands + Photo remain the only completion requirements).

## Objective

When the rep's region type requires photos, a Completed Salescall cannot be
submitted until every configured `SalescallImageType` has at least one photo
for that visit. With the seeded data this means all 9 types across the
Exterior and Interior categories. The Submit button and footer indicator
reflect the same rule. Reps whose region type is exempt (IB Team / OE Team),
or who have no linked region type, follow the existing behaviour (no region
type still requires photos). Partially Completed and Cancelled are unchanged.

## Human-authorized scope

Confirmed 2026-10-19:

- Require every photo type, which also covers every category.
- Keep the existing region-type exemption for IB Team / OE Team.
- Enforce in both the server-side completed-submit blocker and the Submit UI.
- Do not change photo acquisition, persistence, sync, or the photo wizard.
- Implementation authorization: Human design approval 2026-10-19 ("yes").
  Terminal authorization: **none**. No commit or push.

## Task-owned files

- `app/Filament/Pages/SalescallPage.php` — `allPhotoTypesCovered()` now
  verifies every configured type ID; `salescallSubmitBlocker()` uses it for
  the photo gate; blocked-submit notification text updated.
- `resources/views/filament/pages/salescall-page.blade.php` —
  `canSubmitSalescall` and the footer Photo indicator use `photosComplete`
  instead of `hasPhoto`.
- `tests/Feature/RegionTypeImageRequiredTest.php` — two-category fixture and
  coverage for partial sets, complete sets, the no-region-type default, the
  exempt region type, `photosComplete` tracking, and the footer markup.
- `tests/Feature/SalescallFormRequirementsTest.php` — footer Photo markup
  expectation follows the new entanglement.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- `app/Filament/Pages/SalescallPage.php` already contained an uncommitted
  `Illuminate\Support\Str` import and `Str::uuid()` replacement before this
  Work. It is preserved; only photo validation lines are owned here.
- `storage/framework/lsp-*.php` additions/deletions are transient runtime
  cache files, not part of this Work.
- Untracked `docs/work/OMMC-20261016-*.md` notes and
  `tests/Feature/SyncServiceSalescallPushTest.php` belong to other Work.
- `graphify-out/` is user-owned generated infrastructure; it may be refreshed
  by `graphify update .` but is not staged.

## Decisions

- Reused the existing `photosComplete` / `allPhotoTypesCovered()` state rather
  than adding new public properties.
- Replaced the count comparison with an exact set check so a photo of an
  unrelated/deleted type cannot satisfy the requirement.
- Kept `hasPhoto` as the "at least one photo" state for existing tracking, but
  it no longer gates Submit.
- Kept the zero-types case permissive: if no photo types are configured there
  is nothing to require.

## Validation evidence

- TDD RED: `php artisan test --compact --filter=RegionTypeImageRequiredTest`
  → **2 failed / 6 passed (24 assertions)**; the partial-set visit completed
  and the footer still rendered the old `hasPhoto` markup.
- TDD GREEN: same command → **8 passed (28 assertions)**.
- Focused: `RegionTypeImageRequiredTest` + `SalescallFormRequirementsTest`
  → **16 passed (46 assertions)**.
- Broader salescall run including `SalescallPhotoUploadTest`,
  `SalescallFinishLocationCorrelationTest`, and `SalescallFormRedirectsTest`
  → 5 failed / 37 passed (152 assertions). Four failures are the documented
  pre-existing photo-preview-mirror family in `SalescallPhotoUploadTest`;
  the fifth was the stale footer expectation in
  `SalescallFormRequirementsTest`, fixed above and green in the focused rerun.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` →
  **712 passed, 29 failed, 4 skipped (5293 assertions)**. Twenty-eight
  failures are the documented pre-existing environment-dependent families
  (customer code/location/map, Customer Create diagnostics, iOS native
  artifacts, photo preview mirrors, `SalescallStatusesMigrationTest`,
  startup diagnostics, `ExampleTest`). The remaining failure is the
  pre-existing untracked `SyncServiceSalescallPushTest` ("failed salescalls
  are pushed again on the next sync", expected 2 requests, got 1); that test
  and `SyncService` were untouched by this Work.
- `vendor/bin/pint --dirty --format agent` → fixed formatting, exit 0.
- `php -l` on the changed PHP files/tests → no syntax errors.
- `git diff --check` → clean.
- `graphify update .` → graph rebuilt (11540 nodes / 32476 edges / 416
  communities); no application behavior change from the graph refresh.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- Submit stays disabled and the footer Photo item stays red while any photo
  type has no photo.
- Submit enables and the footer item turns green once every category/type has
  a photo.
- A rep in IB Team / OE Team can still submit without photos.
- Deleting a photo for a type re-disables Submit.

## Blockers and remaining work

- No blockers. Implementation and validation complete; manual acceptance
  remains pending the Human's runtime check.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation only; no commits, pushes, or database mutations.
