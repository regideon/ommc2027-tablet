# OMMC-20261011-customer-add-form-consistency

- **State:** TERMINAL_DELIVERED
- **Mode:** Bounded implementation
- **Predecessor:** OMMC-20261011-customer-region-access-automation (TERMINAL_DELIVERED)
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **Authorization:** Human GO for this bounded local implementation. No retained database writes, PROD access, deployment, User provisioning, mapping activation, reference restoration, staging, commit, or push.

## Objective and accepted behavior

Align Portal and Tablet Customer Add/Edit presentation without changing Customer Access mapping architecture, persistence, or synchronization precedence.

- Portal suppresses mapping guidance until a Company is selected; after selection it uses the current resolver message for geographic or OE/IB Customers.
- Tablet Access is a searchable multi-user checkbox selector backed by all locally available User rows. It preserves Add's current-user default, Edit hydration, manual choice, and offline operation. No role/name/dummy-prefix filtering is introduced.
- Tablet Edit visibly identifies a saved Company or Specific Region ID that is absent from local reference data. It retains the hydrated ID and Access selection and does not fabricate a reference row.
- Existing mapping resolver behavior, same-value controller behavior, `customer_user` persistence, and Push/Pull contracts are unchanged.

## Implementation inventory

**Portal**
- `app/Filament/Resources/Customers/Schemas/CustomerForm.php`: return no Access helper text while Company is unselected.
- `tests/Feature/CustomerAccessMappingFormTest.php`: cover blank Company guidance suppression and geographic/OE guidance after selection.

**Tablet**
- `app/Filament/Pages/CustomerCreatePage.php`: detect saved Company and Specific Region IDs with no corresponding local reference row for rendering.
- `resources/views/filament/pages/customer-create-page.blade.php`: searchable local User multiselect, selected User labels, mapping warning, and explicit unavailable-reference labels/messages.
- `tests/Feature/CustomerCreatePageDiagnosticTest.php`: cover searchable multiple selection, unchanged Add defaults, and missing-reference Edit hydration preservation.

No schema, mapping data, User data, or sync contract was changed.

## Runtime diagnostics

The source contains the accepted no-controller resolver behavior, but the iPad's running build was not inspected and no deployment was performed.

To verify the runtime when separately authorized:
1. Capture the exact deployed release/build identifier from deployment metadata or the release manifest. If the deployed release directory contains its Git checkout, record `git -C <release-directory> rev-parse HEAD`; compare it with the approved Tablet commit. A local branch SHA alone does not prove what the iPad is running.
2. Connect Safari Web Inspector to the iPad page, inspect the Customer form's enclosing Livewire root, and inspect its `wire:snapshot` attribute. Parse the JSON and record `data.company_id` and `data.region_specific_id` (and the component identity) at the time the warning appears. This distinguishes genuinely blank controllers from retained form state.
3. Do not use this procedure to change runtime data or deploy a build under this Work.

## Validation

Tests ran with the repositories' PHPUnit configuration using disposable SQLite in-memory databases.

- **Portal:** `php artisan test --compact tests/Feature/CustomerAccessMappingFormTest.php` — **18 passed, 76 assertions**.
- **Tablet:** `php artisan test --compact tests/Feature/CustomerCreatePageDiagnosticTest.php` — **37 passed, 296 assertions**.
- **Pint:** `vendor/bin/pint --dirty --format agent` passed in both repositories.
- **Whitespace:** `git diff --check` passed in both repositories.

The Tablet suite also covers existing no-controller/no-cache warning behavior, manual Access preservation, Edit hydration, and same-value controller updates. Browser interaction on a physical iPad and running-build identity remain unverified.

## Limitations and deferred items

- The unavailable-reference indicator preserves IDs and Access in hydrated form state. Existing save validation still requires valid local Company/Specific Region references; an Edit save with a missing required Company reference remains blocked by existing validation until reference data is restored. No ID is silently substituted or cleared by this change.
- Real Access mapping activation and approved identity assignments remain deferred to the predecessor Work's data gates.
- Missing Specific Region references, orphan Area Cluster parents, NCR/Region IV-A/NIR reconciliation, and Province/Municipality automation remain dependencies of the separate Location Automation Work.
- Dummy salesrep records observed in local databases are database rows, not hardcoded form fixtures. Their origin is unknown; this Work does not filter or clean them.
- No production activation, real-data E2E, or iPad deployment is claimed.

## Terminal readiness

The Human authorized terminal delivery. Final focused validation in this terminalization run passed: Portal `CustomerAccessMappingFormTest` plus the Access foundation seeder suite: **21 tests / 94 assertions**; Tablet `CustomerCreatePageDiagnosticTest`: **37 tests / 296 assertions**. Pint passed in both repositories. No tests used retained databases. No physical iPad or deployed-build validation is claimed.

## Terminal delivery (2026-10-12)

The Human authorized terminal delivery. Portal commit `a3435ff` and Tablet commit `646a38f` were pushed successfully to their existing `origin/main` branches. No retained database writes, production access, mapping activation, User provisioning, deployment, or physical iPad validation occurred.

**Current Work state: `TERMINAL_DELIVERED`.** Real Access mapping activation and location-reference restoration remain deferred.
