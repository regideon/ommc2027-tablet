# OMMC-20261012-tablet-forms-trade-request

- **Title:** Tablet Forms — Trade Request, end to end
- **Repository role:** Tablet `ommc2027-tablet` (branch `19-tablet-forms-trade-request`).
  No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`,
  the implementation conventions, and the per-form precedents (CRR Signage for
  the masters + three statuses + typed attachments, Marketing for the
  idempotent child replacement).

## Objective

Deliver **Trade Request** end to end on the forms foundation: the submissions
list, create/edit with a product master, a child item repeater (trade type,
quantity, size, description), typed attachments, offline-first sync (masters +
pull + push with item snapshot + typed attach), and deletion reconciliation.

## Human-authorized scope

Confirmed 2026-10-12 by the Human:

- The form is the Portal shape: product, sales call, items
  (`trade_type_id` + `quantity` + optional `size`/`description`), and typed
  attachments (`TradeRequestAttachmentType`: Request / Completed).
- **At least one item is required**; each item needs a trade type and a
  quantity (min 0, mirroring the Portal); size and description are optional.
- **At least one Request attachment is required**; Completed is optional
  (the same rule as CRR Signage).
- Attachments are captured as **images** (camera/gallery) through the staged
  one-file-per-request flow; the Portal's admin view displays image
  attachments only.
- The Portal's **scoped pull is kept** (own submissions). The Portal rebuilds
  items on every push, so the tablet replaces its item rows wholesale on edit.
- Editability: only while all three statuses (RSM/TDH/TDS) are `Pending` — a
  TDH return routes back to the RSM, so there is no DRM revision window.
- Implementation authorization: Human `implement` GO on 2026-10-12. Terminal
  authorization: none. No commit or push performed.

## Task-owned files

Tablet:

- `app/Services/Forms/TradeRequestService.php` — the form's single service:
  master refresh (products + types) and caching, scoped pull caching (header +
  item snapshot + statuses), create/update/adopt, required-item enforcement,
  wholesale item replacement, Request-attachment enforcement, staged typed
  attachments, removal, deletion reconciliation.
- `app/Models/Forms/TradeRequest/TradeRequest.php` — `isEditable()`.
- `app/Services/SyncService.php` — master reads (products/types),
  `readTradeRequestsWithStatus()`, and the Trade Request push (with the item
  snapshot) + typed attachment attach sections; `hasPendingChanges()` includes
  Trade Request and its attachments.
- `app/Enums/FormKey.php` — Trade Request available.
- `.gitignore` — ignores the `public/trade_request_attachments`
  preview-mirror directory.
- `app/Filament/Pages/TradeRequestFormPage.php` +
  `resources/views/filament/pages/trade-request-form-page.blade.php`,
  `resources/views/filament/pages/forms-trade-request.blade.php`,
  `forms-page.blade.php` include, `FormsPage` dispatch.
- Tests: `tests/Feature/Forms/TradeRequestWorkflowTest.php` (18 tests),
  `TradeRequestFormPageTest.php` (7 tests), updated `FormsPageTest.php` and
  `FoundationEnumsTest.php`.
- This Work note.

No migrations, enums, or Portal files change; the foundation tables and
`TradeRequestRules` stay as they are.

## Decisions

- **Editable only while fully Pending:** `TradeRequest::isEditable()` requires
  RSM, TDH, and TDS `Pending`; the TDH return loops back to the RSM stage, so
  the tablet follows the CRR/Repair/Signage rule of going read-only once
  anyone acts.
- **Items are a wholesale snapshot:** the Portal force-deletes and rebuilds
  items on every push, so the tablet replaces its item rows wholesale on edit
  (the shared `replaceChildren` helper). Item rows have no sync identity.
- **Masters are pull-only:** `programs()` refreshes the product and type
  caches by `server_id` and returns the products for the
  `FormSubmissionService` contract; the form page reads both cached lists.
  The push sends the masters' **server ids** (`product_id` and each item's
  `trade_type_id`), which the Portal validates against its own tables.
- **Scoped pull cached by `server_id`:** a local row that is not `synced` is
  never overwritten; synced rows take the server's fields and statuses as
  authoritative and their item snapshot is replaced. A pulled row whose
  product or any item type is not cached locally is skipped until the masters
  are refreshed.
- **Attachments are local to the capturing device:** pulled attachment files
  and metadata are not cached; the detail view shows locally captured
  attachments only. Removal is local-only.
- **Typed staged attachments:** each staged file carries its
  `TradeRequestAttachmentType`; pushed after the parent via
  `/api/sync/forms/trade-request/attach` with `server_id` + `type`. Existing
  attachment sync state is left untouched on update (the Portal does not
  rebuild attachments).
- **Request attachment required:** at least one Request attachment must remain
  after removals and additions, counted across existing rows and staged files;
  Completed is optional.
- **Pull caching and reconciliation are query-bounded (Human-reported N+1):**
  the deletion sweep eager-loads attachments in one query
  (`with('attachments')`), the pull cache resolves products, trade types,
  salescalls, and existing rows with one batched lookup each instead of one
  query per pulled row, and the item snapshots are replaced with a single
  delete + single insert across all cached rows (the Laravel Query Detector
  flagged the per-row relation delete from `replaceChildren`; verified clean
  across the edit → back sequence). All three are guarded by query-count
  regression tests.
- **Deletion reconciliation:** a successful pull deletes local synced trade
  requests whose `server_id` is absent, removing attachment files first
  (items cascade with the parent); unsynced rows and failed pulls are never
  purged. Master rows are never deleted by a pull (CRR convention).

## Unrelated/pre-existing worktree changes to preserve

The worktree was clean before this Work; nothing else was modified.

## Validation evidence

- Forms suite: `php artisan test --compact tests/Feature/Forms` → **202 passed
  (1179 assertions)**.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` → **484
  passed, 20 failed, 4 skipped (3000 assertions)**. All 20 failures are the
  pre-existing environment-dependent family recorded in the foundation and
  prior per-form notes (location resolver/Nominatim, iOS native artifacts,
  photo preview mirrors, first-login persistence, startup diagnostics, and
  `ExampleTest`); none are under `tests/Feature/Forms`. `php artisan test
  --compact` under the default 128M memory limit aborts mid-suite inside
  `SyncPullResponseSinkTest`'s >3MB decode, so Pest is invoked directly with a
  raised limit.
- `vendor/bin/pint --dirty --format agent` → passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime: the Trade Request rail
card, create with the product picker + item repeater + Request attachment, the
item-required and Request-required rules, edit-while-pending item replacement
and attachment add/remove, Sync (masters + header + item snapshot + typed
attach), and admin-side deletion reconciliation.

## Blockers and remaining work

- No blockers.
- Remaining: Training; terminal delivery awaits separate Human authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed. Terminal delivery awaits separate explicit Human authorization.
