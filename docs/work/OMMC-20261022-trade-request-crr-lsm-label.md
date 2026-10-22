# OMMC V2 — Trade Request Label to CRR - LSM

- **Work ID:** `OMMC-20261022-trade-request-crr-lsm-label`
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Repositories:** Tablet `ommc2027-tablet`; Portal `ommc2027` (mirrored note).
- **Accepted Human decision:** Rename visible Trade Request labels to `CRR - LSM` in both Admin and Tablet. Internal model, table, route, permission, and service names stay unchanged.
- **Constraints:** Visible labels only. No schema, API payload, route, permission, or business-logic changes.

## Objective

Make the form appear as **CRR - LSM** everywhere users see its name, while preserving `TradeRequest`/`trade_requests` internally.

## Tablet changes

- Form rail list heading and subtitle.
- Create, edit, sync, detail, empty-state, and prompt text.
- Create/edit form heading, breadcrumb line, cancel confirmation, and local-save notice.
- Sales Call form tile label and missing-form message.
- Internal enum case, slug, service, route, and variable names remain unchanged.

## Owned files

- `resources/views/filament/pages/forms-trade-request.blade.php`
- `resources/views/filament/pages/trade-request-form-page.blade.php`
- `resources/views/filament/pages/salescall-page.blade.php`
- `app/Filament/Pages/SalescallPage.php`
- `tests/Feature/Forms/FormsPageTest.php`
- `tests/Feature/Forms/TradeRequestFormPageTest.php`
- `docs/work/OMMC-20261022-trade-request-crr-lsm-label.md`

## Validation

- Red first: the updated label assertions failed against the old `Trade Request` text.
- `FormsPageTest` + `TradeRequestFormPageTest`: **29 passed, 263 assertions**.
- Pint, PHP lint, and `git diff --check` passed.
- `graphify update .` completed.

## Manual acceptance

Pending Human review of the Tablet labels.

## Blockers

None.

## Remaining work

- Human manual acceptance.
- Separate explicit terminal authorization for commit and push.

## Delivery evidence status

Local implementation and validation only. No commit, push, or deployment. Portal changes are recorded in the mirrored Portal Work note.
