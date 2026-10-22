# OMMC V2 — Forms View Customer Title and Details

- **Work ID:** `OMMC-20261022-form-view-customer-title`
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Repositories:** Tablet `ommc2027-tablet`; Portal `ommc2027` (mirrored note).
- **Accepted Human decision:** Portal title/customer work is complete. For Tablet, the Human narrowed scope to **date format only** and chose to keep the existing details heading.
- **Constraints:** Tablet Forms displays only. Preserve list/detail structure, Sales Call labels, existing heading text, approval status, sync behavior, and unrelated Tablet behavior. No commit, push, deployment, migration, or production data operation.
- **Starting state:** Tablet worktree clean before this Work. Portal has task-owned changes recorded under the same Work ID.

## Objective

Use the same readable date formats in the Tablet Forms displays as Portal: date-only values use `M j, Y` (`Oct 22, 2026`) and timestamps use `M j, Y g:i A` (`Oct 22, 2026 9:00 AM`).

## Scope

- CRR list and details: format Date Implemented as `M j, Y` when a full date is stored. Preserve month-only values and ranges that have no exact day.
- Training details: format Preferred At as `M j, Y g:i A`.
- Marketing already uses the shared date and timestamp formats; no change.
- No Customer/Sales Call field changes in this Tablet follow-up.
- No heading changes.

## Owned files

- `app/Models/Forms/CRR/Crr.php`
- `resources/views/filament/pages/forms-crr.blade.php`
- `resources/views/filament/pages/forms-training.blade.php`
- `tests/Feature/Forms/FormsPageTest.php`
- `docs/work/OMMC-20261022-form-view-customer-title.md`

## Validation

- Red first: the CRR Date Implemented test failed because the list and details rendered raw `2026-10-22`; the Training Preferred At test failed because the details rendered raw `2026-11-01 09:00:00`.
- `tests/Feature/Forms/FormsPageTest.php`: **19 passed, 206 assertions**.
- `vendor/bin/pint --dirty --format agent` passed.
- PHP lint passed for every changed PHP file.
- `git diff --check` passed.

## Manual acceptance

Pending Human review of the Tablet Forms date displays.

## Blockers

None.

## Remaining work

- Human manual acceptance of CRR Date Implemented and Training Preferred At on Tablet.
- Separate explicit terminal authorization for commit and push.

## Delivery evidence status

Local implementation and validation only. No commit, push, deployment, migration, or production data operation.
