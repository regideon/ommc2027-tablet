# OMMC-20261014-tablet-salescall-image-submit-state

- **Title:** Tablet — Sales call Submit button and requirements list reflect the RegionType photo rule
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Approved in-chat bounded design, 2026-10-14
  ("yes" to the presented short design).
- **Related Work:** `OMMC-20261004-region-type-image-required` (server-side
  rule this mirrors), `OMMC-20261013-tablet-salescall-form-redirects`
  (delivered; same blocker/notification pattern),
  `OMMC-20261013-tablet-salescall-crr-giveaway-redirects` (same page,
  ready for review).

## Objective

Make the sales call page's Completed-submit UI reflect the already-enforced
server rule (`RegionType::is_image_required`): the Submit Salescall button is
disabled while the required photo is missing, and the footer "Requires:" list
shows a Photo item — "Photo (missing)" in red — exactly like the existing
Brands item. A rep whose region type is exempt (IB Team / OE Team) sees no
Photo item and is not blocked by it. A rep with no linked region type is
treated as requiring a photo.

## Human-authorized scope

Confirmed 2026-10-14:

- Require the image upload based on `app/Models/RegionType.php`
  (`is_image_required`), matching the existing server-side blocker in
  `SalescallPage::salescallSubmitBlocker()`.
- Disable the Submit Salescall button while the required photo is missing,
  similar to how missing Brands already disable it and show as required.
- No change to the server-side Completed gate itself, the Upload Photo
  action tile, the per-subcategory `photosComplete` rule, or the
  required-forms rule.
- Implementation authorization: Human in-chat design approval 2026-10-14.
- Branch placement (2026-10-14): Human instruction "for this region type
  make another branch for this changes"; confirmed branch
  `28-tablet-salescall-image-submit` with "commit there, stay on new
  branch". The Work files are committed on that branch (local only).
  Terminal authorization (push / PR): **none**.

## Accepted decisions

- "Required photo" means **at least one** salescall photo — the ruling's
  wording and the server blocker's rule — not the outdated, commented-out
  "photo in every subcategory" rule.
- The requirement flag (`photoRequired`) is evaluated server-side in
  `mount()` from the logged-in rep's region type and defaulted to required;
  the photo presence flag (`hasPhoto`) is refreshed by `loadPhotos()`.
- The Photo item renders only when `photoRequired` is true, so exempt reps
  are not shown a requirement that does not apply to them.

## Implementation constraints

- Original implementation and validation: local only. Branch placement
  per the 2026-10-14 Human instruction commits the Work files on
  `28-tablet-salescall-image-submit`; **no push**.
- Preserve unrelated worktree changes.

## Task-owned files/components

- `app/Filament/Pages/SalescallPage.php` — `photoRequired` / `hasPhoto`
  public properties; `mount()` and `loadPhotos()` assignments.
- `resources/views/filament/pages/salescall-page.blade.php` — entangled
  `photoRequired` / `hasPhoto`; `canSubmitSalescall` now requires the photo
  when required; footer "Requires:" Photo item; removed the stale
  commented-out photo block.
- `tests/Feature/RegionTypeImageRequiredTest.php` — 3 new tests.
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

None — the worktree was clean at start (`git status --short` empty).
Test runs transiently generated `storage/framework/lsp-*.php` runtime cache
files, which clean themselves up; the final worktree contains only this
Work's files (the three implementation files plus this note).

## Implementation decisions

- The Alpine getter `canSubmitSalescall` stays the single source for the
  button/branch UI and now reads
  `hasSavedBrands && (!photoRequired || hasPhoto)`; the real enforcement
  remains server-side in `initiateFinish` → `salescallSubmitBlocker()`,
  unchanged.
- `photoRequired` mirrors the blocker's `?? true` default, so an unlinked
  rep is blocked exactly like the server blocks them.
- The footer item uses the same markup pattern as the Brands item
  (`(missing)` toggle bound to the entangled flag) for visual consistency.

## Validation evidence

- TDD: the 3 new tests were written first and watched fail for the right
  reason — RED run: `null` vs `true` on both flags, missing
  `<span>Photo<span x-show="!hasPhoto"> (missing)</span></span>` markup;
  existing 5 tests passed. GREEN: focused file **8 passed (23
  assertions)**.
- Mutation checks (each applied, watched fail, reverted):
  - unscoped photo existence query → caught by the "without counting other
    calls' photos" test (`true` vs `false`);
  - `photoRequired` hardcoded `true` → caught by the region-type mapping
    test (`true` vs `false`);
  - photo item rendered unconditionally → caught by the
    shows-only-when-required test.
- Related suites together: `RegionTypeImageRequiredTest`,
  `SalescallFormRequirementsTest`, `SalescallFormRedirectsTest`,
  `SalescallFinishLocationCorrelationTest` → **19 passed (86 assertions)**.
- Full suite (`php -d memory_limit=512M vendor/pestphp/pest/bin/pest
  --compact`) → **658 passed, 20 failed, 4 skipped (4979 assertions)**.
  The failure-name set is **identical to pristine `HEAD`**: the 11 failing
  files were re-run with this Work's changes stashed → 20 failed / 43
  passed, and a programmatic comparison of failure names returned
  "IDENTICAL failure sets". All failures are the documented
  environment-dependent families (location resolver/Nominatim, iOS native
  artifacts, photo preview mirrors, first-login persistence, startup
  diagnostics, `ExampleTest`, `SalescallStatusesMigrationTest`); no
  regressions.
- `vendor/bin/pint --dirty --format agent` → passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- A rep in IB Team / OE Team sees only "Brands" in the Requires list and
  can Complete without a photo (when brands and required forms are done).
- A rep in any other region type (or with no region type) sees a red
  "Photo (missing)" item and the Submit Salescall button is disabled until
  a photo is attached; attaching a photo turns the item green and enables
  Submit.

## Blockers and remaining work

- No blockers. Implementation and validation complete; manual acceptance
  remains pending the Human's runtime check.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

Local commit on branch `28-tablet-salescall-image-submit` (forked from
`27-tablet-customer-syncing` at `1bf4c00`), containing the three
implementation files and this note; **not pushed**. The commit hash is
reported externally (a Work note cannot contain its own commit's hash).
Terminal delivery (push / PR) awaits separate explicit Human
authorization. No database mutations performed for this Work.
