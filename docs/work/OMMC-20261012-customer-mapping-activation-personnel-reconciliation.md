# Customer Access personnel reconciliation — 2026-10-12

**Work:** OMMC-20261012-customer-mapping-activation<br>
**State:** READY_FOR_HUMAN_REVIEW<br>
**Mode:** Phase C read-only reconciliation

This is the mirrored Portal personnel identity manifest. It records candidate identities and the subsequent approved initial REG subset; retained insertion is not authorized.

## Summary

Portal `SELECT DATABASE()` returned `ommc_henri_local_latest`. Portal Users have `id`, `name`, `last_name`, `email`, and `deleted_at`, but no active-status field. All 56 current Users have `deleted_at IS NULL`; login eligibility is unknown. Current mapping counts are 5 geographic dummy pairs, 2 OE/IB dummy pairs, 17 Specific Region mappings, and 99 location decisions. Customer/`customer_user` counts are 18,988/27,141. No writes occurred.

The latest table declares 45 rows but parses to **46**: GMA 8, NL 7, SL 6, VIS 10, MIN 9, OE 2, IB 4. No row was discarded pending Human clarification. Functions remain descriptive and no role/function filtering is used.

See the detailed candidate identity matrix in the Portal Work note: [personnel reconciliation report](../../../ommc2027/docs/work/OMMC-20261012-customer-mapping-activation-personnel-reconciliation.md#identity-matrix). Totals are 11 exact/equivalent, 33 ambiguous, 2 missing, 0 soft-deleted. Exact candidate IDs: 5, 19, 26, 28, 33, 38, 41, 44, 45, 48, 49. The Human has approved the 11 exact identities for the initial geographic subset; 33 ambiguous and 2 missing remain deferred. Gerald Pastoral IDs 53 and 62 are distinct.

The approved initial subset is 11 geographic assignments, with no OE/IB assignments. The current 7 dummy rows are temporary and have been replaced in the retained local Portal DB after fresh backup and exact preflight. Customers, `customer_user`, 17 Specific Region mappings, and 99 location decisions were preserved. The transaction seeder is repeatable; post-commit rollback should use only an exact reviewed inverse after safeguarding later activity.

Portal disposable integration tests validated actor-plus-mapped Add and persistence after updating a stale test expectation: 5 tests/178 assertions; latest focused Portal mapping suites 62/599. Tablet Add/Edit plus strict snapshot 53/417. These are synthetic only, not live authenticated Tablet E2E. Remaining gates are the 33 ambiguous and 2 missing identities, account login eligibility, and Tablet reference Pull/manual form validation.

## Human decision update — approved initial subset (2026-10-12)

The Human approved these 11 exact identity matches as the initial geographic REG membership: GMA→5; NL→33,48; SL→26,38,49; VIS→41,44,45; MIN→19,28. The verified local records match ID, trimmed stored name/surname, email, and are not soft-deleted. No account-active field exists, so login eligibility remains unknown. The other 33 ambiguous and 2 missing roster entries are deferred; no accounts will be created. No OE/IB assignment is approved.

The Portal retained database replacement was authorized and completed as detailed in the mirrored Work note. The exact seven dummy pairs were replaced with the approved 11; the five geographic groups are complete and OE/IB are empty/incomplete. This entry is now superseded by the following execution evidence.

## Retained-local execution evidence

Preflight confirmed local APP_ENV, loopback MySQL, exact database name ommc_henri_local_latest, exact dummy pairs, and all 11 approved User identities. A fresh database-scoped dump was verified before the transaction: 147,056,684 bytes, mode 0600 in mode 0700 directory, SHA-256 c96542bf3bc14dddc429232750568c82d7583244e356a459a7ae9a6b298d2c02; it had 107 table definitions and a completion footer. The dump contains GTID metadata and is not a server-wide GTID backup; see the Portal Work note for recovery cautions and path.

Only the reviewed Portal seeder ran, and it was run twice. The second pass made no additional assignment changes. Final counts: geographic User assignments 11; OE/IB assignments 0; five geographic groups complete; OE and IB incomplete; Specific Region mappings 17; location decisions 99; Customers 18,988; customer_user 27,141; Users 56. No Tablet database write, Pull, migration, provisioning, deployment, staging, commit, or push occurred. Tablet must refresh references through the supported Pull before offline mapping use. Live Tablet E2E and physical device validation are not claimed.
