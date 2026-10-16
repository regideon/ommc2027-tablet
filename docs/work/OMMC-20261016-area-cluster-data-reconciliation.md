# OMMC-20261016-area-cluster-data-reconciliation

- **State:** TERMINAL_DELIVERED
- **Disposition:** Bounded code implementation and patch preparation delivered to `main`; database patch execution and lookup synchronization remain separate Human-authorized work.
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Human authorized bounded Portal/Tablet implementation and later explicitly authorized commit and push. Database writes, patch execution, migrations, customer changes, Customer Push/Pull changes, and deployment remain held. Human confirms the previously inspected Portal dataset represents production; no new production connection or query was made for implementation or delivery.
- **Objective:** Reconcile the supplied Area Cluster business assignments with actual local lookup records and selector/synchronization behavior, without changing data.
- **Business reference:** The Human supplied the markdown assignment table in conversation. The named file `Pasted markdown(20261016-030006).md` was not found in the local workspace/home search; the pasted table itself was used as the business reference. It is not treated as proof that database relationships are correct.
- **Worktree baseline:** Both repositories were on `main` and clean when inspected. No note for this Work ID existed. The matching notes were created for this investigation. Preserve any later unrelated worktree changes.

## Confirmed architecture

- Portal is the current server-side publisher of location references. `TabletSyncPayload::locations()` emits physical Regions, Specific Regions, enabled Area Clusters, provinces, municipalities, and `location_area_cluster_mappings`. The Portal's ultimate business authority/provenance for Area Cluster definitions is not identified in the repository; no Area Cluster maintenance UI or complete definition seeder was found.
- Tablet stores the received reference snapshot in local SQLite and its customer selector reads that local data. `SyncService` validates snapshot sections and parent IDs, upserts lookup rows, and replaces location mapping rows when the mapping section is present. It does not remove Area Cluster rows omitted from a snapshot; Portal currently emits enabled rows only. Thus a previously enabled local row could remain stale after being disabled server-side. This is a possible sync limitation, not the cause in the compared snapshots.
- `area_clusters.region_specific_id` defines which operational Specific Region exposes a cluster. `customers.area_cluster_id` stores a cluster primary key. These numeric IDs are database row identities, not business Area numbers.
- Geographic assignment is separate: `location_area_cluster_mappings` has one row per `(location_level, location_id)`, for province, municipality, or barangay, with `mapped`, `unresolved`, or `requires_finer_location` decision. The resolver checks most-specific location first (barangay, municipality, province); only `mapped` with an enabled cluster can auto-select. A location row does not directly link to an Area Cluster elsewhere in the geographic hierarchy.
- The customer selector offers enabled clusters filtered by the selected internal `region_specific_id`; it can auto-select only a single option or a valid explicit geographic mapping. With multiple eligible options and no mapping, selection remains manual/blank.
- Physical geography and operational grouping are distinct. The approved crosswalk retains GMA / operational Specific Region ID 52 even though that record's physical parent is Region IV-A / Region ID 7. NCR localities use physical Region ID 2 in the canonical Tablet snapshot (with Metro Manila alias Region ID 1 also present). Current location-mapping validation incorrectly equates the cluster's parent physical Region with the locality's physical Region: Portal model/resolver and activation seeder checks, plus Tablet snapshot validation, reject an NCR locality → ID 52 cluster mapping because 7 != 2.

## Read-only local inventory

The Portal database selected by local Laravel configuration was MySQL database `ommc_henri_local_latest`; the Tablet database was `database/database.sqlite` (SQLite). Both were queried through read-only `SELECT` statements on 2026-10-16. At that time the Portal source was treated as local; Human subsequently confirmed that inspected dataset represents production. The Tablet SQLite file is an offline snapshot, not a separate production source. No new production access occurred during this feasibility review. Matching normalized SHA-256 digests were calculated over the same selected reference columns to compare the two snapshots; timestamps and database-specific serialization were excluded.

| Table | Portal | Tablet | Normalized comparison |
| --- | ---: | ---: | --- |
| `regions` | 19 | 19 | same IDs and visible names/codes |
| `region_specifics` | 18 | 18 | same |
| `area_clusters` | 44 | 44 | same |
| `provinces` | 85 | 85 | same |
| `municipalities` | 1,643 | 1,643 | same |
| `barangays` | 42,010 | 42,010 | same |
| `location_area_cluster_mappings` | 99 | 99 | same |

- All 44 current Area Cluster rows are enabled. There are no Area Cluster → Specific Region or Specific Region → Region orphans; no orphan municipality Region/province parents, barangay municipality parents, or mapping location references were found. Mapped rows have a present cluster target. Duplicate *names* exist across distinct Specific Regions by design/legacy overlap; name equality is not a safe key.
- NCR has 17 canonical province-less municipalities in physical Region ID 2 and 1,715 associated barangays. Three extra legacy `provinces` rows named Quezon City, Caloocan, and Manila have null physical Region references and do not parent the current NCR municipality rows.
- NCR has 17 municipality mapping decisions: 16 are `unresolved`; Quezon City (municipality ID 14) is `requires_finer_location`. There are **zero barangay mapping rows**. No Cluster is assigned by those decisions.
- Portal has 18,991 local customer rows; 18,379 currently reference an Area Cluster, including legacy NCR cluster IDs 1–10 and current operational IDs 16/17. Tablet has a smaller local customer dataset (15 rows; 14 have a cluster). These customer records are compatibility evidence, not authority for new assignments. No customer row was read beyond aggregate foreign-key counts, and none was modified.
- Area Cluster data by Specific Region: legacy NCR Specific Region ID 1 has enabled `GMA - Area 1` through `GMA - Area 10` (IDs 1–10); approved GMA ID 52 has only enabled `GMA - Area 6` (ID 16) and `GMA - Area 12` (ID 17). The name `GMA - Area 6` therefore occurs under both IDs 1 and 52 (IDs 2 and 16). GMA Area 2 and 3 definitions exist only under legacy ID 1, not under approved ID 52.
- Current selectors using GMA / ID 52 therefore expose only Area 6 and Area 12. They cannot expose the supplied NCR assignments for Areas 1–5 and 7–10 from ID 52. Quezon City’s supplied Area 2/Area 3 split is explicitly barangay-dependent, but neither approved barangay assignments nor any NCR barangay mapping rows are present.
- Portal schema constraints: `area_clusters.region_specific_id` references `region_specifics`; `customers.area_cluster_id` references `area_clusters` with `ON DELETE SET NULL`; and `location_area_cluster_mappings.area_cluster_id` references `area_clusters` with delete restricted. The polymorphic mapping `location_level`/`location_id` has no database FK. Tablet's local migrations do not define FKs for these Area Cluster relationships, so Tablet relies on snapshot validation and application checks. Current inventory found no orphans. Preserve this difference in any validation plan.

## Business reference reconciliation

Classification compares supplied business assignments to current definitions and explicit location mapping decisions. A global matching name is not a match if its cluster row is attached to the wrong/legacy Specific Region or no approved location assignment exists.

| Reference rows | Classification | Evidence and disposition |
| --- | --- | --- |
| Manila → Area 5; Caloocan → Area 1; Pasay → Area 7; Las Piñas → Area 8; Makati → Area 7; Malabon → Area 1; Mandaluyong → Area 4; Muntinlupa → Area 8; Navotas → Area 1; Parañaque → Area 9; Pasig → Area 10; San Juan → Area 4; Taguig → Area 9; Valenzuela → Area 1; Pateros → Area 10 | `MISSING_CLUSTER` and `MISSING_RELATIONSHIP` | All 15 municipality decisions are `unresolved`. Their assigned GMA Area definitions exist under legacy ID 1 but not approved operational ID 52; no confirmed municipality mapping is stored. |
| Marikina → GMA Area 6 | `MISSING_RELATIONSHIP` | Enabled cluster ID 16 exists under ID 52, but Marikina's municipality decision is `unresolved` and has no cluster ID. |
| Quezon City → GMA Areas 2 or 3 by barangay | `AMBIGUOUS` and `MISSING_CLUSTER` | Database decision is `requires_finer_location`; no barangay-to-cluster rows exist. Areas 2 and 3 are absent under ID 52 and exist only under legacy ID 1. The supplied reference does not say which barangays map to each area, so no assignment can be inferred. |
| Cavite → GMA Area 12; Rizal → GMA Area 6 | `MATCHED` | Province mapping rows point to enabled cluster IDs 17 and 16 under operational Specific Region ID 52, respectively. This uses the approved operational crosswalk and preserves physical parent Region IV-A / ID 7. |
| Province assignments outside NCR whose current mapping row is `mapped` (76 rows in the local manifest, including Cavite and Rizal) | `MATCHED` | Cluster label and specific/geographic relationship match the supplied row in the current local manifest. The inventory found no absent target IDs for these mapped rows. Exact matched province names by supplied cluster: NL Area 1 — Abra, Benguet, Ilocos Norte, Ilocos Sur, La Union, Pangasinan, Tarlac; NL Area 2 — Apayao, Ifugao, Kalinga, Mountain Province, Batanes, Cagayan, Isabela, Nueva Vizcaya, Quirino, Aurora, Nueva Ecija; NL Area 3 — Bataan, Bulacan, Pampanga, Zambales; SL Area 1 — Batangas, Laguna, Quezon; GMA Area 12 — Cavite; GMA Area 6 — Rizal; SL Area 3 — Marinduque, Occidental Mindoro, Oriental Mindoro, Romblon; SL Area 4 — Palawan; SL Area 2 — Albay, Camarines Norte, Camarines Sur, Catanduanes, Masbate, Sorsogon; VIS Area 1 — Aklan, Antique, Capiz, Guimaras, Iloilo; VIS Area 5 — Bohol; VIS Area 4 — Cebu; VIS Area 7 — Biliran, Eastern Samar, Leyte, Southern Leyte; VIS Area 6 — Northern Samar, Samar; MIN Area 5 — Zamboanga del Norte, Zamboanga del Sur, Zamboanga Sibugay; MIN Area 2 — Bukidnon, Camiguin, Lanao del Norte, Misamis Occidental, Misamis Oriental, Lanao del Sur; MIN Area 1 — Davao de Oro, Davao del Norte, Davao del Sur, Davao Occidental, Davao Oriental; MIN Area 4 — Cotabato, Sarangani, South Cotabato, Sultan Kudarat; MIN Area 3 — Agusan del Norte, Agusan del Sur, Dinagat Islands, Surigao del Norte, Surigao del Sur; MIN Area 6 — Basilan, Tawi-Tawi. |
| Negros Occidental → VIS Area 2; Negros Oriental and Siquijor → VIS Area 3 | `CONFLICTING_RELATIONSHIP` | The three current province rows are in physical Negros Island Region / Region ID 11 and explicitly unresolved. Corresponding VIS cluster definitions exist under old Specific Region parents for Regions VI/VII (physical IDs 10/12), not a NIR operational anchor. The current physical-parent equality validator prevents a safe mapping. Business must confirm whether the VIS operational assignment spans this physical-region boundary and which Specific Region should own it. |
| Sulu → MIN Area 6 | `CONFLICTING_RELATIONSHIP` | Sulu’s local province row is under physical Region IX / ID 14 and is unresolved. MIN Area 6 exists as cluster ID 26 under Specific Region ID 60 / physical BARMM Region 19; current validation forbids this cross-parent assignment. Confirm the operational crosswalk and physical/geographic treatment before correction. |
| Maguindanao → MIN Area 4 | `NOT_VERIFIABLE` | Current geographic data has separate `Maguindanao del Norte` and `Maguindanao del Sur` rows, both unresolved; the reference has one unsplit province row. MIN Area 4 exists under Specific Region ID 60, but these records cannot be assigned by guessing a split or bypassing physical-parent validation. Business confirmation is required for both successor provinces. |
| The other province rows named in the supplied NL, SL, VIS, and MIN sections | `MATCHED` where current manifest has a mapped row; unresolved current rows are listed above | 76 mapped province rows are present and their targets exist. Six current province rows are unresolved: Negros Occidental, Negros Oriental, Siquijor, Sulu, Maguindanao del Norte, and Maguindanao del Sur. The latter six rows correspond to five supplied-reference subjects because Maguindanao is split in current geography. |

## NCR root cause

The empty/wrong Area Cluster result is a combination, not an offline snapshot mismatch:

1. **Missing operational definitions under ID 52:** most supplied GMA areas (including Areas 2 and 3) are stored only under legacy ID 1. ID 52 has only Areas 6 and 12, so those are the only options when the approved GMA grouping is selected.
2. **Missing geographic assignments:** 16 NCR cities are marked unresolved; Quezon City is explicitly blocked pending finer location; there are no barangay mapping records. This prevents automatic selection.
3. **Validation conflates geography with operational ownership:** Portal and Tablet mapping validators require `AreaCluster.regionSpecific.region_id == locality.region_id`. That rejects approved operational ID 52 clusters (parent Region 7) for physical NCR Region 2. The same assumption exists in the Tablet snapshot validator and reviewed mapping activation seeder. Correcting data alone cannot produce an accepted NCR → GMA mapping under current checks.
4. **No evidence of NCR lookup data loss during synchronization in the inspected snapshots:** all seven relevant reference-table row counts and normalized projected contents match exactly Portal ↔ Tablet. Portal sends enabled clusters and all 99 mapping rows; Tablet persists the same local records. Thus stale/offline Tablet records are not the observed first failing boundary for this local snapshot. A separate limitation remains: Portal omits disabled cluster rows while Tablet upserts only received cluster rows, so disabling a record may not clear a stale local `enabled` state.

Manual choice and automatic assignment are separate. Manual GMA options are limited by definitions attached to ID 52; automatic/map-based selection is additionally blocked by absent decisions and parent-equality checks. QC’s exact Area 2/3 decision cannot be implemented without a Human-approved barangay crosswalk.

## Baseline and self-healing review

- `CustomerMappingActivationSeeder` is a reviewed, idempotent-style baseline for 99 location decisions (76 mapped, 22 unresolved, one requires-finer-location). It validates exact expected parent/geographic records and refuses conflicting rows. It is explicitly not registered in `DatabaseSeeder`; it was not run. Its validation currently enforces physical-parent equality, so it cannot encode the approved cross-parent GMA/NCR assignment without a separately reviewed implementation change.
- `CustomerLocationReferenceRestorationSeeder` contains a guarded Specific Region restoration manifest for IDs 52–68 and expected cluster counts (ID 52 expects two). It restores Specific Region rows only; it does not define or repair Area Clusters or geographic assignments. It was not run and is not evidence of Area 2/3 mappings.
- No complete Area Cluster definition baseline/import, scheduled self-heal, or Portal admin maintenance screen was located. The current Portal local database is the source of the reference payload consumed by Tablet, but the authoritative business custodian and provenance for cluster definitions remain unverified.
- The supplied business table is used only as the comparison input. The October 12 application manifest is reviewed application data, not an independent proof that every business row remains current.

## Proposed bounded plan (not authorized for execution)

1. **Human business decisions:** confirm all Metro Manila city-area assignments, provide a Quezon City barangay → Area 2/Area 3 crosswalk, confirm whether the legacy ID 1 Area 1–10 definitions should be copied/represented under operational ID 52, and confirm the NIR, Sulu, and split Maguindanao cross-boundary assignments.
2. **Reviewed data proposal:** after decisions, produce an idempotent NCR-only cluster/assignment proposal with stable IDs/codes and exact expected preconditions. Do not re-key or delete IDs 1–10 or change existing customer foreign keys. Hold other regional changes until separately validated.
3. **Bounded implementation proposal:** revise only the operational-vs-physical relationship checks in Portal mapping validation/resolution and Tablet snapshot validation so explicitly approved operational crosswalks can span physical Regions; keep physical locality resolution independent. Preserve the existing manual selector and non-NCR behavior; no automatic QC assignment without a unique barangay decision.
4. **Propagation:** publish the approved Portal snapshot; make Tablet consume the same complete, enabled/disabled state deterministically, and verify the offline database after a normal sync. Keep Customer Push/Pull payload behavior untouched.
5. **Validation/rollback:** test every approved reference row, ID preservation, customer rows currently using legacy IDs, Portal Add/Edit, map hydration, offline Tablet selectors and non-NCR assignments. Compare before/after bounded table diffs and counts. Roll back only the reviewed reference rows if validation fails; no broad table replacement.

## Human decisions and limitations

- Confirm Area Cluster operational ownership: which definitions should be selectable for GMA / ID 52, and whether ID 1 is a deprecated legacy lookup that must remain readable for saved customers.
- Supply or approve the exact QC barangay split for GMA Areas 2 and 3; the provided document intentionally does not define it.
- Confirm cross-physical-region operational mapping for NIR provinces, Sulu, and Maguindanao del Norte/Sur.
- Confirm the ultimate custodian/source of Area Cluster definitions and whether the local Portal database is a complete current copy for all environments.
- Decide whether Tablet snapshot delivery must explicitly mark disabled/deleted clusters, since omission-only upsert can preserve stale local enabled rows.
- Verification is limited to the two available local databases and repository code/manifests. No production database, live runtime, application sync session, or current external business reference file was accessed. No tests, seeders, migrations, writes, code changes, commits, or pushes were performed.

**Owned files:** This work note in Portal and the matching Tablet note only. **Current remaining work:** Human decisions above; only then can any bounded implementation/data proposal be prepared for separate review. **Delivery:** Not terminal; no Git delivery occurred.

## Bounded GMA planning update — 2026-10-16

- **Human direction:** Specific Region ID 52 remains approved as operational GMA. Preserve legacy Specific Region ID 1 and all current references to it. Keep physical geography separate from operational group eligibility. Implementation, DB writes, migrations, customer changes, and commits remain on hold.
- **AreaCluster schema and historical IDs:** Portal `area_clusters` has surrogate `id`, `region_specific_id`, `code`, `name`, `enabled`; unique key is `(region_specific_id, code)`. Portal's cluster Specific Region FK cascades on deleting the Specific Region. Customer cluster references are real FKs with `ON DELETE SET NULL`, and location-map cluster references are FKs with delete restricted. The polymorphic mapping `location_level`/`location_id` has no FK. Tablet's current local migrations do not enforce equivalent Area Cluster FKs; its snapshot validator checks lookup parents. Existing legacy Area Cluster IDs 1–10 are customer-referenced in Portal and must not be reparented, deleted, or assigned new meanings. Existing operational GMA IDs 16 (`gmaarea6`) and 17 (`gmaarea12`) under Specific Region 52 are also preserved. New Area definitions must receive new primary IDs; never treat the Area suffix as a database ID.
- **Option A — Reparent/reuse IDs 1–10:** rejected. Moving those existing rows from Specific Region 1 to 52 would change legacy selector membership and the meaning of customer references; changing their IDs would directly break those references. Keeping IDs but changing parent does not make this a safe reuse.
- **Option B — Add operational definitions under ID 52:** recommended smallest safe approach using the existing schema. Add only the nine missing definitions under the unique natural keys `(52, code)`: `gmaarea1`, `gmaarea2`, `gmaarea3`, `gmaarea4`, `gmaarea5`, `gmaarea7`, `gmaarea8`, `gmaarea9`, `gmaarea10`, with labels `GMA - Area 1` through the corresponding suffix. (There are nine missing keys: Areas 1–5 and 7–10.) Keep ID 16 / Area 6 and ID 17 / Area 12 as-is. The schema allows the same code/name under another Specific Region. Portal's Area Cluster selector filters by selected `region_specific_id`, so GMA / ID 52 sees one of each Area 1–10 and Area 12; legacy customers at ID 1 retain their existing rows and selected IDs. Tablet receives the new rows through the existing Portal location-reference snapshot, which carries stable Portal primary IDs. Add no Area 11 because the supplied reference does not request it.
- **Option C — Add an explicit cluster-to-operational-group relationship:** technically supports shared definitions without duplicate records, but requires a migration, relationship maintenance, revised Portal and Tablet selectors/validators, and a snapshot contract/local lookup change. It raises customer eligibility and rollback complexity and is not needed for the currently supplied GMA assignments. Reconsider only if business confirms that one definition must be shared across operational groups as a durable rule.
- **Selector, eligibility, and assignment remain separate:** adding the nine rows makes them available under ID 52 for manual selection. It does not itself assign a cluster from a location. `location_area_cluster_mappings` continues to determine geographic eligibility and auto-assignment. `customers.area_cluster_id` stores the selected cluster ID independently of `province_id`/`municipality_id`/`barangay_id`.

### NCR geographic proposal

The supplied table unambiguously assigns 16 NCR municipalities at municipality level; the local physical locality rows are province-less, under Region ID 2, and already have municipality IDs. Proposed assignment (using cluster `code` as the stable lookup key; new cluster primary IDs remain database-generated):

| Local municipality record | Physical geography | Operational GMA cluster code/label | Proposed mapping decision |
| --- | --- | --- | --- |
| City of Caloocan (ID 2) | Region 2; no province | `gmaarea1` / Area 1 | mapped |
| City of Las Piñas (ID 3) | Region 2; no province | `gmaarea8` / Area 8 | mapped |
| City of Makati (ID 4) | Region 2; no province | `gmaarea7` / Area 7 | mapped |
| City of Malabon (ID 5) | Region 2; no province | `gmaarea1` / Area 1 | mapped |
| City of Mandaluyong (ID 6) | Region 2; no province | `gmaarea4` / Area 4 | mapped |
| City of Manila (ID 7) | Region 2; no province | `gmaarea5` / Area 5 | mapped |
| City of Marikina (ID 8) | Region 2; no province | Existing ID 16 / `gmaarea6` / Area 6 | mapped |
| City of Muntinlupa (ID 9) | Region 2; no province | `gmaarea8` / Area 8 | mapped |
| City of Navotas (ID 10) | Region 2; no province | `gmaarea1` / Area 1 | mapped |
| City of Parañaque (ID 11) | Region 2; no province | `gmaarea9` / Area 9 | mapped |
| Pasay City (ID 12) | Region 2; no province | `gmaarea7` / Area 7 | mapped |
| City of Pasig (ID 13) | Region 2; no province | `gmaarea10` / Area 10 | mapped |
| City of San Juan (ID 15) | Region 2; no province | `gmaarea4` / Area 4 | mapped |
| City of Taguig (ID 16) | Region 2; no province | `gmaarea9` / Area 9 | mapped |
| City of Valenzuela (ID 17) | Region 2; no province | `gmaarea1` / Area 1 | mapped |
| Pateros (ID 18) | Region 2; no province | `gmaarea10` / Area 10 | mapped |
| Quezon City (ID 14) | Region 2; no province | Area 2 or Area 3 by barangay | Keep `requires_finer_location`; no municipality assignment and no barangay assignments until an authoritative split is approved |

This proposal uses only municipality assignments explicitly present in the supplied reference. It invents no barangay assignment. It excludes Negros, Sulu, and Maguindanao conflicts from the initial NCR scope.

### Validation correction design

The narrow correction should stop treating `AreaCluster.regionSpecific.region_id` as the geographic Region of every location assigned to that cluster. ID 52 is explicitly an operational anchor with parent Region 7; NCR locality geography remains Region 2. A later implementation should:

1. Continue validating the physical location chain independently: a municipality must belong to the selected province when one is provided; a barangay must belong to the selected municipality; derive physical Region from the actual locality records.
2. Confirm the cluster exists, is enabled, and belongs to the specific operational group on the current approved crosswalk (GMA → ID 52). Do not infer group membership from the cluster's display name or its numeric ID suffix.
3. For ordinary mapped groups, retain the existing physical-parent equality check. For an explicitly configured cross-geography operational group, permit only its approved supplemental physical Region IDs. Specifically GMA's accepted physical options must include canonical NCR Region ID 2 (preserving the existing Metro Manila alias ID 1); still accept ID 52's physical parent Region 7 for Cavite/Rizal. Do not turn this into a global “any group may span any Region” exception.
4. Apply the same policy in Portal mapping save validation and resolver, Portal's reviewed mapping baseline validation, and Tablet's incoming snapshot validation/resolver. The Portal configuration currently lists GMA extra physical IDs as `[1]`; Tablet lists `[1, 2]`. Align Portal to the approved canonical NCR ID 2 while preserving alias ID 1, then use the same explicit grouping rule in mapping validation. Avoid weakening municipality/province/barangay parent validation.
5. Keep mapping priority specific-to-general: a future approved Quezon City barangay mapping takes precedence over the municipality's `requires_finer_location` decision; absent a barangay mapping, the result stays unresolved. Do not default to one of the two areas.

### Deterministic dry-run and rollback design

No dry-run script or data correction was executed. For a separately authorized implementation, the read-only preflight should emit a stable report and refuse application unless every condition below holds:

- Confirm exactly one Specific Region 52 named `Region IV-A (Cavite, Rizal)` with physical parent Region 7; confirm Specific Region 1 and legacy cluster IDs 1–10 have not changed.
- For ID 52, validate existing `gmaarea6` and `gmaarea12` rows exactly match IDs 16/17, names, and enabled state. For each of the nine missing codes, classify as add only if absent; treat an exact existing row as already reconciled; abort on same-key name/parent/enabled conflict. Use `(region_specific_id, code)` as the idempotent key and database-generated IDs for inserts.
- Resolve each named municipality by exact current Region 2 and PSGC identity/name. For the 16 proposed mapping rows, require one existing row per municipality with the expected `unresolved` state and null cluster, or accept only an exact already-applied mapping. Abort on duplicates, wrong physical parent, missing municipality, or any differing decision/target. Preserve each mapping row's existing primary key when updating it. Require QC ID 14 to remain `requires_finer_location` with null cluster and require zero newly proposed barangay rows.
- Report the nine definition actions, the 16 municipality map actions (including stable municipality IDs and target natural keys), unchanged QC decision, unchanged AreaCluster IDs 1–10/16/17, customer references by current cluster ID, and expected before/after counts: `area_clusters` 44→53, ID 52 clusters 2→11, mapping rows 99→99 (16 decision changes), and customer values unchanged. For Portal, enforce the FK targets; for Tablet, validate the exact incoming Portal snapshot IDs and parents. Do not write to Tablet directly.
- If later approved, apply Portal definition and mapping updates in one bounded transaction after the approved validation correction is in place; publish through the existing Portal snapshot and verify Tablet after a normal sync. Do not run any broad seed or replacement.
- For rollback, first capture exact pre-images of only the nine `(52, code)` keys and the 16 municipality mapping rows. Restore a mapping only if it still equals the applied expected row; otherwise stop for review. Remove newly inserted map references before considering removal of a newly inserted cluster. Delete a newly created cluster only if it has no `customers.area_cluster_id` references and no remaining mapping references. Never delete/reparent legacy IDs or silently null customer fields. If a new ID has acquired customer references, preserve the row and require a reviewed disable/retention plan; synchronize disabled state deterministically before relying on Tablet selector removal.

### Approach comparison and remaining approvals

| Approach | Customer history | Duplicate names / selectors | Offline Tablet and validation | Rollback | Assessment |
| --- | --- | --- | --- | --- | --- |
| Reparent/reuse IDs 1–10 | Breaks the relationship between saved customer selection and legacy Specific Region 1; risks hiding old values | Removes the legacy selector definitions | Existing customer and sync data continue to reference those IDs under changed semantics | High, because history and current rows need restoration | Reject |
| Add nine definitions under ID 52; preserve old definitions | Keeps all old customer IDs and adds new operational IDs | Same names/codes across different Specific Regions are permitted; selector filters by ID 52 | Uses existing payload shape and Tablet upsert; assignment validation still needs the bounded correction | Low before use; conditional once customer references accrue | Recommend |
| New explicit many-to-many operational relationship | Could retain a single canonical definition but requires interpreting old customer IDs through new relations | Avoids duplicate definitions, but adds relationship/selector complexity | Requires migration and snapshot contract/local schema propagation | Highest; changes keys/semantics across both apps | Defer unless cross-group sharing is confirmed as a lasting need |

At the time of this initial design, approval of the GMA assignments and grouping representation was pending. The subsequent Human direction and its remaining decisions are recorded below. Negros, Sulu, and Maguindanao remain excluded. No implementation, migration, seed execution, database mutation, customer edit, production access, test, commit, or push was performed for this planning update.

## Municipality-based eligibility planning — 2026-10-16

- **Human decision:** GO for bounded Metro Manila mapping design; HOLD database writes, migrations, implementation, customer changes, production deployment, commits, and pushes. The supplied municipality reference is approved business input. Quezon City remains unresolved at barangay level until an authoritative Area 2 / Area 3 split is supplied.
- **Current representation limit:** `area_clusters` defines options per `region_specific_id`; it does not attach a cluster to geography. `location_area_cluster_mappings` stores exactly one `area_cluster_id` per `(location_level, location_id)` because of its unique key. It represents an automatic mapping or a blocked/unresolved decision, but cannot represent the two eligible clusters for Quezon City. No schema currently expresses “these are the allowed choices for a municipality, but do not choose one automatically.”
- **Recommended representation:** add a normalized `municipality_area_cluster_eligibilities` relationship table. Each row links one `municipality_id` to one `area_cluster_id`; enforce a unique pair and foreign keys to both parent tables, with deletion restricted. This supports one or many eligible clusters without JSON encoding or a broad polymorphic key. Keep `location_area_cluster_mappings` for explicit automatic assignment/decision (including barangay-specific decisions) and keep `area_clusters.region_specific_id` as operational grouping ownership. Validate that each eligibility target is enabled, belongs to operational Specific Region 52/GMA, and is physically allowed for that locality under the explicit GMA crosswalk. No new table or migration was created.
- **Why this is the smallest safe choice:** the 16 direct city mappings are one-choice cases; Quezon City is the only currently known two-choice case. The new relationship records the actual choice set independently of the mapping that may automatically select one. A JSON list in the existing column weakens referential integrity and makes validation/query behavior less clear. Replacing or widening the existing unique mapping row would change its meaning and complicate existing province, municipality, and barangay decisions. A general many-to-many relationship for all location levels is broader than the approved municipality-based requirement.
- **Data design:** add the nine missing GMA Area definitions under ID 52 (`gmaarea1`–`gmaarea5`, `gmaarea7`–`gmaarea10`); keep IDs 16/17 as Areas 6/12 and preserve all IDs 1–10 under legacy ID 1. Insert 18 municipality eligibility rows: one row for each of the 16 directly assigned municipalities and two rows (Area 2 and Area 3) for Quezon City. Update the 16 existing NCR municipality mapping decisions from `unresolved` to `mapped` using the same assigned cluster IDs; preserve their mapping row IDs. Leave Quezon City ID 14 `requires_finer_location` with no cluster. Add no barangay decision without new evidence. Expected result after an approved schema/data change: Area Clusters 44→53, Specific Region 52 clusters 2→11, eligibility rows 0→18, existing mapping rows remain 99 with 16 changed decisions, customer rows/values unchanged.
- **Approved municipality assignments:** Caloocan ID 2 → Area 1; Las Piñas ID 3 → Area 8; Makati ID 4 → Area 7; Malabon ID 5 → Area 1; Mandaluyong ID 6 → Area 4; Manila ID 7 → Area 5; Marikina ID 8 → Area 6 / existing cluster ID 16; Muntinlupa ID 9 → Area 8; Navotas ID 10 → Area 1; Parañaque ID 11 → Area 9; Pasay ID 12 → Area 7; Pasig ID 13 → Area 10; San Juan ID 15 → Area 4; Taguig ID 16 → Area 9; Valenzuela ID 17 → Area 1; Pateros ID 18 → Area 10. Quezon City ID 14 is eligible for Areas 2 and 3 only; neither is the municipal automatic assignment.
- **Selector/resolution contract:** for an explicitly configured NCR municipality, obtain allowed choices from `municipality_area_cluster_eligibilities`. Exactly one allowed cluster may auto-select on initial creation or after a locality change. With multiple allowed clusters, an authoritative mapped Barangay decision takes precedence only if its cluster is in the municipality's allowed set; without one, show only the eligible choices and require manual selection. An absent eligibility row means no approved GMA option is inferred. Non-NCR forms continue using the existing mapping behavior.
- **Dependent field updates:** changing Municipality clears its old Barangay. Recompute eligible Area Clusters; preserve the current cluster if it remains eligible. If it is no longer eligible, clear it, then auto-select the sole allowed cluster where exactly one exists. With multiple choices, leave it blank unless a valid Barangay mapping chooses one. On Barangay change, apply an authoritative exact mapping if present; otherwise keep a still-eligible user selection. Never translate a geocoder's city-only/ambiguous result into a Barangay assignment.
- **Existing customer Edit:** hydrate saved Region/Specific Region, locality, and cluster IDs as stored. Do not run inference merely because an Edit form opened. Preserve legacy Specific Region ID 1 and its cluster references. Recalculate only after a user changes a dependent location; preserve the selected cluster if it remains eligible and clear/replace it only when authoritative eligibility says it is no longer valid. Do not update customer data as part of the lookup correction.
- **Province-less localities and map flow:** use physical Region ID 2 plus municipality identity/ID directly; do not require a Province row. Map hydration resolves Region 2 and municipality independently from operational GMA ID 52, then checks an unambiguous Barangay. Apply the same eligibility resolver after manual municipality selection and map hydration. If geocoding only resolves Quezon City, keep the cluster unset unless the user selects Area 2 or 3; if a Barangay has an approved mapping, use it only after the crosswalk/validator accepts it.

### Portal and Tablet boundaries

- **Portal source of truth:** persist operational definitions, municipality eligibility relationships, and approved assignment decisions in Portal. Include eligibility rows in `TabletSyncPayload::locations()` with `reference_counts`; expose the rows from the existing lookup snapshot, not from Customer Push/Pull data. Use Portal-generated Area Cluster IDs as the cross-app stable IDs.
- **Snapshot contract:** current lookup snapshot version is 3 and Tablet currently accepts versions 2/3. Adding a validated table section/count requires a coordinated contract revision (proposed version 4) and a compatibility rollout plan. Tablet must validate duplicate keys, municipality and cluster parent existence, enabled status, GMA ownership, and the explicit physical crosswalk before persisting. Apply eligible-set snapshot in the same reference transaction as Areas and locality mappings; do not replace unrelated lookups. Keep old Tablet builds from receiving an unsupported required contract or silently using unrestricted GMA choices; coordinate capability/rollout before Portal starts publishing the required section.
- **Offline behavior:** add the local eligibility table to Tablet through a migration after separately authorized implementation. On normal reference pull, upsert/replace the complete eligibility set deterministically, mark the location reference snapshot incomplete until all sections/counts validate, then mark it complete. Resolve selectors only from the committed local snapshot while offline. Do not directly edit Tablet SQLite or modify Customer Push/Pull.
- **Physical versus operational checks:** keep province/municipality/barangay parent checks and physical Region derivation. Confirm the cluster belongs to the operational group mapped to ID 52, then allow its locality's physical Region only if it is the Region 7 parent or an explicitly approved GMA supplemental physical Region (NCR canonical Region 2; retain alias Region 1 where supported). Portal's current supplemental GMA list is `[1]`, while Tablet's is `[1, 2]`; align to the approved set. Centralize this rule and use it in Portal model/resolver/baseline validation and Tablet mapping model/snapshot validation. Ordinary groups retain current physical-parent equality. Do not change data interpretation for Negros, Sulu, or Maguindanao in this NCR work.

### Deterministic dry run, verification, and rollback

1. Read-only preflight records exact before-state and a stable digest for Specific Regions 1/52, legacy clusters 1–10, clusters 16/17, all 17 NCR municipality rows, existing 17 NCR mapping decisions, current Portal customers' aggregate cluster references, and the new eligibility table's count. Require physical NCR Region 2 / its canonical PSGC, Region 7 parent for Specific Region 52, province-less municipality IDs/names, and expected pre-correction decisions (16 unresolved; QC requires finer).
2. Resolve candidate definitions by natural key `(52, gmaareaN)` and exact expected label/enabled state. Existing exact entries are “already present”; missing entries are proposed inserts with generated IDs; any same-key conflict aborts the entire plan. Resolve the 18 eligibility pairs by municipality ID and cluster code, and validate each target exists/enabled under ID 52. Resolve the 16 mapping row updates without changing mapping IDs. Leave the QC decision untouched and propose no Barangay row.
3. Output an action report only: 9 definition additions, 18 eligibility additions, 16 existing mapping rows changing unresolved→mapped, QC unchanged, expected counts above, no customer writes, and unchanged legacy IDs. Abort on absent/duplicate locality, conflicting existing data, invalid parent, crosswalk mismatch, or any unexpected customer/lookup precondition. No production access is allowed under this Work.
4. Later, after distinct implementation/data authorization, apply Portal schema and data changes in reviewed order; verify the Portal snapshot; run a normal Tablet reference sync; compare normalized Portal/Tablet eligible pairs and IDs; verify all snapshot section counts before the Tablet selector considers data complete. Never run a broad replacement seeder.
5. Capture exact pre-images of only the new eligibility pairs, the nine newly created cluster natural keys/IDs, and the 16 mapping rows. Roll back mapping decisions only if they still equal the applied values. Remove eligibility rows before new cluster rows. Delete a newly created cluster only if no customer FK and no remaining map/eligibility rows reference it; never rely on `ON DELETE SET NULL` to rollback customer references. If customer records have begun using a new cluster, retain that cluster and require a reviewed disable/retention and Tablet sync plan. Existing IDs 1–10, 16, and 17 are never rolled back or reparented.

### Regression coverage and remaining decisions

When implementation is separately authorized, focused Portal and Tablet tests should cover: all 16 exact municipality→singleton eligibility and automatic choice; Area 2/3 both available for Quezon City with no automatic choice; authoritative Barangay choice overriding QC ambiguity only when it is eligible; invalid cluster rejection; dependent Municipality/Barangay changes preserving still-eligible values and clearing invalid ones; province-less NCR fields; Pick on Map city-only and Barangay-resolved paths; Edit hydration preserving saved/legacy IDs; unchanged non-NCR mapping; snapshot v4 validation, atomic local eligibility availability, and offline lookups. Include regression assertions for no customer row changes and no Customer Push/Pull payload changes. No tests were run during this planning-only turn.

The exact municipality reference and GMA ID 52 crosswalk are now supplied and approved for planning. Still requiring Human decision before implementation or writes: accept the new normalized eligibility table and nine duplicate-name operational definitions under ID 52; approve/version the cross-app snapshot contract and coordinated Tablet rollout; confirm the complete GMA physical Region allowlist (including canonical Region 2, alias Region 1, and parent Region 7); decide how/when disabled Area Cluster state is synchronized; and provide the authoritative Quezon City barangay Area 2/3 split. Negros, Sulu, and Maguindanao remain excluded. Worktree scope remains these matching notes only; Work state remains ACTIVE and non-terminal.

## Readiness and functional alignment review — 2026-10-16

This review supersedes the earlier proposal to add nine duplicate cluster definitions under Specific Region 52 and to introduce a municipality eligibility table. Neither proposal was implemented. This read-only review finds a blocker to completing the requested behavior using the records visible in the available local lookup snapshots. No production data was accessed; local data does not establish production contents.

### Existing definitions and safe reuse

- The local Portal lookup source (ommc_henri_local_latest) and Tablet SQLite snapshot each contain 44 Area Cluster rows and matched on the previously compared reference fields. Under operational Specific Region 52, the visible definitions are GMA Area 6 (row ID 16) and GMA Area 12 (row ID 17). GMA Areas 1–10 also exist under legacy Specific Region 1 (row IDs 1–10); those are legacy-owned records, not definitions under ID 52.
- Do not treat row ID as business Area number. Resolve by the stored cluster code/name and its owning Specific Region. Do not repoint or relabel legacy rows. Historical customer rows refer to legacy cluster IDs, and application validation requires a cluster's region_specific_id to match the selected customer Specific Region. Reusing a legacy row as an ID 52 option would therefore need an explicit cross-group compatibility contract and changes to selector and server-side validation; it is not a safe implicit fallback.
- Existing ID 16 is a potential data reuse opportunity for the approved Marikina → GMA Area 6 mapping. The local municipality mapping remains unresolved, however, and current map resolution rejects the physical parent mismatch (Specific Region 52's parent is Region 7; NCR municipality is Region 2). ID 17 / Area 12 does not satisfy any supplied Metro Manila municipality assignment.
- The local snapshots consequently lack operational ID 52 definitions for Areas 1–5 and 7–10, including both Area 2 and Area 3 required for Quezon City. This is a confirmed local-snapshot gap only. Production contents remain unverified and must not be inferred from this result.

### Current selector and map behavior

- Portal and Tablet manual Area Cluster option queries filter enabled clusters by exact area_clusters.region_specific_id = selected region_specific_id. They do not currently narrow options from Municipality. With operational ID 52 selected, the local snapshots expose only Area 6 and Area 12.
- Both applications route manual locality changes and Pick on Map hydration through LocationAreaClusterMappingResolver and the existing location mapping records. That table can hold one mapped cluster per location or a requires_finer_location decision; it does not represent a two-choice eligibility set for Quezon City.
- The local NCR decision rows are 16 unresolved, with Quezon City requires_finer_location; there are no NCR Barangay mapping rows. Thus the resolver has no approved singleton assignment to apply for NCR today. It also validates the mapped cluster's owning Specific Region physical parent against the locality's physical Region, which rejects NCR Region 2 → ID 52 / parent Region 7 without a narrowly approved operational-versus-physical crosswalk.
- Portal and Tablet have the same data/selection constraint. Their surrounding form and map hydration code differs, but both enforce exact cluster ownership and use the same style of location mapping resolution. Neither can correctly offer Quezon City's Area 2 and Area 3 using the visible ID 52 records.

### Minimal safe correction and blocker

Do not substitute legacy Specific Region 1 cluster IDs, change cluster ownership, add lookup records, or add a schema table as part of this work. Before implementation can deliver the full business rule, obtain an authorized read-only verification of the authoritative Portal dataset and confirm that the required GMA Area definitions exist under ID 52. If absent there, a separately approved data change is required; no code can expose a missing approved definition without substituting or fabricating it.

If authoritative records under ID 52 are confirmed, the bounded code correction can use cluster business codes rather than numeric IDs and the approved municipality mapping as the eligibility rule. Keep the existing exact-owner integrity checks for ordinary groups. Add only a GMA/NCR-aware physical-versus-operational validation exception, and use one shared resolver path for manual locality updates and Pick on Map. For single-choice municipalities, select only the approved eligible ID. For Quezon City, offer Area 2 and Area 3 and leave the value unset until a supported Barangay mapping or manual choice. Preserve a current selection if it remains in the recomputed eligible set; otherwise clear it. Existing edit hydration must preserve saved values.

The existing location_area_cluster_mappings relation can continue to express singleton automatic assignments and the QC requires_finer_location state, but it cannot itself store QC's two eligible options. If no approved configuration/snapshot mechanism can carry that two-choice set without adding schema, a separate Human decision is needed on where that approved eligibility data will live. No speculative storage design is accepted in this review.

### Validation evidence and limitations

- Portal: php artisan test --compact tests/Feature/CustomerMapLocationAutomationTest.php passed: 11 tests, 73 assertions. This verifies existing map automation behavior; it does not prove all requested municipality eligibility behavior.
- Tablet: php artisan test --compact tests/Feature/CustomerCreatePageDiagnosticTest.php produced no output during the observed wait and was interrupted. This is an incomplete run, not a reported test failure.
- No code was changed, so no new manual selector, map, QC, customer-edit, or non-NCR regression test was added or claimed as passing.
- No production access, database writes, migrations, seed execution, customer modifications, deployment, Customer Push/Pull changes, commit, or push occurred.
- Both worktrees remain on main with only this Work note untracked; work remains active and non-terminal.

### Human decisions required before implementation

1. Authorize read-only access to the authoritative Portal environment, or provide a verified export, to confirm GMA Areas 1–10 and their IDs/codes under Specific Region 52.
2. If definitions are absent, decide whether to authorize a separate bounded data correction; this work did not create or import any records.
3. Approve how the two eligible Quezon City choices are carried to offline Tablet without changing lookup schema, or separately authorize a schema/data design.
4. Confirm the GMA crosswalk exception for physical Region 2 localities under operational Specific Region 52 / physical parent Region 7.
5. Provide authoritative Quezon City Barangay assignments before automatic Area 2/Area 3 selection can occur.


### Follow-up validation and production evidence request

Additional focused runs after the review section above:

- Portal CustomerLocationMappingTest: 6 passed, 19 assertions.
- Tablet CustomerLocationPickerTest: 2 passed, 15 assertions.
- Tablet CustomerCreatePageSelectsTest: 1 passed, 2 assertions.
- Tablet CustomerLocationMapTest: 2 passed, 2 failed (15 assertions). Both failures are existing address-string expectation mismatches: expected “123 Roxas Street”, received “123 Roxas St, Angeles, Pampanga”. They do not assert Area Cluster behavior. No source code was changed in this work.
- Tablet CustomerCreatePageDiagnosticTest remained without output and was interrupted; no result is claimed.

The local records cannot establish current production contents. Before any production read, obtain separate Human authorization. The following queries are prepared for read-only execution against the authoritative Portal database; they use only SELECT and inspect the grouping, candidate cluster definitions, and NCR municipality decisions:

```sql
SELECT rs.id AS specific_region_id, rs.name AS specific_region_name,
       rs.region_id AS physical_region_id, r.name AS physical_region_name,
       ac.id AS area_cluster_id, ac.code AS area_cluster_code,
       ac.name AS area_cluster_name, ac.enabled
FROM region_specifics rs
JOIN regions r ON r.id = rs.region_id
LEFT JOIN area_clusters ac ON ac.region_specific_id = rs.id
WHERE rs.id IN (1, 52)
ORDER BY rs.id, ac.code, ac.id;

SELECT m.id AS municipality_id, m.name AS municipality_name,
       m.region_id, m.province_id,
       lam.decision, lam.area_cluster_id,
       ac.code AS area_cluster_code, ac.name AS area_cluster_name,
       ac.region_specific_id AS cluster_specific_region_id
FROM municipalities m
LEFT JOIN location_area_cluster_mappings lam
  ON lam.location_level = 'municipality' AND lam.location_id = m.id
LEFT JOIN area_clusters ac ON ac.id = lam.area_cluster_id
WHERE m.region_id = 2
ORDER BY m.id;

SELECT b.id AS barangay_id, b.name AS barangay_name,
       b.municipality_id, m.name AS municipality_name,
       lam.decision, lam.area_cluster_id,
       ac.code AS area_cluster_code, ac.name AS area_cluster_name,
       ac.region_specific_id AS cluster_specific_region_id
FROM barangays b
JOIN municipalities m ON m.id = b.municipality_id
LEFT JOIN location_area_cluster_mappings lam
  ON lam.location_level = 'barangay' AND lam.location_id = b.id
LEFT JOIN area_clusters ac ON ac.id = lam.area_cluster_id
WHERE m.region_id = 2
ORDER BY m.id, b.id;
```

No production query was executed. The Tablet map test mismatch is recorded as an outstanding unrelated baseline discrepancy and should be triaged separately if that suite must be green for delivery.

## Code-only compatibility feasibility — 2026-10-16

Human confirms that the previously inspected Portal database is the production dataset for this Work. Treat the earlier Portal inventory as authoritative production data. This review did not connect to or query production again. The Tablet SQLite dataset remains a local offline snapshot.

### Verified Area Cluster relationships

| Business code | Legacy Specific Region 1 | Operational Specific Region 52 |
| --- | --- | --- |
| gmaarea1 through gmaarea5 | Existing definitions, rows 1–5 | No definitions |
| gmaarea6 | Existing legacy definition, row 6 | Existing operational definition, row 16 |
| gmaarea7 through gmaarea10 | Existing definitions, rows 7–10 | No definitions |
| gmaarea12 | No legacy definition noted | Existing operational definition, row 17 |

The row numbers above are database primary keys from the inspected data; business Area codes must be resolved by the stored code, never by interpreting the primary key as the Area number. Existing customer references to legacy rows 1–10 and operational rows 16–17 must remain unchanged.

### Feasibility verdict: explicit compatibility is not safe end to end

A code-only eligibility map is technically expressible: key each province-less Metro Manila municipality by its existing municipality identity, map it to approved business codes, and resolve those codes to enabled Area Cluster rows. For a single code, select its resolved cluster; for Quezon City, return Area 2 and Area 3 and leave the value unset until manual choice or authoritative Barangay evidence. Area 6 should resolve to the operational ID 16 row when its code is requested, since that definition already belongs to ID 52.

However, for Areas 1–5 and 7–10 the only available matching rows belong to Specific Region 1. Selecting one while the customer uses operational Specific Region 52 creates the persisted pair (region_specific_id 52, area_cluster_id owned by 1). Current code rejects that pair at multiple boundaries:

- Portal Area Cluster options query only the selected Specific Region. Portal form save validation and TabletCustomerPushService require the selected cluster to belong to the submitted Specific Region.
- Tablet customer validation, option membership, and final save validation apply the same exact-owner rule. A Tablet customer using a legacy cluster with Specific Region 52 would fail locally; if permitted locally, Portal push would still reject the customer.
- The existing mapping resolver returns the cluster owner as the resolved Specific Region. GMA Area 1–5 and 7–10 legacy rows therefore resolve to ID 1, which is marked legacy and is not the approved operational ID 52. For NCR geography, physical-parent equality also rejects the 52/Region 7 versus NCR/Region 2 relationship unless a narrow approved crosswalk is applied.
- Portal publishes Area Cluster rows with their original owner and stable ID. Tablet sync preserves that ownership; sync cannot turn a legacy-owned cluster into an ID 52-owned cluster without changing lookup data. Customer rows have independent foreign keys, but application validation enforces the pair consistency.

Changing only selectors or an in-memory alias does not solve the persistence and Tablet-to-Portal sync rejection. Keeping customer.region_specific_id at 1 would make the pair pass current exact-owner checks but would not persist the approved operational grouping 52. Therefore a full code-only compatibility implementation using the legacy definitions is not safe or consistent while Customer Push/Pull remains unchanged.

The compatibility idea could be revisited only with an explicit cross-owner persistence contract across Portal forms, Tablet forms, server validation, customer sync, and saved-data semantics. That is broader and riskier than the allowed scope, and is not recommended.

### Smallest safe implementation path

Use the existing schema and preserve legacy rows:

1. Separately authorize adding the nine missing operational definitions under Specific Region 52 for business codes gmaarea1–gmaarea5 and gmaarea7–gmaarea10. Keep legacy rows 1–10, ID 16 / Area 6, and ID 17 / Area 12 unchanged. Existing uniqueness by (region_specific_id, code) supports these duplicates without a new schema migration, but creating them is a database modification and remains on hold.
2. Carry municipality eligibility as a bounded code configuration in both repositories, keyed by the existing municipality identity and listing business codes. Resolve each code to an enabled Area Cluster owned by ID 52. No new eligibility table or lookup contract section is needed for this approach; Tablet already receives the same Portal Area Cluster IDs and codes through its lookup snapshot.
3. Apply one shared resolution contract to manual location changes and Pick on Map: singleton eligible set selects that exact resolved row; Quezon City offers only Areas 2 and 3 without automatic assignment; preserve a current cluster only if it remains eligible after a locality change, and otherwise clear it. Do not infer a Barangay split.
4. Add a narrowly scoped GMA/NCR physical-versus-operational validation rule while keeping physical Province/Municipality/Barangay hierarchy checks and ordinary group validation unchanged. Once cluster ownership is ID 52, existing exact-owner customer persistence and Customer Push/Pull validation remain valid and need no changes.
5. Preserve existing saved cluster IDs on edit hydration. Do not migrate existing legacy customer rows to new operational IDs.

No code or database change is authorized in this review. The separate database authorization in step 1 is a prerequisite for the complete mapping and Quezon City choices.

### Portal and Tablet boundaries

- Portal should own the approved municipality-to-business-code configuration and resolve codes against Portal lookup rows. The same versioned mapping must be mirrored into Tablet source code (or otherwise delivered without a contract change) and verified for byte-for-byte parity. This is code duplication risk; add a parity test and keep the crosswalk small and explicitly NCR-only.
- Tablet can resolve the same codes offline from the synced Area Cluster rows. It should fail closed if a code has no enabled row under ID 52, rather than falling back to a row under ID 1. A later normal lookup pull carries newly authorized ID 52 definitions and their stable Portal IDs; no customer push/pull changes or SQLite data rewrite is needed.
- Existing location_area_cluster_mappings may remain unchanged for non-NCR and unrelated province assignments. For NCR, the municipality code mapping provides singleton eligibility; Quezon City remains an unresolved/manual two-choice case. Do not write or seed mapping rows as part of this feasibility task.

### Focused regression scenarios and reversible execution

When separately authorized for implementation and the required ID 52 definitions exist, test:

- Every supplied municipality maps by its authoritative municipality identity to the approved business code.
- Singleton municipalities resolve only to the matching enabled ID 52 cluster and auto-select it.
- Quezon City offers Area 2 and Area 3 and chooses neither automatically; any future Barangay mapping must be within that pair.
- Portal and Tablet manual selection and Pick on Map return the same cluster ID for the same locality.
- Changing Municipality or Barangay preserves an existing choice only while eligible; otherwise it clears it.
- Province-less NCR municipalities, existing customer edit hydration, legacy Specific Region 1 customers, GMA ID 52 persistence, local offline lookup, and unchanged non-NCR behavior.
- Portal save and existing Tablet-to-Portal push accept every permitted ID 52 selection without changes to Customer Push/Pull. This assertion must pass before release.
- Missing or disabled ID 52 definitions produce no fallback to legacy rows; inconsistent locality hierarchies remain rejected.

The reversible code plan is to add one NCR-only mapping config per repository, centralize selection/validation within each existing form and shared location resolver path, leave old customer rows untouched, and avoid migrations or snapshot version changes. Back out only the new config and resolver branches if tests fail. The required Area Cluster definitions need their own reviewed, idempotent database change plan and separate Human approval; never roll back or reparent legacy IDs.

### Remaining approval boundary

The code-only compatibility route with legacy cluster owners is rejected due to Portal and Tablet persistence/sync invariants. To proceed with the recommended safe route, Human approval is still required for the nine ID 52 lookup definitions and for later code implementation. Quezon City Barangay automation additionally requires the authoritative Area 2/Area 3 Barangay crosswalk. The current turn made no database access, writes, code changes, migrations, customer changes, commits, pushes, or deployment.

## Bounded implementation preparation — 2026-10-16

- **Authorization:** Human approved bounded Portal/Tablet implementation and preparation of a data-only correction. Database writes, patch execution, migrations, customer updates, deployment, commit, and push remain held. No database query or write was made for this implementation turn.
- **Lookup prerequisite:** Tablet retains legacy Area Cluster IDs 1–10 under Specific Region 1 and operational GMA Area 6 (ID 16) / Area 12 (ID 17) under ID 52. The Portal patch is prepared to insert only absent operational definitions for Areas 1–5 and 7–10. It has not been run. Tablet must receive resulting IDs/codes through the normal location lookup snapshot before the corrected selector can offer them.
- **Functional behavior:** Mirrored NCR municipality-to-business-code configuration resolves only enabled rows owned by operational Specific Region 52. Row primary keys are looked up by code, not inferred from business Area numbers. Singleton municipality assignments select one actual ID; Quezon City offers Area 2/Area 3 and does not guess a Barangay split. Current selections are retained only while still eligible. Missing operational rows fail closed with no substitution from legacy ID 1.
- **Manual and map paths:** Dependent field changes, Pick on Map hydration, and save validation use the same NCR resolver. The local lookup snapshot validator narrowly accepts approved Region 2 locality → Region 7-parented Specific Region 52 cluster mappings when the cluster business code is eligible for that municipality. Other physical-parent checks are unchanged. Province-less NCR municipality and Barangay validation remains intact.
- **Synchronization boundary:** Only lookup snapshot validation was extended to recognize the approved cross-geography rows. Customer Push/Pull code and contracts were not changed. The future Portal snapshot carries new operational definitions by their stable Portal IDs; Tablet offline resolution stays local.
- **Tests:** Tablet targeted tests passed: 6 tests / 39 assertions, including all 17 mapping entries, actual code-to-ID resolution, Quezon City ambiguity, explicit Barangay mapping, fail-closed missing definitions, NCR manual municipality changes, and NCR map scenarios. A broader run that included the entire diagnostic file stalled and was interrupted; no full diagnostic-suite result is claimed.
- **Limitations / approvals:** The nine-row data patch in Portal must be separately reviewed and executed, then synchronized to Tablet through the normal lookup refresh. Browser Pick on Map click-through and native iPad installation were not independently verified. No database writes, migrations, customer changes, Customer Push/Pull changes, or deployment occurred.

## Terminal delivery — 2026-10-16

- Human authorized separate Portal and Tablet commits and normal pushes to `origin/main`.
- The Portal work note contains the prepared, unexecuted SQL patch. Tablet runtime eligibility remains fail-closed until the approved operational rows arrive through the normal location lookup snapshot.
- Validation evidence is listed above. Complete browser Pick on Map click-through, post-patch lookup synchronization, and native iPad installation remain unverified.
- Commit trailers identify this Work as `TERMINAL_DELIVERED`; hashes are reported outside the commits to avoid self-reference.
