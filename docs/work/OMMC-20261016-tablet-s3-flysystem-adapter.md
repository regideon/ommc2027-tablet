# OMMC-20261016-tablet-s3-flysystem-adapter

- **Title:** Tablet — Restore S3 upload capability (add
  `league/flysystem-aws-s3-v3`)
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Human explicitly approved adding the package
  ("Yes, add the package", 2026-10-16) after the sync check requested in
  the CRR edit-attachment session.
- **Related Work:** `OMMC-20261016-tablet-crr-edit-attachment-display`
  (same session; the sync check that surfaced this).

## Objective

Make `Storage::disk('s3')` usable so `TabletS3UploadService` can upload
SalescallImage and CustomerProfile binaries during sync. Runtime evidence
showed every S3 upload failing with
`s3_upload_failed: Class "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found`.

## Root cause

`config/filesystems.php` defines an `s3` disk and the sync services call
it, but the repository never declared the Flysystem S3 adapter:
`league/flysystem-aws-s3-v3` was absent from `composer.json`,
`composer.lock`, and `vendor`. `aws/aws-sdk-php` was only present
transitively through `laravel/ai` and does not provide the adapter.
`Storage::disk('s3')` therefore threw in every environment, including
native builds; the push recorded the item as retryable and the UI
reported "could not be uploaded and will retry later".

## Human-authorized scope

- Add the missing package only. No sync behavior, queue, or retention
  changes. No credentials or environment changes. Terminal
  authorization: **none**.

## Task-owned files

- `composer.json` — `league/flysystem-aws-s3-v3: ^3.35` added.
- `composer.lock` — lock entry for only that package (verified: the
  upgrade set contains no other package changes).
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

Same set recorded in `OMMC-20261016-tablet-crr-edit-attachment-display`
(salescall submit-requirements changes, area-cluster note, salescall
push tests, graphify-out). The composer post-install scripts
auto-refreshed `CLAUDE.md` guidelines and touched `boost.json` line
endings; both were reverted to keep this change minimal.

## Decisions

- Use the adapter matching installed `league/flysystem` 3.x; Composer
  selected `^3.35` (installed 3.35.3).
- Effective on a runtime only where valid S3 credentials/endpoint are
  configured; this Work does not add or read credentials.
- The iPad app must be rebuilt for the new vendor package to be bundled
  before device syncing can use S3.

## Validation evidence

- Before: `Storage::disk('s3')` → Error `Class
  "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found`.
- After: `Storage::disk('s3')` resolves to
  `Illuminate\Filesystem\AwsS3V3Adapter`; adapter class exists.
- Focused sync suites: `TabletS3UploadTransitionTest` +
  `SyncServiceBinaryFailureResilienceTest` → **9 passed (59
  assertions)**.
- `git diff composer.json` = one added line; `composer.lock` diff adds
  only `league/flysystem-aws-s3-v3`.
- No S3 upload or network call was executed (no runtime mutation).

## Manual acceptance

Pending. Requires the Human to rebuild the native app if device syncing
is wanted, configure/verify the S3 environment, and confirm a salescall
photo (or profile attachment) syncs without `s3_upload_failed`.

## Blockers and remaining work

- Already-exhausted failed rows (`sync_attempts >= 3`) are excluded
  from the automatic retry selector and need a manual retry path
  (re-add the photo or reset the row) before they can upload.
- Sync-target Portal data gaps (empty trade masters, missing
  itinerary) were resolved on 2026-10-16: masters seeded via the
  Portal's `FormMasterDataSeeder` and the itinerary re-pushed; the
  subsequent push synced 9 items with 0 failures. See the resolution
  section in `OMMC-20261016-tablet-crr-edit-attachment-display`.
- Salescall photos: resolved by `OMMC-20261016-tablet-sync-reliability`.
  The S3 pre-upload now requires a complete AWS configuration
  (bucket + key + secret); with none configured, binaries upload
  directly to the Portal. Verified live: 3 salescall photos synced with
  `s3_key` null.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local dependency addition only; no commits, pushes, migrations,
credential changes, or device changes.
