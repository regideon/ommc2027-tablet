# OMMC-20261029-tablet-master-data-reconciliation

- **Title:** Tablet — reconcile lookup master data with Portal without losing submissions
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Bounded design approved 2026-10-29 ("okay then if that is
  better"). Human goal: keep Tablet lookup master data aligned with the Portal
  after admin additions or Portal reseeds, without deleting local submissions.
- **Related Work:** `OMMC-20261029-crr-program-marketing-requirement` (Portal
  reset and Tablet per-program Marketing requirement) and
  `OMMC-20261029-tablet-crr-marketing-gate-removal` (earlier uncommitted gate
  change).

## Objective

1. Keep the 8 Portal lookup tables aligned with the current Portal rows.
2. Preserve local ids and submission references when a Portal reseed changes
   server ids but keeps the same names.
3. Add new Portal rows and remove stale local rows that no submission uses.
4. Keep referenced stale rows for historical submissions instead of cascading
   deletions.
5. Leave local data untouched when the fetch fails or returns no valid rows.

## Scope

- CRR programs.
- Marketing programs.
- Signage products and types.
- Trade Request products and types.
- Training topics and positions.

Giveaway items and Trade Check templates use separate sync paths and are not
part of this Work.

## Human-authorized scope

Confirmed 2026-10-29:

- Reconcile all lookup master data rather than hard-truncating it.
- Preserve local submissions; no cascade deletion.
- Implementation authorization: Human "okay then if that is better"
  2026-10-29. Terminal authorization: **none**.

## Task-owned files

- `app/Services/Forms/AbstractFormService.php` — shared lookup reconciler.
- `app/Services/Forms/CrrService.php`
- `app/Services/Forms/MarketingService.php`
- `app/Services/Forms/SignageService.php`
- `app/Services/Forms/TradeRequestService.php`
- `app/Services/Forms/TrainingService.php`
- `tests/Feature/Forms/MasterDataReconciliationTest.php`
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- The uncommitted `OMMC-20261029-tablet-crr-marketing-gate-removal` and
  `OMMC-20261029-crr-program-marketing-requirement` changes are pre-existing
  and must be delivered together with this Work.
- Untracked `.opencode/` and `graphify-out/` are user-owned infrastructure;
  `graphify-out/` is refreshed with `graphify update .` per `AGENTS.md`.

## Decisions

- Match remote rows by `server_id` first, then by `name`, before creating a new
  local row. This preserves local ids when the Portal truncates and reseeds.
- Delete stale local rows only when the relevant submission relationship is
  empty.
- Same-name duplicates are merged into the matched row; child references are
  reassigned before the duplicate is removed.
- Referenced stale rows are retained for history. CRR programs that are no
  longer in the Portal are excluded from new CRR pickers, and the edit form
  appends the record's current program so old CRRs remain editable.
- Stale-row reference checks use a single `withExists` query per lookup table,
  avoiding one query per stale row.
- An empty or wholly invalid remote response is a no-op, protecting against
  accidental mass deletion.
- Failed fetches keep the last-known local master data.

## Validation evidence

- `php artisan test --compact tests/Feature/Forms/MasterDataReconciliationTest.php`
  → 9 passed (22 assertions).
- Affected form suites (CRR, Marketing, Signage, Trade Request, Training,
  Forms page plus the reconciliation file) → 200 passed (1028 assertions).
- `vendor/bin/pint --dirty --format agent` → passed.
- Only targeted suites were run per the earlier Human direction ("specific
  test only").

## Manual acceptance

Pending. Requires the Human to confirm on the Tablet:

- A new Portal lookup row appears after sync.
- A Portal reseed with new server ids keeps existing local submissions valid.
- Duplicate names appear only once in the CRR picker.
- A stale unreferenced row disappears.
- A stale referenced program remains available to its historical CRR edit.

## Blockers and remaining work

- No blockers.
- Manual acceptance remains pending.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

Not delivered. Local implementation and validation only.
