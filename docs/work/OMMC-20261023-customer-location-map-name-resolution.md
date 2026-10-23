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
