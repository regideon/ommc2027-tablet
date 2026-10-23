# OMMC-20261023-customer-location-map-name-resolution

- **State:** READY_FOR_TERMINAL_REVIEW
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Human authorized Tablet + Portal implementation of the Pick on Map resolver correction. No commit, push, deployment, database write, migration, seeder, or Customer Push/Pull change is authorized. Terminal delivery requires separate explicit Human authorization.
- **Objective:** Correct reverse-geocoder matching so NCR locations whose provider labels differ from local reference names still fill City / Municipality, Barangay, Specific Region, and Area Cluster.

## Investigation

- The Tablet local snapshot `database/database.sqlite` was inspected read-only. Its location-reference snapshot completed at `2026-10-19T07:53:28Z` under contract version 3.
- The supplied business table matches the snapshot for all 17 NCR municipalities. GMA Area 1–10 and Area 12 definitions exist and are enabled under operational Specific Region 52. Quezon City has 142 barangay mappings: 80 to GMA Area 2 and 62 to GMA Area 3.
- Province rows match the supplied table except Negros Occidental, Negros Oriental, Siquijor, and Sulu. Those remain `unresolved` because the approved VIS/MIN clusters have a different physical Region parent (NIR/BARMM). No lookup data was changed; a business decision remains required.
- For the screenshot coordinates `14.6014481, 121.0284805`, Nominatim returns `city=San Juan`, `quarter=Tibagan`, and `region=Metro Manila`. The existing resolver returned all location IDs null.
- First root cause: the provider label `Metro Manila` did not match the canonical `National Capital Region (NCR)` Region. The only `Metro Manila` Region row is legacy and has no PSGC code, so it is excluded. With Region null, the municipality lookup searched nationwide; seven municipalities are named San Juan, so uniqueness failed.
- Second root cause: provider `city=Pasay` did not match the local `Pasay City` row because the normalizer removed only the `City of` prefix, not the trailing `City` suffix.
- Neither failure was caused by missing lookup rows, cluster definitions, or seeders. No new seeder is required for this correction.

## Implementation

- Added `config/customer_location_lookup.php` with normalized provider Region aliases. `metro manila` maps to the canonical NCR PSGC code `1300000000`.
- Added `PhilippineAddressResolver::matchRegion()`, which uses the alias only when normal Region matching fails.
- `PhilippineAddressResolver::match()` now performs exact matching first. Only when no exact match exists does it retry with a trailing `City` suffix removed from both provider tokens and reference candidates. Exact matches retain precedence.
- Added a regression test that runs all 17 NCR municipalities through the resolver and asserts the exact municipality, physical Region 2, Specific Region 52, and expected GMA Area cluster. Quezon City uses a barangay mapping, San Juan uses Tibagan, and Pasay covers the `City` suffix fallback.
- The resolver and configuration changes are mirrored in both repositories.

## Relationship audit

- The same read-only audit was run against the Portal `ommc2027` database and the Tablet snapshot. Both contain 19 regions, 18 specific regions, 53 area clusters, 85 provinces, 1,643 municipalities, 42,010 barangays, and 241 location mapping rows. All relationship results below matched between the two databases.
- All 17 NCR municipality relationships were checked: municipality enabled, physical Region 2, province null, approved GMA code, enabled cluster under operational Specific Region 52, and resolver result. All 17 passed.
- Cluster evidence by business area: Area 1 → Caloocan/Malabon/Navotas/Valenzuela (cluster 50); Area 4 → Mandaluyong/San Juan (53); Area 5 → Manila (54); Area 6 → Marikina (16); Area 7 → Makati/Pasay (55); Area 8 → Las Piñas/Muntinlupa (56); Area 9 → Parañaque/Taguig (57); Area 10 → Pasig/Pateros (58); Quezon City → Area 2 (51) or Area 3 (52) by barangay.
- All 142 Quezon City barangay mappings are valid: every barangay belongs to municipality 14, every decision is `mapped`, and every target is an enabled Area 2 or Area 3 cluster under Specific Region 52. Split: 80 Area 2, 62 Area 3.
- All 76 mapped province relationships match the supplied table, including cluster label, enabled state, and physical Region parent equality. The four previously known exceptions remain `unresolved`: Negros Occidental (VIS Area 2), Negros Oriental (VIS Area 3), Siquijor (VIS Area 3), and Sulu (MIN Area 6). Maguindanao del Norte and del Sur both map to MIN Area 4.
- `provinces.region_specific_id` is null for 82 of 85 province rows; only three legacy NCR province rows carry a value. This denormalized column is not used by the resolver or the location selector, so it has no functional effect on these mappings.
- The Specific Region selector is the physical-region selector. For GMA it intentionally allows Region IDs 1 (legacy Metro Manila alias), 2 (canonical NCR), and 7 (Region IV-A for Cavite/Rizal). The screenshot's `National Capital Region (NCR)` selection is correct for San Juan. The legacy `Metro Manila` option remains available and is not a mapping failure.
- Legacy physical Region 1 has no enabled province and no province-less enabled municipality in either database; its only municipality is the old Quezon City row with a province parent that is not selectable. Selecting `Metro Manila` on Add is therefore a dead-end. Hiding Region 1 from new Add selections while preserving it for Edit hydration is a separate UI decision.
- Live resolver check using the screenshot coordinates `14.5993808, 121.0264206`: Region 2, municipality 15 (San Juan), barangay 1616 (Batis), Specific Region 52, Area Cluster 53 (GMA Area 4).

## Validation

- Tablet focused suites `tests/Feature/PhilippineAddressResolverTest.php` and `tests/Feature/MetroManilaAreaClusterSelectionTest.php`: 14 passed, 135 assertions.
- Portal focused suites `tests/Feature/PhilippineAddressResolverTest.php` and `tests/Feature/MetroManilaAreaClusterSelectionTest.php`: 13 passed, 133 assertions.
- The new all-17 NCR resolver test alone: 1 passed, 85 assertions in each repository.
- Read-only runtime check against the Tablet snapshot with live Nominatim responses:
  - `14.6014481, 121.0284805` → Region 2, San Juan municipality 15, Barangay Tibagan 1632, Specific Region 52, GMA Area 4 / cluster 53.
  - `14.5378, 120.9944` → Region 2, Pasay municipality 12, Specific Region 52, GMA Area 7 / cluster 55.
- Existing Tablet `CustomerMapLocationAutomationTest` retains three pre-existing failures: explicitly unresolved mapping preservation, partial-geocoder preservation, and the Add redirect. The same three fail on the unmodified baseline and are unrelated to this change. They are not claimed as fixed.
- `vendor/bin/pint --dirty --format agent` passed in both repositories; `git diff --check` passed in both.
- A full-suite attempt was stopped: Tablet exhausted its 128 MB PHP memory limit in an unrelated `CustomerCreatePageDiagnosticTest` live-render test. No full-suite result is claimed.

## Owned files

- Tablet: `app/Services/PhilippineAddressResolver.php`, `config/customer_location_lookup.php`, `tests/Feature/PhilippineAddressResolverTest.php`, and this note.
- Portal: `app/Services/PhilippineAddressResolver.php`, `config/customer_location_lookup.php`, `tests/Feature/PhilippineAddressResolverTest.php`, and this note.
- No database tables/rows, seeders, migrations, Customer Push/Pull, or deployment were changed.

## Remaining decisions

- The four unresolved province assignments need explicit business confirmation and a separately authorized data correction before they can auto-select on the map.
- Terminal delivery (commit/push) requires separate explicit Human authorization.
