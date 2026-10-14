# OMMC-20261014-customer-sync-optimization

- **Title:** Customer sync optimization — Tablet side of the streamed
  JSON transport and bounded-memory v1 pull/push endpoints.
- **Repository role:** Tablet `ommc2027-tablet` (branch
  `21-optimization-customer-syncing`, created from `origin/main` `9a234e4`
  during Task T1). The Portal half of this cross-repo Work lives in
  `ommc2027` under the same Work-ID.
- **State:** ACTIVE
- **Shared spec (cross-repo reference):** Portal `ommc2027`
  `docs/superpowers/specs/2026-10-14-customer-sync-optimization-design.md`;
  plan `docs/superpowers/plans/2026-10-14-customer-sync-optimization.md`.
- **Objective:** Keep Tablet sync memory-bounded and incremental at
  production scale (≈18,989 active customers) by consuming the Portal's
  v1 contracts: conditional locations (`known_version` /
  `{"status":"unchanged"}`), the customer scope fingerprint, delta
  customer pages, and the batched customer push.
- **Implementation authorization:** Human-authorized plan
  `docs/superpowers/plans/2026-10-14-customer-sync-optimization.md`
  (2026-10-14), Phase B (Tasks T1–T5). Local implementation and validation
  only.
- **Terminal authorization:** None.
- **Human-authorized scope (Phase B):**
  - T1: Branch/work note; `SyncService::pullLocations(?string $knownVersion
    = null)`; sync state key `location_reference_version`;
    `storedLocationVersion(): ?string`; `pullCustomersStep()`'s locations
    phase passes the stored version.
  - T2: Scope-fingerprint skip in `pullCustomersStep()`.
  - T3: Batched pull reconciliation (identity + fingerprints).
  - T4: Batched customer push client (`CUSTOMER_PUSH_BATCH_SIZE = 10`).
  - T5: Tablet regression suites and matcher updates.
  - No new routes; decoded Portal payload keys and shapes stay unchanged.
- **Constraints:**
  - No commits, no pushes; terminal delivery requires separate explicit
    Human authorization.
  - `CUSTOMER_PULL_PAGE_SIZE = 100` and `pull_timeout = 120` (from the
    Human commit "Adjust Limits" on `origin/main`) stay unchanged.
  - `location_reference_version` is recorded only after a full locations
    download or an `unchanged` response to this device's own versioned
    request — never from `sync_meta.reference_version`.
  - Full-suite runs need `php -d memory_limit=512M` (pre-existing
    requirement).
- **Task-owned files (Task T1):**
  - `docs/work/OMMC-20261014-customer-sync-optimization.md` (this note, new)
  - `app/Services/SyncService.php`
  - `tests/Feature/CustomerLocationSyncTest.php`
  - `tests/Feature/SyncServicePullFreshInstallTest.php` (focused lock;
    changed only if a fake matcher needs the new query param)
  - Later Phase B tasks add their own files to this list as they run.
- **Unrelated/pre-existing worktree changes to preserve:**
  - Modified `docs/work/OMMC-20261013-tablet-notifications-pull-on-visit.md`
    (notifications Work awaiting terminal review; must not be mixed into
    this Work's staging).
- **Accepted decisions:**
  - The Human-directed per-DRM scope is enforced Portal-side; the Tablet
    consumes the resulting `scope_ids`/`scope_fingerprint` unchanged.
  - Batch push correlates Portal `results` by request order (the Portal
    returns one result per request item, in order), because an acknowledged
    uuid can legitimately differ from the sent one in conflict cases.
- **Implementation decisions:**
  - `pull()`'s existing `reference_contract_version < 3` gate is left
    unchanged; only `pullCustomersStep()` passes `storedLocationVersion()`,
    so a repeated Customer Pull on an up-to-date device skips the
    locations download while the one-time fresh-install refresh stays.
  - `SyncServicePullFreshInstallTest.php` needed no change: `pull()` sends
    no `known_version` (its locations call has no argument), so the exact
    `portal.test/api/sync/pull/locations` fake matcher still matches.
  - Scope fingerprint: sync state key `customers.scope_fingerprint.{userId}`;
    the page request sends it as `known_scope_fingerprint`; on the final page
    a matching fingerprint (`scope_ids: null`) skips `recordCustomerScope()`
    entirely, while the returned `scope_fingerprint` is always stored.
  - Batched page work: `localCustomersForPage()` preloads server_id/uuid/id
    maps in three queries; `customerPullFingerprints()` computes fingerprints
    with one query per table for the whole page; `resolveMany()` preloads the
    page's identity rows once and its results are passed into
    `CustomerAggregateReconciler::reconcile(identity: …)`.
  - Batched push: `CUSTOMER_PUSH_BATCH_SIZE = 10`; each batch claims rows
    exactly as before, posts `{customers: [...]}`, and maps results by index.
    Single-record actions surface the exact per-item outcome
    (`customer_identity_conflict`, `customer_conflict`, `push_failed`).
    Transport failures record retryable failures (no status restore); `401`
    restores every claimed row; a missing per-item result is a retryable
    failure with "omitted this Customer" recorded.
- **Validation (Task T1):**
  - **TDD RED** — after appending the three tests to
    `tests/Feature/CustomerLocationSyncTest.php` and before implementing,
    `php -d memory_limit=512M vendor/bin/pest --compact
    tests/Feature/CustomerLocationSyncTest.php` failed the three new tests:
    the `unchanged` test with "Pull error: Portal location response is
    missing supported snapshot metadata." (no unchanged branch), the full
    download test with "Failed asserting that null is identical to 'v2'."
    (version not stored), and the step test with "An expected request was
    not recorded." (no `known_version` query).
  - **TDD GREEN** — after implementing: same file → the three new tests
    passed (file run: 1 pre-existing failure, 3 passed, 20 assertions); the
    one failure is the pre-existing
    `pull stores location reference tables and customer location foreign
    keys` test, reproduced on pristine `HEAD` `9a234e4` from a temp copy of
    `git show HEAD:tests/Feature/CustomerLocationSyncTest.php` (same line-74
    failure). Not caused by this Work.
  - **Focused set** — `php -d memory_limit=512M vendor/bin/pest --compact
    tests/Feature/CustomerLocationSyncTest.php
    tests/Feature/SyncServicePullFreshInstallTest.php
    tests/Feature/SyncPullResponseSinkTest.php` → **7 passed, 1 failed
    (pre-existing), 44 assertions**.
  - **Adjacent suites (no regressions)** — `LocationReferenceSnapshotTest`
    + `CustomerPagedPullTest` → **47 passed (504 assertions)**;
    `SyncNotificationsTest`, `RegionTypeImageRequiredTest`,
    `TabletBaseLocationSyncTest`, `TabletBaseLocationProfileTest`,
    `SalescallStatusesMigrationTest`, `SyncPullReferenceBulkUpsertTest`,
    `LocationAreaClusterMappingTest`, `CustomerOperationalSyncTest` →
    **33 passed, 1 failed**. The `SalescallStatusesMigrationTest >
    pull inserts and updates salescall_statuses from the payload` failure is
    pre-existing and isolated to framework fake behavior (a single
    `Http::response()` stub reused with Guzzle `sink` leaves the second sink
    file empty; reproduced standalone) — the request carries no
    `known_version` query, and T5 owns the remaining fake/matcher sweep.
  - **Pint** — `php vendor/bin/pint --dirty --format agent` → passed.
- **Validation (Tasks T2–T4):**
  - **T2 scope fingerprint** — three tests added to
    `tests/Feature/CustomerPagedPullTest.php` (match skips the scope rewrite,
    scope ids rewrite + store, requests carry the stored fingerprint); RED
    observed, then `CustomerPagedPullTest` → **35 passed (416 assertions)**.
  - **T3 batched reconciliation** — `resolveMany` tests added to
    `tests/Feature/CustomerIdentityResolverTest.php`; `CustomerIdentityResolverTest`,
    `CustomerAggregateReconcilerTest`, `CustomerPagedPullTest` →
    **52 passed (503 assertions)**.
  - **T4 batched push** — `CustomerOnlyPushTest` converted to the batch
    contract and four tests added (one request per ten, 401 restore, mixed
    statuses, missing result); → **26 passed (126 assertions)**.
  - **T5 sweep** — old-shape fakes converted in `CustomerCodeSyncTest`,
    `CustomerLocationPushTest`, `CustomerCodePageTest`, and
    `CustomerCreatePageDiagnosticTest` (fakes return `results[]`; assertions
    read `$request['customers'][0]`); `CustomerCreatePageDiagnosticTest` →
    **95 passed (1718 assertions)**; `CustomerCodeSyncTest` and
    `CustomerLocationPushTest` green. `CustomerCodePageTest` keeps its three
    documented pre-existing redirect failures. Full-suite classification is
    recorded below once complete.
- **Manual acceptance:** Pending (post-deploy, Human-run). Checklist:
  1. First sync on the device: locations download once; customer pages complete; no server 5xx.
  2. Second customer pull with no Portal changes: locations reported unchanged; one small customer request; no scope rewrite.
  3. Edit a customer on the Portal; next pull returns only that customer.
  4. Interrupt the first sync (airplane mode) and confirm it resumes at the failed step.
  5. Create/edit a customer on the tablet, push; confirm acknowledgment and local reconciliation.
  6. Queue several pending customers; confirm one batch request per 10 with per-record outcomes.
  7. Confirm an old build can no longer sync (`/api/sync/pull` 404) and the server stays up.
- **Blockers:** None for Task T1.
- **Remaining work:** Phase B Tasks T2–T5, then Phase C cross-repo
  validation and device acceptance; terminal delivery requires separate
  explicit Human authorization.
- **Delivery evidence status:** Not delivered.
