# OMMC-20261012-customer-mapping-activation

State: TERMINAL_DELIVERED<br>
Mode: Terminal delivery completed<br>
Updated: 2026-10-12

## Scope

Keep the authenticated Tablet User in Customer Access when complete automatic mappings are applied. Preserve unrelated manual assignments on Edit, replace users from a prior complete mapping when a controller genuinely changes, deduplicate assignments, and preserve the established email-based Push/Pull contract. No mapping data or schema changes are part of this correction.

## Implementation

- Customer Add continues to initialize Access with the authenticated local User.
- `CustomerCreatePage` tracks the prior complete automatic assignment set. On a genuine complete mapping change, it removes only that prior mapped set, retains other current manual selections, then merges the authenticated User and destination mapped Users without duplicates.
- `CustomerEditPage` initializes the prior automatic assignment set from the current offline mapping after hydrating the saved Customer. This lets a subsequent controller change replace known prior mapped Users while preserving unrelated persisted Access.
- Incomplete or unavailable mappings leave the current Access list unchanged and retain the existing warning behavior.
- OE/IB Company mappings retain precedence over geographic mappings. Tablet Users continue to resolve by email, not by Portal numeric User ID; customer save and Push/Pull payload contracts are unchanged.

## Disposable validation

`php artisan test tests/Feature/CustomerCreatePageDiagnosticTest.php` passed **39 tests, 309 assertions**. The separate authenticated Portal E2E test was not run in the latest correction validation because its external local API prerequisites were not started.

Coverage includes Add mapping deduplication when the authenticated User is also mapped, mapped User plus actor, Add controller change removing the previous mapped assignment while preserving manual Access, Edit save and reload with actor/manual/destination Users, incomplete mappings, OE/IB mappings, and unchanged controllers. The skipped test did not exercise a live authenticated API round trip in this run.

Work-owned changes in this correction:

- `app/Filament/Pages/CustomerCreatePage.php`
- `app/Filament/Pages/CustomerEditPage.php`
- `tests/Feature/CustomerCreatePageDiagnosticTest.php`
- This Work note.

Validation used the repository test database only. The retained Tablet SQLite database was not read or changed for this correction; no reference refresh, Customer Push, mapping activation, migration, or User provisioning was performed.

## Human validation still required

On the local iPad/runtime, verify that Add and Edit show the authenticated User together with the mapped Users, save and reload the Customer, then perform the normal Pull and confirm Access reconciles by email. No physical iPad validation, live authenticated Pull, or production behavior is claimed by these automated tests.

## Dummy assignment retirement preparation — mirrored Portal operation

**State: READY_FOR_HUMAN_REVIEW.** The authenticated-User preservation correction was validated first: Tablet Customer Add/Edit diagnostics passed **39 tests / 309 assertions**; no live authenticated Tablet-to-Portal E2E or physical iPad test was performed. This corrects the earlier 40/319 note summary to match the actual rerun.

Portal retained local data was checked read-only: seven approved temporary dummy assignment pairs are present, alongside 17 Specific Region mappings and 99 location decisions. Portal has prepared a narrowly scoped retirement seeder and disposable tests. It removes only those seven exact pairs, preserves Users, Customers, `customer_user`, and all geographic mappings, and marks a configuration incomplete unless its remaining assignments exactly match a separate Human-approved replacement set. The prepared replacement manifest is empty, so no configuration would remain complete after removing the current test pairs.

The disposable Portal retirement tests passed **3 tests / 23 assertions**; the combined Portal Access form/Push/retirement suite passed **56 tests / 403 assertions**. No Tablet application code or SQLite cache data was modified. Retained-local cleanup requires separate authorization plus a fresh verified post-activation backup. After a later authorized cleanup, the Tablet cache must update through normal Portal reference Pull. Real personnel assignment approval and population remain a distinct gate. No retained write, Tablet cache write, staging, commit, push, or deployment occurred in this phase.

## Phase C — personnel data alignment

Portal Users were reconciled read-only against the latest candidate roster. The full identity matrix and evidence are in the mirrored [personnel reconciliation report](OMMC-20261012-customer-mapping-activation-personnel-reconciliation.md). It contains 46 rows although the declared total says 45: GMA 8, NL 7, SL 6, VIS 10, MIN 9, OE 2, IB 4. No row was discarded. Reconciliation found 11 exact/equivalent candidates, 33 ambiguous, 2 missing, and no soft-deleted matches. The `users` table has no active-status field. No assignment is approved for insertion.

If all rows are confirmed, expected pivots are 40 geographic plus 6 Company assignments. Add/Edit, Access, Push contract, retirement, and strict snapshot suites passed on disposable databases: Portal **61 tests / 581 assertions**, Tablet **53 tests / 417 assertions**. The separate authenticated Portal round-trip test was **skipped (0 assertions)** because its isolated Portal API prerequisites were not running. No real-assignment activation or live Tablet E2E is claimed. No retained write occurred. Work remains **READY_FOR_HUMAN_REVIEW**.

## Phase C follow-up — approved initial subset prepared, not executed

**State: READY_FOR_HUMAN_REVIEW.** The Human approved only the 11 exact User identities in the Portal reconciliation matrix as initial geographic REG members. The remaining 33 ambiguous and 2 missing personnel are deferred. No accounts were created. This approval did not authorize retained-local writes.

Portal read-only preflight again showed `APP_ENV=local` and `SELECT DATABASE() = ommc_henri_local_latest`. All 11 approved IDs matched the report by stored name, surname, and email, and all had `deleted_at IS NULL`. The `users` table has no separate login-active field, so authentication eligibility was not verified. Exact proposed memberships are GMA→5; NL→33,48; SL→26,38,49; VIS→41,44,45; MIN→19,28. OE and IB receive no assignments and are to remain incomplete.

The retained Portal state remains unchanged: five dummy geographic pivot rows and two dummy OE/IB rows; five geographic and two Company configuration rows are currently complete; 17 Specific Region mappings and 99 location decisions remain. Current Customer, `customer_user`, and User counts are 18,988, 27,141, and 56. Proposed post-state is 11 geographic pivots, zero Company pivots, five geographic groups complete for the approved subset, and both OE/IB configurations incomplete. Reference mappings, Customers, `customer_user`, and Users remain unchanged.

The Portal's non-default transaction seeder validates exact starting or final state, approved identity names/emails, Company identities, and assignment sets. It replaces only the seven reviewed dummy pairs; reruns are no-ops, and conflicts abort. Disposable SQLite validation passed: combined focused Portal suites **62 tests / 599 assertions**; the replacement-specific suite passed **4 tests / 41 assertions**, including a mid-transaction insert failure rollback, idempotency, Customer/reference preservation, identity mismatch rollback, and rejection of unexpected assignments. Pint and `git diff --check` passed.

Retained execution still requires a separate explicit Human GO. Before execution, take and verify a fresh backup, reconfirm local database identity and exact target state, and record Customer/customer_user baselines. No retained Portal or Tablet writes, Tablet cache refresh, live authenticated Pull, migration, deployment, staging, commit, or push occurred. After separately authorized Portal replacement, Tablet must receive mappings by the supported Pull and reconcile User IDs by email. This note records preparation only and does not claim Tablet mapping activation or live E2E.

## Controlled retained-local replacement — execution evidence

**Human authorization:** GO for the Portal local database ommc_henri_local_latest.<br>
**Result:** completed; Work remains READY_FOR_HUMAN_REVIEW.

Portal preflight reconfirmed APP_ENV=local, loopback host 127.0.0.1, and SELECT DATABASE()=ommc_henri_local_latest. The exact seven dummy assignments were present, all 11 approved Users matched stored ID/name/surname/email and were not soft-deleted, and all configuration flags matched the expected before-state.

Before writing, a fresh database-scoped logical dump was created at [ommc_henri_local_latest.sql](/private/tmp/ommc-customer-mapping-activation-replacement-20261012-01/ommc_henri_local_latest.sql). It is 147,056,684 bytes, mode 0600 in a mode 0700 directory, SHA-256 c96542bf3bc14dddc429232750568c82d7583244e356a459a7ae9a6b298d2c02. Verification found 107 table definitions and the mysqldump completion footer. The temporary credential option file was removed. This DB-only dump includes GTID metadata and is not a server-wide GTID backup; restore only to an isolated recovery target with secured credentials and DBA-reviewed GTID handling.

Only the reviewed Portal transaction seeder ran, with immediate APP_ENV/database guards. It replaced the seven dummy pairs with GMA→5, NL→33,48, SL→26,38,49, VIS→41,44,45, and MIN→19,28. Geographic pivots are 11; OE/IB Company pivots are zero. Five geographic groups are complete; OE and IB are incomplete. The seeder ran a second time and made no further assignment changes.

Post-run read-only counts: Specific Region mappings 17; location decisions 99; Customers 18,988; customer_user 27,141; Users 56. All remain at baseline. No schema change, Customer or customer_user update, User provisioning, Tablet SQLite write/Pull, migration, production access, deployment, staging, commit, or push occurred.

Tablet cache refresh through the supported authenticated Portal reference Pull remains necessary before the iPad can use these assignments offline. No live Pull or physical-device E2E is claimed. Portal rendered Add/Edit save/reload validation also remains a separate Human step.

## Controlled local Tablet reference refresh and form validation — 2026-10-12

**State: READY_FOR_HUMAN_REVIEW.** Tablet was confirmed `APP_ENV=local`, SQLite path `database/database.sqlite`, and API target `http://127.0.0.1:8000`. The responding local Portal API process was connected to `ommc_henri_local_latest`. Before Pull, a consistent SQLite online backup (including WAL state) was verified at `/private/tmp/ommc-tablet-reference-refresh-20261012-01/database.sqlite`, 8,040,448 bytes, SHA-256 `45864acc83dd93321dfacac5569f6e0d30f7cdc77fd33a9a2193e8fff8c20c34`, with `PRAGMA integrity_check=ok` and matching checked table counts.

Only the existing authenticated `SyncService::pullLocations()` path ran. It refreshed references only; no Customer Push or Customer Pull occurred. Snapshot marker is `2026-10-12T04:07:58.532340Z` (contract 1). Tablet after-Pull counts: Regions 19, Specific Regions 18, Area Clusters 44, Provinces 85, Municipalities 1,643, Barangays 42,010, location decisions 99, Access mappings 19, Users 21, Customers 705, `customer_user` 841, sync state rows 5. Before Pull, counts were identical except Users 10 and Access mapping rows held the prior dummy references. The 11 Portal-assigned identities were reconciled by email into Tablet-local IDs 11–21; these reference-only rows have no API token. Group membership resolves locally to GMA 5; NL 15,16; SL 12,13,14; VIS 19,20,21; MIN 17,18. OE and IB have empty references and remain incomplete. No Tablet Customer, Access pivot, or User account with login token was created or changed by Customer workflow actions.

Rendered Tablet Add at `localhost:8001` initially showed the logged-in `dummy.salesrep1` selected, with Company and Specific Region blank and no mapping warning. Selecting Region IV-A alone in a fresh Add form kept only the signed-in User. Selecting the Laguna Specific Region then visibly selected `SL - Area 1` and mapped Neil, RC, Raffy plus the signed-in User. Changing the Specific Region to GMA removed the SL assignments and showed Sherwin plus the signed-in User; Area Cluster remained blank because more than one enabled cluster is available. Selecting OE produced the incomplete-mapping warning while retaining the current Access selection. No Add form was saved.

A saved existing Customer Edit route was opened read-only. Its stored Access (Gelo Ponce, dummy RSM, and the three dummy sales reps) hydrated unchanged; the saved row had no Specific Region and displayed its unresolved Barangay reference. No Edit save was submitted. The disposable Tablet `CustomerCreatePageDiagnosticTest` suite passed **39 tests / 309 assertions**, covering Add/Edit hydration, unchanged controllers, complete and incomplete mappings, deduplication, authenticated User retention, and manual Access fallback. Browser verification covered the rendered local Tablet form only; no physical iPad test occurred.

**Acknowledged UI side effect:** selecting Company on the live Add form invokes the normal Portal customer-code reservation endpoint. Three unused reservations were created in the local Portal database during this check: IDs 49–51 for FLEET3745 (Company 4), OE52196 (Company 5), and FLEET3746 (Company 4), associated with local Portal User 58; all have `used_at=NULL`. Portal read-only verification still showed Customers 18,988, `customer_user` 27,141, the 11 approved geographic assignment rows, no OE/IB assignments, 17 Specific Region mappings, and 99 location decisions. The Human acknowledged the reservations and directed that they be retained; no cleanup write was attempted.

No real Customer was created or edited, no Push ran, and no production/remote API, migration, deployment, staging, commit, or push occurred. Physical iPad validation remains outstanding.

## Terminal delivery status — 2026-10-12

Human issued Terminal GO for this Work. Before reconciliation, all modified and untracked files in both repositories were copied to a recoverable local snapshot; patch checksums and file manifests are recorded with that snapshot. An authorized `git fetch origin` confirmed both local `main` branches match their `origin/main` tracking refs; no upstream-only commits or fast-forward were needed. Tablet focused validation passed **39 tests / 309 assertions**, and Pint passed. The Portal focused validation rerun passed **77 tests / 707 assertions** (the earlier accepted focused run was **62 / 599**), with Pint passed. The three unused Portal Customer Code reservations 49–51 are acknowledged and retained. Human accepted that no Customer Edit rendered save/reload, physical iPad test, or authenticated Customer Push E2E is required; no such validation is claimed. Both implementation commits have been pushed; this note records the final Work state and accepted limitations.

## Terminal delivery confirmation — 2026-10-12

**Final Work state: `TERMINAL_DELIVERED`.** Portal implementation commit `05a2216` and Tablet implementation commit `6da4eb5` were pushed successfully to their existing `origin/main` branches. The Portal and Tablet mirrored Work notes were updated after push confirmation; separate documentation commits record terminal state. No production activation is claimed. Actual initial personnel assignments exist only in the retained local Portal database. Thirty-five roster identities remain deferred; OE/IB assignments remain incomplete. Customer Edit rendered save/reload, physical iPad testing, and authenticated Customer Push E2E were not performed and were accepted as not required for this Work. Customer Code reservation IDs 49–51 remain untouched as acknowledged.
