# OMMC-20261002-customer-create-form-hook-restoration

## Work state

`READY_FOR_TERMINAL_REVIEW`

## Objective

Restore the Add Customer page's scoped `customer-create-form` styling hook so
the previously accepted Add Customer field borders/rounding/background treat
the rendered editable controls again.

## Affected repository

- `/Users/highlite/Workspace-Kaisa/PHP/ommc2027-tablet` (this worktree)
- Branch in this worktree at implementation time: `1-tablet-customer-module-location` (`5cade44`)
- Portal is not affected or modified.

## Human-authorized scope

- Bug report from the Human: "the Customer page have missing css
  customer-create-form".
- Implementation authorization: local implementation and validation only.
- Terminal delivery (commit/push) is **not** authorized.

## Accepted decisions

- The CSS rules in `resources/css/filament/saleshub/theme.css` and the built
  Vite theme asset are already correct and current; the regression is that the
  `customer-create-form` hook class is absent from the rendered `<form>`.
- The fix is the minimal restoration of the hook class; no CSS changes, no
  rebuild, and no behavior change.
- Broader alignment/multi-select work from the abandoned
  `OMMC-20261001-ipad-customer-form-alignment` branch is explicitly out of
  scope.

## Implementation constraints

- Do not change form content, layout, validation, persistence, synchronization,
  Livewire bindings, or the location picker.
- Preserve unrelated worktree changes; none existed at start.
- Do not commit or push.

## Root cause

Commit `b406d5b` (Work `OMMC-20261001-ipad-customer-form-field-borders`) added
`customer-create-form` to the Add Customer `<form>` and the scoped rules to
`resources/css/filament/saleshub/theme.css`.

The location-picker branch (`7c24bdf`) rewrote the same `<form>` line from a
base that predated `b406d5b`, adding `x-data="customerLocationPicker()"` while
keeping only `class="space-y-5 pb-8"`. Merge `46e9acd` kept the location-picker
version, so the class hook was dropped from the markup while the CSS kept
targeting `.customer-create-form`. The scoped rules therefore matched nothing.

Note: the earlier Work note `OMMC-20261001-ipad-customer-form-field-borders`
records `TERMINAL_DELIVERED`. The repository now materially disagrees with that
delivered outcome because the hook was subsequently dropped. This Work is a new
regression-restoration Work; it does not rewrite that delivered Work.

## Task-owned files

- `resources/views/filament/pages/customer-create-page.blade.php` (added
  `customer-create-form` to the `<form>` class list).
- `tests/Feature/CustomerCreatePageDiagnosticTest.php` (new regression test).

## Unrelated/pre-existing worktree changes

None. Working tree was clean before this Work.

## Validation

- TDD: new test `customer add page renders the scoped customer-create-form
  styling hook` was written first and watched fail against the unmodified
  markup, then passed after the one-line fix.
- `vendor/bin/pint --dirty --format agent`: passed, no formatting changes.
- `git diff --check`: passed.
- Built theme asset `public/build/assets/theme-D5ffBd_P.css` already contains
  the `.customer-create-form` rules; the Vite manifest is current, so no
  `npm run build` is required.
- Full suite comparison (`php artisan test --compact`) on the clean tree vs.
  the fixed tree: identical 12 failed / 3 skipped in both; the fixed tree had
  one additional passing test (this Work's regression test): 119 passed vs.
  118 passed. No new failures introduced.

## Pre-existing failures (not caused by this Work)

- `Tests\Feature\CustomerCreatePageDiagnosticTest > customer add page renders
  against the complete migrated sqlite schema` (`assertSee('2018 *')`; the
  annual-category labels render as bare years).
- `Tests\Feature\ExampleTest > the application returns a successful response`.
- `Tests\Feature\FirstLoginPersistenceTest` (3 errors).
- `Tests\Feature\IosNativeRegenerationArtifactsTest` (2 failures; missing
  nativephp iOS project file).
- `Tests\Feature\SalescallPhotoUploadTest` (4 failures).
- `Tests\Feature\StartupMigrationClassicDiagnosticsTest` (1 failure).

## Manual acceptance

Pending Human visual confirmation that the Add Customer controls once again
show the accepted borders, rounding, field background, label spacing, and
focus/disabled treatment.

## Remaining work / blockers

- No implementation blocker.
- The pre-existing `2018 *` diagnostic failure is unrelated and remains open.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

Not delivered. Local diff only; no commit, push, or terminal trailers created.
