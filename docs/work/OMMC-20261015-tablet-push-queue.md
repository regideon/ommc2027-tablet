# OMMC-20261015-tablet-push-queue

- **Title:** Tablet push queue — accept-fast Customer pushes with later confirmation
- **Repository:** Tablet `ommc2027-tablet` (branch `tablet-push-queue` from
  `origin/27-tablet-customer-syncing` `4c273b8`). Cross-repo Work: the Portal
  half lives in `ommc2027` under the same Work ID.
- **State:** READY_FOR_HUMAN_REVIEW
- **Authorization:** Human instructed "implement" on 2026-10-15 (following the
  approved design discussion). Local implementation and validation only; no
  commit, push, deployment, retained database operation, or live
  synchronization. Terminal delivery requires separate explicit Human
  authorization.
- **Shared spec (cross-repo reference):** Portal `ommc2027`
  `docs/superpowers/specs/2026-10-15-tablet-push-queue-design.md`; plan
  `docs/superpowers/plans/2026-10-15-tablet-push-queue.md`.
- **Approved behavior:** The tablet pushes each Customer once; the Portal
  replies `queued` after enqueueing and processes later. The tablet holds the
  record in a new `queued` state (no retry-attempt burn, no error) and applies
  the authoritative outcome on a later sync via
  `POST /api/sync/pull/push-results`.
- **Authorized scope (Phase 1):** Customer push only — `queued` state,
  `payload_hash`, confirmation step in `pull()`, edit-while-queued guard, and
  notification copy. Other push types and attachments/forms are later phases.
- **Constraints:**
  - No commits, pushes, live sync, or retained database operations.
  - No new dependencies. No tablet migration is expected.
  - `payload_hash` = `hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))`
    computed before the field is added; sent on the single and batched pushes.
  - Existing customer push/pull contracts otherwise unchanged.
  - Preserve unrelated worktree changes; touch only task-owned files.
  - Run focused tests with `php -d memory_limit=512M` where the suite needs it;
    `vendor/bin/pint --dirty --format agent`; `git diff --check`;
    `graphify update .` after code changes.
- **Task-owned files:**
  - `app/Services/SyncResult.php` (additive `queuedCount`)
  - `app/Services/SyncService.php` (hash, queued handling, confirmation step,
    paginated-pull confirmation)
  - `app/Services/CustomerAggregateReconciler.php` (pulls must not overwrite a
    `queued` row; `preserved_queued` outcome)
  - `app/Filament/Pages/CustomerCreatePage.php` (notification copy)
  - `app/Filament/Pages/CustomerEditPage.php` (notification copy, edit guard)
  - `tests/Feature/CustomerPushQueueTest.php` (new)
  - this note
- **Unrelated/pre-existing worktree changes to preserve:** none identified at
  start (worktree was clean when the branch was created).
- **Validation:**
  - `tests/Feature/CustomerPushQueueTest.php` — **11 passed / 80 assertions**:
    `queued` reply parks the row without burning attempts and stores the
    pushed hash, push-all excludes `queued` rows, create-page notification
    reports awaiting confirmation, confirmation applies processed+snapshot,
    invalid result becomes a Portal rejection, non-terminal stays queued,
    edit clears the awaiting hash, in-flight edit guard ignores a stale
    result, a pull page does not overwrite a `queued` row
    (`preserved_queued`), and the paginated customer pull confirms awaiting
    customers when it completes.
  - `CustomerOnlyPushTest` — **26 passed / 126 assertions**;
    `CustomerAggregateReconcilerTest` + `CustomerOnlyPushTest` +
    `CustomerPagedPullTest` — **70 passed / 593 assertions**.
  - Earlier focused set during implementation (before the pull-protection
    guard): `CustomerCreatePageDiagnosticTest` + `CustomerPagedPullTest` +
    `CustomerIdentityBoundaryTest` + `SyncNotificationsTest` — **138 passed /
    2,161 assertions**.
  - Pre-existing failures (not caused by this Work, verified against pristine
    branch files): `CustomerMapLocationAutomationTest > Customer Add persists
    map-derived location…` (stale `reference_contract_version` fixture),
    `CustomerLocationSyncTest > pull stores location reference tables…`
    (pivot expectation), and one `customer add`-filtered case in the same
    pre-existing set.
  - `php -l` passed for all changed/created PHP files;
    `vendor/bin/pint --dirty --format agent` passed; `git diff --check`
    exit 0; `graphify update .` rebuilt the graph.
  - Tests used disposable databases only. No retained database operation or
    live synchronization occurred.
- **Blockers:** none.
- **Remaining work:** Phases 2/3 (other pushes; attachments/forms); admin
  visibility over the Portal queue (deferred); manual acceptance (Portal
  migration applied + `queue:work database --queue=default` running; create a
  customer on the tablet, verify `queued`, processing, and confirmation on the
  next sync); terminal delivery requires separate explicit Human
  authorization.
- **Delivery evidence status:** Not delivered.
