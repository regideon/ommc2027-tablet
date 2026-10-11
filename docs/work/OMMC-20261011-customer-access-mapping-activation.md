# OMMC-20261011-customer-access-mapping-activation

- **State:** TERMINAL_DELIVERED (inactive foundation only)
- **Mode:** Bounded local database execution
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Human authorized the Portal foundation seeder against the named local database. No manual Tablet cache population, production access, deployment, staging, commit, or push.

## Tablet scope

No Tablet application/schema change is proposed in this preparation phase. Once reviewed Portal configuration is approved and synchronized, Tablet should receive it through the existing reference Pull and validate/cache it through the existing offline mapping contract. Do not manually populate the Tablet cache.

## Accepted Region Type architecture decision

Human accepts that `region_types` remains a User/team and sales workflow classification. Do not add a Region Type dropdown to Customer Add/Edit and do not use `users.region_type_id` to resolve Customer Access. The Tablet Customer contract remains physical Region for location, `region_specific_id` for geographic Access, Customer Access Groups as mapping scopes, and OE/IB Company for Company-scoped Access.

## Prepared Portal configuration

The Portal Work note and `CustomerAccessMappingFoundationSeeder` prepare five incomplete geographic Access Group containers (GMA, NL, SL, VIS, MIN) and two incomplete Company configuration containers (OE, IB). The seeder creates no User assignments or Specific Region mappings and is not registered in the normal all-database seeder. All completeness flags are explicitly false.

The seeder refuses to run if any Specific Region or User assignment rows already exist, protecting later configuration from being reset by a rerun.

The reviewed Portal execution preview is exactly seven records: five `customer_access_groups` rows (GMA, NL, SL, VIS, MIN) and two `customer_company_access_configurations` rows (OE Company ID 5 and IB Company ID 6). All seven have `is_complete=false`; no Specific Region mapping, User assignment pivot, or `customer_user` row is added or changed. Incomplete configuration is non-applicable: automatic Access replacement remains disabled, and manual Access is preserved.

## Authorized execution evidence — 2026-10-11

Portal ran only `CustomerAccessMappingFoundationSeeder` against `ommc_henri_local_latest` after verifying the database name, five empty mapping tables, and OE/IB Company IDs 5/6. The seeder succeeded. It created five groups (IDs 1–5: GMA, NL, SL, VIS, MIN) and two Company configurations (OE company 5/config 1; IB company 6/config 2), all `is_complete=false`. The three Region/User assignment tables remain empty. Portal verified `customer_user` 27,141 rows, Users 56 rows and their max `updated_at`, and Customers 18,988 rows and their max `updated_at` unchanged. Resolver checks for geographic Specific Region 1 and OE/IB returned incomplete with no User IDs, so automatic replacement remains disabled. No Tablet data/cache was changed.

Portal disposable validation passed: **3 focused tests, 18 assertions** on PHPUnit's SQLite in-memory database. It verified the exact five Group codes, idempotency, inactive status, no assignment rows, no `customer_user` changes, incomplete resolution, rollback when a required Company is missing, and refusal to reset configured mappings. Tablet application code and cache were not changed.

## Personnel data gate

The latest Human-supplied table is retained as the verified **46-row candidate baseline**; its stated total of 45 was a counting error. It matches the earlier 46-row table row-for-row. All candidate identities and assignments remain unapproved; the full candidate matrix and unresolved Gerald/Natalio/Josie/Vincent cases are recorded in the mirrored Portal Work note. Do not create or cache User assignments until identities are explicitly approved.

## Remaining dependencies

- Explicit confirmation of candidate User identities and assignments.
- Approved geographic Specific Region crosswalk after missing reference restoration.
- Keep local configuration inactive until mapping and identity approvals and a separate activation authorization are complete.
- Normal Portal reference Pull and disposable Tablet-to-Portal-to-Tablet validation after configuration approval.

No Tablet cache data was written and no production activation is claimed.

## Foundation terminal review

The inactive foundation implementation is complete for delivery: Portal seeder plus disposable regression tests, with the mapping schema/resolver and Tablet cache contract already delivered in the predecessor Customer Access foundation commit. In this terminalization run, the combined Portal focused suites passed **21 tests / 94 assertions**, including the seeder suite (**3 tests / 18 assertions**); Pint passed. The existing seven local configuration rows remain incomplete and no Access assignments or Specific Region mappings were added.

## Foundation terminal delivery (2026-10-12)

The inactive configuration foundation was delivered in Portal commit `247f3eb` and Tablet documentation commit `adf8bec`; both were pushed successfully to the existing `origin/main` branches. The Portal implementation commit contains only the inactive seeder, its disposable test, and its mirrored Work note. No Tablet application or cache data changed.

**Current Work state: `TERMINAL_DELIVERED` for the inactive foundation only. Real Customer Access activation is not complete or claimed.** Candidate identities remain unapproved; the Specific Region-to-group mappings and real User assignment pivots remain empty. The seven retained local configuration rows remain incomplete. No Users were provisioned, no Tablet cache was manually populated, and no production operation occurred.
