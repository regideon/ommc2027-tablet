# OMMC-20261007-customer-profile-drm-reconciliation

## Status

TERMINAL_DELIVERED

## Objective and authorization

The initial phase investigated the historical V1 meaning and current V2 representation of Customer DRM. The Human then authorized the bounded V2 implementation described in the continuation request. Historical reconciliation, production database writes, commit, push, and deployment remain out of scope.

## Sources inspected

### V1 evidence

Authoritative available V1 source inspected: `/Users/highlite/Workspace-Kaisa/PHP/ommc2026`, branch `main`.

Relevant source:

- `database/migrations/2026_01_08_200000_create_customers_table.php`
- `resources/js/pages/customers.js`
- `resources/views/components/customers/dealer-information-form.blade.php`
- `resources/views/components/customers/add-customer-modal.blade.php`
- `resources/views/pages/customers.blade.php`
- `routes/api.php`
- `resources/js/powersync/schema.js`

V1 has no `App\\Models\\Customer`, Customer API controller, or Customer detail/profile page in this repository snapshot. The web Customer screen is a list with Add/Edit/Delete actions; its modal is an editor, not a read-only profile.

### V2 evidence

Portal:

- `app/Models/Customer.php`, `app/Models/User.php`
- `app/Filament/Resources/Customers/Schemas/CustomerForm.php`
- `app/Filament/Resources/Customers/Pages/ViewCustomer.php`
- `app/Filament/Resources/Users/Schemas/UserForm.php`
- `app/Services/TabletSyncPayload.php`
- `app/Http/Controllers/Api/SyncController.php`
- `app/Services/CustomerExportMapper.php`

Tablet:

- `app/Models/Customer.php`, `app/Models/User.php`
- `app/Services/CustomerProfileFormService.php`
- `app/Filament/Pages/CustomerPage.php`
- `resources/views/filament/pages/customer-page.blade.php`
- `app/Services/SyncService.php`
- Customer Access and Customer-scope migrations.

Related decisions reviewed: `OMMC-20261001-tablet-created-customer-list-visibility`, `OMMC-20261001-add-customer-save-rsm-requirement-reconciliation`, `OMMC-20261005-customer-sync-pagination`, `OMMC-20261002-customer-reconciliation-patch-investigation`, and the completed final Customer reconciliation note.

## V1 DRM semantics

- Storage was a direct nullable text column `customers.drms`, separate from nullable `customers.rsm`. V1 JavaScript treats both as JSON arrays and serializes them to/from storage. They were not pivots or User foreign keys.
- DRM cardinality was zero or many free-form strings. The Add/Edit modal lets the editor type arbitrary DRM names, add unique values, and remove them. It does not select authenticated User records, derive the list from roles or a hierarchy, or auto-assign the creator.
- The separate nullable `customers.user_id` is not the DRM field. The separately editable `rsm` array is not the DRM field.
- The editor shows the label **DRMs** with removable name tags and an input placeholder “Type and press Enter to add DRM.” The V1 list does not show DRMs; no dedicated Customer profile/detail display exists in this repository snapshot.
- V1 JavaScript includes `drms` and `rsm` in Customer serialization, normalization, local-write columns, and its JSON-column handling. However, the checked-in PowerSync schema does not define a Customers table and `routes/api.php` defines no Customer API routes. Therefore the source establishes the intended local form/data semantics, but not a functioning V1 server synchronization contract.

## V1 -> Portal V2 -> Tablet V2 mapping

| V1 concept | V1 storage/behavior | Portal V2 | Tablet V2 | Gap |
|---|---|---|---|---|
| Customer DRM(s) | `customers.drms`: free-form JSON-array-like text; editable zero/many names | No DRM column. Customer `users()` is many-to-many through `customer_user`; User roles are separate Spatie roles. | Same local `customer_user` relation exists; local User roles exist. Pulled Customer records do not carry the Access pivot or assigned-user roles. | Portal can identify direct Access users with role `drm`, but does not label/render them as DRM. Tablet cannot reliably derive the Portal assignment for pulled Customers. |
| Customer Access | No distinct V1 Access relationship evidenced; `user_id` is a separate scalar | Editable multi-select labeled **Access**, persisted in `customer_user`; not role-filtered | Local multi-select persisted to local `customer_user`; also used for list visibility | Access is broader than DRM and must not be rendered wholesale as DRM. |
| RSM | Separate editable `customers.rsm` text list | No Customer RSM column. Portal derives/display RSM from assigned Access users’ `users.rsm_id`; User form assigns a DRM’s RSM via `rsm_id`. | User role names are synchronized for the authenticated user at login; current Customer pull does not provide other assigned users/roles. Tablet detail displays no RSM or DRM. | RSM hierarchy is not a substitute for a direct Customer DRM assignment. |
| Creator/owner | Nullable `customers.user_id`, distinct from `drms` | `customers.created_by` is attribution; distinct from `customer_user` Access | Authenticated local user is defaulted into Customer Access on Add; this is saved locally | Creator attribution does not by itself establish a DRM relationship. Tablet Customer push currently omits `access_user_ids`. |

## Portal V2 findings

- Customer `users()` and User `customers()` are many-to-many through `customer_user`; Customer’s scalar `person_in_charge_id` is a different concept.
- The Customer form’s multi-select is labeled **Access** and accepts users without filtering by role. It checks for a non-null `rsm_id`, not that every selected user is a DRM. Thus `customer_user` alone does not mean DRM.
- User has Spatie roles plus `rsm_id`; `drms()` and `rsm()` express the user hierarchy. User administration offers an RSM from users with role `rsm` and describes this as assigning a designated RSM to a DRM.
- `drm`, `drm_approver`, `rsm`, and `rsm_approver` are distinct role names/capabilities. The role-based customer-scope code expands some approver/RSM visibility through `rsm_id`; this is visibility, not proof that an approver is a Customer’s DRM.
- Portal Customer profile (`ViewCustomer`) shows all related `users.name` under **Access** and derives a separate **RSM** display from those users’ `rsm_id`. It has no **DRM** entry and does not role-filter the Access values for display.
- Portal already has enough relational data for a direct-DRM presentation without schema changes: filter that Customer’s Access users by the actual `drm` role. The Customer export mapper demonstrates role filtering (`drm`/ `rsm`) for its DRM/RSR export field, but only for Fleet/OE/IB export rows; this is not a global Customer-profile implementation.

## Tablet V2 findings

- The local Customer model has the `customer_user` many-to-many relation. `CustomerProfileFormService::saveAggregate()` syncs `access_user_ids` into that local pivot.
- Add Customer defaults the authenticated local user ID into Access and persists it locally. The accepted no-RSM behavior does not create or require an RSM relationship.
- `CustomerPage::viewCustomer()` loads Customer scalar/profile/location data without `users`, and its detail payload/Blade contains no Access or DRM field.
- The current paginated pull calls Portal `/api/sync/pull/customers`. That response is built from `TabletSyncPayload::customerRecords()` (Customer and child profile/category rows); it does not include `customer_user`, Access-user identities, or their roles. Its `scope_ids` are Customer IDs only.
- The legacy full Portal `/pull` includes a `customer_user` section and users without role names, but the current paginated pull does not; Tablet’s current `applyPullPayload()` does not upsert a `customer_user` section either. Do not treat that legacy response as proof current V2 pull supports DRM.
- Current pull user columns omit roles, and the paginated Customer response does not include users. Login role synchronization covers the authenticated user only. The local User fillable list also excludes `rsm_id`, so the refresh-token assignment of `rsm_id` is currently discarded. This further prevents deriving all assigned people from local users, though role-filtered direct DRM identity would not need an RSM.
- Incremental Customer change detection tracks Customer and listed profile/category child tables, not `customer_user`. Even a future pivot payload would need an explicit way for Access changes to cause a Customer to be returned by incremental pull.

## Read-only data evidence

Only aggregate results were inspected; no Customer names, codes, user names, or row values are recorded here.

- Portal configured local database: `ommc_henri_local`. Read-only aggregates: 18,988 Customers; 18,972 have at least one `customer_user` Access assignment; 18,151 have an Access user with role `drm`. This confirms qualifying relationships exist broadly in this local snapshot, but cannot identify the QA screenshot’s Customer because no Customer identity was supplied.
- Tablet configured local database: repository SQLite file. Read-only aggregates: 707 local Customers, 681 Customers with local Access pivots, 14 `customer_scopes` rows, and 3 local users. The local pivots are not provenance evidence that current Portal paginated pull synchronized Access; source code confirms it does not.
- No database was mutated. No production database was accessed.

## Reconciliation evidence and DB-dominant boundary

The historical `Henri Upload_September 16, 2026.xlsx` has an **Access** column on all four source sheets (21,058 source rows; populated for each row in the inspected workbook). It has a separate **DRM/RSR** column only on Fleet, populated on 3,833 of 5,651 Fleet rows. The `CUSTOMER DATA TESTING.xlsx` findings sheet includes Access but no DRM/RSR column.

This is potential evidence, not blanket authority to restore assignments:

- Existing reconciliation work treated the database as dominant, preserved populated values and all `customer_user` pivots, and excluded Access mutations.
- A missing/null Access pivot may be a candidate for a separately approved, exact-identity reconciliation if source semantics are confirmed and the source person maps uniquely to a canonical User.
- The historical explicit DRM/RSR field only supports a candidate for its Fleet population. Other sheets’ Access values cannot automatically be equated with DRM; the Human must confirm their semantics before using them to fill a DRM relation.
- No target Customer was provided, so the QA record cannot be classified as a missing relationship versus a display-only omission.

## Evidence-supported classification and smallest proposed correction

This is a combination:

1. **Portal presentation/query:** direct relationships and user roles exist for many Customers, but the profile displays generic Access and derived RSM, not a DRM-specific role-filtered value.
2. **Tablet sync and presentation:** the profile does not render DRM/Access, and the current paginated pull omits the relationship and assigned-user role information needed to derive it offline.
3. **Possible data reconciliation:** some Customers may lack an Access assignment or an Access user with role `drm`; the supplied QA record has no identifier, so that possibility is unresolved.
4. **Schema/domain:** Portal has a relationship and role model adequate for a direct Access-user-with-role-`drm` definition; no Portal schema field is indicated. Tablet lacks a synchronized local projection, not necessarily a missing business domain. Offline display may need a Tablet-local projection/schema change unless the existing Access/user tables are deliberately synchronized and mapped.

Smallest proposed behavior, pending Human decision:

- Portal profile: show the Customer’s direct Access users whose Portal role is `drm`, including multiple names as V1 allowed multiple DRMs; keep RSM separate; show an em dash when no qualifying DRM exists.
- Tablet profile: show the same Portal-derived DRM identities offline. The minimal bounded data contract is a role-filtered DRM projection in the existing paginated Customer pull, persistently associated with each local Customer, and refreshed when that Customer’s Access changes. This avoids treating all Access users as DRMs and avoids broad user-role synchronization. A Tablet-local migration/projection may be needed; no Portal migration is indicated.
- Tablet Customer creation: current local Access default can identify the creator as a DRM only when that local user actually has role `drm`. The current Customer push payload omits `access_user_ids`, while Portal persists Access only if that field is supplied. The Portal actor/creator is not a safe substitute for the selected Access set. This means a new Tablet Customer’s local Access does not currently establish a Portal Access/DRM relationship. Do not silently infer assignments or manufacture RSMs.

## Human decision resolution and remaining data question

The Human resolved the implementation decisions in the continuation authorization: DRM is direct Customer Access plus exact role `drm`; approver roles do not qualify; multiple DRMs are valid; Portal `customer_user` remains authoritative; Tablet Access is pushed using verified Portal IDs. No historical workbook-based restoration is authorized in this Work. A target QA Customer identity was not supplied, so record-level diagnosis of any historical missing assignment remains a separate reconciliation question.

## Initial investigation worktree and actions

- Portal `main`: clean before note creation.
- Tablet `main`: only the two existing untracked `public/expense_attachments/*.png` files were present before note creation; preserved untouched.
- V1 `ommc2026` `main`: clean and inspected read-only.
- At the initial investigation stage, only these mirrored Work notes were created; no application code, migrations, schema, or database records changed.
- The investigation itself did not run focused code/tests.
- No commit, push, or deployment.

## Accepted V2 definition and implementation continuation

Human decision: a V2 DRM is a direct Customer Access user (`customer_user`) whose exact role name is `drm`. Zero or multiple users qualify. `drm_approver`, `rsm`, `rsm_approver`, hierarchy, and generic Access alone do not qualify. DRM, Access, and RSM remain separate. No Portal `drm_id`, `customers.drms`, free-text names, allocation logic, or historical assignment backfill was added.

### Portal implementation

- Added `Customer::drmAccessUsers()` as the shared direct-Access + exact-role relationship, ordered by User name and ID.
- The Customer profile displays this relation as `DRM` with the existing em dash placeholder. Existing `Access` and `RSM` entries remain. Customer Resource queries eager-load Access/roles and the filtered DRM relation to avoid per-entry role queries.
- The Tablet paginated Customer payload now includes `customer_access` rows with Portal Customer/User IDs and `access_users` records limited to ID, name, email, and role names for those direct Access users.
- Incremental change detection now considers `customer_user.updated_at`. For Access removals/replacements that delete pivot rows, Portal Customer edits and Tablet Customer pushes touch the parent Customer timestamp; additions from the recovery importer are discoverable through pivot timestamps.
- The Tablet push endpoint accepts an empty Access list and no longer requires Access users to have an RSM relationship. This is necessary because Access is independent of RSM and Tablet-created Access must be persisted without manufacturing an RSM.

### Tablet implementation and identity safety

- The offline Customer profile uses the same `Customer::drmAccessUsers()` relationship and displays zero as an em dash, one name, or multiple names in deterministic order.
- Paginated Customer pulls persist the actual normalized `customer_user` relation and role names, then reconcile that relation for server-authoritative Customers. Pending, failed, conflicted, and same-ID colliding local Customers are protected; their local Access is not overwritten by Portal data. No DRM-name projection was added.
- Tablet users had no Portal ID column, while Portal and Tablet numeric User IDs are not interchangeable. A Tablet-only migration adds nullable unique `users.server_id`. Login responses already provide Portal `id`; the Tablet now records it. Pulled Access users also carry their Portal IDs and are matched to an existing login by unique email only to attach that explicit ID, or persisted as non-login local identity rows. Push sends only the stored Portal IDs. If any selected Access user is unmapped, push stops with a retryable identity error rather than guessing or mapping by display name.
- Portal remains authoritative for already-synced Customers. Unsynced local Customers retain local Access until successful push; subsequent pull converges to the Portal relationship. Customer Code reservation/push behavior is unchanged.

### Validation and remaining boundary

- Portal focused validation: `CustomerDrmProfileTest.php` and `TabletSplitPullTest.php` — 13 tests passed, 70 assertions.
- Tablet focused validation: `CustomerPagedPullTest.php`, `CustomerOnlyPushTest.php`, `TabletBaseLocationSyncTest.php`, and `CustomerPageAccessTest.php` — 33 tests passed, 172 assertions; `CustomerPageSearchTest.php` and `CustomerCodeSyncTest.php` — 6 tests passed, 39 assertions.
- PHP syntax checks passed for changed PHP files. Focused Pint `--test` passed on the changed Portal resource/model/controller/payload/test files and Tablet models/sync/migration/test files; existing formatting was preserved in the legacy Portal Edit/View and Tablet CustomerPage methods to avoid broad reformatting. `git diff --check` passed in both repositories.
- No production database mutation occurred. No workbook was used to alter Access. Historical missing assignments remain a separate, DB-dominant reconciliation concern; no target Customer was supplied for record-level reconciliation.
- Human validation on the Portal Customer profile and Tablet offline Customer profile/sync remains required.
- Final Work state: `READY_FOR_TERMINAL_REVIEW`. No commit, push, or deployment.


## Reopened investigation: creator-derived DRM hypothesis

The Human reopened this Work for investigation only. The previous V2 implementation and its history remain in this note, but its READY_FOR_TERMINAL_REVIEW state is superseded. No creator-derived rule has been selected or implemented.

### V1 `customers.user_id` provenance

- The V1 migration defines nullable `customers.user_id` as an indexed unsigned integer and separately defines nullable text `customers.drms` and `customers.rsm`.
- V1 `customers.js` maps stored `row.user_id` to `formData.userId`, then serializes it back as `user_id`. The new-customer modal's blank form does not initialize `userId`; therefore new Customer creation sends `user_id: null` rather than assigning the authenticated creator.
- Existing rows can preserve an existing `user_id` through edit. V1's generic PowerSync upload handler accepts an incoming `user_id` value for a table operation, but does not derive or inject the authenticated user as the Customer's `user_id`.
- V1 credentials use the authenticated identity as PowerSync's token subject when available (or a demo subject), but that identity is not automatically written to `customers.user_id`. The checked-in Customers list also does not filter by `user_id`.
- V1 source therefore proves `user_id` is separate from the free-form DRM names and is not auto-populated as the creator in the checked-in creation flow. It does not establish whether historical `user_id` values mean assigned owner, importer, legacy creator, or another ownership concept. Do not reinterpret them as DRM without client confirmation and source evidence.

### Current creator and Access provenance

- Portal Customer creation sets `customers.created_by = auth()->id()`. Portal API push sets `created_by` to the authenticated Portal API user only when creating a new Customer; updates do not replace that creator attribution. Portal's Customer Access relation remains a separate `customer_user` pivot.
- Tablet Customer creation currently defaults the authenticated local user into the editable `access_user_ids` list. The local Customer creation path does not set the Customer's `created_by` field. The current Tablet push sends the selected Access users as verified Portal IDs; Portal assigns the API actor as `created_by` for a newly pushed Customer.
- These are related provenance facts but not a single shared rule: Portal creator attribution and explicit Customer Access can differ. Tablet's default Access selection may coincide with the creator, but is editable and may include additional users.
- Read-only aggregate inspection of Tablet's local SQLite database found 707 Customers: 699 have a non-null local `created_by`, 8 are null, and none of the 699 creator IDs match a row in the current 3-row local `users` table. These local values do not currently provide a verified local creator-to-role identity mapping.
- Read-only aggregate inspection of Portal's configured local database `ommc_henri_local` found 18,988 Customers with non-null, resolvable `created_by`; 15,253 have a creator with exact role `drm`, and 3,735 have a creator without exact role `drm`. The local data demonstrates the creator's role is not consistently `drm`; it does not establish which semantics the client wants for the profile field.
- Only counts were inspected; no Customer or User names, codes, or row values were recorded. The Portal and Tablet database inspection was read-only. No production database was accessed.

### If the client selects creator-derived DRM

The existing uncommitted implementation is based on the previously approved definition: direct Customer Access users with exact role `drm`. It must not be treated as already satisfying creator-derived semantics.

- **Reusable with little or no change:** the Portal/Tablet role-aware identity and display conventions, test scaffolding, verified Portal User ID mapping, and bounded sync/profile structure may provide implementation scaffolding after the rule is decided.
- **Adapt:** the profile source/query and sync payload would need to resolve the Customer creator and that user's role. Tablet would need a reliable Portal creator identity and role available offline; the existing `customer_user`-based DRM selector would not represent that rule. Local creator IDs cannot be assumed to be Portal User IDs.
- **Revert or remove if no longer needed:** Access-pivot changes made solely to carry/display DRM, Access-specific tests, and any migration/data contract that is unnecessary to a creator-derived representation. The Tablet `users.server_id` mapping could remain useful elsewhere or for creator identity, but must be justified by the confirmed design rather than retained automatically.
- **Not yet classified:** exact code-level keep/adapt/revert decisions depend on the client answer, schema inspection, and whether the implementation is intended to support both explicit Access and creator-derived display. No code or migration was changed during this reopened investigation.

### Client decision required

Please confirm which rule the Customer profile should display:

1. **Creator-derived:** the Customer's recorded creator, only when that user has exact role `drm`; or
2. **Explicit assignment:** each user explicitly assigned through Customer Access whose exact role is `drm` (the previously approved V2 implementation).

For Tablet-created Customers, should a creator automatically count as the DRM when the editable Access list is empty or differs? Should multiple DRM names be allowed? Should a historical V1 `user_id` ever be considered an input to this rule, given that its checked-in provenance is unresolved?

Until this is answered, creator-derived DRM is under investigation and explicit relation-based semantics are not re-authorized by this reopened request.

### Reopened Work state

- Human ambiguity identified: creator-derived DRM versus explicitly assigned Access-user DRM.
- Creator-derived DRM remains under investigation.
- Explicit Customer Access relation semantics are not currently authorized for further implementation.
- Work state: `ACTIVE`; awaiting client confirmation.
- No implementation, migration, schema change, commit, push, deployment, or database mutation occurred during this reopened investigation.


## Terminal investigation outcome

This terminal delivery closes the investigation and architectural reconciliation only. It does not deliver a DRM product behavior.

### V1 findings and remaining observation

- V1 `customers.drms` is separate from `customers.rsm` and `customers.user_id`.
- V1 represented DRM as zero-to-many free-form names.
- Checked-in V1 source does not establish `user_id` as DRM or as the authenticated creator. The new Customer form sends `user_id: null`.
- Human observation of the RUNNING V1 application is still required to establish current behavior. Client confirmation may still be needed after that observation.

### V2 findings

- Portal has explicit Customer Access through `customer_user`; Access and User role are separate concepts.
- Portal has durable Customer creator provenance in `created_by`.
- The inspected local Portal database had 18,988 Customers with resolvable creators: 15,253 creators had exact role `drm`, and 3,735 did not. These counts do not establish that creator means DRM.
- Tablet has local Customer Access, but the current committed Portal/Tablet behavior does not establish an authoritative DRM domain rule.

### Experimental implementation not accepted or delivered

- The attempted V2 interpretation was direct Customer Access plus exact role `drm` = DRM.
- Its automated validation passed: Portal focused tests reported 13 passed / 70 assertions; Tablet focused tests reported 33 passed / 172 assertions and an additional 6 passed / 39 assertions.
- Human architectural review identified that the Customer forms still lacked an explicit DRM field and that repository evidence had not established the business semantics. The implementation was not Human accepted.
- That implementation has now been reverted before delivery. Creator-derived DRM was also investigated but not adopted because repository evidence does not prove creator = DRM.
- No DRM application behavior is being delivered. No experimental schema/migration remains; the Tablet `users.server_id` migration was removed. Experimental Access sync/push expansion and associated tests were reverted.

### Open business question and next evidence

The authoritative definition remains unresolved. Is Customer DRM:

1. the DRM user who originally creates the Customer;
2. an explicitly assigned DRM independent of creator and Access;
3. creator-initialized but subsequently assignable or changeable; or
4. another client-defined rule?

Also unresolved are whether multiple DRMs remain valid in current business behavior and whether historical V1 `user_id` has any DRM meaning.

The next evidence is Human inspection of the RUNNING V1 application. Review that observation and obtain client confirmation as needed before authorizing any V2 implementation.

### Final status

- Work state: `TERMINAL_DELIVERED`.
- This means the investigation/reconciliation Work is complete.
- The QA issue `No DRM in customer profile` remains OPEN / DEFERRED pending authoritative behavior confirmation; it is not functionally resolved by this delivery.
- No schema or migration from the experimental DRM implementation remains.
- No deployment or database mutation occurred.
