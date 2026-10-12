# OMMC-20261012-tablet-forms-giveaway

- **Title:** Tablet Forms — Giveaway (dynamic checklist), end to end
- **Repository role:** Tablet `ommc2027-tablet` (branch `13-tablet-forms-giveaway`).
  No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`,
  the implementation conventions, and the per-form Trade Check precedent
  (`docs/work/OMMC-20261012-tablet-forms-trade-check.md`).

## Objective

Deliver the **Giveaway** form end to end on the forms foundation: the
submissions list, create/edit with freeform checklist rows and one uploaded
signature image per item, offline-first sync (pull + push + signature attach),
and deletion reconciliation.

## Human-authorized scope

Confirmed 2026-10-12 by the Human:

- Checklist rows are freeform: the user adds rows of category
  (`GiveawayCategory`: Gift Check / Calendar / Shirt), item name, and an
  optional quantity/answer.
- Each item carries a **signature drawn on an on-screen pad** per item —
  Salescall-style, at the Human's request after reviewing the Salescall
  implementation (2026-10-12). The pad exports a PNG that is staged through
  the existing one-file-per-request flow; camera/gallery upload was dropped.
- The Portal's **scoped pull is kept** (no Portal changes): giveaways are
  per-user submissions, unlike Trade Check's shared templates.
- Save requirements: name, at least one row, category + item name per row,
  distinct item names, and a signature per row. Quantity stays optional.
- Editability: only while every one of the user's answer rows has RSM
  `Pending` (the Portal's single-stage approval).
- Implementation authorization: Human `implement` GO on 2026-10-12. Terminal
  authorization: none. No commit or push performed.

## Task-owned files

Tablet:

- `app/Services/Forms/GiveawayService.php` — the form's single service: pull
  caching (giveaways + items + answers + answer values), create/update/adopt,
  `isEditableBy`-guarded edits, per-item signature staging/persistence,
  required-row enforcement, deletion reconciliation.
- `app/Models/Forms/Giveaway/Giveaway.php` — `isEditableBy()`.
- `app/Services/SyncService.php` — `readGiveawaysWithStatus()` and the Giveaway
  push + signature attach sections; `hasPendingChanges()` includes Giveaway
  and its signatures.
- `app/Enums/FormKey.php` — Giveaway available.
- `.gitignore` — ignores the `public/giveaway_signatures` preview-mirror
  directory, matching `public/trade_check_attachments`.
- `app/Filament/Pages/GiveawayFormPage.php` +
  `resources/views/filament/pages/giveaway-form-page.blade.php`,
  `resources/views/filament/pages/forms-giveaway.blade.php`,
  `forms-page.blade.php` include, `FormsPage` dispatch.
- Tests: `tests/Feature/Forms/GiveawayWorkflowTest.php` (15 tests),
  `GiveawayFormPageTest.php` (9 tests), updated `FormsPageTest.php` and
  `FoundationEnumsTest.php`.
- This Work note.

No migrations, enums, or Portal files change; the foundation tables and
`GiveawayRules` stay as they are.

## Decisions

- **Item rows are local-only until push:** the Portal push force-recreates
  items on every sync, so item names are unique per giveaway locally (the
  Portal `/attach` resolves the signature target by `item_ref` = item name).
- **Signatures are drawn per item (Salescall-style pad):** each row renders a
  canvas pad (`window.giveawaySignaturePad`, mirroring the Salescall profile
  pad) whose strokes debounce into a PNG staged through the shared
  `StagedAttachmentService` (one file per request) and stored in
  `giveaway_signatures`; pushed after the parent via
  `/api/sync/forms/giveaway/attach` (`item_ref` = item name). Re-drawing
  discards the previous staged draft; clearing a redraw reverts to the saved
  signature; cancel/row-removal discards staged drafts. Signatures are
  required per item; quantity stays optional. Row actions are keyed by a
  stable per-row key so pads survive row re-indexing.
- **Update re-pushes signatures:** because the Portal rebuilds items on push
  (cascading server-side answers and signatures), a local edit re-marks the
  submission's signature rows `pending` so they re-attach after the rebuild.
  Signatures are only ever created locally on the capturing device; pulled
  signature files are not downloaded (same rule as Trade Check attachments).
- **Pulled work is never overwritten:** a local giveaway that is not `synced`
  is skipped by the pull cache for both its header and its items; answer rows
  are cached only when the local row is `synced` and the Portal
  `submitted_by` matches the logged-in user's `users.server_id`.
- **Deletion reconciliation:** a successful pull deletes local synced
  giveaways whose `server_id` is absent, removing signature files first;
  child rows cascade. Unsynced rows and failed pulls are never purged.
- **List shows the user's giveaways only** (newest first) with the RSM badge
  and a Pending Sync badge; there is no "Not submitted" template concept.
- **Signature previews:** the service mirrors a stored signature into
  `public/giveaway_signatures/` for display (the Trade Check attachment-preview
  pattern); the directory is gitignored.
- **Pad dispatch uses `this.$wire`:** the pad factory is defined in a global
  `@once` script, and bare `$wire` is only in scope inside inline `x-data`
  expressions — the first revision used bare `$wire` and its staging call
  threw a silent `ReferenceError` at runtime (the pad showed "Signature
  captured" but the server never received the staging call, so Save always
  reported the signature as missing). Fixed 2026-10-12 and guarded by a
  rendered-HTML test asserting the factory dispatches through `this.$wire`.
- **Editability:** `Giveaway::isEditableBy()` returns false when the user has
  no answer rows and requires every answer row to be RSM `Pending`.

## Unrelated/pre-existing worktree changes to preserve

The worktree was clean before this Work; nothing else was modified.

## Validation evidence

- Forms suite: `php artisan test --compact tests/Feature/Forms` → **132 passed
  (810 assertions)**.
- Full suite: `php -d memory_limit=512M vendor/bin/pest --compact` → **414
  passed, 20 failed, 4 skipped (2629 assertions)**. All 20 failures are the
  pre-existing environment-dependent family recorded in the foundation,
  Marketing, and Trade Check notes (location resolver/Nominatim, iOS native
  artifacts, photo preview mirrors, first-login persistence, startup
  diagnostics, and `ExampleTest`); none are under `tests/Feature/Forms` and
  the failures reproduce in isolation without any forms code. `php artisan test
  --compact` under the default 128M memory limit aborts mid-suite inside
  `SyncPullResponseSinkTest`'s >3MB decode, so Pest is invoked directly with a
  raised limit.
- `vendor/bin/pint --dirty --format agent` and an explicit Pint pass over every
  changed path → passed. `php -l` clean on every changed PHP file.

## Manual acceptance

Pending. Requires the Human to confirm in runtime: the Giveaway rail card,
create with per-item category/name/quantity rows and per-item signature pads
(draw, clear, redraw), edit-while-pending with the saved-signature revert,
Sync (push + attach), and admin-side deletion reconciliation.

## Blockers and remaining work

- No blockers.
- Remaining: Repair, Signage, Trade Request, Training; terminal delivery
  awaits separate Human authorization.

## Delivery evidence status

None. `READY_FOR_TERMINAL_REVIEW`; no commits, pushes, or database mutations
performed. Terminal delivery awaits separate explicit Human authorization.
