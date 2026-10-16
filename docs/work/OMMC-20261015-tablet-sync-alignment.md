# OMMC-20261015-tablet-sync-alignment

- **Title:** Tablet sync pipeline alignment — form-style customer push, per-module controllers, tablet push slice
- **Repositories:** Tablet `ommc2027-tablet` (branch `tablet-push-queue`) and
  Portal `ommc2027` (branch `tablet-push-queue`). Cross-repo Work; materially
  equivalent note in the other repository.
- **Objective:** Align the Tablet sync pipeline with the Portal — form-style
  single-record customer push, per-module Portal controllers, Tablet `Sync\`
  slice extraction, push-queue retirement, and documentation reconciliation.
- **State:** READY_FOR_TERMINAL_REVIEW
- **Authorization:** Human approved the spec and this plan on 2026-10-15;
  implementation authorization covers local implementation and validation only.
  Terminal delivery requires separate explicit authorization.
- **Spec:** `docs/superpowers/specs/2026-10-15-tablet-sync-alignment-design.md`
  (Portal `ommc2027` copy; cross-repo reference).
- **Plan:** `docs/superpowers/plans/2026-10-15-tablet-sync-alignment.md`
  (Portal `ommc2027` copy; cross-repo reference).
- **Owned files:** (tablet) `app/Services/Sync/SyncClient.php`,
  `app/Services/Sync/SyncFailureRecorder.php`,
  `app/Services/Sync/Push/CustomerPushService.php`,
  `app/Services/Sync/Push/CustomerPushPayload.php`,
  `app/Services/SyncResult.php` (additive `aggregate()`),
  `app/Services/SyncService.php` (delegations), tablet tests per §9 of the spec
  (existing customer-push suites unchanged plus new extraction coverage),
  `tests/fixtures/sync/customer-push/*`, docs per §8 of the spec
  (`docs/02-sync-process.md`, `docs/01-login-logic.md`,
  `docs/work/OMMC-20261015-tablet-push-queue.md` — superseded), and this note.
- **Implementation constraints:**
  - No commits, pushes, deployments, retained database operations, or live
    synchronization; terminal delivery requires separate explicit Human
    authorization.
  - No new dependencies (spec D8); no Tablet migration is expected.
  - Disposable databases only for tests and manual acceptance.
  - Preserve unrelated worktree changes; touch only task-owned paths.
  - `vendor/bin/pint --dirty --format agent` and `git diff --check` must pass;
    run `graphify update .` after code changes.
  - Run focused suites first, with `php -d memory_limit=512M` where the suite
    needs it.
  - Parity gate (D7): the existing customer-push suites (`CustomerOnlyPushTest`,
    `CustomerCodeSyncTest`, `CustomerLocationPushTest`, `CustomerCodePageTest`,
    `CustomerCreatePageDiagnosticTest`, and any other suite faking
    `/api/customers/sync`) must pass **unchanged**.
- **Significant implementation decisions (spec D1–D9):**
  - D1/D5: the Portal's pre-optimization form-style single-record contract is
    the authority; the Tablet client already matches it and the envelope/batch
    generation is not kept.
  - D2: the customer push is synchronous in-request; no queue and no wait
    interlock. The Tablet has no queue worker — deferred work is a local status
    plus the next sync, never a job.
  - D3: one target-architecture spec, phased implementation; this Work is
    Phase 1, the Tablet half being the `Sync\` slice extraction.
  - D6: standard 10 s `sync.timeout` for pushes, via `SyncClient`.
  - D7/D8: behavior parity outside the customer push path; no new dependencies;
    the push-queue retirement removes the `queued` client state.
  - D9: the extracted slices mirror the Portal's per-module services
    (`SyncService` keeps its public methods and delegates); cache holds only
    derivable, versioned data with explicit invalidation; jobs carry only
    acknowledgment-independent side effects.
- **Unrelated changes to preserve:** none identified at Step 1 (2026-10-15). The
  Tablet worktree on branch `tablet-push-queue` was clean (no modified, staged,
  or untracked paths). `git branch -vv` shows `tablet-push-queue` at `7d08cb1`
  ahead of its upstream `origin/27-tablet-customer-syncing` by one existing
  commit, `7d08cb1 Merge pull request #33 from regideon/tablet-push-queue`, which
  is unrelated to this Work and is carried unchanged.
- **Validation:** Task 1 checkpoint (2026-10-15): both Work notes exist, contain
  no secrets/credentials/tokens, and every listed owned path was verified
  against its repository (existing paths present; planned-create paths have
  their parent directories). No repo code was touched by Task 1.
- **Validation (final gates, 2026-10-16):** implementation continued across
  2026-10-15/16 (Tasks 1–9 complete). All runs used disposable databases only.
  - Tablet full suite (`php -d memory_limit=512M vendor/bin/pest`): **18 failed,
    4 skipped, 694 passed (5,182 assertions)**. Failures classified as follows.
    - Attributed — focused-parity pre-existing set (Task 6/7): 3 ×
      `CustomerCodePageTest` ("Component did not perform a redirect") and 1 ×
      `CustomerCreatePageDiagnosticTest` ("Undefined array key 'customers'").
    - Attributed — pre-existing list in `OMMC-20261015-tablet-push-queue.md`:
      `CustomerMapLocationAutomationTest` ("Customer Add persists map-derived
      location…") and `CustomerLocationSyncTest` ("pull stores location reference
      tables…", pivot expectation).
    - Unattributed (not in any recorded baseline; all in test files this Work
      never touched and exercising non-sync paths — location-map resolution,
      iOS regeneration, photo upload, salescall statuses, startup migration,
      the default `ExampleTest`): 2 × `CustomerLocationMapTest`, 1 ×
      `CustomerLocationSaveTest`, 1 × `ExampleTest`, 2 ×
      `IosNativeRegenerationArtifactsTest` (missing
      `nativephp/ios/…/project.pbxproj`), 4 × `SalescallPhotoUploadTest`, 1 ×
      `SalescallStatusesMigrationTest`, 1 ×
      `StartupMigrationClassicDiagnosticsTest`.
  - Fixture identity: all four `tests/fixtures/sync/customer-push/*.json`
    hash-match the Portal copies pairwise.
  - Focused contract check (disposable DBs): `CustomerPushServiceTest` →
    5 passed (14 assertions).
- **Blockers:** none.
- **Remaining work:** Phases 2–7 of the spec's phase map (§5.3): Phase 2
  (optimized pull client re-land), Phase 3 (scale guards), and the indicative
  Phases 4–7 (transport/auth extraction and `SyncStateStore`; pull-domain
  decomposition; remaining pushes; read views and `SyncService` facade
  retirement). Later phases are scheduled as their own Works and land on the
  slices created by earlier phases. Terminal delivery requires separate
  explicit authorization.
- **Delivery evidence status:** Not delivered.

## Routing amendment (2026-10-16)

- **What changed:** Human-directed routing amendment to the FINAL scheme
  `/api/{module}/{action}`: the `sync` URL segment is gone, module prefixes are
  plural, module groups are combined, the customer write is
  `POST /api/customers/sync`, and forms moved to `/api/forms/*`. Reason: match
  the Portal's forms-style convention (module-first, Human-directed) with plural
  modules and no `sync` segment. Verbs and request/response shapes are
  unchanged — only URL paths.
- **Tablet replacements:** 331 endpoint literals across 42 files (40 test files
  + `app/Services/SyncService.php` +
  `app/Services/Sync/Push/CustomerPushService.php`).
- **Portal replacements (companion, R1c):** 140 path literals across 14 test
  files (`routes/api.php` was rewritten separately);
  `docs/demo-reset-and-test-guide.md` was also updated in R3.
- **Agreement results:** Tablet — 58 distinct normalized paths, 55 matched, 3
  explained (the `Http::fake` catch-all `/api/*/pull*`; the deliberate
  non-existent `/api/pull`; `/api/events`, whose `_native` prefix the extractor
  drops and which is a real `POST _native/api/events` route). Portal — 36
  distinct normalized paths, 35 matched, 1 unmatched: `/api/sync/pull` in
  `TabletSplitPullTest.php`, the intentional legacy `assertNotFound()` negative.
- **Focused results:** Tablet 128 passed plus the known 4 pre-existing
  failures; Portal 92 passed (523 assertions).
- **Remaining old-scheme path mentions (docs):** only in historical records
  (push-queue superseded docs, plan files, spec §2/§7.3 "was" prose) plus the
  agreement-record mentions in these notes; the live
  `docs/demo-reset-and-test-guide.md` was updated in R3 rather than left as
  historical.
- **Amendment final gates (full suites, both repos):** complete. Portal full
  suite: 5 failed / 693 passed (the known 5 pre-existing). Tablet full suite:
  18 failed / 4 skipped / 694 passed (same 18-failure profile; 6 attributed + 12
  independent). Route table re-checked; agreement clean. State remains
  READY_FOR_TERMINAL_REVIEW.
- 2026-10-16 follow-up: the salescall-expenses read moved to GET /api/expenses/salescalls/{salescallId} (name expenses.salescalls) under the expenses module; portal routes/tests, tablet client/tests, and docs updated; route table regenerated.
- Requests & Resources alignment (2026-10-16, Human-directed): 15 FormRequests created (14 sync actions + `PushCustomerRequest` with 84 rules moved verbatim from the service); 4 API resources created (`ExpenseResource`, `ExpenseAttachmentResource`, `NotificationResource`, `UserResource`); controller serializer helpers deleted; `salescallExpenses`/`fetchUser` coverage added (`SalescallExpensesApiTest`, `FetchUserApiTest`, 8 tests).
- Validation highlights: rules moved verbatim (no message changes); `TabletLoginRequest::authorize()` returns true (pre-auth endpoint); expense unknown-field vs rule ordering corner documented; decoded JSON checked identical for expense/notification payloads.
- Final gate: portal full suite 5 failed / 5 deprecated / 701 passed (5,386 assertions) (the known 5 pre-existing failures; 8 new tests added). Tablet untouched (response bodies unchanged). State remains READY_FOR_TERMINAL_REVIEW.
- Master-data caching (2026-10-16, Human-directed): `MasterDataCache` (sync lookups + location geography keyed by `ReferenceVersion`, TTL 24 h) and `FormReferenceVersion` + `FormMasterDataCache` (8 forms master-data endpoints). Barangays stay streamed; `customer_access_mappings` stays fresh. Cache hit = zero master queries; version rotation = invalidation. Tests: `MasterDataCacheTest` (6), `FormMasterDataCacheTest` (4), plus forms cluster 76 passed. Portal full suite 5 failed / 5 deprecated / 711 passed (5,466 assertions); route table unchanged. Tablet untouched (response bytes identical).
