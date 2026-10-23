# OMMC-20261023-salescall-image-portal-storage

- **Title:** Tablet — Sales-call images upload directly to the Portal; keep the
  local path and store the Portal `server_id`
- **Repositories:** Portal `ommc2027` and Tablet `ommc2027-tablet`. Cross-repo
  Work; materially equivalent note in both repositories.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Human-approved in-chat design, 2026-10-23. The earlier
  fallback idea (keep S3 when configured) was replaced by "remove the S3
  totally with the tablet" for sales-call images. Scope was fixed to sales-call
  images only; profile signatures and profile attachments keep their existing
  S3 behavior.
- **Related Work:** `OMMC-20261016-tablet-s3-flysystem-adapter`,
  `OMMC-20261016-tablet-sync-reliability` (the earlier S3/direct-Portal
  fallback work that was never committed on this branch).

## Objective

Sales-call photos must reach the admin Portal without the tablet requiring S3.
The tablet sends the local image directly to the existing Portal endpoint,
stores the returned Portal `server_id`, and keeps the original local file path
for offline access. The Portal stores the file on its env-selected filesystem
disk (`FILESYSTEM_DISK` / `config('filesystems.default')`) and serves it from
the admin.

## Human-authorized scope

Confirmed 2026-10-23:

- Remove S3 pre-upload for **sales-call images only** in the tablet.
- Portal stores the uploaded image on the env-configured default disk.
- Tablet keeps `local_path`; it does not replace it with a Portal or S3 path.
- Store the Portal `server_id`; no new migration because `server_id` already
  exists on `salescall_images`.
- Failed sales-call images retry regardless of `sync_attempts` so existing
  stuck photos can reach the Portal.
- Profile signatures and profile attachments are unchanged.
- Implementation authorization: Human design approval 2026-10-23.
  Terminal delivery (commit / push) authorization: **none**.

## Accepted decisions

- `server_id` is the tablet's server-side reference for a sales-call image.
  `s3_key` remains in the schema for legacy rows but is cleared for successful
  image pushes and is no longer written.
- The tablet's `local_path` is the canonical local copy and is never updated
  from the Portal response.
- The Portal owns storage location through `FILESYSTEM_DISK`; today that is
  `public`, and it can move to S3 by changing the env without changing the
  tablet.
- Sales-call image retry is not capped by `sync_attempts`. `hasPendingChanges()`
  also treats those failed rows as pending work.
- `TabletS3UploadService` remains for profile signature and profile attachment
  uploads only.

## Task-owned files

Tablet:

- `app/Services/SyncService.php` — image push no longer calls S3; stores
  `server_id`, clears `s3_key`, keeps `local_path`; salescall and image
  retry selectors no longer cap at 3 attempts; `hasPendingChanges()` includes
  exhausted failed salescall/image rows.
- `app/Services/TabletS3UploadService.php` — removed the sales-call image
  upload methods.
- `tests/Feature/SyncServiceBinaryFailureResilienceTest.php` — direct-Portal,
  no-S3, local-path-retention, and exhausted-retry coverage.
- `tests/Feature/TabletS3UploadTransitionTest.php` — sales-call image test now
  proves no S3 write; signature S3 coverage retained.
- `docs/playbooks/nativephp-photo-integration.md` — records the new storage
  ownership.
- This Work note.

Portal:

- `app/Http/Controllers/Api/Sync/SalescallController.php` — stores uploads on
  `config('filesystems.default')`.
- `app/Filament/Resources/Salescalls/RelationManagers/SalescallImagesRelationManager.php`,
  `app/Filament/Resources/Customers/RelationManagers/SalescallImagesRelationManager.php`,
  `app/Filament/Resources/SalescallImages/Tables/SalescallImagesTable.php` —
  read images from the configured default disk.
- `tests/Feature/Api/SalescallImageUploadTest.php` — proves the configured
  disk is used and `server_id` is returned.
- Portal copy of this Work note.

## Unrelated/pre-existing worktree changes to preserve

None at start: both worktrees were clean (`git status --short` empty) before
this Work. `graphify-out/` is user-owned generated infrastructure and is not
staged.

## Validation evidence

- Tablet focused suites (post-change):
  `SyncServiceBinaryFailureResilienceTest`, `TabletS3UploadTransitionTest`,
  `SyncServiceSalescallPushTest` → **15 passed (104 assertions)**.
- Tablet `SalescallPhotoUploadTest` → 38 passed / 4 failed. The four failures
  are the documented pre-existing preview-mirror family and are unrelated to
  this Work.
- One-off tablet full suite run: **746 passed, 29 failed, 5 skipped
  (5710 assertions)**. The failure names are the documented pre-existing
  environment families (customer code/location/map, iOS native artifacts,
  photo preview mirrors, `SalescallFormRedirectsTest`,
  `SalescallStatusesMigrationTest`, startup diagnostics, `ExampleTest`). The
  previously failing `SyncServiceSalescallPushTest` now passes. Full-suite
  re-runs were stopped per Human direction; focused tests are the validation
  gate for this Work.
- Portal focused suites (`SalescallImageUploadTest`, `SyncPushSalescallAuditTest`,
  `FormSyncTest`) → **35 passed (200 assertions)**.
- Portal full suite: not run; the focused `SalescallImageUploadTest` passed
  **1 test (8 assertions)**.
- `vendor/bin/pint --dirty --format agent` passed in both repositories.
- `git diff --check` passed in both repositories.
- `graphify update .` rebuilt the tablet graph (11742 nodes).

## Manual acceptance

Pending. Requires the Human to run a sales-call photo from the tablet with no
S3 credentials, trigger sync, and confirm:

- The Portal admin (Sales Calls → Photos and Salescall Images) shows the image.
- The tablet row has a Portal `server_id` and its `local_path` still points to
  the local file.
- `s3_key` is null for the new image.
- The four previously stuck images (tablet `salescall_images` ids 4–7) retry
  and reach the Portal on the next sync.

## Blockers and remaining work

- The native tablet app must be rebuilt to include this change before device
  testing.
- Full-suite validation is not a gate for this Work per Human direction;
  focused sync/photo suites are green.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation and validation only; no commits, pushes, or
database migrations.
