# NativePHP Photo Integration Playbook

Operational guidance for Camera and Gallery workflows in the OMMC Tablet app.
This documents the implementation accepted for the Sales Call photo
regression; it is not a shared photo abstraction or a proposal to redesign
other upload flows.

## 1. Purpose and scope

Use this playbook when working on NativePHP Camera or Gallery events, the Sales
Call photo wizard, or a photo attachment flow that shares those native events.
It covers the current NativePHP Mobile **3.3.6** integration on iOS, the
Livewire callback boundary, and the browser file-input fallback.

Primary implementation locations:

- `app/Filament/Pages/SalescallPage.php` — Camera/Gallery entry points,
  callbacks, capture correlation, Sales Call persistence, preview refresh, and
  Supporting Documents routing.
- `resources/views/filament/pages/salescall-page.blade.php` — the Alpine photo
  wizard, browser fallback, preview list, and delete UI.
- `packages/ommc2027/camera/resources/ios/CameraFunctions.swift` — iOS camera,
  PHPicker, temporary image copying, and native event payloads.
- `vendor/nativephp/mobile/src/PendingPhotoCapture.php` and
  `vendor/nativephp/mobile/src/PendingMediaPicker.php` — the installed
  NativePHP 3.3.6 PHP operation wrappers. Inspect for reference; never patch
  vendor files for app behavior.
- `vendor/nativephp/mobile/src/Events/Camera/` and
  `vendor/nativephp/mobile/src/Events/Gallery/` — PHP event constructor
  contracts.
- `nativephp/ios/NativePHP/ContentView.swift` — iOS JavaScript event bridge.

The Sales Call native handler is the proven integration for `SalescallImage`.
Supporting Documents shares the native plugin but persists through
`CustomerProfileAttachment`. Browser file inputs use a separate base64 action.
Do not assume these storage models, validation, or synchronization rules are
interchangeable.

## 2. Architecture and ownership

The native photo path has six distinct responsibilities:

1. **Acquisition:** NativePHP invokes `Camera.GetPhoto` or `Camera.PickMedia`;
   iOS presents `UIImagePickerController` or `PHPickerViewController`.
2. **Event delivery:** Swift calls `LaravelBridge.shared.send`. The iOS bridge
   injects a `native-event` CustomEvent into the WebView and posts the same
   event payload to `/_native/api/events`. The injected helper dispatches a
   Livewire event named `native:` plus the PHP event class name. The HTTP
   controller separately constructs and dispatches the Laravel event.
3. **Correlation:** `SalescallPage` records a NativePHP capture ID in
   `$pendingPhoto` *before* calling `start()`. Its one Livewire listener for
   each native event resolves that ID to a Sales Call and image type.
4. **Local file handling:** Native camera/gallery files are temporary inputs.
   The Sales Call handler checks that the source path is a file and copies it
   to the Laravel `local` disk.
5. **Domain persistence and sync:** The Sales Call flow creates a
   `SalescallImage` row with `sync_status=pending`. Existing sync code later
   uploads the binary and sends the Sales Call image payload. The photo
   callback must not perform or redesign synchronization.
6. **Preview:** The component mirrors the canonical file to a web-readable
   preview path and reloads `$callPhotos`; the photo list sits outside the
   wizard's `wire:ignore` island so that this state can render.

The original regression came from registering the same NativePHP event names
twice on one Livewire component: once for Sales Call photos and once for
Supporting Documents. Livewire resolves a duplicate listener name to one
method, allowing one flow to shadow the other. Keep **one `#[On]` registration
per native event per component**. The current listener routes by capture ID to
Supporting Documents only when that ID exists in `$pendingAttachment`; other
IDs are handled by Sales Call photo logic.

## 3. Camera integration recipe

The current `SalescallPage::takePhoto()` pattern is:

```php
$capture = Camera::getPhoto();
$captureId = $capture->getId();

$this->pendingPhoto[$captureId] = [
    'salescall_id' => $salescallId,
    'type_id' => $typeId,
];

$startReportedSuccess = $capture->start();
```

The pending context must be registered before `start()`. Do not remove it or
show an invocation failure solely because `$startReportedSuccess` is false;
see [NativePHP compatibility notes](#5-nativephp-compatibility-notes).

The current Livewire callback contract is:

```php
#[On('native:'.PhotoTaken::class)]
public function onPhotoTaken(
    string $path,
    string $mimeType = 'image/jpeg',
    ?string $id = null,
): void {
    // Route a matching Supporting Documents ID first, if applicable.
    // Otherwise resolve the Sales Call context and call saveImageFromPath().
}
```

NativePHP Mobile 3.3.6 event constructors:

- `Native\Mobile\Events\Camera\PhotoTaken`: required `path`, optional
  `mimeType` (defaults to `image/jpeg`), optional `id`.
- `Native\Mobile\Events\Camera\PhotoCancelled`: optional `cancelled`
  (defaults to true), optional `id`.
- `Native\Mobile\Events\Camera\PermissionDenied`: required `action`,
  optional `id`.

The app-owned iOS plugin writes successful camera images as JPEG in the app's
temporary directory and sends `path`, `mimeType: image/jpeg`, and the capture
`id`. User cancellation sends `cancelled: true` and `id`. Camera operational
failure sends `cancelled: false` and `id`. `PhotoCancelled` has no `failure`
constructor parameter; do not add one to the native payload. Laravel records
a fixed generic operational failure marker when it receives `cancelled: false`.

The PHP callback also accepts a legacy no-ID single pending context for older
builds. New integrations should always use and return the capture ID.

## 4. Gallery integration recipe

The current `SalescallPage::pickFromGallery()` pattern is:

```php
$picker = Camera::pickImages('image')->single();
$captureId = $picker->getId();

$this->pendingPhoto[$captureId] = [
    'salescall_id' => $salescallId,
    'type_id' => $typeId,
];

$startReportedSuccess = $picker->start();
```

The successful iOS callback uses
`Native\Mobile\Events\Gallery\MediaSelected` with these fields:

- `success: bool`
- `files: array`
- `count: int`
- optional `error: string|null`
- `cancelled: bool` (optional in PHP, defaults to false)
- `id: string|null`

For an image, each native file object currently contains `path`, `mimeType`,
`extension`, and `type: image`. `onMediaSelected()` resolves the first file
through `NativeMediaPath::resolve()`, which accepts a path string, `file://`
URL, or an object with a path, then hands the result to the same
`saveImageFromPath()` method used by Camera.

When the user cancels PHPicker, iOS sends `success: false`, an empty `files`
array, `count: 0`, `cancelled: true`, and the ID. Import failures use
`success: false`, `cancelled: false`, an empty files list, and a fixed error
message. Keep these cases distinct from user cancellation.

## 5. NativePHP compatibility notes

### Asynchronous `start()` response

In the installed NativePHP Mobile 3.3.6 wrappers,
`PendingPhotoCapture::start()` and `PendingMediaPicker::start()` return true
only if the `nativephp_call()` result decodes to an object containing
`status: success`. On iOS, `BridgeResponse.success(data:)` returns the
function's data directly. The app-owned asynchronous `Camera.GetPhoto` and
`Camera.PickMedia` functions return an empty dictionary because their results
arrive later through events. That response is `{}`, so the PHP wrapper can
return `false` even after invoking the native picker.

This behavior is verified for this NativePHP version and these iOS bridge
functions. It is not a general statement about every NativePHP operation or
platform. Treat the diagnostic `start_reported_success` as the wrapper's
parsed result only; the correlated completion/cancel event determines the
workflow outcome.

### Constructor compatibility

`DispatchEventFromAppController` instantiates the PHP event using named payload
keys (`new $event(...$payload)`) before dispatching it. Unsupported or
misspelled keys can fail before the Livewire photo callback runs. Check the
installed PHP event constructor whenever editing Swift payloads, and test the
actual event names and keys. In particular, never attach an app-only
`failure` key to `PhotoCancelled`.

The iOS bridge's event endpoint returning HTTP 200 is not proof that the
Sales Call Livewire listener persisted an image. Continue tracing the actual
`native-event` → Livewire listener → capture match → file → row → preview path.

## 6. Livewire integration and lifecycle

- Preserve the existing wizard island in
  `resources/views/filament/pages/salescall-page.blade.php`: it is wrapped in
  `wire:ignore` because Livewire morphing previously reset Alpine `photoStep`
  from the capture stage to the start. The photo list remains outside that
  island so updated `$callPhotos` renders.
- The user action calls the Livewire method; the method records the ID-keyed
  `$pendingPhoto` context before starting the native operation. The native
  event must return the exact same ID.
- Public Livewire state survives ordinary component hydration/rerenders. A
  full page navigation/remount creates a new component and does not guarantee
  that an outstanding pending capture survives. Do not navigate away during a
  picker flow. The physical app background/resume lifecycle is not
  independently verified by this work.
- On return, `ContentView.swift` emits the event into the currently loaded
  WebView. Its helper dispatches `native:<fully qualified PHP event class>`
  through `window.Livewire.dispatch()`.
- Keep one method attribute such as
  `#[On('native:'.MediaSelected::class)]` for each native event. Do not add a
  second listener for another domain feature. Route by the capture ID into
  each domain's existing persistence handler, as `SalescallPage` now does.
- `capture_context_matched` consumes the ID entry. `capture_context_unmatched`
  means the ID was not present and the result is discarded. A repeated native
  callback is therefore unmatched after the first callback consumed its ID;
  it must not create another record.

## 7. Offline persistence and ownership

For NativePHP Sales Call photos, `saveImageFromPath()` currently:

1. Resolves the native path and checks `is_file()`.
2. Copies the file to the Laravel `local` disk under
   `storage/app/private/salescall_images/<uuid>.<extension>`.
3. Creates the domain-specific `SalescallImage` row with its Sales Call ID,
   image type ID, local path, local UUID, optional GPS values, and
   `sync_status=pending`.
4. Mirrors the canonical file to `public/salescall_images/<local_uuid>.<ext>`
   for preview delivery, then reloads the Sales Call photo list.

The tablet's iOS runtime roots Laravel storage under
`Library/Application Support/storage`; the SQLite database is
`Library/Application Support/database/database.sqlite`. `SalescallImage`
belongs to a `Salescall` and image type. It is not a universal attachment
record.

The native path currently verifies file existence and copy success. It records
detected MIME type and byte count for diagnostics, but does **not** reject by
MIME type or size. Camera writes JPEG. Gallery requests an image-compatible
representation and tries to normalize it to JPEG; if UIKit cannot decode it,
the plugin falls back to copying the original bytes and extension. Physical
HEIC/large-image behavior still requires device coverage.

The browser file-input fallback accepts `image/*`, reads a data URL, and calls
`saveImage()`. That separate method writes a `.jpg` file and creates the same
SalescallImage/preview state; it does not use the NativePHP capture ID flow.
Do not present that path as equivalent input validation to the native path.

Synchronization remains owned by `SyncService`. Pending SalescallImage rows
wait until their parent Salescall has a server ID, then the tablet sends the
local file directly to the Portal through the existing Sales Call image
endpoint. Salescall images are **not** pre-uploaded to S3; the Portal stores
the received file on its env-selected `filesystems.default` disk and returns
the Portal `server_id`. The tablet keeps the original `local_path` and stores
that `server_id`; it does not replace the path with a Portal or S3 path.

`TabletS3UploadService` remains in use for Customer Profile signatures and
profile attachments. Do not extend the sales-call image path back into S3
without a separate design decision. Keep photo acquisition and local
persistence offline-first; do not upload directly in the callback, clear
pending state on a false `start()` result, or change sync behavior as part of
a photo integration fix.

## 8. Diagnostic playbook

Laravel emits bounded `INFO` records named `salescall_photo_diagnostic` from
`SalescallPage`. Each has a `source` (`camera` or `gallery`), stage `event`, a
hash-based `capture_correlation` (not the raw capture ID), and
`component_instance`. Initiation stages also carry an `action_attempt_id`.
Diagnostics do not include image bytes, base64, raw capture IDs, customer or
Sales Call IDs, tokens, or complete file paths.

### Events and first-boundary interpretation

| Event | What it establishes |
|---|---|
| `photo_action_initiated` | User action reached the Livewire action. |
| `capture_id_generated` | NativePHP created/retrieved the operation ID. |
| `pending_capture_registered` | Domain context was stored before native start. |
| `native_operation_invoked` | PHP called the Camera/Gallery wrapper. |
| `native_operation_start_result` | Wrapper result only; `start_reported_success=false` is not proof the native picker was rejected. |
| `native_event_received` | The Livewire photo listener ran. |
| `capture_context_matched` | Callback ID matched and its pending context was consumed. |
| `capture_context_unmatched` | Callback ID had no pending entry; it was discarded without persistence. This also describes duplicate delivery after a successful first callback. |
| `capture_context_missing` | No ID or usable legacy single-slot context was available. |
| `photo_selection_cancelled` / `photo_capture_cancelled` | User cancelled Gallery / Camera. |
| `photo_capture_failed` | Camera returned the operational-failure cancellation contract. |
| `gallery_result_rejected` | Gallery returned a failed, empty, or contextless result. |
| `returned_file_validated` | Source path existed; MIME and size are diagnostic measurements, not rejection checks. |
| `returned_file_invalid` | Source file or Gallery path payload was invalid. |
| `photo_file_copy_failed` / `photo_file_copied_to_local_storage` | Local file copy failed / succeeded. |
| `salescall_image_persistence_failed` / `salescall_image_persisted` | SQLite model creation failed / succeeded. Successful rows remain `pending` for sync. |
| `photo_preview_refreshed` | Component photo state was reloaded; inspect `preview_available` and `photo_count`. |

Interpret the first missing stage. No native callback record after operation
invocation points toward native callback delivery or WebView event delivery.
An `HTTP 200` from `/_native/api/events` alone does not prove Livewire handling.
`native_event_received` without a match points to ID/lifecycle state. A matched
event without `returned_file_validated` points to result payload handling.
Copy, persistence, and preview events narrow later failures. Compare
`component_instance` values to detect a remount between registration and
callback. Use only the correlated diagnostic rows; avoid exporting or sharing
the full Laravel log because unrelated log records can contain other data.

### Laravel logs on an iPad

Laravel's configured single-file log is
`storage/logs/laravel.log`. NativePHP iOS maps that Laravel storage directory
to the app container's
`Library/Application Support/storage/logs/laravel.log`.

With the device connected and trusted, copy just the log using Apple's
`devicectl` (replace the UDID):

```bash
UDID='500C32B2-4769-5ACC-9AD4-884CE616A2F8'
DEST="$(mktemp -d)"
xcrun devicectl device copy from \
  --device "$UDID" \
  --domain-type appDataContainer \
  --domain-identifier com.kaisa.ommc2027tablet \
  --source 'Library/Application Support/storage/logs/laravel.log' \
  --destination "$DEST/laravel.log"
/usr/bin/grep 'salescall_photo_diagnostic' "$DEST/laravel.log"
```

Alternatively, in Xcode choose **Window → Devices and Simulators → [iPad] →
Download Container…**, then search the downloaded container for
`Library/Application Support/storage/logs/laravel.log`. If no Laravel stage
arrives, inspect the connected device console in Xcode or Console.app for the
same time window and app process; native Swift console output is separate from
Laravel's file log. Do not share unfiltered device logs.

### Simulator trace

For the booted simulator, locate Laravel's log in its app data container:

```bash
CONTAINER="$(xcrun simctl get_app_container booted com.kaisa.ommc2027tablet data)"
LOG="$CONTAINER/Library/Application Support/storage/logs/laravel.log"
/usr/bin/grep 'salescall_photo_diagnostic' "$LOG"
```

To inspect simulator unified logs around a test attempt:

```bash
xcrun simctl spawn booted log show --last 10m --style compact \
  --predicate 'process == "NativePHP-simulator"'
```

Filter the output by test time and picker/event terms. On the simulator used in
this investigation the PHPicker opened and selected images were copied into
the app's temporary Gallery directory, but the old Livewire listener collision
prevented Sales Call persistence. The corrected build could not be exercised
there because Xcode rejected the workspace and CoreSimulatorService was
unavailable.

## 9. Validation checklist

For a code change, use disposable test data and prove the relevant automated
cases:

- [ ] Camera `PhotoTaken` event name, constructor fields, and capture ID.
- [ ] Gallery `MediaSelected` event name, file-object payload, and capture ID.
- [ ] Camera cancellation and Camera operational failure reach distinct
      handlers with constructor-compatible payloads.
- [ ] Gallery cancellation and import failure are handled separately.
- [ ] Matched, absent, mismatched, and duplicate capture IDs.
- [ ] Source file exists and local copy succeeds; failure is diagnosed.
- [ ] The correct domain row is created with pending sync state.
- [ ] Preview mirror and component preview state refresh.
- [ ] Sync continues through the existing queue/service path.
- [ ] Existing Sales Call data is not reset; no migrations are introduced.
- [ ] `php artisan test --compact` for focused photo, event, and related
      attachment/sync tests.
- [ ] `php -l` for changed PHP files, `vendor/bin/pint --dirty --format agent`,
      and `git diff --check`.

For a physical release check, record the iPad model, iPadOS version, app build,
and test separately: Camera success, Gallery selection, Camera cancel,
operational failure if safely reproducible, local file and row, preview,
offline pending state, and later ordinary sync. Simulator Camera cannot prove
physical camera capture. Mark any unrun device case as unverified.

## 10. Known limitations

- Human confirmation for the accepted correction says the workflow works, but
  did not specify device model, OS version, app build, or separate Camera and
  Gallery outcomes. Do not attribute that confirmation to a particular
  environment.
- Automated tests exercise PHP event dispatch and local persistence; they do
  not exercise iOS presentation, real camera permission prompts, or physical
  file-provider behavior.
- The pre-correction simulator Gallery regression was reproduced. A
  post-correction simulator run was blocked by an invalid Xcode workspace and
  CoreSimulatorService failure. No independent physical iPad trace was
  collected in this work.
- Native path handling checks file existence and copy success but does not
  enforce MIME or maximum byte size. Do not imply a validation guarantee that
  the code does not provide.
- Background/resume preservation of an in-flight capture was not confirmed on
  a physical iPad. A full Livewire component remount can lose the public
  pending capture map.
- HEIC normalization and large-image behavior have not been physically
  accepted on an iPad. Gallery asks for a compatible representation and tries
  JPEG conversion, but it can fall back to the original file bytes.

## 11. Agent operating contract

Before changing these flows, agents must:

1. Read this playbook, the current `AGENTS.md`, the Work note, and current
   status/diffs. Inspect the source before proposing a new approach.
2. Reuse the existing Camera/Gallery calls, event IDs, callback handlers,
   local storage, and domain persistence where they fit.
3. Keep one Livewire listener per NativePHP event name on a component. Route
   event ownership by the pending ID; do not register competing handlers.
4. Keep acquisition and event dispatch separate from domain records. Do not
   use `SalescallImage` as a universal attachment model.
5. Preserve offline records and local files. Do not reset or uninstall an app,
   clear a device database, or run migrations without explicit Human approval.
6. Do not change synchronization or unrelated upload flows silently. Propose
   broader abstractions separately; this regression did not establish a
   reusable Photo Utility.
7. Report what evidence was observed, which environments were actually
   tested, and which acceptance cases remain outstanding. Do not claim a
   physical device test from simulator or PHP evidence.
8. Stop and obtain Human approval before destructive data changes or a broad
   architecture change.

## 12. Troubleshooting history

The October 15, 2026 regression presented as both Camera and Gallery opening
but leaving no visible Sales Call photo. The first supplied iPad Camera trace
ended at `native_operation_start_result` with
`start_reported_success=false`. That boolean was a NativePHP 3.3.6 async
response-shape false negative, not proof the native picker was rejected.
Earlier investigation also found Camera operational-failure payloads carrying
an unsupported `failure` named argument for `PhotoCancelled`; the native
payload was corrected to the supported `cancelled` and `id` fields.

The simulator Gallery reproduction showed picker selection and temporary file
copy, plus an HTTP 200 from the NativePHP event endpoint, but no Sales Call
listener diagnostic, image row, or preview. The decisive defect was duplicate
Livewire `#[On]` declarations for `PhotoTaken`, `MediaSelected`,
`PhotoCancelled`, and `PermissionDenied` in `SalescallPage`. Supporting
Documents declarations shadowed the Sales Call listeners; their ID resolver
did not own the Sales Call ID and returned without saving. The correction
removed those duplicate listener attributes and routed each event through the
single listener by its pending capture ID. Camera and Gallery both use that
fixed shared dispatch boundary.

Focused tests reproduced the failed Gallery Livewire dispatch before the
correction and passed after it. The final regression validation reported 53
tests and 273 assertions across Sales Call photos, event compatibility,
attachment synchronization, and iOS regeneration artifacts. Human later
confirmed that the corrected workflow works. Simulator post-fix and
independent physical device evidence remain limited as stated above.

**Reuse boundary:** This proven implementation does not provide a reusable
Photo Utility. Keep extraction as a separate follow-up proposal after
requirements from multiple concrete integrations are reviewed; do not perform
that extraction as part of a regression fix.
