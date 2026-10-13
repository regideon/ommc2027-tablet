# OMMC V2 — Tablet Customer Pull and Location Diagnostics

- **Work ID:** `OMMC-20261013-tablet-customer-pull-location-diagnostics`
- **State:** Packages A/B and Package C `TERMINAL_DELIVERED`.
- **Repositories:** Tablet `ommc2027-tablet`; Portal `ommc2027`.
- **Authorization:** Human accepted Packages A/B, authorized Package C implementation, then accepted Package C and authorized scoped documentation/implementation commits and pushes. No retained database operation, Customer Pull/Push execution, migration, checkpoint/watermark changes, or deployment.
- **Pre-existing worktree state:** Tablet had modified files and an untracked Work note/test for `OMMC-20261013-tablet-customer-interaction-reconciliation` before this note was added. Those changes were left untouched. Portal worktree was clean at inspection. The previous Interaction Work’s terminal delivery is not verified by this investigation; its note says `READY_FOR_TERMINAL_REVIEW`, and its files remain uncommitted in this Tablet checkout.

## Findings at a glance

- **Track A:** Matching local Pull logs identify a Tablet-side Customer identity reconciliation failure: `A Portal Customer ID is already mapped to multiple Tablet Customers.` A read-only check of the local Tablet SQLite database confirms one duplicated `server_id` group (23591, two local rows) and an active run at `phase=pages`, `after_id=0`, `pulled=0`. This corroborates the logged failure but does not prove which response page contained that ID because no Portal payload or correlation ID was captured.
- **Track B:** Read-only inspection of the local Tablet snapshot confirms Makati Municipality ID 4 (physical Region 2, no Province), Magallanes Barangay ID 223 parented to Municipality 4, and an explicit `unresolved` location mapping for Municipality 4. Region 2 is canonical PSGC NCR; legacy Specific Region 1 remains parented to legacy Region 1, and GMA Area 7 cluster ID 3 remains parented to Specific Region 1. The incompatible commercial parents explain why grouping and Area Cluster resolution stay empty. No mapping was changed.
- **Track C:** **Independent defect conditions; no shared root cause established.** Pull logs show a local identity collision after response decoding. Makati has a separately documented unresolved commercial location mapping. An older failed location refresh does not explain the logged Customer ID collision.

## Track A — Customer Pull

### Request and failure handling

The Customer Pull component repeatedly invokes `pullCustomersStep()` once per server page. When a step returns `success: false`, or when the Livewire call throws, the Blade component displays `Customer Pull failed. Please try again.` In both cases the detailed step message, error code, and exception are discarded by the client UI. Therefore the reported text does not identify authentication, transport, Portal response, mapping, or local persistence as the failing stage.

`SyncService::pullCustomersStep()` first starts or resumes per-user state under `customers.pull_run.{userId}`. Its first phase calls the separate `/api/sync/pull/locations` endpoint. A failed location snapshot is logged as `sync:pull:locations:failed`, retained local reference rows are left unchanged by the location transaction, and the run advances to Customer pages with a warning. Subsequent steps call `/api/sync/pull/customers` using the saved `after_id`, page limit, and optional `updated_since` watermark. HTTP 401 becomes a token-expired result; other unsuccessful statuses, transport exceptions, and response decode errors become `SyncResult` failures. Customer page validation and `applyPullPayload()` run inside a per-page `DB::transaction`.

The cursor/run checkpoint is saved with each successful page. The watermark is updated only after page scanning, Customer scope reconciliation, and missing-ID recovery are complete. A failed current step restores its prior run checkpoint; earlier successful page transactions remain committed. This is a **partial-Pull boundary**, not an all-pages transaction. Retrying repeats the failed step from its saved checkpoint and does not automatically undo earlier pages.

The client-side generic message also covers a rejected Livewire/JavaScript request. Server-side API failure results are returned with diagnostic text by `pullCustomersStep()`, but the client masks that text. Transport and ordinary `SyncResult` failures are not consistently written to the Tablet log; thrown exceptions are reported, and location refresh failures have a dedicated warning record.

### Existing log evidence

Tablet `storage/logs/laravel.log` contains repeated `local.ERROR` entries on 2026-10-13 between 05:43:11 and 05:50:05: `A Portal Customer ID is already mapped to multiple Tablet Customers.` Their stacks identify `SyncService.php:1290`, inside Customer identity reconciliation called by `pullCustomersStep()`. These records have no correlation ID, HTTP status, page cursor, returned Customer ID, or transaction commit marker. The message establishes the failure boundary after a decodable Portal response reached local application; it does not establish which duplicate rows exist now or the current cursor.

The log also contains repeated `sync:pull:locations:failed` warnings from 2026-10-09 through 2026-10-12 for `Portal location response contains an invalid area_clusters.region_specific_id parent.` That is a separate historical reference snapshot failure. It is nonfatal in the Customer Pull state machine and is not the cause of the logged Customer ID collision.

The current Tablet marker is contract version 3, completed at `2026-10-13T05:42:45.383735Z`; the active run records `location_refresh_failed=false`, and SQLite integrity check returned `ok`.

The historical nullable-Company compatibility Work also records partial retained Pull pages and unresolved Customer/Access reconciliation. It says saved cursor/watermark and partial state must be reconciled against authoritative Portal data before another retained Pull, and explicitly says not to restore the prior backup automatically. That older blocker remains relevant independently of this run.

### Current local state inspected read-only

The Tablet SQLite file was opened with SQLite read-only mode; integrity check returned `ok`. Aggregate/key-only reads found one duplicated non-null `customers.server_id` group: server ID **23591**, present on local Customer IDs **-687143025929916172** and **23591**. Both rows have a `local_uuid`. No names, addresses, contact details, credentials, or payloads were read. The active `customers.pull_run.2` checkpoint is `phase=pages`, `after_id=0`, `pulled=0`, `location_refresh_failed=false`; the last completed watermark for that user is `2026-10-13T01:28:24.476729Z`. The current run has not advanced past its first Customer page. The single duplicate group is the only current local candidate for the logged collision, but without the Portal response/correlation ID it cannot be tied conclusively to that run. No row or checkpoint was changed.

### Retry and safety assessment

Do not retry against retained data until the current duplicate identity is reconciled and the prior partial-Pull blocker is reviewed. The logged failure stage is known, but the exact returned Customer ID/page correlation and Portal canonical identity remain unverified. The current run is still at its first page; completed earlier page transactions from older attempts are separate retained changes. A retry repeats this failed page and is likely to hit the same duplicate if the response includes server ID 23591. Retry behavior is checkpointed and designed to be repeatable; it is not a rollback of the overall multi-page history.

Customer Pull **does** request geographic references through `/api/sync/pull/locations` at the beginning of a run. The dedicated Portal endpoint serializes physical Regions, Specific Regions, Area Clusters, Provinces, Municipalities, mapping decisions, and streamed Barangays. Tablet validates section counts and parent IDs, then applies the reference snapshot transactionally and sets its completion marker only after a valid snapshot is applied. However, location refresh failure preserves the previous snapshot and is nonfatal to Customer page retrieval. Customer page location IDs are optional scalar references and may be retained even when local geographic references are missing; Customer Pull is therefore not a reliable way to repair an absent mapping when its reference phase fails.

### Failure classification

| Stage | Evidence and current assessment |
|---|---|
| A. Authentication/token | Sync-token middleware protects Portal endpoints; Tablet converts 401 to `token_expired`. The logged collision occurred after response decoding, so authentication did not cause those events. |
| B. HTTP/network | Non-2xx, transport, and decode failures become controlled results, but the UI masks details. No such failure is evidenced for the logged collision. |
| C. Portal API | The collision log proves a decoded payload reached Tablet apply logic. No Portal API failure is evidenced for those events; no response body/status was retained. |
| D. Response contract | Required Company and identity conditions can reject payloads. The logged error is a local `server_id` uniqueness conflict, not an identified Portal schema mismatch. |
| E. Local apply/persistence | **Confirmed logged boundary.** Multiple Tablet rows match a returned Portal ID. `applyPullPayload()` throws inside the page transaction. Current page writes roll back; earlier committed pages remain. |
| F. Pagination/cursor | Disposable tests verify retry at the same cursor and no watermark advance while incomplete. Current local run is `phase=pages`, `after_id=0`, `pulled=0`; it has not committed a Customer page. Earlier Pull attempts remain subject to the separate partial-state reconciliation blocker. |
| G. Geographic snapshot | Historical validation failed on an invalid Area Cluster parent. Disposable tests prove snapshot rollback and that Customer Pull proceeds with a warning. This is independent of the logged ID collision. |

### Disposable Pull regression evidence

Ran five targeted cases from `tests/Feature/CustomerPagedPullTest.php`: interrupted page resume, failure after reading a page and retry, duplicate Portal ID mapped to multiple local rows, invalid location snapshot atomic rejection, and independent reporting of Customer/location failures. Result: **5 passed, 109 assertions**. The tests use SQLite `:memory:` and fake Portal responses. The duplicate-ID test reproduces the logged rejection; cursor tests verify retry from the saved page; snapshot tests verify an invalid refresh leaves prior references intact while Customer Pull can continue. These test executions did not inspect or change retained sync state.

## Track B — map and reference evidence

### Resolver behavior

`PhilippineAddressResolver` independently matches provider state/province/municipality/barangay text to local reference rows, then backfills physical Region and Province from a matched Municipality. It resolves `region_specific_id` and `area_cluster_id` only through `LocationAreaClusterMappingResolver`. That resolver first checks geographic parent consistency and then requires an explicit mapping decision; an `unresolved` decision returns no Specific Region or Area Cluster. It does not infer the business grouping from conventional geography.

Therefore a map result can contain Municipality, Barangay, address, and coordinates while leaving commercial grouping and cluster fields blank when the explicit location mapping is unresolved. This is an intentional fail-closed result in the current resolver contract, not evidence of an omitted Livewire state assignment by itself.

### Makati evidence and limits

Portal `database/seeders/data/customer_mapping_activation_20261012.json` records a Municipality mapping for `location_id: 4`, `expected_location_name: City of Makati`, expected physical Region ID 2, decision `unresolved`, and no target Area Cluster. Its reason states: `Legacy NCR physical Region 2 does not match the listed Area Cluster Specific Region parent Region 1; NCR reconciliation remains unresolved.` The workbook label is `GMA - Area 7`; reference evidence identifies that candidate Area Cluster as parented through Specific Region ID 1, whose parent is physical Region ID 1. Under the current explicit mapping resolver, this unresolved decision yields no `region_specific_id` or `area_cluster_id`.

`config/customer_region_grouping.php` maps the approved Region Type “Metro Manila” to Access group `GMA`, but its approved Specific Region list for GMA is `[52]`; legacy Specific Region ID 1 is explicitly listed as protected/unresolved and is not assigned a group. The Portal mapping activation manifest and prior `OMMC-20261011-customer-region-access-automation` Work note identify Region ID 1 as the legacy `MM` row and Region ID 2 as the canonical PSGC NCR row. The current local Tablet rows agree: Region 1 is `MM` / Metro Manila, Region 2 is `PSGC-1300000000` / National Capital Region, Specific Region 1 is parented to Region 1, Specific Region 52 is parented to Region 7, and ten Area Clusters including GMA Area 7 (ID 3) are parented to Specific Region 1. The prior Work note’s report that one older Portal snapshot lacked IDs 52–68 remains historical and is not treated as current Portal state.

The current local Tablet snapshot contains Makati Municipality ID 4 (`region_id=2`, `province_id=NULL`, PSGC `1380300000`) and Magallanes Barangay ID 223 (`municipality_id=4`, PSGC `1380300013`). The Municipality 4 mapping-cache row is explicitly `unresolved` with no cluster. Thus the local locality records exist and their parent relationship is consistent; the commercial Specific Region/Area Cluster mapping is unresolved. Customer-import CSV mentions of Makati or Magallanes were not used as geographic reference evidence.

The current Tablet snapshot marker is contract version 3, completed at `2026-10-13T05:42:45.383735Z`. This follows the prior Work’s version-2 measurement dated 2026-10-12. The current local importer expects the version-3 contract and the source Portal serializer emits v3. The current marker and local Makati rows were inspected read-only; current Portal DB rows were not queried.

### Missing rows vs relationships vs resolver

- **Missing locality rows:** none in the inspected Tablet snapshot; Makati ID 4 and Magallanes ID 223 exist and align by Municipality parent. Current Portal DB rows were not inspected.
- **Invalid business parents:** confirmed mapping mismatch. Makati’s physical Region is 2, but Specific Region 1 and Area Cluster GMA Area 7 are under legacy physical Region 1. Metro Manila→GMA is approved, but Specific Region 1 is intentionally excluded from the approved crosswalk.
- **Snapshot version:** current local marker is v3; prior note reported v2 on 2026-10-12. No current Portal DB snapshot was requested or read.
- **Resolver behavior:** the explicit unresolved decision fails closed. No resolver defect is indicated for the grouping/cluster result.
- **Physical selector state:** current `CustomerCreatePage::resolveLocation()` assigns the resolved physical Region ID to `physical_region_id`. With the matched Makati Municipality’s `region_id=2`, the “Specific Region” physical selector should receive ID 2. The reported unselected physical selector was not reproduced; this remains a possible runtime/UI state discrepancy, not a missing-reference finding.
- **High-level Region and Area Cluster:** both remaining blank is consistent with the approved crosswalk and unresolved mapping. The high-level grouping cannot be inferred from legacy Specific Region 1.

## Track C — causal reconciliation

**Classification: INDEPENDENT DEFECT CONDITIONS.** The active local Pull checkpoint and one duplicate local `server_id` group corroborate the identity-reconciliation blocker. Makati map resolution is separately constrained by the unresolved NCR commercial mapping. The current v3 local snapshot is present and the locality hierarchy is consistent, so current evidence does not support a shared snapshot-refresh cause.

## Proposed bounded follow-up

1. **Pull diagnostics and recovery:** reconcile duplicate server ID 23591 and its two local rows against authoritative Portal identity before another Pull; any merge, reassignment, or deletion requires a separate data decision. Separately, the smallest observability change is a random per-step reference ID and structured logging for phase/endpoint, HTTP status if available, exception class, safe `after_id`/page count, current transaction outcome (`not_started`, `rolled_back`, or `committed`), and whether prior steps may have committed. Return only the reference ID and short stage label to UI; do not expose tokens, payloads, Customer IDs, or exception details. No instrumentation is implemented here.
2. **Makati reference decision:** Human/data owner should decide how to resolve the explicit legacy NCR Region 2 vs Specific Region parent Region 1 inconsistency. Verify Makati Municipality, Magallanes Barangay, parent IDs, and current Portal/Tablet snapshot versions through an approved read-only data session. Do not map GMA Area 7 or assign any grouping until approved.
3. **Snapshot refresh:** in disposable tests only, verify a valid version-3 location payload updates all reference tables atomically, and invalid Area Cluster parents preserve the prior snapshot and do not mark refresh complete. No retained refresh was performed.

## Regression plan

- **Pull:** focused disposable tests for authentication expiry, HTTP/network/decode failure, location snapshot validation failure, Customer page validation/persistence failure, multi-page partial commit, cursor restore, watermark not advancing on incomplete run, and retry idempotence at a saved page. Assert UI distinguishes failed Customer pages from a location-refresh warning and does not overstate complete synchronization.
- **References/map:** fixture rows for explicit mapped, unresolved, missing mapping, and invalid parent cases. For Makati, use authoritative approved Municipality/Barangay/Region/Specific Region/Area Cluster rows once supplied. Assert locality fields can resolve while commercial fields remain blank for unresolved mapping, and that no guessed mapping is created. Assert snapshot marker and parent integrity agree.
- **Cross-path:** verify Customer Pull failure does not mutate location refs unless a valid location snapshot transaction succeeds, and map resolution reads only the applied local snapshot/mapping decision. Keep tests and any later correction separate by defect boundary.

## Human decisions and blockers

- Provide/approve the authoritative correction for the Makati/NCR Specific Region parent mismatch before assigning a Region grouping, Specific Region, or Area Cluster.
- Resolve the documented earlier partial Customer Pull state and reconcile the currently observed active run (`after_id=0`, `pulled=0`) and duplicate `server_id=23591` before any retained retry. The active failed page has not advanced this run’s cursor; prior run commits remain a separate historical concern. No retry is authorized by this Work.
- Provide an authoritative Portal identity comparison for server ID 23591 and approve a recovery decision for the two Tablet rows before any row mutation. Do not delete, merge, reassign, or rewrite rows under this Work.
- Authorize implementation of the proposed diagnostic reference/stage fields as a separate bounded Work before changing Pull observability.
- If a Pull failure is to be diagnosed on the affected device, authorize collection of a redacted event-correlated error code/request reference and current snapshot marker; do not provide or collect tokens.
- Decide separately whether the Customer Pull interface should expose safe diagnostic detail or retain the current generic message.

## Execution record

- Inspected Tablet Pull UI/action, `SyncService`, Portal sync routes/controller/`TabletSyncPayload`, snapshot validator/import transaction, map resolvers, mapping activation manifest, prior Work notes, and historical Tablet log entries. Opened the local Tablet SQLite file in read-only mode and queried integrity, duplicate-ID aggregates, Customer Pull checkpoints/watermarks, Region hierarchy, Makati, Magallanes, and its explicit location mapping. No Portal database or production system was accessed.
- Ran five targeted `CustomerPagedPullTest` cases: **5 passed, 109 assertions**. The cases cover duplicate server-ID rejection, same-page retry after transport failure, rollback after a failure during scope reconciliation, invalid reference snapshot isolation, and the independent Customer/location failure path. All use SQLite `:memory:` and fake HTTP responses.
- Ran `php artisan test tests/Feature/PhilippineAddressResolverTest.php`: **10 passed, 38 assertions**. This suite uses `RefreshDatabase` with the configured SQLite `:memory:` database and fake geocoder responses; it exercises resolver matching and fail-closed behavior without calling Customer Pull/Push. It is synthetic reference evidence and does not reproduce the exact Makati/Magallanes device snapshot.
- No live Portal request, retained Customer Pull/Push, retained database write, token/cursor modification, migration, or app-code change was made. The five disposable Pull cases above use fake Portal responses and an in-memory database only. One read-only retained Tablet database inspection was performed as authorized; no row or sync state was changed.
- No change was made to any pre-existing modified or untracked file. The only file created for this Work is this note.

**Recommendation:** `READY_FOR_HUMAN_REVIEW` — the Pull error is localized to the duplicate `server_id=23591` guard, with two current local rows and a first-page checkpoint. The page response/customer ID correlation and Portal canonical identity remain unverified. Makati and Magallanes locality rows are verified in the current Tablet v3 snapshot; the commercial NCR parent conflict remains unresolved. The UI’s physical Region selector discrepancy is not reproduced. No implementation, retained sync, or reference-data change is authorized by this Work.

## Identity reconciliation follow-up — 2026-10-13

**Decision state: `READY_FOR_HUMAN_REVIEW`.** This is a read-only comparison. The Tablet SQLite file was opened with `mode=ro`; no Portal database, production system, row, cursor, or checkpoint was accessed or changed.

### Duplicate local rows

Both rows claim `server_id=23591`, and both are currently `sync_status=synced`, with zero recorded sync attempts, no sync error, a populated `synced_at`, `is_active=1`, and no deletion timestamp. Those flags indicate no currently recorded dirty/pending Customer state or tombstone, but they do not prove the related data is equivalent or that there are no unsynced child changes.

| Tablet local ID | Local UUID | Customer Code (`unique_id`) | Company ID | Created / server-updated | Profile and location distinction |
|---:|---|---|---:|---|---|
| `-687143025929916172` | `25a4b477-d5f8-48e4-aca7-3b61346f1f06` | `CARCLUBS00013` | 3 | `2026-10-13 04:49:21` / same timestamp | Outlet profile, Entry Detail AB; Specific Region 53, Municipality 505, Province 28, Barangay 13567, Area Cluster 18; General Category 1; PIC local user 2. |
| `23591` | `ed72ddcd-d2e0-48fc-ba08-a6369eaeff9d` | `IB53385` | 6 | `created_at=NULL`; `updated_at=2026-10-09 16:28:51`; server update `2026-10-09T12:05:36Z` | IB profile; Specific Region and Area Cluster null; Municipality 505, Province 28, Barangay 13567; General Category 2; no PIC. |

The row values are materially different. Customer Code, Company, profile, General Category, location grouping, PIC, and UUID disagree. The fact that the negative-ID row has timestamps from October 13 while both rows already claim server ID 23591 is not sufficient to infer which identity was created on Portal or whether a prior sync assigned it incorrectly. The positive local primary key is not proof of authority.

### Related-record inventory

| Relationship table | Negative-ID row | Positive-ID row | Reconciliation significance |
|---|---:|---:|---|
| `customer_trade_profiles` | 1 (Outlet / AB) | 1 (IB) | Different profile data exists on both rows; do not overwrite or combine without field-level review. |
| `customer_category_histories` | 9 annual rows (2018–2026) | 0 | Nine distinct annual history rows would be lost or reassigned if the negative row were discarded. |
| `customer_category_events` | 0 | 0 | No event rows currently attached. |
| `customer_user` access links | 8 user IDs | 1 user ID | Access sets differ; merging can grant excess access, while choosing one can remove access. User IDs were counted, not names or emails. |
| `customer_scopes` | 0 | 0 | No explicit scope rows currently attached. |
| `customer_categories`, `customer_brands`, `customer_notes` | 0 | 0 | No rows currently attached in these tables. |

No Customer names, addresses, contacts, serialized profile payloads, user names/emails, credentials, or failed request payloads were read or placed in this note. The displayed Customer Code and relationship counts are non-secret identity evidence. `created_by` is null on both rows. These observations cover the named relationship tables only; they are not a guarantee that every application table with a Customer reference has been inventoried. Before repair, perform a complete schema-level foreign-key/reference inventory, including salescall and indirect dependents, and check child sync queues/status independently.

### Portal identity authority

Portal source defines the identity contract: `TabletSyncPayload::customerRecords()` serializes the Portal Customer model attributes, including Portal primary key `id` and `local_uuid`, plus trade profiles and category histories. `SyncController::pullCustomers()` paginates by ascending Portal `id` using `after_id`. Tablet `SyncService::applyPullPayload()` matches `server_id` and `local_uuid` and rejects a duplicate server mapping or when the two keys resolve to different rows. A later bounded read-only authority check below accessed only the local non-production Portal database after verifying its environment/host/database identity; its results establish which Tablet UUID and Customer Code belong to Portal ID 23591.

The authority evidence now obtained is recorded in “Portal authority verification” below. It came from `APP_ENV=local`, MySQL at `127.0.0.1`, database `ommc_henri_local_latest`, queried inside a read-only transaction and rolled back. No production system was accessed.

### Reconciliation options (none executed)

| Option | Assessment and data-loss/relationship risks | Sync and rollback requirements |
|---|---|---|
| **A. Retain one row and reassign dependents** | Cannot choose the retained row yet. Either choice may discard or conflate a different Company/profile and the distinct access set. All direct and indirect FK/reference rows, child sync records, and UI references must be inventoried. | Correct `server_id`/UUID uniqueness first in a separately authorized transaction; preserve a verified pre-change backup and row/relationship manifest; validate all FKs, profile/history/access counts and identity after repair. Roll back from the manifest/backup if any comparison fails. |
| **B. Merge nonconflicting data into Portal-authoritative row** | Current evidence shows conflicting Company/profile/category identity, not just complementary null fields. Category histories and access links are not safely mergeable by default; merging can create wrong business history or broaden visibility. | Requires field-by-field Human/data-owner adjudication plus explicit rules for each child relationship and uniqueness constraint. Use a recoverable backup, transaction, before/after checksums and disposable rehearsal. No automatic merge is supported by the evidence. |
| **C. Preserve both pending manual review** | **Safest current no-write state and recommended until evidence arrives.** No local data is discarded; conflicting values and relationships remain available for comparison. | Pull remains blocked because the duplicate `server_id` guard will reject any returned page containing 23591. Keep the checkpoint unchanged and do not retry. A later authorized repair needs a recorded decision and rollback plan. |
| **D. Quarantine one mapping / clear a duplicate `server_id`** | Could unblock matching only if the row’s true identity is established. Clearing a mapping on the wrong row may cause Portal ID fallback or UUID recovery to bind that row incorrectly; marking it unsynced may trigger Push and overwrite Portal data. | Not safe without Portal UUID proof and an explicit policy for whether the row is a separate local Customer, a lost-ack Push duplicate, or an erroneous Pull duplicate. No repository procedure establishes a safe quarantine state. |

**Recommendation:** retain both rows pending an authoritative Portal comparison and Human adjudication. Do not infer that one is a duplicate copy merely because `server_id` matches: distinct UUIDs, Customer Codes, Companies and profiles, plus unique related data, make it a genuine identity/data conflict. No repair option is authorized here.

### Pull checkpoint and safe-resume conditions

The current `customers.pull_run.2` remains at `phase=pages`, `after_id=0`, `pulled=0`; the last completed watermark remains `2026-10-13T01:28:24.476729Z`. No Customer page has been counted as applied in this active run. A log from this failure boundary shows the duplicate check occurs inside the per-page apply transaction, so the failed page is rolled back. The run checkpoint does not establish that older, separate runs have no committed pages: the historical nullable-Company Work documents partial page commits and unresolved Customer/Access reconciliation. Page-level retries are intended to be repeatable, but a retry is not a global rollback and will fail again if the first returned page contains server ID 23591. Do not infer the present retained database state from those historical logs.

Before any separately authorized retained Pull: (1) obtain the Portal identity/child-record comparison; (2) inventory every direct and indirect dependent plus pending child sync state; (3) Human approves the exact survivor/merge/quarantine action and rollback artifact; (4) reconcile the older partial-Pull record and verify cursor/watermark against its run history; (5) rehearse identity repair and repeated page application against a disposable copy; (6) confirm exactly one intended Tablet row maps to Portal ID 23591 and that the UUID contract resolves to that same row; and (7) obtain a separate GO for any retained repair and another for Pull. Do not reset the checkpoint or watermark as a substitute for identity reconciliation.

### Separate NCR mapping decision package

The local Tablet v3 reference snapshot records Municipality `4` (City of Makati) under physical Region `2`, with no Province; Barangay `223` (Magallanes) has `municipality_id=4`. The commercial mapping for Municipality 4 is explicitly `unresolved`. GMA Area Cluster `7` is local Area Cluster ID `3`, parented to legacy Specific Region ID `1`; Specific Region 1 remains parented to legacy physical Region 1. Approved crosswalk policy maps Metro Manila to GMA, but legacy Specific Region 1 is excluded from new selection and must not be silently reassigned. Makati’s physical Region 2 therefore conflicts with the legacy Specific Region 1 / Area Cluster 7 parent chain. No geographic row was changed.

The required business decision is an authoritative choice of the valid NCR chain for Makati: confirm whether an approved existing Specific Region under physical Region 2 should be used and identify its eligible Area Cluster, or explicitly approve a reference-data correction to the legacy parents/mapping under a separate Work. Identify the authoritative OMMC reference owner/source and state whether Area Cluster 7 is valid for Makati. Until then leave grouping, Specific Region and Area Cluster unresolved; do not infer from conventional geography. The `region_types` crosswalk groups Region Type “Metro Manila” as GMA; it is not a physical Region ID and is not permission to remap legacy Specific Region 1.

### Exact Human decisions required

1. Review and explicitly approve the proposed identity disposition below: negative local ID is canonical for Portal 23591; preserve the positive-ID row as a separate local record and remove only its incorrect `server_id=23591` association, unless the Human supplies evidence of another intended Portal identity.
2. Separately authorize that retained database repair, including the proposed transaction/backup/rollback procedure. The current GO authorizes read-only verification only.
3. Separately authorize a future Customer Pull only after the repair and all safe-resume preconditions are documented and verified.
4. Name the OMMC authority and approve the NCR Specific Region/Area Cluster relationship for Makati, or direct that it remain unresolved. No geographic reference update is authorized by this Work.

**Execution addendum:** Read-only Tablet queries compared both Customer rows, current sync flags/timestamps, location/business reference IDs, and counts/identity keys in trade profiles, category history/events, Customer Access, scope, categories, brands and notes. Portal source identity serialization, pagination and Tablet duplicate-resolution code were inspected. No Portal database or production access occurred. No tests, synchronization, checkpoint change, database write, application edit, migration, commit or push occurred. The Work remains `READY_FOR_HUMAN_REVIEW`.

## Portal authority verification — 2026-10-13

**Access gate:** Portal `php artisan env` reported `local`. Bootstrapped configuration reported MySQL, host `127.0.0.1`, database `ommc_henri_local_latest`; this matched the known local Portal target. A PDO transaction was explicitly set `READ ONLY`, all statements below were `SELECT`, and the transaction was rolled back. No credential values were read or emitted. No production access occurred.

### Customer 23591 comparison

Portal Customer `id=23591` exactly matches Tablet local row **`-687143025929916172`** on the primary identity evidence:

| Field | Portal Customer 23591 | Tablet `-687143025929916172` | Result |
|---|---|---|---|
| `local_uuid` | `25a4b477-d5f8-48e4-aca7-3b61346f1f06` | same | Exact match |
| Customer Code (`unique_id`) | `CARCLUBS00013` | same | Exact match |
| Company | ID 3, `CAR_CLUBS` | ID 3 | Exact match |
| Created/updated | `2026-10-13 04:49:21` / same | same / same | Exact match |
| Profile | Outlet, Entry Detail AB; classifications Battery Specialist, Hardware, General Merchandise | same | Exact match |
| Geography | General Category 1; Specific Region 53; Municipality 505; Province 28; Barangay 13567; Area Cluster 18 | same | Exact match |
| Annual category history | 9 rows, 2018–2026, matching each year/profile/stream/category | same 9 rows | Exact match |
| Category events | 0 | 0 | Match |
| Customer Access | 8 Portal users | 8 Tablet links; all 8 identities match by normalized email | Exact identity set |
| PIC | Portal user ID 12 | Tablet user ID 2; same normalized email identity | Exact identity match |

Portal `created_by` is user 12; Tablet `created_by` is null. This is a provenance-field difference, but does not outweigh the exact UUID, Customer Code, Company, timestamps, profile, geography, annual history, Access, and PIC matches. Portal direct Customer-FK inventory found 1 trade profile, 9 category histories, and 8 access links; branches, customer brands/categories, category events, notes, profiles, expenses, salescall brands/categories, and salescalls each had zero rows for this Customer.

### Classification of both Tablet rows

- **Tablet `-687143025929916172`: A — Confirmed Portal identity match** for Portal Customer 23591, supported by exact identity fields and matching related data above.
- **Tablet `23591`: B — Confirmed different Customer identity from Portal Customer 23591.** Its UUID is `ed72ddcd-d2e0-48fc-ba08-a6369eaeff9d`, Customer Code is `IB53385`, Company ID is 6, profile is IB, and its Access identity does not match Portal 23591. Portal read-only lookup found no Customer with that UUID or Customer Code in the current local Portal database. This proves it is not the Portal 23591 identity; it does **not** establish whether it is a valid local-only Customer or which other Portal identity, if any, it was intended to represent.

The positive-ID row has one IB trade profile and one Customer Access link, but no annual category history, category events, scope, branches, customer brands/categories, notes, expenses, or salescall-related rows in the inspected direct dependency inventory. It has no PIC, is active, not tombstoned, and is marked synced with zero Customer sync attempts/no error. It is therefore separate data that must be preserved, not merged into or deleted with the canonical Portal row. Its exact alternate origin remains unresolved. The same complete direct dependency inventory was made against Tablet; the negative row has its 9 annual histories and 8 Access links, and both rows have their respective single trade profiles. Child sync statuses must still be considered before any future repair; a Customer-level synced flag alone is insufficient.

### Recommended canonical row and bounded repair proposal (not executed)

**Canonical Tablet row for Portal ID 23591: `-687143025929916172`.** Preserve its local primary key, UUID, Customer Code, business fields, profile, all nine category histories, all eight Customer Access links, PIC, and locality relationships. **Preserve local row `23591` and its local UUID, Customer Code, Company, IB profile, Access link, and any future-discovered dependents as a separate Customer.** Do not merge data. The smallest proposed correction is to remove only the positive row’s erroneous `server_id=23591` binding, leaving its local primary key and other data untouched, unless the Human identifies an authoritative alternate Portal identity that should instead be bound. Do not change it to pending/failed or push it as part of this repair; no Push is authorized. The local Portal search found no matching UUID or Customer Code to justify a replacement server ID.

This proposal restores the intended one-to-one mapping for Portal Customer 23591 without discarding the other local aggregate. Before execution, rehearse on a disposable copy to verify `applyPullPayload()` resolves incoming ID and UUID to the negative-ID row after the positive mapping is cleared, leaves the positive row byte-for-byte unchanged aside from the approved association field, and remains repeatable. Include all direct and indirect references, uniqueness/index behavior, sync states, and the current run checkpoint in a pre-change manifest.

### Repair, Pull, and rollback gates

Before any authorized repair: (1) Human approves the negative row as canonical and explicitly approves disposition of the positive row’s `server_id`; (2) verify whether the positive row has an intended alternative Portal identity from an OMMC data owner; (3) create and verify a restorable, access-controlled backup of the Tablet database and a row/dependent/checkpoint manifest; (4) rehearse the exact change and two repeated Pull-page applies on a disposable copy; and (5) execute only the approved field-level change atomically, with before/after row and relationship checks. On any mismatch, stop immediately and restore the verified backup rather than attempting an ad hoc reverse edit. No repair has been applied.

Only after independent validation of the repaired identity and a review of the historical partial-page records may a separate Human GO authorize Customer Pull. Preserve the current active run checkpoint (`after_id=0`, `pulled=0`) and last completed watermark during repair; do not reset either. Confirm all previous page commits and scope reconciliation against run history, ensure exactly one Tablet row maps to Portal 23591 and its UUID, verify the failed page transaction left no partial Customer/child changes, and then verify a disposable replay is idempotent before retained retry. Pull and repair require separate authorization. No retained Pull ran.

**Final state:** Portal authority is established for one row; the other row is confirmed different from Portal 23591 but its alternate identity is unresolved. The proposed correction is **not authorized or executed**. Human approval is required for the exact mapping correction and preserved disposition before any database mutation. The Work remains `READY_FOR_HUMAN_REVIEW`.

**Execution addendum:** Portal identity and related records were read from the verified local non-production database inside a read-only transaction, which was rolled back. Tablet related-record inventory used SQLite `mode=ro`. Only aggregate/sanitized identity evidence was recorded; user emails were compared in memory and never emitted. No database writes, migrations, Pull/Push, checkpoint changes, production access, app-code changes, commit or push occurred. `git diff --check` passed; the Work note has no trailing whitespace.

## Identity collision repair preparation — 2026-10-13

**Decision state: `READY_FOR_HUMAN_REVIEW`.** This phase prepared and verified the proposed repair on disposable data only. No retained database repair, Pull, Push, migration, or application-code change occurred.

### Schema and synchronization preconditions

- Tablet `customers.id` is the local primary key and is not the Portal key. `server_id` is nullable. Its index is non-unique, which is why the existing bad state can contain two rows with `server_id=23591`. `local_uuid` has a unique index. Clearing the wrong `server_id` therefore does not require a schema change and leaves the positive local primary key available.
- Pull reads `server_id` and incoming UUID matches before the same-ID fallback. With the negative row retaining `server_id=23591` and the matching Portal UUID, and the positive row’s server association null, both identity lookups resolve to the negative row. The conflict guard should pass; the same-ID fallback does not override a consistent ID+UUID match.
- The retained positive-ID row currently remains `sync_status=synced`, `sync_attempts=0`, `sync_error=NULL`, and has `synced_at`; no matching `sync_states` keys for either Customer ID were found. Clearing `server_id` alone does not alter those fields. `pendingCustomerPushQuery()` selects only `pending` or retryable `failed` rows and does not select by null `server_id`; `hasPendingCustomerPushWork()` is false for the disposable repaired state. Thus **server_id=NULL alone does not make the currently synced row Push-eligible**.
- Push safety is conditional over time: if the row is later changed to `pending` or retryable `failed`, `customerPushPayload()` sets `sync_intent=create` when `server_id` is null. Portal create handling resolves by `local_uuid` and would create a Portal Customer if that UUID is not already present. The current local Portal database had no record for the positive row’s UUID or Customer Code, but that does not rule out another retained Portal target or an identity known to the data owner. Do not change its sync status or Push it as part of the repair. Before any later edit/retry that makes it pending, establish the intended identity and Customer Code uniqueness on the authorized Portal target.
- The current retained rows remain synced and unchanged; the active run is still `customers.pull_run.2` at `after_id=0`, `pulled=0`, with last completed watermark `2026-10-13T01:28:24.476729Z`. Historical records still show earlier partial page commits. No checkpoint/watermark change is part of the proposed repair.

### Disposable repair proof

Added a focused test to `tests/Feature/CustomerPagedPullTest.php`: `clearing only an incorrect server ID preserves the local Customer and lets repeated Pull resolve the canonical row`. It uses `RefreshDatabase` on SQLite `:memory:` (confirmed by `phpunit.xml`) and `Http::fake`; it does not call live endpoints or use the retained Tablet or Portal databases.

The test seeds a collision using the confirmed two IDs, UUIDs, Customer Codes, Companies, distinct Outlet/IB profiles, eight canonical Access links versus one separate link, and nine canonical annual category-history rows. It applies only a guarded `UPDATE` to the disposable positive row (`id=23591 AND server_id=23591 AND local_uuid=<expected>`) to set `server_id=NULL`, then feeds a mocked Portal response with the verified canonical ID/UUID twice. Assertions verify:

1. Both Customers remain; the canonical negative row still has `server_id=23591`, matching UUID and Customer Code, and exactly one row maps to that server ID.
2. The separate row retains its local ID, UUID, Customer Code, Company, `synced` state, profile, Access link and all other Customer columns; only its fixture server association is null.
3. Canonical Outlet/AB profile, all nine history years, all eight Access identities, and PIC identity remain present; no history or profile is attached to the separate IB row.
4. An unrelated third Customer, its profile, Access link and scoped relationship remain unchanged.
5. Both mocked Pull runs succeed; after repetition there are still three Customers total, one canonical UUID match, and one mapping for Portal ID 23591. No duplicate is inserted.
6. The repaired row is not push work while still synced/null-server-ID.

Result: **1 focused test passed, 28 assertions**. The initial disposable runs exposed only fixture/assertion mismatches (including an incorrect expected count after adding the unrelated Customer); correcting the test fixture/assertions allowed it to pass. No application defect was exposed by this proof. This validates the lookup behavior and field-level repair model, not execution against retained records or historical Pull recovery.

### Exact retained-repair proposal and rollback

Only after a separate explicit Human GO, the candidate SQL operation is equivalent to:

```sql
UPDATE customers
SET server_id = NULL
WHERE id = 23591
  AND server_id = 23591
  AND local_uuid = 'ed72ddcd-d2e0-48fc-ba08-a6369eaeff9d';
```

Require exactly one affected row. The canonical row `id=-687143025929916172` is a read-only precondition and must still have `server_id=23591` and UUID `25a4b477-d5f8-48e4-aca7-3b61346f1f06`. The candidate positive row must still have its confirmed UUID and remain synced with no pending status change. If any condition differs, abort without writing. Do not update UUID, Customer Code, Company, status, timestamps, profiles, histories, Access, checkpoint, or watermark.

**Pre-change snapshot:** take a verified, restorable full Tablet database backup; record the two Customer rows, all direct and indirect Customer references, profile/history/access/PIC/scope rows, sync-state keys relevant to these IDs, and the active checkpoint/watermark. Record row counts and stable-field fingerprints; protect the backup and manifest as sensitive operational data.

**Atomic execution/post-checks if later approved:** within one SQLite transaction, run the guarded update and require one row affected. Before commit, verify both local IDs and UUIDs remain; exactly one `server_id=23591` remains and belongs to the negative row; positive row `server_id` is null and `sync_status` remains synced; Customer Codes and nonidentity fields are unchanged; every captured relationship count/value is unchanged; and the checkpoint/watermark is unchanged. Then exercise the corrected mapping against a disposable copy of the verified payload before permitting any separate Pull.

**Rollback:** if any precondition or post-check fails before commit, roll back the transaction. If a post-commit verification fails, stop all sync activity and restore the verified full backup (or the exact captured association only if a database owner verifies the database has had no intervening writes); do not issue a blind inverse update that could overwrite concurrent work. Confirm restored row/relationship fingerprints and checkpoint before resuming normal use.

### Remaining safety blockers and approvals

This exact one-column correction resolves only the duplicate identity guard. It does not make retained Customer Pull safe by itself. The previous partial-page history still needs reconciliation against its run records; the current failed page is at the first cursor and rolled back, but previous pages from earlier runs may have committed. Before any Pull, confirm the complete prior page/scope history, that the active failed page left no partial apply, preserve current cursor and watermark, validate the exact page replay and idempotence on a disposable copy, and confirm only the canonical local row resolves by both Portal ID and UUID. A separate Human GO for Pull is still required.

**Required Human approval before execution:** approve (1) the guarded `server_id=NULL` update on local row 23591 only, with the negative row as canonical; (2) preservation of the positive row as a separate local Customer and its synced state; and (3) the specified backup, transaction, and rollback procedure. Any future Push eligibility or alternate Portal identity must be reviewed separately. A distinct GO is required for Customer Pull after historical partial-page reconciliation. No repair or synchronization is authorized or performed by this preparation Work.

### Validation and execution record

- Focused repair test: **1 passed, 28 assertions** (`php artisan test tests/Feature/CustomerPagedPullTest.php --filter='clearing only an incorrect server ID'`). Existing duplicate-rejection test: **1 passed** (`--filter='pull fails safely when a Portal ID is already duplicated'`). Test database: SQLite `:memory:`; Portal endpoints mocked.
- `php -l tests/Feature/CustomerPagedPullTest.php`: passed.
- `vendor/bin/pint --test tests/Feature/CustomerPagedPullTest.php`: passed.
- `git diff --check`: passed.
- Read-only retained-state recheck confirmed both duplicate rows and the first-page checkpoint remain unchanged. No retained database writes, migrations, Customer Pull/Push, production access, application-code changes, commit, push, or deployment occurred. Worktree application changes from the separate Tablet Customer Interaction Reconciliation Work were left untouched.

**Recommendation:** `READY_FOR_HUMAN_REVIEW` — the disposable proof supports the exact one-column correction while preserving both local Customers. Retained repair awaits explicit Human approval; Customer Pull additionally awaits historical partial-page reconciliation and a separate authorization.

## Guarded identity repair execution — 2026-10-13

**Repair result: PASS. Work state: `READY_FOR_HUMAN_REVIEW`.** This execution used the Human GO for the bounded Tablet identity correction only. It did not authorize or perform Customer Pull/Push.

### Database and backup

- Laravel runtime configuration identified `APP_ENV=local`, SQLite connection, and the exact active file as `/Users/highlite/Workspace-Kaisa/PHP/ommc2027-tablet/database/database.sqlite`. The path matches the Tablet database inspected during the accepted identity investigation. The file was 8,568,832 bytes before repair, with `PRAGMA integrity_check=ok`.
- Before mutation, a consistent SQLite online backup was created and verified at `/private/tmp/ommc-tablet-identity-pre-repair-20261013-jb_ckk6d.sqlite`, mode `0600`, size 8,568,832 bytes, SHA-256 `df3c3da304a8b2b84831d2c206aa5489e855f7fb9f51de8094ff`.
- Backup verification: integrity check `ok`; 100 application tables; every table row count matched the source; both identity rows and their expected identity fields matched. A disposable restore-copy rehearsal also passed (`integrity_check=ok`, 706 Customers, both original duplicate associations present); the temporary restore copy was removed. The verified pre-repair backup remains available at that path for recovery review.

### Precondition results

All required checks matched the accepted evidence immediately before the transaction:

- Canonical row `id=-687143025929916172`: `server_id=23591`, UUID `25a4b477-d5f8-48e4-aca7-3b61346f1f06`, Customer Code `CARCLUBS00013`, Company 3, synced, zero attempts, no sync error, not deleted.
- Separate row `id=23591`: `server_id=23591`, UUID `ed72ddcd-d2e0-48fc-ba08-a6369eaeff9d`, Customer Code `IB53385`, Company 6, synced, zero attempts, no sync error, not deleted.
- Exactly two rows then mapped to server ID 23591. No per-Customer sync-state keys were present.
- Dependent rows before repair: trade profiles 1 per Customer; annual category histories 9 on canonical / 0 on separate; category events, scopes, branches, brands, categories, notes, expenses, salescalls, salescall brands and salescall categories 0; Customer Access links 8 canonical / 1 separate. Customer PIC identities and all dependent identities/values had already been matched against the accepted Portal evidence and were captured for exact in-transaction comparison. No dependent table row had a pending/failed sync status.
- No precondition differed, so the bounded update proceeded.

### Transaction and post-repair verification

Inside one SQLite `BEGIN IMMEDIATE` transaction, the identities, duplicate cardinality, sync state, related rows, and checkpoint state were rechecked. The only mutation was the approved guarded update of `customers.server_id` for `id=23591`, requiring its expected UUID and prior `server_id=23591`. **Exactly one row was affected; the transaction committed.**

Post-commit read-only verification confirmed:

- Canonical negative-ID Customer retains `server_id=23591`.
- Separate positive-ID Customer has `server_id=NULL` and remains `sync_status=synced`, with UUID, Customer Code, Company, all other Customer fields, and its profile/access relationship unchanged.
- Both Customers still exist; only one Customer maps to Portal ID 23591; the canonical UUID still resolves to the canonical negative-ID row.
- All 9 category histories, both trade profiles, and all 9 Customer Access links remain. Other captured relationships remain unchanged.
- All 706 Customer rows were logically compared against the verified backup. The **only** permitted difference was `customers.id=23591.server_id: 23591 -> NULL`. All 100 application tables were compared; there were no other table differences. `sync_states`, including the active Pull checkpoint and watermark, matched the backup.
- Live database integrity check remains `ok`.

Pull identity resolution was **not** executed against the retained database. The already-passing disposable regression exercises the actual Pull apply path after the same guarded field correction, feeds the mocked Portal payload twice, verifies the canonical row is selected without duplicate insertion, preserves profiles/history/Access/PIC and an unrelated Customer, and reports the second payload unchanged. The retained database now satisfies the same unique server ID and UUID predicates. No live or retained Pull/Push was performed.

### Recovery and remaining Pull blockers

The verified pre-repair backup is available for recovery. If a future discrepancy is found, stop all sync activity and first compare the exact current committed state with the pre-repair backup and this one-field manifest. Do not blindly restore over any intervening application writes. If no intervening writes occurred, restore the verified backup; otherwise obtain database-owner review and use only an explicitly approved recovery plan. Before commit, any failed check would have rolled back the transaction; no check failed.

The repair removes the duplicate identity guard only. Historical earlier Pull attempts may have committed prior pages; the checkpoint/watermark were preserved, not reset. Reconcile those page and scope effects, confirm the failed current page left no partial changes, and perform the documented disposable idempotency/replay checks before requesting a separate Human GO for Customer Pull. No Pull is automatically authorized by this repair.

### Exact change and validation record

- Retained database change: `customers.id=23591`, `server_id` changed from `23591` to `NULL`; one row affected. No other database field, record, checkpoint, or reference table changed.
- Focused disposable repair test: **1 passed, 28 assertions**. Existing duplicate-rejection test: **1 passed, 3 assertions**. Both use SQLite `:memory:` and mocked Portal responses.
- PHP lint for the added test passed; targeted Pint passed; `git diff --check` passed; Work note whitespace check passed.
- Only repository file changed for this preparation/repair Work: this Work note and the focused test `tests/Feature/CustomerPagedPullTest.php`. Pre-existing Tablet Customer Interaction Reconciliation changes were preserved.
- No migration, Customer Pull/Push, checkpoint modification, Portal database modification, application-code change, commit, push, or deployment occurred.

**Final decision:** `PASS — GUARDED IDENTITY REPAIR VERIFIED`. Retained repair is complete. Customer Pull remains blocked pending historical partial-page reconciliation and its own explicit Human GO.

## Customer Push/Pull authority reconciliation plan — 2026-10-13

**Decision state: `READY_FOR_HUMAN_REVIEW` — planning only.** Human accepted the authority design and authorized implementation planning, but explicitly did not authorize application changes, retained data changes, migrations, Push/Pull execution, commit, or deployment. Existing repository edits and the completed identity repair above are historical state; this planning pass made no application or database changes.

### Verified current contracts

- **Portal Push:** `SyncController::pushCustomer()` validates the request, resolves create idempotently with `firstOrNew(['local_uuid' => ...])`, and persists Customer plus profile, access, category history, and category events in a transaction. Updates require Portal `server_id` + matching `local_uuid` and compare `base_updated_at`; a mismatch returns `409 customer_conflict`. Success currently returns flat `server_id`, `local_uuid`, `unique_id`, `updated_at`, event keys, and an optional access warning. It does **not** return the persisted Customer aggregate.
- **Portal Pull:** `TabletSyncPayload::customerRecords()` serializes Customer attributes, PIC/access email references, Customer brands/categories, trade profiles, category histories, and category events. `pullCustomers()` returns those arrays for each selected Customer page, plus keyset cursor, server time, and scope IDs. Collections are complete for the selected Customer set in that response, but the wire format has no explicit completeness/version marker. A successful empty collection is therefore indistinguishable from an omitted/partial collection to a generic consumer.
- **Tablet Push:** `SyncService::customerPushPayload()` sends `local_uuid`, optional `server_id`, `base_updated_at`, the Customer fields, profile, access/PIC email references, category history, and events. A successful response currently causes `markSynced()` to set `server_id` and sync metadata only; the Tablet fields are not reconciled to persisted Portal values. The success handler also does not check whether that `server_id` is already associated with a different local Customer.
- **Tablet Pull:** `applyPullPayload()` rejects multiple Tablet rows for one incoming `server_id` and rejects a UUID/server-ID disagreement. It prefers UUID, then `server_id`, but also has a same-numeric-primary-key fallback. That fallback is not identity proof and conflicts with the accepted rule against numeric-ID-only merges. Customer, profile, category history, and event writes use the resolved local key. `customer_brands` and `customer_categories` are currently reconciled later using the incoming Portal `customer_id` directly, not the resolved local key; their Tablet schema uses `customer_id` foreign keys to the local `customers.id`.
- **Conflict state:** Tablet already has `sync_status=conflict` and `sync_error`; Pull protects `pending`, `failed`, and `conflict` Customer rows. Automatic Customer Push selects `pending` and retryable `failed`, not `conflict`. Customer Edit currently changes a saved conflict Customer to `pending`, however, so there is no complete durable review/resolution workflow yet. Child tables do not share one common dirty-state contract: some carry sync status, while profile/access/history are part of the Customer aggregate.
- **Identity schema:** Tablet `customers.local_uuid` is nullable unique; `server_id` is nullable but only indexed, not unique. Local primary keys are referenced by Tablet children and must remain stable. Customer Code (`unique_id`) is useful corroborating evidence but is allocated/reserved and is not the cross-system identity key.
- **Pull recovery:** each successfully applied page and its run checkpoint are committed together. A failure rolls back that page and restores the prior step checkpoint; earlier pages from older attempts can remain committed. The final watermark is written only when page scan, scope reconciliation, and missing-ID recovery finish. The repaired active checkpoint/watermark were preserved. Historical partial-page state still needs reconciliation before a retained retry.

### Bounded implementation work packages

| Package | Scope and preconditions | Behavior and compatibility | Focused tests / acceptance | Rollback and dependency |
|---|---|---|---|---|
| **A. Portal acknowledgment contract** | Portal `SyncController::pushCustomer()` and `TabletSyncPayload::customerRecords()`; first verify the serializer can serialize the just-saved Customer and all its associated records inside/after the transaction. | Return an additive, versioned authoritative snapshot while retaining existing top-level response keys for older Tablets. Snapshot must declare each child collection complete, including empty collections. Do not mark the Tablet row synchronized from legacy identity-only acknowledgments. | Portal create/update tests assert returned snapshot equals a subsequent Pull representation, including empty and populated children, after profile/access/category persistence. Existing clients continue reading the old keys. | Roll back by disabling snapshot emission and keeping old envelope. Tablet package B/C depends on the agreed snapshot schema. API schema/version policy remains a Human decision if mixed-version deployments must be supported. |
| **B. Tablet identity resolution** | `SyncService` Push acknowledgment and `applyPullPayload()`; inventory and resolve existing duplicate UUID/server-ID associations before enabling stricter enforcement. | Resolve by unique `local_uuid`; require any existing `server_id` to equal Portal ID. If UUID is absent only allow a unique existing `server_id` match for previously linked rows; otherwise quarantine as unresolved. Treat duplicate matches, mismatched IDs, and same server ID with different UUIDs as conflicts. Remove same-primary-key fallback. Never merge on Customer Code or numeric ID alone. | Fixtures cover lost acknowledgment/same UUID, duplicate UUID rows, duplicate server ID rows, different UUID claiming same server ID, missing UUID legacy row, and same numeric ID/different UUID. Unsafe cases produce no row/child mutation. | Roll back matching policy behind a feature/config gate or revert Tablet code; do not undo data. Depends on A for Push acknowledgment and precedes C/D. |
| **C. Shared aggregate reconciliation** | Add a Tablet reconciliation service called by both Push acknowledgment and Pull, used inside the caller’s transaction. Keep local `customers.id` unchanged. | Apply Portal values to synchronized fields after successful Push; Pull does the same. Upsert Customer/profile in place. Replace exact-set children only when snapshot completeness is asserted; preserve local-only data and append-only event history by stable event key. Preserve existing relationship row IDs where possible; never delete/reinsert the Customer. Reject incomplete/unknown snapshot versions without marking sync complete. | Replay same snapshot twice; compare all synchronized fields and child sets; empty authoritative sets clear only the corresponding synchronized set; preserve local-only records and unrelated Customers; inject mid-reconcile failures and assert full page/ack transaction rollback. | One transaction rollback restores prior aggregate automatically. Depends on A/B/D. Do not introduce broad deletion semantics for omitted fields. |
| **D. Customer child foreign-key correction** | `applyPullPayload()` sections for `customer_brands` and `customer_categories`, plus all other Customer-owned collections. Build a Portal-ID → stable Tablet local-ID map once and require it for every child row. | Persist child FKs against the resolved local Customer ID. Keep children with independent local identity or append-only semantics on their established keys; only replace complete snapshot collections. Audit remaining child payloads for any direct Portal-ID use. | Temporary negative/local key with non-empty brands/category; assert FK points to local Customer. Repeat Pull without duplicates; verify empty complete set behavior and pending child preservation. | Covered by the page transaction. Roll back only D if assertions show unrelated callers depend on Portal IDs in local FK columns. Depends on B/C mapping. |
| **E. Pending-edit conflict protection** | Push claim/ack boundary, Customer Edit save behavior, Pull protected-status path, and child dirty-state checks. Define a fingerprint/version for the complete pushed Customer aggregate and a consistent way to detect pending child edits. | Capture the exact aggregate fingerprint at Push claim. On acknowledgment compare current state with sent fingerprint. If unchanged, reconcile authoritative snapshot and mark synced; if changed in flight, attach verified Portal identity but retain newer local and child values, mark `conflict`, and store safe reference/reason. Pull preserves pending/failed/conflict data. Customer Edit must not silently convert `conflict` to `pending`; require an explicit resolution action that chooses Portal snapshot or deliberate local re-Push. | Edit Customer and child data while mocked Push is in flight; verify ack cannot erase it. Pull with pending Customer and child rows; verify retained values and conflict state. Verify a conflict cannot enter automatic retry until explicitly resolved. | Snapshot prior row/status/fingerprint in disposable tests; rollback code without DB migration. If durable conflict details exceed `sync_error`, decide whether a new local sync-state record is necessary before implementation. Depends on C and may require a narrowly scoped UI resolution action. |
| **F. Pull checkpoint and watermark regression** | `pullCustomersStep()`, Portal `pullCustomers()`, scope/missing-ID reconciliation. No retained run may be started for validation. | Keep each page’s data and checkpoint atomic. Replay the failed cursor; never advance watermark on partial run. Record the snapshot/server-time boundary used by a run and ensure concurrent Portal changes cannot fall behind a cursor/watermark. Maintain current checkpoint until historical reconciliation is complete. | Failure before apply, during Customer/child apply, during scope reconciliation, and after earlier committed pages; assert retry cursor, row counts, no duplicate children, and watermark unchanged until completion. Verify scope and missing-ID recovery replay. | Restore disposable DB and checkpoint fixture. Any cursor format change needs backward-compatible parsing for active old run state. Depends on B–E. |
| **G. End-to-end disposable validation** | Coordinated Portal + Tablet test harness, temporary SQLite/MySQL test targets or isolated disposable DBs, mocked HTTP; no retained credentials or targets. | Exercise the whole agreed lifecycle and record sanitized row/relationship fingerprints before and after. Include confirmed 23591 collision as a regression fixture; no live endpoint. | Offline create → Push → acknowledgment reconcile → repeated Pull; Portal edit → Pull; lost ack/repeated Push; two UUIDs/one server ID; two rows/one UUID; pending edits; child relationships; later-page failure/retry; unrelated Customer unchanged. Acceptance requires one local row per confirmed identity and no silent data loss. | Drop disposable DBs only. This is the integration gate for all packages; any confirmed mismatch blocks retained sync. |

**Scope boundary:** work is limited to Customer push/pull identity, snapshot contract, Customer-owned aggregate records, conflict preservation, and Customer Pull checkpoint behavior. It excludes geographic reference remapping, other syncable record types, Customer Access business-rule changes, production diagnostics/UI redesign beyond an explicit conflict-resolution action, retained database repair, retained Pull/Push, and deployment.

### Contract and migration decisions

- **API compatibility recommendation:** additive `customer_snapshot` contract alongside existing acknowledgment fields; snapshot uses the same Customer and child serializer as Pull and includes a contract version plus explicit complete-set markers. Existing Tablet clients ignore the new field. New Tablet code must not mark synced from an identity-only acknowledgment. For mixed-version deployment, prefer a targeted Pull by acknowledged Portal ID to obtain the snapshot; if that endpoint cannot provide the required version/completeness contract, leave the row retryable/conflicted and surface a safe error. Do not assume coordinated deployment order until authorized.
- **Identity constraints:** keep `customers.id` stable and `local_uuid` unique. Do not add unique `server_id` until the complete retained inventory is clean and a disposable migration proves SQLite upgrade/rollback behavior. Unique nullable `server_id` is the desired final guard; it is a separate retained migration requiring authorization.
- **Child completeness:** Portal Pull currently sends the full rows for the selected Customer set, but has no per-collection completeness marker. Add explicit completeness metadata before the Tablet uses empty/missing arrays to delete synchronized child rows. For event history, use event-key upserts and explicit tombstones only if event deletion is a supported Portal operation.
- **Conflict representation:** reuse `sync_status=conflict` and `sync_error` initially; block automatic push/pull overwrite and edit-to-pending transitions. A minimal explicit resolution action is required for eventual recovery. If conflict audit detail cannot safely fit existing fields, add a separate local-only conflict record only after Human approval; no Portal schema change is otherwise assumed.

### Migration and historical recovery sequence

1. Freeze retained Customer Push/Pull while taking a verified Tablet backup and read-only identity inventory. Count duplicate non-null `server_id`, duplicate/non-null UUIDs, and rows where UUID and server ID resolve to different Customers. Inventory all direct/indirect dependents and pending child state.
2. Reconcile the historical partial-page attempts against Portal authority using the saved run/checkpoint, existing logs, and before/after local evidence. Do not reset the current checkpoint/watermark and do not infer that a failed current page means prior pages were rolled back.
3. Rehearse each exact identity/child correction on a restored disposable copy; record a guarded change manifest and rollback. Human separately authorizes any retained repair.
4. Deploy additive Portal contract and compatible Tablet consumer only after mixed-version behavior is decided. Run all disposable contract tests before any retained sync authorization.
5. After all duplicate server IDs are resolved, separately authorize and execute the unique nullable `server_id` migration with backup, integrity check, and rollback plan. Migration rollback must not restore stale data over intervening writes.
6. Only after post-migration uniqueness, complete-page replay, cursor/watermark integrity, and aggregate idempotency are verified should Human consider a separate GO for retained Pull. Customer Push remains separately gated; this planning authorization permits neither.

### Unresolved questions and recommendation

1. Confirm the minimum supported Portal/Tablet version pairing and whether targeted Pull is the fallback when an older Portal omits the new acknowledgment snapshot.
2. Approve whether explicit conflict resolution is a bounded part of this implementation or a prerequisite follow-up Work. Without it, preserving edits safely can leave Customers blocked in `conflict` indefinitely.
3. Confirm whether the Tablet’s `customer_brands` and `customer_categories` are wholly Portal-authoritative or can contain Tablet-local edits; replacement/deletion rules depend on that ownership.
4. Confirm the approved historical partial-Pull evidence source and who may approve its reconciliation manifest.

**Recommendation:** `READY_FOR_HUMAN_REVIEW` for package boundaries and contract decisions. Implement A–G only after these questions are settled; perform duplicate inventory, historical reconciliation, unique-index migration, retained repair, and retained synchronization under separate explicit authorization. No application code, database, checkpoint, migration, sync operation, commit, or push was changed by this planning update.

## Packages A and B implementation gate — 2026-10-13

**State: `READY_FOR_HUMAN_REVIEW`.** Human accepted the implementation plan and authorized bounded application implementation for Packages A and B only. No retained database access/write, migration, Customer Pull/Push execution, checkpoint/watermark modification, deployment, commit, or push occurred. Earlier unrelated Tablet interaction changes listed in the pre-existing worktree remain preserved and are not part of this package scope. `CustomerPagedPullTest.php` and `CustomerCreatePageDiagnosticTest.php` already contained unrelated edits before this gate; this Work adds focused package assertions and acknowledgment UUIDs in those shared files, so their full diffs contain mixed ownership.

### Package A — Portal acknowledgment

Portal Push success now includes an additive `customer_snapshot` object produced by the existing `TabletSyncPayload::customerRecords()` serializer for the single persisted Customer. It identifies contract version 1, capability `customer.aggregate.snapshot.v1`, and names the synchronized Customer/child collections guaranteed complete, including empty collections. Existing top-level acknowledgment keys are retained; the snapshot is scoped to the acknowledged Customer. Tablet can recognize this contract, but Packages C onward must implement applying it. Until then, Tablet explicitly reports that delivery was acknowledged but authoritative aggregate refresh was not applied. Older responses without the capability may establish delivery identity only when local UUID identity is verified; they do not claim complete aggregate reconciliation.

Portal changed files:
- `app/Http/Controllers/Api/SyncController.php`
- `app/Services/TabletSyncPayload.php`
- `tests/Feature/TabletCustomerCreationContractTest.php`

Portal tests: `php artisan test --compact tests/Feature/TabletCustomerCreationContractTest.php` — **35 passed, 212 assertions**. Coverage includes create/update acknowledgment, persisted values, serialized related data, version/capabilities/completeness, backward-compatible top-level fields, and authorization/data isolation.

### Package B — Tablet identity resolver

Added a read-only `CustomerIdentityResolver` returning explicit `matched`, `new`, or `conflict` results. It validates Portal IDs, prefers `local_uuid`, checks any existing `server_id`, detects duplicate UUID/server associations and split identity claims, uses company and Customer Code as corroboration for a legacy server-ID-only match, and refuses a numeric local primary-key coincidence. Pull resolves all Customer identities before aggregate writes and uses the resolved local key while preserving the Tablet primary key. Push acknowledgment verifies the returned identity against the exact in-flight local Customer; contradictions mark the row `conflict` without binding the Portal ID. No row is merged or deleted.

Tablet changed files owned by Packages A/B:
- `app/Services/CustomerIdentityResolution.php` (new)
- `app/Services/CustomerIdentityResolver.php` (new)
- `app/Services/SyncService.php`
- `tests/Feature/CustomerIdentityResolverTest.php` (new)
- `tests/Feature/CustomerOnlyPushTest.php`
- `tests/Feature/CustomerPagedPullTest.php`
- `tests/Feature/CustomerCreatePageDiagnosticTest.php` (mock acknowledgment UUID compatibility)

The 23591 disposable regression proves UUID selects canonical local row `-687143025929916172`; the distinct local Customer at ID `23591` remains unchanged and creates an explicit conflict if it claims the same Portal server ID. Other tests cover repeated UUID resolution, duplicate server IDs, numeric-ID collision, missing/conflicting UUID, legacy corroboration, stable local key on acknowledgment, and unrelated Customer preservation. To satisfy accepted invariant 7 at the identity boundary, Pull maps `customer_brands.customer_id` and `customer_categories.customer_id` through the resolved Portal-ID-to-local-ID map. A negative-local-key regression reads both foreign keys back and confirms they point to the canonical local ID, with no child rows written under the Portal numeric ID.

### Validation and known gap

- Portal acknowledgment suite: **35 passed, 212 assertions**.
- Tablet identity resolver plus Customer Push suites: **27 passed, 124 assertions**. The focused Pull identity subset after the child-FK change: **8 passed, 68 assertions**; the directly affected negative-local-key case also passed in isolation (**1 passed, 9 assertions**).
- Tablet `CustomerIdentityResolverTest.php`, `CustomerOnlyPushTest.php`, and `CustomerPagedPullTest.php` combined: **56 passed, 1 failed, 501 assertions**. All identity resolver and Customer Push cases pass; Pull identity/collision and local child-FK cases pass. The one failure is the existing Sales Call Pull test `the salescall pull uses the schedule endpoint and fetches locations only when missing` (second `pull()` returns unsuccessful). It exercises the separate schedule endpoint and does not contain Customer rows; it is outside Package B. It remains a validation blocker for claiming the broader three-file suite fully green and was not altered. Do not claim that this unrelated path passed.
- During focused Push tests, the acknowledgment path exposed an absent optional `unique_id` key warning. The existing fallback now handles omitted values safely; it preserves the local code. Tablet Customer Push tests then passed **21/21, 99 assertions**.
- PHP lint passed for the scoped Portal and Tablet application/test PHP files. Targeted Pint passed in both repositories. `git diff --check` and trailing-whitespace checks for both Work notes passed.
- Compatibility fake responses now include `local_uuid` where they represent a valid Tablet-origin acknowledgment. A missing or contradictory UUID is intentionally an explicit identity conflict; omission of the snapshot capability alone is the supported legacy case.
- Targeted child-FK read-back test: **1 passed, 9 assertions**. No migration or retained synchronization was used. No full aggregate application is claimed because Package C is not implemented. The broader Package D audit and any further child reconciliation remain outstanding; only the explicitly accepted brand/category local-FK invariant was included here.

### Remaining accepted packages and decision

Packages C–G remain: shared transactional aggregate reconciliation, broader child foreign-key audit/reconciliation, pending-edit conflict protection, Pull checkpoint/watermark regressions, and end-to-end disposable validation. The explicit brand/category local-FK invariant was implemented with Package B identity mapping. Package A/B behavior is implemented and focused identity/acknowledgment evidence passes, with the unrelated Sales Call Pull failure recorded above. Human review is required before treating the full validation gate as clear or authorizing later packages. Work remains `READY_FOR_HUMAN_REVIEW`.

## Packages A/B regression investigation — 2026-10-13

**Authorization:** Read-only regression investigation only. No application/test implementation change was made in the working Tablet checkout. Disposable test databases and temporary probe copies under `/private/tmp` were used; all probe files were outside the repository. Existing worktree changes were preserved.

### Reproduction and state

The exact test `the salescall pull uses the schedule endpoint and fetches locations only when missing` fails independently and in the broader current Tablet suite. The isolated first Pull succeeds with `Pulled 0 itineraries, 0 salescalls.` It fetches `/api/sync/pull/locations`, then `/api/sync/pull/schedule`. The first location response stores `location_reference_snapshot_complete.reference_contract_version=2`; the fixture has one Municipality, no Customers, no Itineraries, and no Sales Calls. Before the second Pull, that v2 marker remains and the database still has no Customer or itinerary rows. Production `SyncService::pull()` checks for snapshot version `< 3`, so the second Pull fetches locations again. It returns false with `Pull error: Portal location response is missing supported snapshot metadata.` The second schedule request is never sent.

The failing test registers one static `Http::response(...)` object for the location URL. Because the location response is streamed to a temporary file, reusing that same fake response for the second location request does not provide a fresh response body. A disposable probe with a callback that creates a fresh response for each request and the same v2 payload makes both Pull calls succeed, but sends four requests (locations + schedule twice), contradicting the test's expected three. A disposable probe using a fresh version 3 snapshot, including `landmass_options`, makes both pulls succeed with the expected three requests.

### Baseline and change impact

Classification: **C — test fixture/contract mismatch; not test-order contamination and not introduced by Packages A/B.**

- Exact isolated reproduction at pre-A/B `HEAD=8e2d081` fails with the same second-Pull result. `git archive HEAD` provided an isolated baseline checkout; no branch, current file, or worktree was reset or changed.
- The baseline `CustomerOnlyPushTest.php` + `CustomerPagedPullTest.php` suite reports **1 failure**, the same Sales Call Pull case (46 pre-existing warnings, 429 assertions). The current Package A/B suite reports **56 passed, 1 failed, 501 assertions**, with that same single case failing.
- With the v3 fresh-response fixture in temporary copies, the target test passes in both baseline and current code (5 assertions each; Pest reports one warning in each run).
- Packages A/B modify Push acknowledgment handling and Customer identity processing inside `applyPullPayload()`. The failing second call exits during the earlier location snapshot validation. The test's schedule payload contains `customers: []`, so Customer identity resolution is not invoked. Endpoint routing in `pull()`, the `< 3` snapshot-version gate, and `validateLocationSnapshot()` are unchanged from baseline.

### Recommendation

The evidence supports **GO to keep Packages A/B independent of this failure**; their identity/Push/Pull focused cases are unaffected. Recommended correction is limited to the stale test fixture: produce the currently supported version 3 snapshot with `landmass_options`, and use a response factory/callback for mocked HTTP so any repeated streamed read receives a fresh body. No production change is indicated. Because the uncorrected broader suite still has a red test, keep terminal delivery on hold until a separate Human GO authorizes that test-only correction and it is rerun. No test correction was made under this read-only authorization.

## Sales Call Pull fixture correction — 2026-10-13

**Authorization:** Bounded test-only correction. The only test-source change in this step is inside `the salescall pull uses the schedule endpoint and fetches locations only when missing` in `tests/Feature/CustomerPagedPullTest.php`; no application source, retained data, migration, checkpoint, or synchronization operation was changed or run.

The fixture now sets the currently supported `reference_contract_version` to `3`, supplies the required `landmass_options` metadata, and uses an HTTP fake callback that creates a fresh response for each request. The test's original assertions remain unchanged: both Pulls succeed, the first fetches locations and schedule, the second fetches schedule only, and the total remains three requests.

### Validation evidence

- Corrected test independently: **1 passed, 5 assertions**.
- Sales Call Pull tests (`--filter='salescall pull'`): **2 passed, 15 assertions**.
- Package A/B identity, Customer Push acknowledgment, and Customer Pull identity regression files: **57 passed, 504 assertions**.
- Full Tablet suite (`php artisan test --compact`): **601 passed, 3 skipped, 13 failed, 4,737 assertions**. Failures are outside the approved fixture scope: three `CustomerCodePageTest` redirect expectations; one `CustomerCodeSyncTest` Push expectation; two `CustomerLocationMapTest` street-only address expectations; one `CustomerLocationSaveTest` incomplete Add fixture; one `CustomerMapLocationAutomationTest` redirect expectation; one `ExampleTest` unauthenticated root response expectation; three `FirstLoginPersistenceTest` calls to missing `SyncService::lastError()`; and one separate legacy fixture in `SalescallStatusesMigrationTest` (second Pull returns unsuccessful). These failures were not modified.
- `php -l tests/Feature/CustomerPagedPullTest.php`: passed.
- Targeted Pint for that test file: passed.
- `git diff --check`: passed.

Packages A/B's focused identity/Push/Pull regressions are green at **57/57** in the combined three-file run. The previously failing named Sales Call Pull test is now green; the full repository suite is not all green due to the failures listed above. Packages C–G remain unimplemented. No commit or push was performed. Work remains `READY_FOR_HUMAN_REVIEW`; terminal delivery is not authorized by this decision.

Additional Sales Call regression inspection: `tests/Feature/SalescallStatusesMigrationTest.php` run independently reports **2 passed, 1 failed, 9 assertions**. Its separate `pull inserts and updates salescall_statuses from the payload` fixture fails on its second Pull at line 82. This is outside the specifically authorized fixture and was left unchanged; it remains an unrelated Sales Call regression blocker in the full-suite result.

## Packages A/B full-suite failure triage — 2026-10-13

**Authorization:** Read-only classification only. No application/test source was edited for this triage; no retained data or sync operation was run. The pre-A/B baseline is commit `8e2d0813e6acbaa0bdabed2c3e159a397cb60c62`. I extracted it with `git archive` under `/private/tmp`, copied the installed dependencies into that disposable tree, supplied a test-only APP_KEY, ran the equivalent failing-file suite, and removed the tree. The attempted Git worktree route was denied because sandbox permissions do not allow `.git/worktrees` writes; it created no worktree and did not alter repository state. The initial archive runs with a linked vendor directory / no APP_KEY were invalid infrastructure attempts and are excluded from the comparison.

### Failure inventory and classification

Every current failure below was rerun independently with a single-test filter and reproduced; all 13 also appeared in the current grouped failure-file run and the full-suite run. The equivalent baseline grouped run reproduced 12. `CustomerCodeSyncTest` passed on baseline and is the sole added failing expectation in the current tree.

| # | Test and observed failure | Sync / identity path | Baseline | Classification |
|---|---|---|---|---|
| 1 | `CustomerCodePageTest` — `creation keeps the reservation token on the local Customer when its automatic push fails`; expected redirect, none occurred. The Add fixture supplies Company/code but omits other create-required details. | Save validation blocks before Push; no identity resolution. | Same failure | **C — stale incomplete Add fixture** |
| 2 | `CustomerCodePageTest` — `creation commits locally before successful automatic Customer reconciliation`; expected redirect, none occurred. Fixture supplies only Company and name. | Save validation blocks before Push; no identity resolution. | Same failure | **C — stale incomplete Add fixture** |
| 3 | `CustomerCodePageTest` — `creation remains successful and locally stored when automatic push throws offline`; expected redirect, none occurred. Fixture supplies only Company and name. | Save validation blocks before offline Push; no identity resolution. | Same failure | **C — stale incomplete Add fixture** |
| 4 | `CustomerCodeSyncTest` — `customer sync sends the reservation token and reconciles Portal unique_id`; Push result is false, expected true. Its fake success body omits `local_uuid`. | `SyncService::pushCustomerRecord()` invokes `CustomerIdentityResolver`; with `expectedLocalId` and no matching UUID it returns `acknowledgment_has_no_matching_local_uuid`, marks conflict, and rejects the acknowledgment. | **Pass** | **C — stale mock exposed by Package B’s identity guard.** This is one test-suite delta attributable to the new guard, but not a confirmed real Portal contract regression: Portal’s pre-A/B `pushCustomer()` response already returned top-level `local_uuid` (verified at Portal HEAD). The fake does not represent that contract. |
| 5 | `CustomerLocationMapTest` — `tablet resolveLocation fills coordinates, address and hierarchy`; expected `123 Roxas Street`, received provider display address `123 Roxas St, Angeles, Pampanga`. | Map reverse-resolution only; no SyncService or identity resolution. | Same failure | **C — stale street-only assertion** against the accepted full-display-address behavior. |
| 6 | `CustomerLocationMapTest` — `tablet resolveLocation resolves directly from passed coordinates`; same expected/actual address mismatch. | Map reverse-resolution only; no SyncService or identity resolution. | Same failure | **C — stale street-only assertion** against the accepted full-display-address behavior. |
| 7 | `CustomerLocationSaveTest` — `a location resolved from the map can be saved on the create page`; Livewire reports required errors for `contact_person`, `business_landline_number`, `business_mobile_number`, and `date_established`. | Customer Add validation stops before persistence or Push; no identity resolution. | Same failure | **C — stale incomplete Add fixture** under the accepted completeness contract. |
| 8 | `CustomerMapLocationAutomationTest` — `Customer Add persists map-derived location and manual Access through its sync path`; expected redirect, none occurred. Fixture supplies Company, name, mapped location and Access, but omits other create-required values. | Add validation stops before persistence/Push; no identity resolution. | Same failure | **C — stale incomplete Add fixture**. |
| 9 | `ExampleTest` — `the application returns a successful response`; `/` returns HTTP 302, expected 200. | Route/auth response; no SyncService or identity resolution. | Same failure | **C — stale generic smoke-test expectation** for the existing root redirect. |
| 10 | `FirstLoginPersistenceTest` — `isReachable reports the HTTP status when the sync server responds with an error`; call to undefined `SyncService::lastError()`. | Generic reachability only; Customer identity path is not used. | Same failure | **C — test expects an unavailable diagnostic accessor.** |
| 11 | `FirstLoginPersistenceTest` — `isReachable reports the underlying connection exception`; same undefined `lastError()` call. | Generic reachability only; Customer identity path is not used. | Same failure | **C — test expects an unavailable diagnostic accessor.** |
| 12 | `FirstLoginPersistenceTest` — `isReachable reports an unconfigured sync server`; same undefined `lastError()` call. | Generic reachability only; Customer identity path is not used. | Same failure | **C — test expects an unavailable diagnostic accessor.** |
| 13 | `SalescallStatusesMigrationTest` — `pull inserts and updates salescall_statuses from the payload`; second Pull returns unsuccessful. Its location helper supplies contract version 2 without `landmass_options`; the test reuses a static streamed location response. | Generic Pull and snapshot validation fail before schedule payload application; `customers` is empty, so Customer identity resolution is not invoked. | Same failure | **C — stale repeated-Pull location fixture**, the same v2/static-response pattern as the separately corrected Sales Call test. |

No failure was classified as test-order contamination or an infrastructure issue after the valid rerun. The independent filters and grouped runs agree. The three `FirstLoginPersistenceTest` failures are PHPUnit errors (exit 2); the others are failed assertions (exit 1).

### Baseline and Packages A/B impact

- The current full Tablet suite remains **601 passed, 3 skipped, 13 failed, 4,737 assertions**. The previously approved named Sales Call fixture correction passes independently and within its related Pull test group; it is not one of these 13 failures.
- The equivalent eight-file grouped run at baseline `8e2d081` has the same 12 inherited failures and the `CustomerCodeSyncTest` passes. The corrected named Sales Call test was not part of those eight files; the earlier v2 fixture failure in that test has already been corrected in the current test-only change and its focused pass is recorded above.
- Current single-test filters reproduce all 13 failures. Current grouped failure-file run reproduces all 13. Baseline grouped failure-file run reproduces 12, with the Customer Code Push test passing.
- The single test delta exercises Package B's stricter acknowledgment identity check, but its mock omits `local_uuid`; Portal's pre-A/B response contains that field. The conservative resolver is behaving according to the accepted identity rule. The smallest remedy, if separately authorized, is to make this mock echo the request `local_uuid`; do not weaken identity matching.
- The other 12 failures are not caused by A/B. The Customer Add failures stop in form validation; the map address failures are in reverse-geocode display assertions; Example and FirstLogin are pre-existing test/API expectation mismatches; and the Sales Call Pull failure occurs at the unchanged snapshot-version validation boundary before Customer reconciliation.
- The `SyncService` diff for A/B is limited to Customer identity pre-resolution / local-key mapping in `applyPullPayload()`, Customer Push acknowledgment identity and capability handling, and generic Push summary formatting. Non-Customer Pull/Push persistence and endpoint routing are unchanged. The full-suite failures provide no evidence of non-Customer side effects from the summary-formatting change. Focused Customer identity, Push acknowledgment, and Pull identity suites remain **57 passed, 504 assertions**.
- No failure exercises or demonstrates a defect in Customer child foreign-key mapping, Customer Edit, or the accepted Customer Pull collision logic. Their relevant A/B focused regressions pass; this does not establish completion of Packages C–G.

### Minimal remediation recommendations (not implemented)

1. Add the echoed `local_uuid` to the `CustomerCodeSyncTest` acknowledgment fake and retain strict identity validation.
2. Complete the omitted required fields in Customer Add test fixtures; keep assertions and the validation contract intact.
3. Update the two map-address assertions to expect the provider display address required by the accepted address behavior.
4. Make `ExampleTest` assert the existing unauthenticated redirect (or authenticate and assert the intended page response).
5. Reconcile the three FirstLogin tests with the current reachability API/logging contract; only add an accessor if it is separately approved as a product requirement.
6. Update the separate `SalescallStatusesMigrationTest` location fixture to the supported version 3 contract with required metadata and fresh responses for repeated streamed requests. This is a separate fixture correction and was not authorized here.

### Readiness recommendation

- **Focused Packages A/B validation:** Pass (57 tests / 504 assertions).
- **Full-suite baseline parity:** 12 inherited failures reproduce; one current test failure is a stale Customer Push mock exposed by Package B. No supported Portal contract regression is confirmed, but the current suite is not fully green.
- **Implementation acceptance:** Human can accept Packages A/B independently on focused evidence, with the stale mock explicitly recorded for a future authorized test correction. The 12 unrelated baseline failures do not by themselves invalidate A/B.
- **Terminal delivery readiness:** Not established by this triage. This Human decision prohibits commit/push; terminal delivery also needs separate authorization and a reviewed delivery scope. Resolve or explicitly accept the stale Customer Push mock before using a green full-suite claim.
- **Retained synchronization readiness:** No. Packages C–G, historical partial-Pull reconciliation, and separate retained-sync authorization remain outstanding.

Temporary archive copies were removed. Current worktree changes were preserved. No database, application source, test fixture, migration, checkpoint, or synchronization operation was changed for this triage. Work remains `READY_FOR_HUMAN_REVIEW`.

## CustomerCodeSyncTest acknowledgment fixture reconciliation — 2026-10-13

**Authorization:** Bounded test-only correction. No application code, retained data, migrations, synchronization, checkpoint, or Git delivery was changed.

The successful mock in `tests/Feature/CustomerCodeSyncTest.php` now returns `local_uuid` equal to the exact UUID inserted by this test and sent with its Customer Push (`aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa`). This matches the verified Portal Push acknowledgment contract. Existing assertions were preserved, and `CustomerIdentityResolver` was not weakened.

### Validation evidence

- `CustomerCodeSyncTest.php`: **1 passed, 6 assertions**.
- `CustomerIdentityResolverTest.php`, `CustomerOnlyPushTest.php`, `CustomerPagedPullTest.php`, and `CustomerCreatePageDiagnosticTest.php`: **152 passed, 2,222 assertions**.
- Corrected named Sales Call Pull fixture test: **1 passed, 5 assertions**.
- Full Tablet suite: **602 passed, 3 skipped, 12 failed, 4,742 assertions**. Compared with the previously recorded 601 passed / 3 skipped / 13 failed / 4,737 assertions, the Customer Code mock failure is resolved and one additional test passes; the remaining 12 failures match the already documented pre-A/B failure inventory. These include stale or mismatched fixtures/expectations in Customer code creation, map-address assertions, Customer Add validation, generic root response, FirstLogin reachability diagnostics, and the separate SalescallStatuses repeated-Pull snapshot fixture. No additional failure group was identified. The separately corrected named Sales Call Pull test passes.
- `php -l tests/Feature/CustomerCodeSyncTest.php`, targeted Pint on that file, and `git diff --check`: **passed**.

### Reconciliation and readiness

The earlier failure inventory and recommendation are superseded for `CustomerCodeSyncTest`: its failure was a stale test acknowledgment fake, now corrected. The exact pre-A/B baseline behavior and Portal contract evidence remain unchanged. Packages A/B focused identity/Push/Pull evidence remains **57 passed, 504 assertions**; this correction adds a passing compatibility fixture and does not alter that production behavior.

**Packages A/B acceptance recommendation:** GO for independent Human acceptance based on focused passing evidence and the verified acknowledgment contract. The 12 remaining full-suite failures are the pre-existing baseline set; full-suite green status is not claimed. Packages C–G remain outstanding: shared aggregate reconciliation, broader child relationship audit/reconciliation, pending-edit conflict protection, Pull checkpoint/watermark regressions, and end-to-end disposable validation. Historical partial-Pull recovery and retained synchronization remain separately gated.

**Terminal delivery readiness:** Not authorized or performed. No commit, push, or deployment was done. Preserve the existing mixed worktree; only the stale acknowledgment fake and this Work note received changes for this bounded task. Status: `READY_FOR_HUMAN_REVIEW`.

## Packages A/B upstream integration investigation — 2026-10-13

**Authorization:** Read-only Git integration investigation. No fetch, merge, rebase, reset, stash operation, application change, stage, commit, push, retained data operation, or synchronization was performed.

### Repository state and remote freshness

- Tablet is on `main` at `8e2d0813e6acbaa0bdabed2c3e159a397cb60c62`, tracking `origin/main`. The current cached `origin/main` is `c812058af0d916d319bdbdeef74667bc7fe45e51`; local metadata reports **five commits behind**. This is newer than the three-commit list previously reported. The cached ref is not confirmed current with the remote.
- Portal is on `main` at `205b9a7eb326ce07467fad4a0474d9725b7d3317`, tracking `origin/main`. Its cached `origin/main` is `6c99e8851b24a2bf8f18443c76c80cecb526b0eb`; local metadata reports **three commits behind**. This ref is also not confirmed current.
- Both indexes have no staged changes. Each repository has one existing stash for the separate Customer Create Required Fields Validation Work; it was neither created nor applied here and is not treated as a backup of the present Packages A/B changes.
- `git fetch origin main` was denied with `cannot open '.git/FETCH_HEAD': Operation not permitted` in both repositories. `FETCH_HEAD` exists and its Unix mode is owner-writable (`-rw-r--r--`); the denial comes from the execution sandbox's read-only `.git` boundary, not a missing Unix write bit. No permission was changed. Its timestamp does not prove the cached refs are current. A fresh fetch requires an environment authorized to write the repositories' Git metadata (`FETCH_HEAD` and remote refs), or an authorized Git client that can report the current remote tips. Do not chmod the metadata.

### Incoming cached changes

The Tablet cached range is `beb2aa2`, `f8a9594`, merge `9bcd84f`, `e6984a2`, and merge `c812058` (the merges integrate the respective feature branches).

| Commit | Purpose and relevant effects |
|---|---|
| `beb2aa2` — per-user notifications | Adds the Tablet notification page/bell and `SyncService::pullNotifications()`. The existing general `pull()` calls it after its normal payload transaction; notifications have separate local storage and a per-user watermark, while notification errors are logged and skipped. The existing Sales Call test now expects two schedule pulls, one location pull, and two notification pulls (five requests total). |
| `f8a9594` — notification Work record | Updates the notification Work note to record delivery; no application behavior. |
| `9bcd84f` — PR #24 merge | Integrates the notification feature and Work record. |
| `e6984a2` — Sales Call form requirements | Adds requested-Sales-Call locking, form redirects and completion rules, related tests, and shared Pest setup. Its commit does not change `SyncService` or `CustomerPagedPullTest`. |
| `c812058` — PR #25 merge | Integrates the Sales Call form feature after the notifications merge. |

In Tablet `SyncService.php`, Packages B edit Customer identity resolution/child-ID mapping (`applyPullPayload`) and Customer Push acknowledgment/result handling. Incoming notification hunks add an import/constants, call the notification pull after generic Pull, and define notification retrieval; they are separate methods and do not overlap the Package B hunks by line or behavior, though both change the same file and require combined regression validation. Incoming `CustomerPagedPullTest.php` changes the exact approved Sales Call test by adding notification request expectations. The local v3 snapshot/fresh-response correction and upstream notification behavior must be integrated together: retain the location/schedule assertions, provide a valid empty notification response, and account for the two notification calls. This is a real behavioral expectation update, not merely a same-file overlap.

Portal's cached range is `d27448a`, `1dc214e`, and merge `6c99e88` (PR #20). It adds the authenticated notification Pull endpoint, request validation, route, tests, and notification Work record. Package A changes `SyncController::pushCustomer()` and `TabletSyncPayload::customerAcknowledgementSnapshot()`; the incoming `pullNotifications()` method is a separate controller method. The controller file overlaps, but inspected method hunks are disjoint. Portal `main` is nevertheless three commits behind its cached upstream and remote freshness is unverified.

### Local ownership and preservation

No files are staged. Tablet current changes classify as:

- **Packages A/B:** `app/Services/SyncService.php`; new `CustomerIdentityResolution.php`, `CustomerIdentityResolver.php`, and `CustomerIdentityResolverTest.php`; and Customer identity/acknowledgment hunks in `CustomerOnlyPushTest.php`, `CustomerPagedPullTest.php`, `CustomerCreatePageDiagnosticTest.php`, and `CustomerCodeSyncTest.php`.
- **Approved fixture correction:** the named Sales Call test in `CustomerPagedPullTest.php` (snapshot v3, `landmass_options`, fresh response callback, and original three-request assertions). This is a mixed-ownership file and now needs notification request accounting too.
- **Earlier Customer Interaction work:** `CustomerCreatePage.php`, `CustomerPage.php`, their two Blade views, `CustomerInteractionReconciliationTest.php`, `CustomerMapLocationAutomationTest.php`, and its Work note. `CustomerCreatePageDiagnosticTest.php` also contains an earlier locality regression, so that file has mixed ownership.
- **Mixed / hunk review required:** `CustomerPagedPullTest.php` has Packages B tests/fixtures, the approved Sales Call fixture change, and prior edits noted before the Packages A/B gate. `CustomerCreatePageDiagnosticTest.php` has Packages B mock changes and a separate locality regression. Exact hunks must be re-reviewed on the fresh upstream base before staging.
- **Documentation:** Tablet and Portal diagnostics Work notes are untracked. Portal's modified `SyncController.php`, `TabletSyncPayload.php`, and `TabletCustomerCreationContractTest.php` are Package A changes.

Existing local worktrees and their one existing stash each remain untouched. No broad patch was created. No stash was used to preserve current changes.

### Recommended lossless integration plan (not executed)

1. Obtain an environment explicitly permitted to refresh Git refs and create an isolated worktree (or clone). Fetch both remotes and record exact tips; review any commits newer than the currently cached IDs.
2. Before reconstruction, preserve the full tracked delta from each local `HEAD` (including the currently empty index diff) plus copies of every untracked file. Store a manifest and checksums outside the repositories. Do not apply, overwrite, or rely on the existing stashes.
3. Create separate isolated worktrees/clones at the freshly verified upstream tips. Leave both existing `main` worktrees untouched.
4. Reapply only accepted Packages A/B paths and reviewed hunks. Exclude Customer Interaction changes. Resolve the Package B and Sales Call fixture changes alongside the notification Pull contract; preserve all other local work outside delivery staging.
5. Run Portal acknowledgment tests; Tablet identity, Push/Pull, CustomerCode, and diagnostic tests; the corrected Sales Call and notification Pull tests; relevant general Pull tests; PHP lint, targeted Pint, and `git diff --check`. Compare remaining suite failures with baseline and do not claim a green full suite without evidence.
6. Review explicit staged diffs for each repo and stage only the accepted code/test/documentation hunks. Recheck both remote tips immediately before any separately authorized delivery.

Rollback would discard only the isolated worktrees/clones and retain the external patch/copies; never reset or clean the original worktrees. Stop if identity behavior conflicts, ownership of any mixed hunk remains uncertain, the Sales Call change requires behavior beyond request accounting, or either remote advances after validation.

### Portal delivery and Human gates

Portal Package A is a distinct package but **cannot be delivered independently now**: Portal is also behind its cached upstream, its controller file overlaps the incoming notification endpoint, and the paired Work requires both repositories to be reconciled and reviewed together. No Portal file was staged or committed.

Required next decisions: (1) authorize an environment to refresh Git metadata and create isolated worktrees; (2) confirm integration against the latest upstream notification and Sales Call changes, not only the earlier three Tablet commits; and (3) review results before any new terminal-delivery action. Current status: `READY_FOR_HUMAN_REVIEW`; terminal delivery is not ready, and retained synchronization remains NO GO.

## Isolated upstream integration and validation — 2026-10-13

**Authorization:** Isolated integration and validation only. The original Portal and Tablet worktrees were not modified. No commit/push, retained database operation, Pull/Push, migration, checkpoint/watermark change, or deployment was performed.

### Verified upstream and preservation

Fresh isolated clones refreshed `origin/main`; the resulting tips match the previously cached refs, with no additional incoming commits:

- Portal `origin/main`: `6c99e8851b24a2bf8f18443c76c80cecb526b0eb`.
- Tablet `origin/main`: `c812058af0d916d319bdbdeef74667bc7fe45e51`.

Before reconstruction, tracked deltas and untracked files from both originals were archived and checksum-verified. Tablet archive SHA-256: `93bdc13d54fa3bf58c4c7a64268b42d8bd725887a7ec900d07b717ba8435fc92`. Portal archive SHA-256: `17572ad4b90e60b5f6c242ce9754526165a76316710c7f66fd1673039b06a660`. Patch comparisons, copied-file hash comparisons, and archive listing checks passed. Existing stashes were inventoried and left untouched. The original worktrees remain the source of record and were not reset, stashed, or cleaned.

### Reconstruction scope and hunk decisions

Portal isolated clone contains only Package A changes in `app/Http/Controllers/Api/SyncController.php`, `app/Services/TabletSyncPayload.php`, and `tests/Feature/TabletCustomerCreationContractTest.php`, plus the Work note. The upstream notification Pull method/route remains intact; Package A modifies the separate Push acknowledgment path in the shared controller.

Tablet isolated clone contains Package B and its approved fixtures in `app/Services/SyncService.php`, `app/Services/CustomerIdentityResolution.php`, `app/Services/CustomerIdentityResolver.php`, `tests/Feature/CustomerIdentityResolverTest.php`, `tests/Feature/CustomerOnlyPushTest.php`, `tests/Feature/CustomerPagedPullTest.php`, `tests/Feature/CustomerCodeSyncTest.php`, and only the UUID acknowledgment mock hunks in `tests/Feature/CustomerCreatePageDiagnosticTest.php`, plus the Work note. In the mixed `CustomerPagedPullTest.php`, Package B hunks were reapplied separately, then the approved Sales Call fixture was reconciled with upstream notification behavior: snapshot v3 plus `landmass_options`, fresh response objects, and the upstream-required notification requests/response. The original assertions that location refresh occurs once and schedule Pull runs on both calls remain. Earlier Customer Interaction UI/test hunks were excluded; no `CustomerInteractionReconciliationTest.php`, map automation test, Customer page, or Customer form UI files are present in the integration diff.

### Validation evidence

- Portal acknowledgment and upstream notification Pull tests: **42 passed, 241 assertions**.
- Tablet identity/Push/Pull, Customer diagnostics/code, and notification tests: **all tests passed after the isolated clone received its required built Vite manifest**. The initial run's single failure was only the absent temporary `public/build/manifest.json`; rerunning the bell test after copying the existing build artifact passed (4 warnings, 7 assertions).
- Corrected named Sales Call Pull test: **1 test, 7 assertions, exit 0**. The upstream baseline reproduces the old fixture failure on its second Pull; the integrated fixture passes while retaining notification assertions.
- `CustomerCodeSyncTest`: **1 test, 6 assertions, exit 0**.
- Focused Package A/B identity, Push, Pull, and notification group: **exit 0**, 2,263 assertions; Pest emitted warnings but no failed tests.
- Targeted PHP lint, Pint, and `git diff --check`: **passed** for the reconstructed changed PHP files in both isolated clones.
- The broader full Tablet suite was not rerun here. The prior verified upstream-baseline failure is the outdated repeated Sales Call fixture; this integrated fixture now passes. No full-suite green claim is made.

The temporary clones use disposable test configuration/mocked HTTP; no live synchronization endpoint or retained SQLite database was used. No checkpoint or watermark was changed.

### Readiness and delivery boundary

The integrated diffs are reviewable in `/private/tmp/ommc20261013-portal-upstream-verify` and `/private/tmp/ommc20261013-tablet-upstream-verify`; the Tablet baseline clone is `/private/tmp/ommc20261013-tablet-upstream-baseline`. These are isolated validation artifacts, not terminal delivery branches. The original worktrees remain unchanged and still require any future separately authorized delivery procedure. Human review should inspect the isolated diffs and decide whether to authorize scoped terminal delivery. Packages C–G, historical Pull recovery, and retained synchronization remain out of scope and NO GO. Status: `READY_FOR_HUMAN_REVIEW`.

## Packages A/B isolated terminal delivery — 2026-10-13

**Human decision:** Accepted isolated integration; scoped commit and push authorized. Packages A/B are terminal-delivered after the paired implementation commits and Work-note updates are verified on `origin/main`.

### Delivery record

- Verified Tablet base before commit: `c812058af0d916d319bdbdeef74667bc7fe45e51`.
- Tablet Package B commit: `74a3d74d8061da3be8dafc74a6e75729f05729b3` — `Add conservative Tablet Customer identity resolution`.
- Tablet push to `origin/main`: fast-forward succeeded; post-push fetch verified the commit on remote `main`.
- Scope: reusable UUID-first identity resolution, safe Customer child-key mapping, approved Customer identity/acknowledgment tests, Customer Code mock correction, and Sales Call fixture correction reconciled with notifications. Earlier Customer Interaction changes were excluded; notification functionality remains intact.

### Validation and boundaries

The final focused Tablet identity/Push/Pull and notification regression group exited successfully with **2,264 assertions** and Pest warnings, but no failed tests. The corrected Sales Call test exited successfully with 7 assertions; `CustomerCodeSyncTest` exited successfully with 6 assertions. Portal paired acknowledgment/notification tests passed **42 tests, 241 assertions**. Targeted PHP lint, Pint, and `git diff --check` passed. The full Tablet suite was not rerun, so full-suite green is not claimed. The upstream baseline's stale Sales Call snapshot fixture failure was reproduced and corrected only in the approved test fixture.

No retained database operation, Customer Pull/Push, checkpoint or watermark update, migration, or deployment occurred. Packages C–G and historical Pull recovery remain future work; retained synchronization remains NO GO. The original Tablet worktree was not modified. Terminal state applies to Packages A/B only.

## Package C — shared aggregate reconciliation — 2026-10-13

**Decision:** Packages A/B remain accepted and terminal-delivered. Human authorized bounded Package C implementation in isolated checkouts only. Current status: `READY_FOR_HUMAN_REVIEW`. No commit/push, retained database operation, Customer Pull/Push, migration execution, checkpoint/watermark change, or deployment occurred.

**Verified bases:** Portal `9c2d085097afaa48aaa5b7f65e97b347895d0354`; Tablet `5a1704bce2a4db031a85200f542dc65773e2955f`. The isolated checkouts were based on these exact `origin/main` tips. The original dirty worktrees were left untouched.

**Contract and behavior:** Portal now emits the Package A version-1 aggregate capability and explicit complete-collection list on full, scheduled, paginated Customer Pull responses as well as Push acknowledgment snapshots. Tablet uses `CustomerAggregateReconciler` for both versioned Push acknowledgments and Pull pages. It reuses Package B UUID-first identity resolution, preserves `customers.id`, writes Portal `server_id`, applies Portal synchronized fields, and reconciles Access/PIC, profile, history/events, brands, and categories in the existing local transaction boundaries. Pull still applies one page inside its original transaction; page cursor and final watermark code were not changed.

A versioned Push snapshot must satisfy the complete contract or the Customer is marked `conflict`; identity-only legacy acknowledgment updates identity/sync metadata only and does not claim aggregate reconciliation. Pull without completeness metadata updates scalar fields and upserts present child rows, while leaving absent child rows intact. Complete collection replacement is limited to a supported v1 contract declaring all collections complete. Pending parent edits are preserved; Portal advancement from the stored synchronized base (or no known base) records a durable `conflict` through the existing `sync_status`/`sync_error` fields. A fingerprint guards edits made during an in-flight Push. Tablet-originated category events and derived histories, and Customers with pending/failed/syncing/conflicted Sales Call brand/category rows, are retained during complete Portal refresh. No conflict schema or UI was introduced.

**Files changed in this isolated Tablet checkout:**
- `app/Services/CustomerAggregateReconciler.php` (new)
- `app/Services/SyncService.php`
- `tests/Feature/CustomerAggregateReconcilerTest.php` (new)
- `tests/Feature/CustomerOnlyPushTest.php`
- `tests/Feature/CustomerPagedPullTest.php`
- `docs/work/OMMC-20261013-tablet-customer-pull-location-diagnostics.md`

**Validation:** Tablet focused aggregate, Push, Pull, identity resolver, Customer Code, and notification tests: **86 passed, 617 assertions**. Portal targeted contract/split Pull tests: **41 passed, 255 assertions**. These used disposable SQLite test databases and mocked Portal HTTP. The Tablet set includes the exact Customer 23591 duplicate-server-ID regression (both local rows preserved and the ambiguity rejected), stable local ID/child mapping, repeated Pull idempotence, complete/partial snapshot behavior, pending-edit conflict protection, legacy ACK safety, and notification compatibility. Targeted PHP lint, Pint, and `git diff --check` passed for changed PHP files.

The full Portal `TabletSplitPullTest` has one unrelated existing assertion failure: its location snapshot test expects `reference_contract_version=2`, while the current base implementation returns version 3. The six schedule/customer Pull cases that exercise the changed routes pass. The full Tablet suite was not run; no full-suite green claim is made. An initial fixture run exposed the above legacy partial-child behavior and pending local history preservation gap; both are addressed and covered by the final passing tests.

**Limitations and follow-up:** Conflict resolution remains manual through the existing status/error fields; there is no resolution UI. The shared reconciler intentionally covers the defined Customer aggregate collections only. Packages D–G, historical Pull recovery, retained identity repair, retained synchronization, schema migrations, and deployment remain separate human gates. Original Portal and Tablet worktrees were not modified.

## Package C final assurance and remote reconciliation — 2026-10-13

**State:** READY_FOR_HUMAN_REVIEW. Package C remains uncommitted and unpublished. This gate made no application or test changes, did not touch retained databases or synchronization, and did not modify either original dirty worktree.

### Remote verification

Fetched origin/main in both isolated Package C checkouts. The live tips still equal the accepted bases:

- Portal: 9c2d085097afaa48aaa5b7f65e97b347895d0354.
- Tablet: 5a1704bce2a4db031a85200f542dc65773e2955f.

Each accepted base is an ancestor of its remote tip; HEAD..origin/main contains no commits and no changed files. There are no incoming commits or overlapping incoming hunks to integrate. The isolated working diffs remain limited to the Package C files already listed above. Hunk review found Portal changes only add the Customer aggregate contract to full, scheduled, and paged Customer Pull responses and refactor the existing Push snapshot metadata helper; the Tablet diff routes Customer aggregate writes through the shared reconciler and adds its focused coverage. Neither diff changes Notification Pull, Sales Call processing, location snapshot generation or validation, Pull page transaction boundaries, checkpoint, or watermark code. No merge, rebase, or reset was performed.

The original repositories were inspected read-only and remain dirty with their pre-existing unrelated work. Portal original main remains five commits behind its cached upstream; Tablet original main remains seven commits behind its cached upstream. Their local changes remain in place and were not used for Package C validation. The isolated clones are the only Package C implementation checkouts.

### Portal location snapshot failure classification

Exact failing test: “locations pull returns the location reference tables” in tests/Feature/TabletSplitPullTest.php.

The test expects reference_contract_version = 2; the response is version 3. I reproduced the failure from a clean git archive of the unchanged accepted Portal base 9c2d085, without Package C changes: 1 failed, 8 assertions, at the version assertion. The unchanged base serializer emits location reference version 3. Therefore the mismatch predates Package C and is an outdated test expectation; Package C only adds customer_snapshot_contract to Customer Pull JSON responses and does not modify the location stream or its version. The fixture was not changed in this gate. This one known Portal test failure remains outside Package C’s change impact and should be handled in a separately authorized test-fixture change.

### Authority and reconciliation evidence

Portal acknowledgment tests passed: 2 tests, 16 assertions for create/update acknowledgment behavior. They exercise persisted Portal aggregate serialization, including authoritative saved values and response scoping. Portal scheduled and paged Pull contract tests passed: 2 tests, 28 assertions.

Tablet authoritative Push acknowledgment/reconciler tests passed: 6 tests, 42 assertions. The complete reconciler suite passed: 9 tests, 51 assertions. Tablet stable-ID, idempotent Pull, duplicate identity and Customer 23591 collision regressions passed: 4 tests, 20 assertions. These assert the same local row is updated, Portal values replace synchronized fields, repeat application does not duplicate the Customer, and ambiguous duplicate server_id associations do not merge or alter either distinct aggregate.

Complete snapshots reconcile only their declared complete collections. Partial/legacy snapshots leave absent child records intact. Pending parent edits and Tablet-originated child history are protected; pending Sales Call child work is retained. Child relationships are written against the canonical local Customer ID. Disposable regressions read back resulting records and cover local foreign-key stability and unrelated Customer preservation.

### Shared sync and transaction compatibility

Tablet notification and Sales Call compatibility tests passed: 18 tests, 45 assertions, including the repeated schedule Pull fixture. The Portal location snapshot failure is independently reproduced on the unchanged base and is not a Package C regression. Code review confirms the existing Pull page transaction/checkpoint flow is unchanged; the reconciler executes within the existing local transaction. No retained checkpoint or watermark behavior was exercised or modified.

### Validation and remaining status

Additional tests executed in this gate:

- Portal Push acknowledgment: 2 passed, 16 assertions.
- Portal scheduled/paged Pull: 2 passed, 28 assertions.
- Tablet aggregate reconciler: 9 passed, 51 assertions.
- Tablet Push/reconciler identity regressions: 6 passed, 42 assertions.
- Tablet stable-ID/idempotent Pull/collision regressions: 4 passed, 20 assertions.
- Tablet notifications/Sales Call compatibility: 18 passed, 45 assertions.
- Clean Portal base location test: 1 known baseline failure, 8 assertions (3 actual versus 2 expected).
- PHP lint and targeted Pint passed for all Package C PHP files; git diff --check passed after the review. No source or test files changed in this assurance gate.

The prior Package C focused totals remain as recorded above. The full Tablet suite was not run; no full-suite green claim is made. No Package C defect was found in the executed assurance. Terminal delivery is not authorized in this gate and remains pending separate Human review/authorization. Packages D–G, historical Pull recovery, retained identity repair, retained synchronization, migrations, and deployment remain separate work/gates.

### Package C implementation commit and delivery gate

- Portal implementation commit: `1307de3` (`Add shared Tablet Customer aggregate reconciliation`).
- Tablet implementation commit: `bc5c6a6` (`Reconcile Tablet Customer aggregates on sync`).
- Final rerun immediately before commit: Portal acknowledgment + Pull coverage **41 tests / 255 assertions**; Tablet aggregate, Push/Pull identity, notifications, and Sales Call coverage **86 tests / 617 assertions**.
- PHP lint, targeted Pint, and `git diff --check` passed in both isolated checkouts. The known clean-base Portal location contract test still fails because v3 is returned while the stale test expects v2; it is not part of the changed location behavior.
- Only the reviewed Package C implementation and tests are in these commits. Work-note changes are being committed separately. Original dirty worktrees remain unchanged. No synchronization, retained database operation, migration, checkpoint/watermark change, or deployment was performed.
- Delivery is not yet verified. State remains `READY_FOR_HUMAN_REVIEW` until both implementation and paired Work-note commits are pushed and fetched successfully; only then may the Work be marked `TERMINAL_DELIVERED`.

## Package C terminal delivery closure — 2026-10-13

**Package C state: TERMINAL_DELIVERED.** This closure supersedes the earlier readiness statements above, which record the state before the authorized push and post-push verification.

- Portal implementation: `1307de3`; Portal paired Work-note delivery record: `e5e5ff2`.
- Tablet implementation: `bc5c6a6`; Tablet paired Work-note delivery record: `8752d92`.
- Verified Portal `origin/main` after push: `e5e5ff2d75bd02dbe8a98af4ef560757765163dc`.
- Verified Tablet `origin/main` after push: `8752d92b41d2f11b0f58a99ccc71ee24fdd7ee77`.
- Portal final focused assurance: **41 tests / 255 assertions**. Tablet final focused assurance: **86 tests / 617 assertions**. PHP lint, targeted Pint, and diff checks passed.
- Known unrelated Portal baseline failure remains: the location snapshot test expects contract v2 while the unchanged base emits v3; reproduced on a clean copy of the accepted base. No full-suite green claim is made.
- The original dirty Portal and Tablet worktrees were preserved without edits. Only scoped Package C implementation/tests and paired Work-note changes were delivered from the isolated checkouts.
- No retained Customer Pull/Push, retained database modifications, migrations, checkpoint/watermark changes, or deployment were performed. Packages D–G remain future work.

## Customer Pull failure follow-up — 2026-10-13

**Scope:** Read-only diagnosis after the prior approved identity repair. No application code, retained data, checkpoint, or watermark was changed in this follow-up; no retained Customer Pull/Push was run.

### Root cause and current status

The historical Tablet `local.ERROR` entries from 2026-10-13 05:42–06:08 record `A Portal Customer ID is already mapped to multiple Tablet Customers.` The stack places the exception in Tablet identity resolution while applying a Customer page inside `SyncService::pullCustomersStep()`. The earlier read-only snapshot identified the conflicting server identity as Portal Customer 23591. The log does not contain an HTTP status, response body, request duration, response byte count, or exact Customer/page identity; the first-page run snapshot was `after_id=0`, `pulled=0`. Therefore 23591 is the confirmed local collision associated with the failure, but the exact returned page/customer was not captured in the log. This is a Tablet-side identity/data conflict after response decoding, not evidence of a Portal timeout or query failure.

That conflict was already repaired under the earlier explicit Human authorization: Tablet row `id=23591` now has `server_id=NULL`, while canonical local row `id=-687143025929916172` retains `server_id=23591`. This follow-up made no database writes. A read-only check now finds zero duplicate non-null `server_id` groups, both expected rows still present with their prior synced status, and SQLite `integrity_check=ok`. There is no active `customers.pull_run` marker. The current `customers.pulled_through.2` value is `2026-10-13T09:26:35.855770Z`; application code advances this watermark only when page scanning, scope reconciliation, and missing-ID recovery complete. This is consistent with a later completed Pull, but this follow-up did not run or independently attribute that operation.

The UI deliberately maps all failed steps and Livewire exceptions to `Customer Pull failed. Please try again.`, discarding the returned step message. This explains the generic text but is not the underlying historical failure. The available operational entries do not provide timings or payload metrics. Later `testing.*` log entries are test diagnostics and were not treated as operational Pull evidence. No Portal operational log or request correlation for the failed page was available.

### Pagination, persistence, and performance

Tablet requests 500 Customers per paginated step (up to 200 IDs for missing-scope recovery) with a 60-second pull HTTP timeout. The browser invokes one `pullCustomersStep()` per request. Portal accepts up to 1,000 rows, queries a keyset page in ascending ID order, and returns `next_after_id`; its page serializer eager-loads PIC/Access Users and bulk-loads the associated child collections. No per-Customer child-query loop was found in this path. The terminal page also returns the visible scope ID list. No query-duration, PHP memory, web-server timeout, or response-size telemetry is recorded, so a performance cause is not established.

Each Tablet Customer page is applied inside a SQLite transaction. A failure during identity resolution rolls back writes for that page and restores the prior run checkpoint; previous successful pages are separate committed transactions. The watermark is written only after the entire scan and scope recovery finish. Disposable tests confirm keyset paging, same-page retry after failure, duplicate-ID rejection, and the guarded-repair/idempotent-resolution case. Portal tests confirm scoped paging, incremental reads, ID recovery, and malformed-ID rejection.

### Workers and validation

Laravel queue workers are not indicated. The observed exception is a deterministic local uniqueness/identity conflict; moving it to a background job would not fix that conflict. Pull is already divided into bounded per-page HTTP requests, and there is no measured server-timeout or memory failure to justify a new worker architecture.

- Tablet `CustomerPagedPullTest.php` focused pagination, retry, duplicate protection, repair regression: **7 passed, 133 assertions**.
- Portal `TabletSplitPullTest.php` focused page ordering, null Company, incremental changes, scoped ID recovery, malformed IDs: **5 passed, 27 assertions**.
- These use disposable databases and mocked HTTP. No live endpoint was called.
- No new application fix was necessary in this follow-up: the known identity collision had already been corrected and current local identity/read-integrity checks pass. The failure display remains generic for future unrelated errors; changing that UI/logging contract is separate from fixing this confirmed incident.

**Conclusion:** The confirmed failure was Tablet-side duplicate `server_id` identity during local reconciliation. The authorized row repair already addressed the cause, and current read-only state shows the conflict removed. The evidence does not support background workers or a Portal performance correction. Historical failed-request metrics and exact page customer ID remain unavailable.

## Customer Pull reliability and diagnostics correction — 2026-10-13

**State: `READY_FOR_HUMAN_REVIEW`.** This bounded follow-up preserves the historical investigation above and records the code correction now authorized. Tablet and Portal were fetched before editing; their local `main` branches matched `origin/main` at Tablet `d437ba234105ccf44384c3a866d3d8d9bb3d2576` and Portal `5bcd1b22e51d56b354efa333a87ec8a2feff7ec8`. No incoming changes were present. The prior paired Work-note edits were preserved. No commit or push was made.

### Findings and current failure status

The historical operational failure was a Tablet-side identity-resolution exception caused by two local Customers claiming Portal `server_id=23591`; it occurred after the Portal page had been decoded. The approved repair removed the incorrect association from the separate local Customer. Current read-only Tablet evidence from this follow-up found SQLite integrity `ok`, no duplicate non-null `server_id` groups, and no active Customer Pull run. The most recent watermark is consistent with a completed later run, but cannot prove which installed build performed it. No later operational Tablet or Portal log entry identifying a new Customer Pull failure was found. The failure is therefore historical in available evidence and is not currently reproducible from the repository/disposable tests. The exact old response page and Customer were not recorded at the time.

The Tablet source checkout is current at `d437ba234105ccf44384c3a866d3d8d9bb3d2576` and contains the delivered Package C sync implementation. This environment cannot verify which application build is installed on the physical Tablet. Device verification requires confirming/rebuilding the installed app from the current source; no install, deployment, or retained sync was performed.

No Portal-side failure evidence was found. The Portal page API remains paginated; its focused endpoint contract tests previously passed. The available logs do not establish a Portal timeout, slow query, memory limit, or web-server timeout. Queue workers are not indicated: the historical exception was a deterministic Tablet identity conflict, and paging already bounds each request.

### Bounded diagnostics change

The actual generic message was produced in the Tablet UI, which discarded returned step messages. Customer Pull failures now return a safe `failure_stage` and UUID `diagnostic_reference`; the UI displays that controlled message. Server logs use `sync:pull:customers:failed` with the stage, phase, `after_id`, safe error code, HTTP status when available, exception class/code and trace for server-side diagnosis, request duration and response byte count for failed HTTP requests, returned row count and numeric Portal Customer IDs for a decoded failed page, and whether the local page transaction committed. Exception details and Customer IDs are not copied into the user-facing message.

Customer page reference validation now runs before the write transaction so it can be identified as its own failure stage. The page transaction still atomically applies records and checkpoint state; failed transaction handling restores the prior run checkpoint. The watermark remains updated only on successful completion. HTTP failures leave the cursor unchanged. Notification and Sales Call application behavior was not changed.

### Validation

All tests used disposable databases and mocked Portal HTTP; no retained Customer Pull/Push or checkpoint operation occurred.

- Focused Customer Pull failure/retry/rollback/validation and escaped-Livewire diagnostic tests: **5 passed, 70 assertions**.
- Full Tablet `CustomerPagedPullTest.php`: **31 passed, 1 failed, 402 assertions**. The sole failure is the existing Sales Call test `the salescall pull uses the schedule endpoint and fetches locations only when missing`: both Pull calls succeed, but it expects five HTTP requests and records three. Its expected two notification requests conflict with `SyncNotificationsTest`'s explicit contract that schedule Pull does not fetch notifications. No Sales Call or notification behavior was altered here; this stale assertion remains outside this Customer Pull correction.
- `SyncNotificationsTest.php` plus `SyncServicePullFreshInstallTest.php`: **8 passed, 37 assertions**.
- PHP lint passed for all changed PHP files.
- Targeted Pint (`vendor/bin/pint --dirty --format agent`) and `git diff --check` passed.
- No retained DB write, migration, Customer synchronization, checkpoint/watermark change, commit, push, or deployment was performed.

**Device-testing recommendation:** the repaired identity state and disposable Customer Pull tests support a controlled device test once the installed Tablet build is confirmed to include the current Tablet sync source. That operational build/version check is still outstanding. The unrelated Sales Call test assertion is also recorded rather than silently changed.

## Customer Pull finalization — 2026-10-13

The Sales Call test discrepancy was a stale test expectation, not an application defect. `SyncService::pull()` performs one location request for an old/missing snapshot and one schedule request per Pull; notification Pull is separate, and the accepted `SyncNotificationsTest` contract explicitly asserts that schedule Pull does not request notifications. The fixture/assertion now expects three requests (one locations, two schedule, zero notifications). No notification or Sales Call application behavior changed.

After fetching, Tablet `main` and `origin/main` both remained `d437ba234105ccf44384c3a866d3d8d9bb3d2576`; Portal `main` and `origin/main` both remained `5bcd1b22e51d56b354efa333a87ec8a2feff7ec8`. No incoming commits or functional conflicts required reconciliation.

- Tablet `CustomerPagedPullTest.php`: **32 passed, 406 assertions**.
- Tablet `SyncNotificationsTest.php`, `SyncServicePullFreshInstallTest.php`, and `SyncPullResponseSinkTest.php`: **10 passed, 42 assertions**.
- PHP lint, targeted Pint, and `git diff --check`: passed.
- All database tests used disposable databases and mocked HTTP. No retained synchronization, database modification, migration, checkpoint change, or deployment occurred.

The Customer Pull implementation and corrected assertion are ready for device verification after the scoped changes are delivered and a Tablet build containing them is installed. The installed device version still must be confirmed on the Tablet; no app installation or deployment was performed.

**Customer Pull finalization: `TERMINAL_DELIVERED`.** The scoped Tablet implementation, regression/test-fixture correction, and paired Work-note update were delivered in Tablet commit `ab4a0426eae0042b0d291d3299b28239c0a3b886`. The paired Portal Work-note update was delivered in Portal commit `5c30e9746edd028d1b993957cbda63d9ed22abe3`. Post-push fetch verified Tablet `HEAD = origin/main = ab4a0426eae0042b0d291d3299b28239c0a3b886` and Portal `HEAD = origin/main = 5c30e9746edd028d1b993957cbda63d9ed22abe3`; both worktrees were clean. No retained synchronization, database operation, migration, or deployment occurred. Physical Tablet build/version verification remains pending.
