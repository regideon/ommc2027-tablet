# OMMC V2 — Tablet Giveaway Quantity After Sync

- **Work ID:** `OMMC-20261022-tablet-giveaway-sync-quantity`
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Repositories:** Tablet `ommc2027-tablet`; Portal `ommc2027` (mirrored note).
- **Trigger:** A Giveaway quantity became null after syncing.
- **Root cause:** The Portal pull serializes the loaded answer relation as `answer_item` (snake_case), while the Tablet pull read `answerItem` (camelCase). The answer was therefore cached as `null` on the next pull.
- **Constraints:** Pull mapping only. Preserve push behavior, signatures, local pending work protection, and Portal data.

## Objective

Read the Portal Giveaway answer from the actual serialized key so the quantity survives a sync/pull cycle.

The mirrored Portal note also records a separate Admin-view defect found while verifying the push: `GiveAwayAnswer::answerItem()` matched the answer `id` instead of its `item_id`, hiding the stored quantity whenever those ids differed.

## Implementation

- Read `answer_item.answer` first.
- Fall back to `answerItem.answer` for compatibility with existing fixtures/older payloads.
- Add a regression test using the real Portal snake_case payload.

## Owned files

- `app/Services/Forms/GiveawayService.php`
- `tests/Feature/Forms/GiveawayWorkflowTest.php`
- `docs/work/OMMC-20261022-tablet-giveaway-sync-quantity.md`

## Validation

- Red first: the updated workflow test failed with `null` instead of quantity `'2'`.
- Confirmed the live Portal serialization uses `answer_item` through `GiveawayAnswer::toArray()`.
- Giveaway workflow, form page, and foundation suites: **30 passed, 159 assertions**.
- `vendor/bin/pint --dirty --format agent` passed.
- PHP lint passed for the changed service and test.
- `git diff --check` passed.
- `graphify update .` completed.

## Manual acceptance

Pending Human retry of a Giveaway sync and quantity check.

## Blockers

None.

## Remaining work

- Human retry of a Giveaway sync.
- Separate explicit terminal authorization for commit and push.

## Delivery evidence status

Local implementation and validation only. No commit, push, or deployment.
