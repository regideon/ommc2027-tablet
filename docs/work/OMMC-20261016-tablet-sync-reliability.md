# OMMC-20261016-tablet-sync-reliability

- **Title:** Tablet — Retry until synced, direct binary upload without S3,
  and expense amount serialization
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Human directives 2026-10-16: "dont add limit with
  syncing until it is synced and make sure it is syncing without any
  issues", following the approval to make the S3 pre-upload optional
  when S3 is not configured. This supersedes the
  `OMMC-20261014-expense-amount-validation` constraint "Preserve the
  three-attempt automatic retry limit" — the Human explicitly reversed
  that limit in this session.
- **Related Work:** `OMMC-20261016-tablet-s3-flysystem-adapter` (S3
  adapter package), `OMMC-20261016-tablet-crr-edit-attachment-display`
  (session context and sync check).

## Objective

1. Remove the automatic 3-attempt retry cap: an un-synced row (pending
   or failed) is retried on every sync until it is synced.
2. Upload binary attachments (salescall images, profile files) directly
   to the Portal whenever the runtime has no complete S3 configuration;
   keep the S3-first behavior when S3 is fully configured.
3. Fix the expense push serialization: the `decimal:4` model cast sent
   four-decimal amounts, which the Portal rejects (`decimal:0,2`).

## Human-authorized scope

- The three changes above, focused tests, and Work-note updates.
- No Portal changes, migrations, credentials, or database deletions.
- Terminal authorization: **none**.

## Task-owned files

- `app/Services/SyncService.php` — cap removal in all push selectors and
  the customer claim/retryability branches; S3 gate in the three binary
  pushes; expense amount formatting.
- `config/sync.php` — `s3_uploads` flag.
- `tests/Feature/SyncServiceSalescallPushTest.php`,
  `tests/Feature/CustomerOnlyPushTest.php`,
  `tests/Feature/SyncServiceBinaryFailureResilienceTest.php`,
  `tests/Feature/TabletS3UploadTransitionTest.php`,
  `tests/Feature/SalescallExpenseReadTest.php`.
- This Work note.

## Decisions

- **No retry cap:** selectors now take `pending` OR `failed` regardless
  of `sync_attempts`; the counter remains for diagnostics. Customer
  failure results are always retryable; the manual exhausted-Customer
  retry path remains but is now redundant for normal syncs.
- **S3 gate:** `config('sync.s3_uploads')` enables S3 first-upload only
  when `AWS_BUCKET`, `AWS_ACCESS_KEY_ID`, and `AWS_SECRET_ACCESS_KEY`
  are all non-empty (empty-string env values do not count); the
  `SYNC_S3_UPLOADS=true|false` env force-overrides. When disabled, the
  binary goes straight to the existing Portal upload — the same
  multipart contract the forms already use.
- **Expense amount:** push sends `number_format((float) $amount, 2, '.', '')`.

## Validation evidence

- TDD RED then GREEN, focused suites only (Human instruction):
  - RED: salescall push "exhausted left behind" (1 sent vs 2), customer
    indicator/parity (exhausted excluded), S3-disabled image test
    (`s3_key` not null), expense amount (`125.0000` vs `125.00`).
  - GREEN: the five owned suites → **58 passed (403 assertions)**.
- Regression checks against pre-existing failures: `CustomerLocationSyncTest`
  and the two `CustomerCreatePageDiagnosticTest` cases fail identically
  with these changes stashed (documented environment families).
- Live environment verification (tablet → Portal at
  `http://192.168.100.200:8000`, which is this workstation's Portal):
  - Itinerary + 2 salescalls + 2 Trade Requests + 4 attachments → 9
    items synced, 0 failed.
  - 3 salescall photos → synced directly to the Portal (server ids
    1/2/3, `s3_key` null) with S3 disabled; no S3 required.
- `php -l` passed for changed PHP files; Pint passed;
  `git diff --check` passed.

## Sync findings needing Human decisions (not code defects fixed here)

- **Customer push contract drift — RESOLVED (2026-10-16, Human-directed
  full aligned integration):** the aligned pair was merged locally —
  tablet `tablet-push-queue` merged `main` at `1a9f378`; Portal
  `tablet-push-queue` merged `main` at `030f6fc` — and the development
  pair was switched to it (`POST /api/customers/sync`). Live verification:
  customer `sample` (OE52200) synced and reconciled (Portal id `23593`).
  This session's fixes were ported onto the aligned structure. See the
  integration record in `OMMC-20261015-tablet-sync-alignment.md`.
- **Legacy Expense row:** after the amount fix, the residual failures
  are business-rule data issues of an old test row (Lodging needs the
  confirmation flag — added locally — and at least one attachment,
  which the row has none). Human decision: keep as-is; it stays
  retryable until an attachment is added or the row is deleted.
- **Two data-invalid customer test rows** keep failing their own Portal
  validation (`sampe`: trade profile classifications must be an array;
  `sample store`: person-in-charge email required). Not code defects;
  fix via the Customer Edit page or delete.

## Manual acceptance

Pending. Requires the Human runtime check: a fresh sync reports no
unexpected failures, un-synced rows keep retrying every sync, and
salescall photos reach the Portal without S3 credentials.

## Blockers and remaining work

- Implemented on the merged `tablet-push-queue` branch (local merge;
  not pushed). Delivery (commit/push of the ported fixes and the local
  merges) is the Human's call.
- Data-invalid rows keep retrying (by design): two old customer test
  rows and one legacy Lodging expense.
- The alignment branch's attributed pre-existing failures remain:
  3 × `CustomerCodePageTest` ("Component did not perform a redirect").
- The iPad app (iPad) needs a rebuild to include the flysystem package
  and these changes.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

None. Local implementation only; no commits, pushes, migrations, or
Portal changes.
