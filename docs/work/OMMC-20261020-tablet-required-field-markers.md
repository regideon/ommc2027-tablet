# OMMC-20261020 — Tablet Required Field Markers

## Work state

**READY_FOR_TERMINAL_REVIEW** — Local implementation and automated validation are complete. Tablet manual acceptance and terminal delivery remain pending.

## Objective and repositories

- Tablet: `ommc2027-tablet` only.
- Make every required field in the tablet forms show a red `*` beside its label.
- Keep optional fields unmarked.

## Authorization and constraints

- Human authorized the all-forms scope on 2026-10-20.
- No commit, push, deployment, migration, production database change, or Portal change.
- Do not change validation rules or requiredness. This Work only makes the existing requirements visible and consistent.
- Preserve the uncommitted `OMMC-20261020-tablet-remember-me` work and the pre-existing deleted `storage/framework/lsp-*.php` file.

## Confirmed findings

- Most custom form labels use `.field-label`, whose text color is gray. A literal `*` inside that label is therefore gray, not red.
- `Giveaway` has no required markers.
- `Salescall` is missing markers on Add Salescall Scheduled At, the required Customer selection, Quick Note Note, and profile Category/Sub Category.
- `Marketing` dynamic row fields are required when a row exists but have no markers.
- `Customer Create` and `Trade Check` already use red markers.
- `Signage`, `Trade Request`, and `Training` required attachment sections already use red markers.

## Task-owned files

- `resources/views/filament/pages/crr-form-page.blade.php`
- `resources/views/filament/pages/customer-create-page.blade.php`
- `resources/views/filament/pages/expense-create-page.blade.php`
- `resources/views/filament/pages/giveaway-form-page.blade.php`
- `resources/views/filament/pages/marketing-form-page.blade.php`
- `resources/views/filament/pages/repair-form-page.blade.php`
- `resources/views/filament/pages/salescall-page.blade.php`
- `resources/views/filament/pages/signage-form-page.blade.php`
- `resources/views/filament/pages/trade-request-form-page.blade.php`
- `resources/views/filament/pages/training-form-page.blade.php`
- `tests/Feature/RequiredFieldIndicatorTest.php`
- `tests/Feature/CustomerCreatePageDiagnosticTest.php` (Edit marker assertion)
- This Work note

## Unrelated changes to preserve

- The uncommitted `OMMC-20261020-tablet-remember-me` implementation and its Work note.
- The pre-existing deleted `storage/framework/lsp-b98a3a4d817a6042.php`.

## Decisions

- Use `<span class="text-red-500">*</span>` consistently, matching the existing Trade Check and Salescall markers.
- Leave optional labels unmarked and do not alter server-side validation.
- Add markers to conditional fields whose controls are required when visible, such as Marketing row fields.
- Add the Repair attachments heading marker because at least one attachment is required.
- Customer Edit now marks its two required fields, Store/Account Name and Company; its Add-only optional fields remain unmarked.

## Validation evidence

- TDD RED: the marker guard failed for the gray/missing markers, and the Customer Edit marker assertion failed because Edit hid all markers.
- TDD GREEN:
  - `tests/Feature/RequiredFieldIndicatorTest.php` — **2 passed (62 assertions)**.
  - Focused form suites (CRR, Giveaway, Marketing, Repair, Signage, Trade Check, Trade Request, Training, Salescall requirements/redirects) — **91 passed (522 assertions)**.
  - Customer Add/Edit required-indicator cases — **3 passed (60 assertions)**.
  - Prior authentication/native suites, re-run to confirm no regression — **49 passed (222 assertions)**.
- `vendor/bin/pint --dirty --format agent` — **passed**.
- `git diff --check` — **passed**.
- `graphify update .` — **completed** (11,679 nodes, 32,959 edges, 414 communities).
- No plain non-red `*</label>` remains in any page Blade view.

## Manual acceptance and remaining work

- Rebuild/redeploy the tablet app bundle; Blade changes are not native-runtime changes.
- Visually confirm the red markers on CRR, Repair, Signage, Trade Check, Trade Request, Training, Marketing, Giveaway, Expense, Salescall (Add/Customer/Note/Profile), and Customer Add/Edit.
- Confirm optional fields remain unmarked.
- No commit, push, or deployment has been performed. Terminal delivery remains unauthorized.

## Worktree ownership

- Owned paths are listed above.
- No unrelated changes will be staged or delivered.
