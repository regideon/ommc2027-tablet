# OMMC-20261001-ipad-customer-form-alignment

## Work state

`ACTIVE`

## Investigation scope

## Local-first location baseline implementation

The Work now uses a repository-owned canonical location baseline generated
from the Portal reference tables. The Tablet artifact is
database/reference/tablet-location-baseline.json.gz, version 2026-10-01, and
contains only location reference data. The existing startup migration gate
applies it through LocationReferenceBaselineService after schema migrations.
A reference_baseline_versions marker makes the import idempotent and
retained-device safe; imports use canonical IDs, chunked upserts, and never
delete Customers, users, or other business data.

The Portal export command is tablet:export-location-baseline. The canonical
export contains 19 Regions, 18 Specific Regions, 85 Provinces, 1,643
Municipalities, 44 Area Clusters, and 42,010 Barangays. Area Cluster and
Barangay remain ordinary authenticated sync refresh datasets; the baseline is
their offline bootstrap, not a second identity system.

Customer Code remains Portal-authoritative. Add Customer now records safe
request/result lifecycle diagnostics without logging credentials, tokens, or
the returned code. No local allocator was added.

The accepted sync ordering remains Portal users -> Customers ->
customer_user. Physical and commercial location selectors remain independent.

## Implementation validation

- Portal export generated a 377,779-byte gzipped JSON artifact with canonical
  counts 19/18/85/1,643/44/42,010.
- Tablet baseline applied successfully, then reported CURRENT on the second
  application; local SQLite counts match the artifact.
- Pending offline Customer preservation and representative physical/commercial
  selector chains passed focused coverage.
- Customer Code online success, failed-request safety, and Company-change
  reservation replacement passed focused coverage.
- Accepted customer-user ordering regression passed.
- Blade cache, PHP syntax, Pint, and git diff --check passed.

No commit or push was performed. Physical-iPad retest remains required.

## Physical-iPad form runtime investigation

The local-first baseline is present locally and the exact Livewire component
render was exercised against it. Region 7 (Region IV-A/CALABARZON) returns 5
Province options, Province 26 (Batangas) returns 34 Municipalities, Municipality
444 returns 21 Barangays, and Specific Region 52 returns 2 Area Clusters. The
rendered Livewire HTML contains representative option labels at each step.

The current source therefore has no reproduced query, state, or Blade-option
defect for this chain. The physical report of placeholders and “Generated
after sync” requires safe runtime diagnostics from the actual iPad build to
distinguish stale deployed assets, device database state, or a reservation
request failure. Customer Code remains Portal-authoritative; the component
tests prove the hook assigns both returned values at the network boundary.

The inspected iOS runtime source invokes `app:startup-migrate` before the
WebView loads, uses the application-support SQLite path, and the current
`nativephp/ios/NativePHP/app.zip` contains the exact baseline artifact with a
matching SHA-256 and valid gzip content. The baseline importer now also checks
all six reference-table row counts when the marker is already current; a
current marker with an incomplete table re-applies the canonical rows. Focused
coverage proves this retained-device self-healing path and the physical-region
selector chain. Direct inspection of the physical iPad database remains
unavailable in this environment, so an on-device retest is still required to
capture its startup artifact/trace if the issue persists.

This is a read-only comparison of the current Portal V2 Customer form/API
contract and the current Tablet V2 Add Customer/offline/sync implementation.
No application behavior, schema, styling, Portal code, or sync code was
changed. The preceding Tablet field-border Work remains terminal at
`b406d5b887ab017c1dcb19b962b2cc278d93a901`.

Primary evidence inspected:

- Tablet `app/Filament/Pages/CustomerCreatePage.php`,
  `resources/views/filament/pages/customer-create-page.blade.php`,
  `app/Services/CustomerProfileFormService.php`,
  `app/Services/SyncService.php`, customer migrations/models, and
  `config/customer_trade_form.php`.
- Portal `app/Filament/Resources/Customers/Schemas/CustomerForm.php`,
  `app/Filament/Resources/Customers/Pages/CreateCustomer.php`,
  `app/Http/Controllers/Api/SyncController.php`,
  `app/Services/CustomerCodeAllocator.php`,
  `app/Services/CustomerProfileService.php`, customer migrations/models, and
  `config/customer_trade_form.php`.

## Portal V2 reference inventory

The current Portal Customer Create form contains:

- Customer Information: name (required), Customer Code (automatically
  reserved for supported companies), Active, Company, Access, derived RSM
  display, Person in Charge for outlet, General Category, conditional
  Competitor Volume, Address, Contact Person, Business Landline Number,
  Business Mobile Number, and Date Established.
- Location: map view, physical Region, Specific Region, Area Cluster,
  Province, City / Municipality, Barangay, Latitude, and Longitude. Physical
  Region is form state used to constrain the hierarchy; Province, City /
  Municipality, Barangay, and commercial IDs are persisted as applicable.
- Company/profile-specific sections selected by
  `Company::profileTypeForCode()`: Outlet, Fleet, OE, or IB.
- Outlet: Entry Detail, Classifications, Conversion Program, Working Days,
  Opening/Closing Time, MOTIV User, conditional Warehouse Code, Delivery Type,
  conditional Delivery Detail, ULAB, AB/MCB annual categories, and Owner
  Profile.
- Fleet: Type, Classification, Battery Class, Status, Fleet annual
  categories, and Owner Profile.
- OE: Entry Detail, Classification, Battery Class, conditional Sulfuric Acid,
  OE annual categories, and Owner Profile.
- IB: Classification, IB annual categories, and Owner Profile.
- Owner Profile: Name of Owner, Birthday, Nickname, Successor Name,
  Successor Birthday, Relationship with the Owner, Generation, and Hobbies.

The current Portal form does not render `serving_outlet_id`, DRM/RSR user
selection, or the legacy brand fields even though related config/model/API
structures still exist. Those are not automatically Tablet gaps for this
Work.

## Company/profile behavior

The actual current mapping is:

| Company | Portal/Tablet profile |
| --- | --- |
| OMMC | outlet |
| LAST_MILE | outlet |
| CAR_CLUBS | outlet |
| FLEET | fleet |
| OE | oe |
| IB | ib |

Tablet uses the same map and renders the same broad profile sections. The
profile-specific field sets are substantially aligned for fields currently
rendered. The important conditional mismatch is Outlet Entry Detail: the
Tablet offers `AB/MCB`, but its annual-category visibility logic does not
consistently recognize that token. The Portal/API code also contains a token
inconsistency: current form config uses `AB/MCB`, while the sync controller's
stream expectation explicitly checks `AB and MCB`. A follow-up implementation
must choose a canonical wire value or add an explicit compatibility mapping;
this is a product/contract decision, not something to infer silently.

## Field-by-field alignment matrix

Status meanings use the requested categories. “Local/sync” summarizes whether
the Tablet has a safe local representation and whether the current Tablet
push includes the value.

| Portal field / backing key / control / rule | Tablet equivalent and behavior | Local/sync | Status |
| --- | --- | --- | --- |
| Store/Account Name `name`, text, required | `name`, text, required; label changes by profile | customer column / sent | ALIGNED |
| Customer Code `unique_id`, reserved/disabled for supported companies | read-only field; online Company selection requests Portal reservation, offline state remains blank until sync | code plus reservation token / reservation token sent and authoritative response reconciled | ALIGNED |
| Active `is_active`, toggle/default true | same toggle/default true | column / sent | ALIGNED |
| Company `company_id`, searchable select, required | same ID/select, required | column / sent | ALIGNED |
| Access `users`/pivot, optional select with RSM-relationship rule | `access_user_ids`, optional multi-select with same local rule | pivot exists / not sent by Tablet push | DIFFERENT_BEHAVIOR |
| RSM derived display, not an input | no Add Customer RSM display | derived only / API derives from Access | PORTAL_ONLY |
| Person in Charge `person_in_charge_id`, outlet-visible, schema currently nullable | same outlet-visible select, nullable | customer column / sent | DIFFERENT_BEHAVIOR |
| General Category `general_category_id`, optional live select | same optional live select | column / sent | ALIGNED |
| Competitor Volume `competitor_volume`, visible only for category 1 | same condition and values | column / sent | ALIGNED |
| Address `address`, optional textarea max 500 | same optional textarea max 500 | column / sent | ALIGNED |
| Contact Person `contact_person`, optional text | same optional text | column / sent | ALIGNED |
| Contact Number `contact_number`, API/model-supported but not current Portal Create control | Tablet property/validation/persistence exists but no rendered Add Customer control | column / sent | DIFFERENT_BEHAVIOR |
| Business Landline/Mobile, Date Established | same controls and nullable rules | columns / sent | ALIGNED |
| Physical Region, `physical_region_id`, selector state/dependency | selector exists and filters children, but not stored on Customer | no customer column / not sent | DIFFERENT_BEHAVIOR |
| Province `province_id`, independent-locality-aware selector and persisted ID | selector exists, but Customer save omits it and local schema has no `province_id` | absent / not sent | MISSING_ON_TABLET |
| City/Municipality `municipality_id`, dependent selector/validation | dependent selector and validation; persisted | column / sent | ALIGNED_WITH_DIFFERENT_RULES |
| Barangay `barangay_id`, dependent searchable selector and hierarchy rule | disabled “Unavailable” placeholder; no state or table | absent / not sent | MISSING_ON_TABLET |
| Specific Region `region_specific_id`, select and dependent Area Cluster | selector exists and persists ID; no Area Cluster dependency | column / sent | DIFFERENT_BEHAVIOR |
| Area Cluster `area_cluster_id`, dependent selector and validation | not rendered; no local table/column/model | absent / not sent | MISSING_ON_TABLET |
| Latitude/Longitude, numeric inputs | same numeric inputs | columns / sent | ALIGNED |
| Location map, Portal view component | no equivalent Add Customer map component | not applicable | PORTAL_ONLY |
| Outlet Entry Detail, profile relationship field | same concept; `AB/MCB` category token behavior is inconsistent | profile column / sent | DIFFERENT_BEHAVIOR |
| Outlet Classifications, Working Days, Operating Hours, ULAB | same current controls and profile storage | profile columns / sent | ALIGNED |
| Outlet Conversion Program, Delivery Type/Detail, MOTIV, Warehouse Code | same conditional controls; Tablet stores active JSON | profile JSON / sent | ALIGNED_WITH_DIFFERENT_STORAGE |
| Fleet Type/Classification/Battery Class/Status | same controls and active JSON | profile JSON / sent | ALIGNED |
| OE Entry Detail/Classification/Battery Class/Sulfuric Acid | same controls and active JSON | profile column/JSON / sent | ALIGNED_WITH_DIFFERENT_STORAGE |
| IB Classification | same control and active JSON | profile JSON / sent | ALIGNED_WITH_DIFFERENT_STORAGE |
| Annual categories | same profile streams/years/options in principle | category history / sent | DIFFERENT_BEHAVIOR |
| Owner Profile fields including Hobbies | same fields under `active.owner` | profile JSON / sent | ALIGNED |
| `serving_outlet_id` | neither current Add Customer form renders it | unavailable by current contract | PORTAL_ONLY |
| DRM/RSR assignment fields | neither current Add Customer form renders them | unavailable by current contract | PORTAL_ONLY |
| Legacy trade brand fields | not rendered by either current Add Customer form | profile columns/API legacy support | PORTAL_ONLY |

The statuses above intentionally distinguish “not in the current Portal form”
from true Tablet gaps. `ALIGNED_WITH_DIFFERENT_RULES` is used descriptively
for the municipality row; the implementation status should be treated as
`DIFFERENT_BEHAVIOR` until the final matrix is converted to the exact allowed
status vocabulary.

## Location findings

The Portal has two hierarchies:

- Physical: Region → Province → City/Municipality → Barangay.
- Commercial: Region → Specific Region → Area Cluster.

Tablet currently has local Region, Province, Municipality, and Region Specific
reference data, but no Area Cluster or Barangay reference table. Its Add
Customer view filters Province and Municipality locally, but renders Barangay
and Area Cluster as unavailable. Its Customer table has no `province_id`,
`barangay_id`, or `area_cluster_id`; it stores only `municipality_id` and
`region_specific_id`. `physical_region_id` is transient form state and is
re-derived from Municipality during edit/hydration. The sync payload sends
Municipality and Specific Region only.

The Portal/API accepts independent-city rows with a nullable Province and
validates Province/Municipality/Barangay membership. Tablet’s current
validation requires a Municipality whenever any physical geography field is
present and cannot represent Barangay or a persisted independent Province
selection. Location alignment therefore requires reference-data, schema,
form, validation, payload, pull, and migration-safety work together.

## Access, RSM, DRM, RSR, and PIC

- Portal Access is optional in the form; if supplied, each selected user must
  have an `rsm_id`. RSM is displayed as derived information from assigned
  Access users and is not a separate Customer input. The API does not require
  Access to exist, but sync validates the relationship when the field is
  present.
- Tablet applies the same local RSM relationship validation and persists the
  Access pivot, but `SyncService` does not include `access_user_ids` in the
  push payload. Access assignments can therefore be lost on server create.
- Portal and Tablet both show Person in Charge for outlet only, but both
  current schemas/API rules leave it nullable despite the config contract
  declaring it required for outlet. Human decision is required before making
  it required.
- No current Add Customer form provides direct RSM, DRM, or RSR selection.
  Do not add those fields without a product decision; RSM is currently
  derived from Access relationships.

## Customer Code findings

Portal/API is authoritative. On sync create, the API allocates the code from
the company sequence and ignores the Tablet-supplied `unique_id` as the
authoritative value. The allocator namespaces match the accepted contract:

- OMMC → `OMMC` width 5, baseline 8738.
- LAST_MILE → `MILE` width 5, baseline 3073.
- CAR_CLUBS → `CARCLUBS` width 5, baseline 1.
- FLEET → `FLEET` width 0, baseline 3728.
- OE → `OE` width 0, baseline 52196.
- IB → `IB` width 0, baseline 53383.

Tablet currently renders Customer Code as editable, stores it locally, sends
it in the push payload, and then marks the row synced using only `server_id`,
`updated_at`, and `category_event_keys` from the response. The API response
does not return the allocated `unique_id`, and Tablet does not update the
local code from the response. A later pull can reconcile it, but the immediate
post-sync local record can remain stale or blank. Tablet must not implement an
independent allocator. The bounded implementation should either reserve via a
Portal/API endpoint before offline save or explicitly treat the code as
server-assigned and reconcile the authoritative code after create; Human
decision is required on whether offline-created records need a pre-sync code
visible on-device.

## Local/offline persistence findings

The current local create lifecycle is:

`CustomerCreatePage` validation → negative local Customer ID + `local_uuid` →
Customer row with `sync_status=pending` → `CustomerProfileFormService::saveAggregate`
for trade/profile JSON, category history, and Access pivot → `SyncService`
push → `markSynced` with server ID/timestamps → future pull reconciliation.

Safe current local storage exists for most common fields, profile columns,
active profile JSON, owner JSON, annual category history, Person in Charge,
and Access pivot. Required alignment storage gaps are:

- no `province_id` on local customers;
- no `barangay_id` or `area_cluster_id` local columns or reference tables;
- no durable physical Region ID (only derived from Municipality);
- no Customer Code reservation token/status or authoritative-code response
  reconciliation field beyond `unique_id` itself; this gap is now closed by
  the additive local reservation-token column and sync contract;
- Access is stored locally but omitted from the push payload and not visibly
  reconciled from pull data.

Any schema work must use the retained-device migration/baseline safety model
already used by the Tablet repository. Do not collapse these changes into a
single unreviewed destructive migration.

## Sync/API contract findings

Tablet currently sends common customer values, `company_id`, General
Category/Competitor Volume, Specific Region, Municipality, contact/address/
coordinates, Person in Charge, profile type, trade profile, profile JSON,
category histories, and category events.

The current API accepts all of those plus `area_cluster_id`, `province_id`,
`barangay_id`, and `access_user_ids`. The Tablet currently does not send the
four missing location/access values. The API therefore cannot receive the
Portal-aligned values even where the future form could collect them.

The API also enforces company/profile matching, Municipality/Province and
Barangay hierarchy, category stream/year/options, MOTIV Warehouse Code,
Delivery Detail, and Access-user RSM relationships. Tablet has partial local
validation but cannot validate unavailable Barangay/Area Cluster references.

On successful push, the API returns `server_id`, `local_uuid`, `updated_at`,
and category event keys. It does not return the authoritative Customer Code;
Tablet stores the ID/timestamp only. On pull, the Portal response includes
Customer `unique_id` and related profile/category data, but protected pending
local rows are skipped. Access pivot data is included in the Portal bootstrap
payload, yet Tablet’s pull path has no observed `customer_user` reconciliation
loop. This is a second Access parity gap for already-synced Customers.

## Existing Customer boundary

The intended boundary is still visible in the Tablet UI: Customer list/detail
is view-only and its Edit link is commented out; Add Customer is the active
creation route. However, an `CustomerEditPage` implementation still exists
and can save local updates if reached directly. No implementation should
expose or expand that route in this Work. Human decision is required only if
future alignment is intended to delete/disable the dormant edit path; that is
outside the Add Customer alignment scope.

## MUST ALIGN NOW

Recommended minimum for a correct current Add Customer flow:

1. Establish the canonical Outlet `AB/MCB` wire token and make Portal/API,
   Tablet visibility, category history, and sync validation compatible.
2. Make Customer Code behavior explicit and Portal-authoritative, including
   post-sync authoritative-code reconciliation; do not allocate independently
   on Tablet.
3. Add end-to-end support for Access assignments in push and pull, preserving
   the existing optional Access/RSM rule.
4. Add end-to-end persisted/synced physical Province, Barangay, and commercial
   Area Cluster support, including Tablet reference data and hierarchy
   validation, if Human confirms those fields are required for Add Customer.
5. Add payload/response contract coverage for every field newly collected so
   values cannot be accepted in the form and then dropped during sync.

## CAN DEFER

- Portal map parity and coordinate capture UX, if existing Tablet coordinate
  behavior is intentionally sufficient.
- Direct RSM/DRM/RSR inputs; current contract derives RSM from Access and does
  not render the others.
- Serving Outlet and legacy brand controls, because neither is in the current
  Portal Add Customer form and current config marks some dependencies
  unavailable.
- Exposing Tablet Edit Customer; preserve the current view-only boundary.
- Refactoring duplicate profile-form code into a shared abstraction; useful
  but not required for contract correctness.

## DO NOT CHANGE

- The terminal Tablet field-border/color Work or its accepted styling.
- Existing offline negative-ID/local-UUID identity and retained-device
  migration safety model.
- Optional Access semantics unless Human explicitly changes the rule.
- Portal Customer Code allocator authority or Portal implementation in this
  investigation.
- Existing Customer list/detail view-only product boundary.

## Decisions recorded for implementation planning

1. Tablet Add Customer will collect, persist, validate, sync, and reconcile
   Province, Barangay, and Area Cluster now.
2. An offline-created Tablet Customer will show Customer Code blank/read-only
   until Portal sync assigns the authoritative code; no local allocator or
   provisional code will be introduced.
3. Person in Charge remains optional for Outlet, matching current Portal/API
   behavior rather than the stale config `required` flag.
4. `AB/MCB` is the canonical current wire/display value. The implementation
   will accept/translate legacy `AB and MCB` at compatibility boundaries so
   category visibility and sync validation agree.
5. The dormant direct Tablet Edit route remains hidden and is deferred from
   this Add Customer alignment Work.

## Suggested implementation order after authorization

1. Lock the four decisions above and write a field/key contract table before
   editing code.
2. Implement canonical profile/category token compatibility and Customer Code
   server-authoritative reconciliation first.
3. Add local schema/reference migrations for confirmed location fields using
   the retained-device safety pattern; update model hydration and validation.
4. Extend Add Customer controls only for confirmed missing fields, preserving
   the accepted styling and current view-only boundary.
5. Extend Tablet push/pull payloads for location and Access, including server
   response reconciliation and conflict/protected-row behavior.
6. Add focused contract/static tests only after implementation authorization;
   manually validate offline create, delayed sync, pull reconciliation, each
   company profile, independent locality, Access assignment, and Customer Code.

## Investigation conclusion

The current Tablet form was not merely missing visual fields. It had a mostly
aligned profile UI but an incomplete persistence/sync contract for location,
Access, and authoritative Customer Code. The bounded implementation closes
those gaps without a visual redesign or broad Portal form rewrite.

## Implementation decisions accepted

- Province, Barangay, and Area Cluster are now in scope for end-to-end Tablet
  Add Customer support.
- Offline Customer Code remains blank/read-only until Portal sync; Tablet does
  not allocate or provisionally generate codes.
- Outlet Person in Charge and Access remain optional; RSM remains derived from
  Access.
- `AB/MCB` is canonical, with legacy `AB and MCB` accepted at compatibility
  boundaries.
- The dormant Tablet edit route remains hidden and out of scope.

## Implementation progress

- Additive Tablet migration/models added for Province, Barangay, and Area
  Cluster references and Customer foreign-key IDs.
- Add Customer form now exposes dependent Area Cluster and Barangay selectors,
  persists the new IDs, keeps independent-locality behavior, and makes
  Customer Code read-only until sync.
- Tablet sync pull/push now carries location IDs and reconciles Access pivot
  assignments; successful push reconciles the API-returned authoritative
  Customer Code.
- Portal sync response now returns `unique_id`; Portal/API category handling
  accepts canonical `AB/MCB` while retaining legacy compatibility.

## Final implementation record

The implementation stayed within the bounded Add Customer contract. It did
not expose the dormant Tablet Edit route, restructure the Portal form, add
map parity, change validation policy beyond the newly persisted geography
relationships, or introduce a local Customer Code allocator. Customer Code
remains blank/read-only offline and is updated from the authoritative Portal
sync response. Person in Charge and Access remain optional; RSM remains
derived from Access.

Validation evidence:

- Focused Tablet feature checks: 6 passed, 103 assertions.
- Tablet PHP syntax checks passed for all changed PHP and the new migration.
- Portal PHP syntax check passed for `SyncController.php`.
- Tablet and Portal Blade view caches completed successfully.
- `git diff --check` passed in both repositories.
- Pint completed on changed PHP in both repositories.

No commits or pushes were performed. The Work is ready for separately
authorized terminal review and delivery.

## Customer Code reservation correction

Human manual review found that blank-until-sync was safe but insufficient for
the client requirement that Tablet Customer Code be system generated from the
selected Company while online. The Work therefore returned to `ACTIVE` for a
bounded reservation correction.

Implementation evidence:

- Tablet Add Customer requests a Portal reservation after Company selection;
  the existing read-only field displays the returned authoritative code.
- The reservation token is stored in the retained local Customer row and sent
  with the later Customer sync.
- Portal exposes only the narrow authenticated sync reservation endpoint and
  reuses `CustomerCodeAllocator`; no Tablet allocator or second sequence was
  added.
- Portal consumes the reservation atomically during create. A retried create
  with the same local UUID reuses the already-created authoritative code,
  preventing a second allocation after a lost response.
- Company changes clear the previous code/token and request a new Company
  reservation. Previously consumed reservations remain consumed; they are not
  released or recycled, so sequence gaps are acceptable for concurrency
  safety.
- If Portal is unreachable or reservation fails, the form keeps Customer Code
  blank with the existing generated-after-sync state and local offline create
  remains available.

Validation evidence for this correction:

- Tablet focused feature checks: 6 passed, 103 assertions.
- Tablet and Portal changed PHP syntax checks passed.
- Tablet and Portal Pint checks passed for changed PHP.
- Portal reservation route appears in `artisan route:list`.
- Tablet and Portal `git diff --check` passed.
- No destructive database lifecycle operation, commit, push, or PROD access
  was performed.

The Work is ready for Human manual review of online reservation display,
offline fallback, Company changes, and delayed/retried synchronization.

## Final manual-review corrections

Human review found two remaining issues: dependent Barangay and Area Cluster
options were not appearing reliably after parent selection, and the raw
multi-select controls were unnecessarily tall in their empty/default state.
The Work returned to `ACTIVE` for these bounded corrections.

Correction evidence:

- Barangay’s parent Municipality binding is now live, so the dependent list
  rerenders immediately. The option query uses the local `barangays` table’s
  canonical `municipality_id`; it does not use names or fuzzy matching.
- Area Cluster options use an explicit local query by canonical
  `region_specific_id`, with live parent/child bindings. The commercial chain
  remains independent from Province, Municipality, and Barangay.
- Existing `SyncService` reference hydration already receives Portal
  `barangays` and `area_clusters` records with their canonical parent IDs, so
  no Portal/API or additional SQLite migration was required for this fix.
- Changing Municipality clears `barangay_id`; changing Specific Region clears
  `area_cluster_id`. Hydration and save/sync continue to use the same IDs.
- Access, Classifications, and Working Days now share the scoped
  `customer-form-multi-select` treatment: compact by default and expanded on
  focus, preserving native multi-select behavior and usability.

Validation evidence:

- Focused Tablet feature checks: 6 passed, 114 assertions.
- Test fixtures verify valid Barangay/Area Cluster inclusion, unrelated-record
  exclusion, parent-change clearing, and local persistence.
- PHP syntax, Pint, Blade view cache, and `git diff --check` passed.
- No Portal files, SQLite migration, destructive operation, commit, push, or
  PROD access was used for this correction.

The remaining item is Human visual/runtime review of the compact multi-select
focus behavior and dependent option lists on representative synchronized data.

## Runtime regression correction

The failed runtime review identified two issues within this Work. The
location controls were relying on a mixture of global Blade collections and
Livewire rerender timing, while Specific Region was not scoped to the selected
Region. The correction now uses explicit local parent-scoped queries:

- Region → Province by `provinces.region_id`.
- Province → Municipality by `municipalities.province_id` and the selected
  physical Region, including the intentional null Province independent-locality
  branch.
- Municipality → Barangay by `barangays.municipality_id` only.
- Region → Specific Region by `region_specifics.region_id`.
- Specific Region → Area Cluster by `area_clusters.region_specific_id` only.

Parent reevaluation preserves compatible child IDs and clears only invalid
children and descendants. No Portal form/API or SQLite migration change was
needed.

Reservation diagnostics now log bounded, secret-free distinctions for missing
configuration/token, authentication failure, unavailable route, non-success
responses, malformed responses, and transport exceptions. Offline fallback
remains non-blocking. The current local Tablet `.env` still has
`SYNC_SERVER_URL=http://127.0.0.1:8000`; physical-iPad testing must configure a
LAN- or HTTPS-reachable Portal URL and refresh Laravel/runtime configuration.
Protected local `.env` state was not modified.

Final validation:

- Focused Tablet feature checks: 7 passed, 129 assertions.
- PHP syntax checks passed for changed Tablet and Portal PHP.
- Pint and Blade view cache passed.
- Portal route listing confirms `POST api/sync/reserve-customer-code`.
- `git diff --check` passed in both repositories.

The Work is ready for another Human runtime review. Runtime verification must
use a reachable `SYNC_SERVER_URL`; localhost is not valid from a physical
 iPad.

## Temporary physical-iPad runtime diagnostics

The latest physical acceptance failed after the supported iOS rebuild, while
local baseline and component evidence remained green. A temporary,
task-owned diagnostic block was added to the Add Customer page. It executes
inside the same Livewire/Laravel runtime and active database connection as the
form, and is not a form-behavior correction.

The block reports only environment, connection name, SQLite path/file
existence, reference-table counts, baseline artifact readability and expected
version, installed marker version, startup baseline status/integrity/repair
flags, selected physical region ID, Region existence/name, raw Province rows
for that region, and the Province options returned by provinceOptions().
Customer records, personal information, credentials, tokens, and Customer Code
values are not exposed. Startup now records the baseline result in the
task-scoped storage/framework/nativephp/location-baseline-status.json file for
the in-app probe to read.

No root cause is claimed from local evidence. Human physical-device capture is
required before any further Customer form correction.

## Physical Livewire probe failure investigation

Human device evidence now shows that the WebView reaches readyState complete,
window.Livewire is an object, Livewire initialization completes, four
components are registered, and no JavaScript/resource errors are reported.
However, tapping the temporary Increment action and editing the live model
produce no request and no DOM morph. The generated device update URI is
php://127.0.0.1/livewire-b9392f1f/update.

The temporary probe now records raw pointer/touch/click events, the exact
wire:click and wire:model.live attributes, wire:id ownership and component
lookup, Livewire directive-init recognition, commit preparation, request
success/failure, DOM morph completion, and JavaScript/resource errors. It is
still domain-neutral and does not modify Customer, location, sync, baseline,
or Customer Code behavior.

Installed source evidence: Laravel 13.23.0, Livewire 4.3.3, Filament 5.7.3,
NativePHP Mobile 3.3.6. Livewire 4.3.3 maps wire actions through Alpine's
event listener, sets an action origin, prepares a commit, then calls fetch()
with the generated update URI. NativePHP's patched PHPSchemeHandler supports
custom-scheme POSTs and reads JavaScript request bodies from
httpBodyStream. No vendor or NativePHP runtime change was made. The first
missing transition on the physical device remains to be identified by the
expanded probe; no compatibility or architecture conclusion is claimed yet.

## Temporary Livewire runtime probe

The physical device also failed to open the temporary diagnostics block, while
native select controls continued to change visually. This adds a second,
domain-neutral probe to Add Customer: an Increment action and a live-bound
text property, plus safe browser-side observability for document readiness,
window.Livewire presence/initialization, registered component count, generated
Livewire update URI, request success/failure, DOM morph completion, and
JavaScript/resource errors. No network, Portal, Customer, baseline, or
Customer Code operation is used by the probe.

Installed dependency evidence is Laravel 13.23.0, Livewire 4.3.3, Filament
5.7.3, and NativePHP Mobile 3.3.6. Livewire auto-injects its assets because
livewire.inject_assets is enabled; the Filament panel layout supplies
Filament styles/scripts and the rendered page contains Livewire wire bindings.
NativePHP iOS sets APP_URL and ASSET_URL to php://127.0.0.1 at runtime, and
the checked-in PHPSchemeHandler supports GET/POST custom-scheme requests,
including streamed JavaScript request bodies. Physical-device runtime results
are not inferred from this source inspection; the probe must be captured on
the iPad.

## Ownership probe correction

Livewire 4.3.3 source confirms that its internal ownership lookup walks
ancestors for the __livewire runtime property and then resolves the component
store; a nearest DOM wire:id selector alone is not the authoritative check.
The temporary probe was corrected to report both nearest DOM wire:id and
runtime __livewire ownership, plus ownership for Region, Province, Company,
Increment, model input, and the diagnostics button. It also lists the four
registered component IDs, names, and root elements when available.

This is diagnostic-only. No Customer page structure or domain behavior was
changed.

## Standalone probe handoff

The temporary diagnostics were removed from the actual Add Customer page after
the investigation showed that the page-specific probe was not a reliable
physical-device control surface. The accepted Customer form, location
behavior, Customer Code behavior, sync behavior, and startup baseline behavior
remain unchanged.

A temporary plain Livewire page now owns the remaining runtime investigation at
`/native-runtime-probe` behind normal authentication. It contains only a
server-side counter action, a live-bound text property with server echo, a
plain JavaScript counter, and safe runtime observability for readiness,
component ownership, DOM events, Livewire commits/requests/morphs, and browser
errors. It does not reference Customer, location, sync, baseline, Portal, or
Customer Code code.

Focused server-side coverage verifies the standalone component action, live
model binding, and authenticated non-Filament route. A physical NativePHP
build/install remains required to exercise the standalone page on the iPad and
capture the runtime transition. No framework, vendor, or NativePHP runtime
patch was made.

## Controlled regression reconstruction and rollback

Human review changed the investigation strategy from expanding runtime
diagnostics to reconstructing the Customer form regression. The repository
history supports the following timeline:

- `b406d5b887ab017c1dcb19b962b2cc278d93a901` is the terminal field-border
  baseline and contains no Area Cluster or Barangay form implementation.
- The current Customer alignment changes are uncommitted; no intermediate
  commit for the physically working alignment state is present in local Git
  history or reflog. The Work note is the available record of that state.
- Area Cluster and Barangay were introduced together with new public form
  state, dependent option methods, parent-clearing handlers, hierarchy
  validation, persistence, sync fields, and new active selectors. Later
  corrections also changed Specific Region and Municipality to live bindings
  and replaced the previously working Blade collection loops with runtime
  option methods.
- The accepted Customer Code reservation path is separate from that location
  slice and remains preserved.

The smallest reversible recovery slice is the Add Customer location DOM and
its directly associated Livewire wiring: Specific Region and Municipality
return to their prior `wire:model`/Blade collection patterns; Area Cluster and
Barangay return to the prior disabled `Unavailable` controls; their Add
Customer option methods and parent-clearing behavior are dormant. The
additive Province persistence and existing Customer Code reservation are
preserved. The Area Cluster/Barangay SQLite columns, reference models,
baseline artifact/importer, retained-device repair, and sync hydration remain
intentionally present and dormant for the next incremental reintroduction.

The temporary standalone probe route, component, view, and probe-only test
were removed. CustomerCreatePage contains no temporary diagnostic UI or
instrumentation. No NativePHP, Livewire, Filament, or dependency change was
made; no retained SQLite database or business data was deleted, and migration
history was not rewritten.

Validation after the rollback is local only. The supported NativePHP rebuild
and physical regression checkpoint are still required before considering this
recovery successful:

1. Open Add Customer.
2. Change Company.
3. Change Region, open Province, and select a Province.
4. Open Municipality and select a value.
5. Change Specific Region.
6. Confirm ordinary existing controls remain interactive.

Area Cluster and Barangay must remain unavailable during this checkpoint. If
the pre-existing interactions work on the iPad, stop for Human confirmation;
do not immediately reintroduce either field.

Rollback validation evidence:

- `CustomerCreatePage`, route, and focused test PHP syntax checks passed.
- Pint passed on the changed Tablet PHP.
- Blade view cache completed successfully.
- Focused Customer/location/sync coverage passed: 14 tests, 142 assertions.
- `git diff --check` passed.
- The exact supported rebuild attempted was
  `php artisan app:build ios --rebuild --export-method=development
  --no-interaction`.
- The rebuild reached the signed iOS build step but stopped while setting up
  signing credentials (`Failed to setup signing credentials`). No NativePHP,
  Livewire, vendor, dependency, retained SQLite, or business-data changes
  resulted; Git status contains no native runtime or vendor modifications.

Files changed for this rollback slice are `app/Filament/Pages/CustomerCreatePage.php`,
`resources/views/filament/pages/customer-create-page.blade.php`,
`tests/Feature/CustomerCreatePageDiagnosticTest.php`,
`tests/Feature/LocationReferenceBaselineTest.php`, and this Work note. The
pre-existing alignment files and additive location infrastructure remain
preserved as task-owned uncommitted work.

## Local authentication unblock investigation

Human physical rollback acceptance is currently blocked at Tablet login, not
at the Customer form. Tablet authentication is local-first: the custom
Filament Login page looks up the email in the local `users` table and verifies
the local password hash with `Hash::check`. If no local user exists, it calls
Portal's `/api/auth/tablet-login` through `SYNC_SERVER_URL`, stores the returned
password hash and API token locally, then authenticates the local user. Later
logins can work offline from that local hash. Trusted-session restoration is a
separate fallback that requires a previously authenticated local user and a
valid local API-token marker.

The Tablet development `.env` currently sets `SYNC_SERVER_URL` to
`http://127.0.0.1:8000`. In a NativePHP iPad build, that loopback address is
the iPad, not the Mac or Portal. The Tablet repository has no
`/api/auth/tablet-login` route, while the Portal repository owns that route.
Therefore normal Portal credentials cannot establish a first-login session
from this build unless the configured sync endpoint is reachable Portal. If
the attempted email is the existing local account, a password mismatch is
checked locally before any remote attempt.

Read-only local Tablet database inspection found one user row:
`diag2@test.invalid` (`Diag2`) with a non-empty password hash. The documented
Tablet `test@example.com` and sample `reg.ebalobor@kaisa.com` accounts are not
present locally. No password hashes, tokens, or credentials were printed or
changed. No requested Human test email was supplied, so its presence could
not be separately classified.

The supported zero-source-change development path is a normal first login
against a LAN- or HTTPS-reachable local Portal test account. Create an additive
development-only Portal user with the existing User model/factory and a
Human-chosen temporary password in the Portal repository, configure the
Tablet build's uncommitted local `SYNC_SERVER_URL` to the reachable Portal
base URL, rebuild, and log in once. The normal first-login flow then writes
that account to the retained Tablet SQLite database. Do not use a real user's
password, seed a destructive dataset, or add a debug-login path.

The Mac Tablet SQLite database and the iPad SQLite database are separate. A
user created in `database/database.sqlite` does not appear on the iPad. The
NativePHP runtime contains an internal Artisan bridge used by startup, but no
supported user-facing device procedure exists for arbitrarily running
`db:seed` or Tinker against the retained iPad database. Consequently, the
safe physical procedure is remote first login through Portal, not an invented
device database injection. No local development test user was created during
this investigation.

Portal API authentication remains separate from local UI authentication.
Customer Code reservation, sync pull/push, and other Portal API operations
still require the locally stored Portal API token and a reachable configured
Portal endpoint. Those operations are not required merely to open Add Customer
for the rollback checkpoint.

Auth validation evidence: the existing first-login persistence test passed;
the existing trusted-session suite passed (14 tests, 54 assertions). The
remaining three `isReachable` assertions in `FirstLoginPersistenceTest` call a
missing pre-existing `SyncService::lastError()` method and fail before any
authentication behavior is evaluated; no source was changed for this Work.
