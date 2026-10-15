# OMMC-20261015-salescall-photo-capture-regression

- **Title:** OMMC V2 — Sales Call photo capture regression diagnostics
- **Repository role:** Tablet `ommc2027-tablet`; Portal `ommc2027` read-only reference.
- **State:** `TERMINAL_DELIVERED`
- **Human decision:** GO — terminalize the existing regression; Human confirms the corrected photo workflow works (2026-10-15).
- **Implementation authorization:** Bounded Camera/Gallery event handling correction, NativePHP event compatibility, diagnostics, and focused regression tests. No migrations, physical install, deployment, or production database changes.
- **Terminal authorization:** GO — commit the accepted regression on the existing branch and push only under the repository delivery protocol. No deployment, migrations, or production database changes.

## Objective

Trace Sales Call photo capture and selection from the UI through local persistence, previews, offline sync, and Portal attachment handling. Identify the regression and make a bounded correction only if evidence confirms the cause.

## Worktree reconciliation

- The Oct 15 investigation found no confirmed device root cause. The Human then authorized bounded diagnostics under this same Work ID.
- Tablet worktree has one unrelated staged/working-tree change: `nativephp/ios/NativePHP-Info.plist` is staged as added and absent from the working tree (`AD`). Preserve it untouched.
- Portal worktree was clean at inspection. No Portal files were changed.
- `graphify-out/graph.json` is absent, so Graphify was unavailable for this repository.
- No active browser session was available for console or network inspection. No physical iPad was available for device reproduction.

## Confirmed implementation trace

### Entry points and convergence

- Sales Calls > Photos > Add Photo enters the nested Alpine photo wizard. Store Facade and other requirements are data-driven `SalescallImageType` entries; the selected type ID is passed to the shared Take Photo or Gallery action.
- In NativePHP iOS/Android, Take Photo calls `SalescallPage::takePhoto()` → `Camera::getPhoto()`. Gallery calls `pickFromGallery()` → `Camera::pickImages('image')->single()`. Both register an ID-keyed pending `salescall_id` + `type_id`, then start the native operation.
- Camera returns `PhotoTaken(path, mimeType, id)`; gallery returns `MediaSelected(success, files, count, error, cancelled, id)`. The handlers resolve the matching pending ID and converge on `saveImageFromPath()`.
- In browser Safari/WebView fallback, hidden file inputs handle both camera capture and gallery. Each reads the chosen file as a data URI and calls `saveImage(salescallId, typeId, dataUri)`.
- Both implementations create a UUID-named file under `storage/app/private/salescall_images`, insert a `salescall_images` row with `sync_status=pending`, create a public preview mirror, then refresh `callPhotos` and `hasPhoto`. The gallery-native route normalizes file objects and `file://` paths through `NativeMediaPath`.
- Photo replacement is add-another-photo plus optional deletion of an existing image; there is no in-place replacement operation. `deleteImage()` removes the canonical file, preview mirror, and database row.
- Change Profile > Supporting Documents uses separate `pendingAttachment` handlers and `customer_profile_attachments` persistence. It shares the NativePHP camera/gallery plugin but not the Sales Call photo table/handlers. It is a distinct affected candidate if both areas fail; it is not the same persistence workflow.
- Offline images stay local with `sync_status=pending`. `SyncService` queues `SalescallImage` rows, and `TabletS3UploadService::ensureSalescallImageUploaded()` uploads the binary before the sync payload transitions. Related Portal attachment acceptance was not modified or exercised in this investigation.

### Regression evidence and history

- Current feature tests cover native camera/gallery operation registration, ID correlation, native gallery path payloads, cancellation, missing temporary files, multi-capture ordering, local persistence, preview mirrors, and deletion.
- Historical working implementation was introduced before commit `1d73017` (2026-08-07). Commit `0b4de05` (2026-08-10) isolated wizard UI state under `wire:ignore` to survive Livewire morphs. Later photo work added Change Profile attachments (`f2b3f59`, 2026-08-25). Sales Call camera/gallery handlers and their shared persistence path have no subsequent implementation change in the current branch history.
- Native camera source writes a temporary JPEG and fires a `PhotoTaken` event with path and capture ID. Native gallery uses PHPicker, compatible image representation, and copies selected images into app-owned temporary storage (HEIC converted to JPEG) before firing `MediaSelected` with the picker ID.
- Both native handlers previously returned silently when a non-null event ID was absent from `pendingPhoto`. Diagnostics now record that case as `capture_context_unmatched` with `discarded_without_persistence=true`.

## Diagnostic implementation

- Camera and Gallery emit the same structured Laravel info log message: `salescall_photo_diagnostic`. Each record includes a stage, source, a SHA-256-derived capture correlation (first 16 hex characters), and a random Livewire component-instance ID.
- Action initiation and native setup also record a random action-attempt ID, which connects initiation to capture-ID generation. Raw capture IDs are not logged.
- Covered stages: action initiated; native bridge unavailable; operation setup failed; capture ID generated; pending context registered; native operation invoked and its start-reported result; native event received; context matched/unmatched/missing; result cancelled/rejected; returned file valid/invalid; local copy succeeded/failed; `SalescallImage` persistence succeeded/failed; preview refreshed.
- Records include only bounded metadata such as Camera/Gallery, safe fixed failure codes, extension, MIME type, byte count, pending-capture count, exception class, and preview state. They omit full paths, image contents/base64, customer or Sales Call identifiers, tokens, and native error strings.
- One user operation produces a finite series of stage records; there is no polling, per-byte logging, or file-content logging. Existing PHP warnings for source/copy failures were also redacted to remove their file paths.
- iOS camera user cancellation and native capture/setup failure are distinguished with the constructor-compatible `cancelled` boolean (`true` for user cancellation, `false` for operational failure). Laravel records a fixed generic failure code for the latter. Gallery cancellation remains marked by its existing `cancelled` field; failed import is logged separately without logging the raw error text.
- `photoDiagnosticsComponentId` is assigned on `mount()` and survives Livewire hydration/rerenders as public component state. A page remount receives a new ID. Therefore logs can show whether an event reached a new component after navigation; native suspension alone does not clear the pending map if the same Livewire component state is restored. An actual iPad background/resume cycle is still needed to confirm runtime behavior.
- NativePHP Swift source removes raw capture IDs, error strings, and photo paths from its Camera/Gallery diagnostic prints. Photo selection, file copying, and persistence remain unchanged.

## Environment findings

- PHP CLI limits: `upload_max_filesize=512M`, `post_max_size=512M`, `memory_limit=512M`, `max_file_uploads=20`.
- Livewire temporary-upload settings show framework defaults: no configured disk, rules, or directory override. Sales Call photo code does not use Livewire temporary uploads and has no image validation; native paths copy files directly, while browser fallback sends base64 through a Livewire action.
- `local` disk resolves to `storage/app/private`, with non-throwing/non-reporting writes. Tests fake this disk and verify canonical file creation. Runtime filesystem permissions were not verified on a device.
- Camera permission is checked in the iOS plugin; denial fires a correlated permission event. Gallery uses PHPicker, which follows a separate system picker route. The package and NativePHP config include camera usage descriptions. Permission state on the test iPad was unavailable.
- Relevant Laravel log tail had no usable Sales Call photo-flow record. The available log entries were unrelated test/customer sync and local Portal connection warnings. No current app browser session was open, so JavaScript console errors and failed photo requests could not be inspected.
- No production data was read or changed. No disposable runtime capture was possible without a connected device or active app session.

## Reproduction matrix

- Automated local coverage: camera/gallery native action registration, valid JPEG-like temporary files, path normalization, cancel, missing source, repeated capture correlation, local row/file creation, preview generation/recovery, and deletion are covered by the focused test suite.
- Not reproduced in this environment: actual iPad camera capture, actual Safari/WebView file-input selection, cancel through OS pickers, HEIC device handoff, large camera image transport, offline native capture, simulated disk permission failure, or failed-upload retry on-device.
- Physical iPad acceptance remains required for both native and browser fallback, with console/network capture and verification of a new local row, canonical file, preview, pending sync state, and retry behavior.

## Validation evidence

- `php artisan test --compact tests/Feature/SalescallPhotoUploadTest.php tests/Feature/SalescallPhotoFlowDiagnosticsTest.php` → **26 passed (94 assertions)**, including action logging, bridge acceptance, matching/unmatched/missing context, Camera/Gallery success, cancellation vs processing failure, persistence failure, uncorrelated cancel cleanup, and component remount identity.
- `php artisan test --compact tests/Feature/TabletS3UploadTransitionTest.php tests/Feature/SyncServiceBinaryFailureResilienceTest.php` → **9 passed (59 assertions)**.
- `php artisan test --compact tests/Feature/IosNativeRegenerationArtifactsTest.php` → **10 passed (86 assertions)**.
- `php -l` passed for `SalescallPage.php` and both focused test files.
- `vendor/bin/pint --dirty --format agent` passed and formatted only the changed PHP files.
- `git diff --check` passed. The unrelated `NativePHP-Info.plist` staged/working-tree change remains untouched.
- The Swift plugin was not compiled on an iPad/Xcode build in this environment; a diagnostic iOS build is required to collect a device trace.

## Device evidence and NativePHP bridge reconciliation (2026-10-15)

- The supplied iPad trace records action initiation, capture ID creation, pending-context registration, native operation invocation, then `bridge_accepted=false`. It contains no event, persistence, or preview stage.
- In NativePHP Mobile 3.3.6, `PendingPhotoCapture::start()` and `PendingMediaPicker::start()` return `true` only when `nativephp_call()` returns JSON with `status=success`. The iOS `BridgeResponse.success(data:)` implementation returns the function data directly. Both app-owned Camera iOS bridge functions return an empty dictionary for asynchronous operations, encoded as `{}`. Therefore the observed false value is a wrapper/parser false negative; it does not establish that the native function was rejected. Camera and Gallery share this result behavior.
- The current generated iOS plugin registration includes `Camera.GetPhoto` and `Camera.PickMedia`; generated Camera source matched the package source before this correction. The physical installed app binary cannot be verified from the supplied trace, so installed registration/build freshness remains unconfirmed.
- The diagnostic field was renamed from `bridge_accepted` to `start_reported_success` so the log does not imply native rejection. The Sales Call caller does not branch on this return value.
- A separate confirmed camera event contract defect existed in the app-owned iOS plugin: capture/presentation failures included a `failure` named field, but NativePHP 3.3.6 `PhotoCancelled` only accepts `cancelled` and `id`. The plugin now reports cancellation as `cancelled=true` and operational failure as `cancelled=false`; Laravel emits a fixed generic failure code for the latter. This keeps the event compatible and distinguishable without sending an unsupported field.
- `PhotoTaken`, Gallery `MediaSelected`, and Camera `PermissionDenied` payload names match their PHP event constructors. The supplied `$granted` and `$permanentlyDenied` errors map to the app-owned Geolocation `PermissionRequestResult` emitter, whose payload does not match that event constructor. That flow is separate from the Sales Call Camera/Gallery events and was not changed under the no-unrelated-authentication constraint.
- The native operation start result does not explain the absent photo callback. No `native_event_received` record was supplied, so callback delivery/dispatch, installed binary freshness, and the remaining Gallery failure stage still require device verification.

## Latest validation

- Focused Sales Call photo and native event compatibility tests: **29 passed (104 assertions)**.
- The two operation-start tests now simulate the observed false return while asserting capture context remains registered and diagnostics report `start_reported_success=false`.
- The PhotoCancelled controller contract test verifies that the failure payload can be instantiated and dispatched without an unknown named parameter.
- PHP lint, Pint, and `git diff --check` are rerun for the latest changes before delivery.

## Proposed bounded next step

### Device trace collection

1. After separate Human GO for native build/install, build and install the iOS app from this branch so the app-owned Camera plugin contains the constructor-compatible PhotoCancelled payload and corrected diagnostic field.
2. On the iPad, trigger one Camera capture and one Gallery selection from Sales Calls > Photos. Include one normal cancel, one simulated/unavailable-camera failure if practical, and one successful save. Record whether the app was backgrounded or the Sales Call page was left before returning.
3. Connect the iPad to the Mac, use Xcode **Window → Devices and Simulators → [iPad] → Download Container…**, then locate the app's Laravel `storage/logs/laravel.log` in the downloaded container. Filter it for `salescall_photo_diagnostic` (for example, `rg 'salescall_photo_diagnostic' laravel.log`). Keep the whole small block for each `capture_correlation` and `action_attempt_id`; do not export the selected images.
4. For each attempt, check the ordered stages: action → ID → context registration → native invocation → event → context match → file validation → local copy → DB persistence → preview refresh. A registered ID with no later event points to the native callback/transport. An event with `capture_context_unmatched` shows the result was discarded without persistence; compare `component_instance` to determine whether the page remounted. A `photo_capture_failed` or `gallery_result_rejected` identifies the native import stage. Persistence/copy/preview failures are explicit.
5. If the device log has no corresponding event record, collect the app's sanitized Xcode device console output for that same attempt and note iPadOS version, app build, and whether the app was backgrounded. Do not include credentials, selected photos, customer identifiers, or full file paths.

## Physical device verification attempt (2026-10-15)

- The worktree was rechecked before device preparation. The existing `NativePHP-Info.plist` staged-add/working-tree-delete state and all other changes were preserved.
- The generated iOS Camera source at `nativephp/ios/NativePHP/Bridge/Plugins/Camera/CameraFunctions.swift` byte-matches the current package source, and `nativephp/ios/NativePHP/app.zip` contains `SalescallPage.php` with the `salescall_photo_diagnostic` and `start_reported_success` markers. The existing signed Debug app bundle has bundle ID `com.kaisa.ommc2027tablet`, version `1.0.0` / build `59`, and its build timestamp follows the source and app ZIP timestamps. This is evidence the existing local build was generated with the accepted correction; it does not establish that it is installed on Janus' iPad.
- The generated app bundle declares the camera usage description. Gallery uses PHPicker; no `NSPhotoLibraryUsageDescription` key is present in this bundle.
- Physical device discovery failed before an install could be attempted: `xcrun devicectl list devices` returned `Timed out waiting for CoreDeviceService to fully initialize (com.apple.coredevice.devicectl error 1)`. Installed app identity/version and device data could therefore not be inspected. No device command that modifies state was run.
- A second read-only inventory check with `xcrun xcdevice list` enumerated only the Mac; the target iPad was absent. USB inventory also did not show the iPad. CoreSimulatorService is unavailable on this host, so simulator installation is unavailable as well.
- The build and install were not run. The project startup gate automatically runs `app:startup-migrate` at app launch and applies any pending migrations. Since the iPad database migration state could not be read, a no-migration installation cannot be established under the current device connection failure. The local build uses the same bundle identifier, and Apple's in-place app installation ordinarily retains its app data, but this alone does not prove the actual target install path or startup schema state. Do not install until the device can be queried and the migration state is confirmed current (or a no-migration startup path is explicitly approved).
- At the time of this device-readiness attempt, source inspection found three remaining Camera delegate failure payloads with unsupported `failure` fields. The next authorized correction below removes those fields and marks operational failure with `cancelled=false`.
- No physical Camera, Gallery, cancellation, persistence, preview, or diagnostic-log verification occurred. After CoreDevice is available and a no-migration in-place update is established, install with `xcrun devicectl device install app --device 500C32B2-4769-5ACC-9AD4-884CE616A2F8 <signed .app path>`; this command installs an app bundle and does not request uninstall or data removal. First query the installed bundle with `xcrun devicectl device info apps --device 500C32B2-4769-5ACC-9AD4-884CE616A2F8 --bundle-id com.kaisa.ommc2027tablet`. For read-only log retrieval, use Xcode Devices and Simulators → the iPad → Download Container, then search the downloaded container's `Library/Application Support/storage/logs/laravel.log` with `rg 'salescall_photo_diagnostic' <log path>`. A `devicectl` alternative after service recovery is `xcrun devicectl device copy from --device 500C32B2-4769-5ACC-9AD4-884CE616A2F8 --domain-type appDataContainer --domain-identifier com.kaisa.ommc2027tablet --source 'Library/Application Support/storage/logs/laravel.log' --destination <local log path>`.

### Current readiness

- **Held before device modification:** Janus' iPad and installed app/offline database state remain unverified. The three Camera delegate failure payloads are now corrected in package source and covered by tests; the generated iOS app has not been rebuilt to include this final correction.
- Continue only after a separately approved native build, device availability, target app/data inspection, and confirmation that startup migration status is current.

## Callback payload correction and migration readiness (2026-10-15)

- `CameraPhotoDelegate` previously sent `failure` alongside `cancelled` for image retrieval, JPEG encoding, and photo-file write failures. NativePHP Mobile 3.3.6 `PhotoCancelled` accepts only `cancelled` and optional `id`. The three operational failure payloads now send `cancelled=false` and optional `id`; user cancellation remains `cancelled=true`. Camera success remains `path`, `mimeType`, and optional `id`. Gallery success/cancel payloads use the `MediaSelected` constructor fields. Permission denial uses `action` and optional `id`.
- The async result mismatch and event payload contract are documented in `docs/Photo-Integration-Playbook.md` for future photo integration work.
- `NativePHPApp` runs `app:startup-migrate` before allowing the WebView to load on every app launch. The command checks the SQLite `migrations` table against the bundled migration files; it reports `current` when none are pending, otherwise Laravel applies the pending migrations and reports `migrated`. Migration errors block app readiness. The PHP service does not itself clear or replace the SQLite database.
- iOS sets `DB_DATABASE` to `Library/Application Support/database/database.sqlite`. The app's Laravel storage/logs is separately rooted under `Library/Application Support/storage`. A same-bundle-ID in-place iOS app update preserves the application data container; the NativePHP bundled PHP app extraction replaces `Documents/app`, while the SQLite file is in Application Support. Thus installation alone should retain the database file, but first launch can change its schema/data if a migration is pending. Existing rows are preserved only to the extent that applied migrations preserve them; this cannot be assumed without reviewing the pending migrations.
- Before any future installation/launch, download a read-only copy of the existing app container and inspect the copied `database/database.sqlite` `migrations` table against migration filenames in the proposed app bundle. If any migration is pending, any migration is destructive/data transforming, the schema state cannot be established, or the container backup cannot be verified, stop and obtain explicit Human approval before proceeding. Human approval is also required for any uninstall/reinstall, reset/clear, database mutation, or migration execution. This Work authorizes none of those actions.
- Non-destructive verification checklist: (1) verify the physical iPad UDID, installed bundle ID/version, and signing team; (2) download and retain a local copy of the app container before installation; (3) compare the copied SQLite migration table with the candidate bundle's migrations without opening the app; (4) proceed only if migration state is current and a backup is readable; (5) install over the existing `com.kaisa.ommc2027tablet` app using the signed `.app` and `devicectl device install app` without uninstalling; (6) launch once and verify the startup artifact reports `current`; (7) request Human verification for Camera success, Gallery selection, and Camera cancel/operational failure; (8) download the container again and correlate `salescall_photo_diagnostic` stages with the canonical image file, `salescall_images` row, preview file, and pending sync state. Stop if any step indicates data change outside the approved scope.
- The app-owned iOS package source is corrected and the regression tests pass, but the pre-existing generated Xcode source/app bundle still reflects the older generated state. It was intentionally not regenerated because native build/install are on HOLD. A new approved build is required before device testing can exercise this source correction.
- Physical acceptance remains on HOLD. The device was not modified; previous inventory attempts did not enumerate Janus' iPad. A connected-device trace is still required.
- Validation for this correction: `php artisan test --compact tests/Feature/SalescallPhotoFlowDiagnosticsTest.php tests/Feature/SalescallPhotoUploadTest.php` → **30 passed (114 assertions)**; PHP lint passed for `SalescallPhotoFlowDiagnosticsTest.php`; Pint passed; `git diff --check` and `git diff --cached --check` passed. No native build, installation, migration, or device test was run.

## Native Livewire listener collision and simulator trace (2026-10-15)

- A disposable Gallery attempt on the existing iOS simulator reached the Photos picker and closed after image selection. The previous Laravel log block for its redacted capture correlation contains operation initiation, ID generation, pending registration, invocation, and the known false-negative `start_reported_success=false`; it has no `native_event_received` entry. Simulator unified logs show the native `/_native/api/events` request returned HTTP 200, and the app-owned Gallery temp directory gained newly copied JPEG files. This places the first application-handling failure after native import/bridge HTTP handling and before the Sales Call listener's first diagnostic. HTTP 200 alone is not proof that a Livewire method handled the event.
- Source inspection found duplicate Livewire `#[On]` registrations for `PhotoTaken`, `MediaSelected`, `PhotoCancelled`, and `PermissionDenied` on `SalescallPage`: one set for Sales Call Photos and another set for Change Profile Supporting Documents. Livewire's listener map resolves a duplicate event name to one method, so the later Supporting Documents methods shadowed Sales Call's methods. The attachment handlers only resolve IDs in `pendingAttachment`; a Sales Call capture ID is absent there, so they return without Sales Call diagnostics, local persistence, a `SalescallImage`, or preview refresh. This accounts for the shared visible Camera and Gallery failure. The pre-correction `Livewire::test()->dispatch('native:...MediaSelected', ...)` reproduction failed to create an image (0 rows); the same dispatch passed after correction.
- The bounded correction leaves one Livewire registration per native event in the Sales Call photo handlers and routes an event to the Supporting Documents method only when its ID is present in `pendingAttachment`. Existing Sales Call ID correlation, validation, offline local storage, DB persistence, preview update, and sync state are preserved. Unmatched and repeated IDs continue through the Sales Call unmatched-capture diagnostic and are discarded without a second write.
- Added actual Livewire dispatch tests for Gallery persistence and Camera persistence/preview state, plus duplicate Gallery callback behavior. Existing tests cover constructor contracts, cancellation/failure distinction, matched/missing/unmatched contexts, local persistence, and persistence exceptions.
- The simulator UI reproduction confirms the pre-correction failure. A rebuild was attempted with the documented `php artisan native:build --simulated --no-tty` workflow. Laravel packaging completed and the generated `nativephp/ios/NativePHP/app.zip` contains the corrected `SalescallPage.php`, but Xcode could not build: it reports `NativePHP.xcworkspace` is not a workspace file, and CoreSimulatorService could not initialize. No simulator app install/relaunch occurred, so the updated PHP bundle has not been exercised end to end in the simulator. The simulator Gallery success criterion remains outstanding pending repair of the Xcode workspace / CoreSimulator environment.
- The synthetic Gallery fixture was removed from the simulator Photos library (moved to Recently Deleted) and its local temp fixture was removed. No Sales Call image row was created in the simulator, and no photo was synced.
- Camera uses the same duplicate event name and listener map, so the shared component defect applies to Camera by source-level tracing. The Camera native event contract and Laravel event path pass automated tests; successful physical capture, persistence, and preview still require the held iPad acceptance.
- No physical iPad installation or terminal delivery was attempted. The `NativePHP-Info.plist` staged-add/working-tree-delete state remains untouched.

### Latest focused validation

- `php artisan test --compact tests/Feature/SalescallPhotoUploadTest.php tests/Feature/SalescallPhotoFlowDiagnosticsTest.php tests/Feature/TabletS3UploadTransitionTest.php tests/Feature/SyncServiceBinaryFailureResilienceTest.php` → **43 passed (187 assertions)**.
- The newly added Livewire dispatch regression test failed before the listener routing correction and passed afterward. Camera dispatch persisted one local `SalescallImage` and refreshed `hasPhoto`; Gallery dispatch persisted one image; duplicate Gallery delivery created no second row and logged the unmatched discard; Camera cancellation and operational failure reached distinct diagnostics through the registered Livewire event.
- `php -l` passed for `SalescallPage.php` and both focused test files; `vendor/bin/pint --dirty --format agent` passed; `git diff --check` and `git diff --cached --check` passed. The simulator build remains blocked by the invalid workspace/CoreSimulator environment described above.

## Terminalization (2026-10-15)

- Confirmed root cause: duplicate Livewire `#[On]` registrations for the same NativePHP Camera/Gallery events in `SalescallPage` caused Supporting Documents handlers to shadow the Sales Call handlers. Their capture ID lookup returned without creating Sales Call photo state. Camera and Gallery share this defect.
- Final correction: retain one listener for each NativePHP event and route by the registered capture ID to either Sales Call Photos or Supporting Documents. Keep constructor-compatible Camera cancellation/failure payloads and preserve diagnostic logging, local offline persistence, `SalescallImage`, preview refresh, and synchronization state.
- Human functional confirmation: Human confirms the corrected photo workflow works. The device model, OS version, app build, and separate Camera/Gallery observations were not specified in the confirmation; therefore this is recorded as Human acceptance, without inventing device evidence.
- Environments independently verified: PHP CLI focused tests described below. On iOS Simulator (iPad Pro 13-inch M5, iOS 26.1), the pre-correction Gallery failure was reproduced. The post-correction simulator build was attempted, but Xcode rejected `NativePHP.xcworkspace` and CoreSimulatorService was unavailable; no corrected simulator app was installed or run. No physical iPad was connected or independently verified in this session.
- Final automated validation: 53 focused Sales Call photo, NativePHP event, attachment synchronization, and iOS regeneration artifact tests passed (273 assertions); PHP lint passed for the changed PHP source/tests; Pint passed; staged and unstaged `git diff --check` passed.
- Physical-device limitation: no independent trace identifies the exact Human-tested iPadOS/app build, and no new device-side Camera/Gallery diagnostics were collected. The Human has confirmed function; future physical verification should record device/iPadOS/app build and confirm both sources separately if deployment readiness requires reproducible device evidence.
- Future playbook findings retained here: NativePHP Mobile 3.3.6 async `start()` can report false for an accepted asynchronous operation returning `{}`; `PhotoCancelled` accepts only `cancelled` and `id`; duplicate Livewire listener names silently route events to one handler; use a single listener and dispatch by capture ID. No Engineering Playbook or reusable Photo Utility was implemented in this terminalization.
- Terminal delivery scope: app-owned SalescallPage correction/diagnostics, Camera iOS event payload compatibility, focused regression tests, and this Work note. The existing staged-add/working-tree-delete `NativePHP-Info.plist` change and untracked `docs/Photo-Integration-Playbook.md` were preserved and excluded from the Work commit.
- Delivery evidence: commit hash is reported in the terminal response; commit includes `Work-ID: OMMC-20261015-salescall-photo-capture-regression` and `Work-State: TERMINAL_DELIVERED` trailers.

## Task-owned files

- `app/Filament/Pages/SalescallPage.php` — structured logs and safe pending-context outcomes.
- `packages/ommc2027/camera/resources/ios/CameraFunctions.swift` — fixed failure codes and path/ID/error redaction in camera/gallery prints.
- `tests/Feature/SalescallPhotoUploadTest.php` — focused diagnostic and persistence tests.
- `tests/Feature/SalescallPhotoFlowDiagnosticsTest.php` — component lifecycle coverage; removed the unused generic log sink test.
- `docs/Photo-Integration-Playbook.md` — pre-existing untracked future-work artifact; retained without changes and excluded from terminal commit scope.
- This Work note.

## Manual acceptance and blockers

- The supplied trace identifies a NativePHP 3.3.6 false-negative start result and a corrected Camera failure-event contract. The underlying missing callback and Gallery persistence failure remain unconfirmed until a post-correction device trace is collected.
- Physical iPad verification remains on HOLD until separate Human GO for build/install, CoreDevice can enumerate the iPad, and the installed app/offline database state can be inspected with migrations confirmed current. The corrected package source must first be included in a new native build. Camera and Gallery success/cancel, app background/resume, and page navigation/remount still require acceptance afterward.
- Do not modify Customer Push/Pull, authentication, Portal, migrations, unrelated upload flows, or synchronization architecture under this Work.

## Engineering knowledge handoff and final reconciliation (2026-10-16)

- Canonical operational guide added at `docs/playbooks/nativephp-photo-integration.md`. It records the accepted NativePHP Mobile 3.3.6 Camera/Gallery event contracts, async start-result false-negative, single-listener/capture-ID routing rule, lifecycle boundaries, local persistence and sync ownership, diagnostics, device log retrieval, and validation/acceptance limits. It documents the existing implementation and does not extract a reusable utility or redesign the architecture.
- `AGENTS.md` now directs future Camera, Gallery, photo attachment, and NativePHP media-event work to read the canonical guide before changing those flows.
- Reconciled root cause: duplicate Livewire `#[On]` listener registrations on `SalescallPage` shadowed the Sales Call listeners with Supporting Documents handlers. Those handlers did not find the Sales Call ID in `pendingAttachment`, so callbacks produced no Sales Call file, row, or preview. The bounded fix uses one event listener and routes by capture ID. The NativePHP async `start()` false result was a reporting mismatch, not evidence that native invocation failed; the incompatible `PhotoCancelled` failure payload was corrected separately.
- Human confirms the corrected photo workflow works. The confirmation did not identify device, OS, build, or separately describe Camera and Gallery, so no additional physical-device environment is claimed. Independently observed: pre-correction Gallery failure on the iOS Simulator (iPad Pro 13-inch M5, iOS 26.1). Post-correction simulator build/run was blocked by Xcode rejecting `NativePHP.xcworkspace` and unavailable CoreSimulatorService. No physical iPad trace or post-correction native app run was independently obtained.
- Final automated validation on PHP 8.5.7: focused Sales Call photo, native event, attachment synchronization, and iOS artifact tests **53 passed (273 assertions)**; PHP lint passed for `SalescallPage.php` and both focused photo test files; Pint passed; staged and unstaged `git diff --check` passed.
- Terminal handoff scope for this follow-up is limited to this Work note, `docs/playbooks/nativephp-photo-integration.md`, and `AGENTS.md`. The existing short `docs/Photo-Integration-Playbook.md` remains unchanged. No reusable Photo Utility, Engineering architecture refactor, migration, reset, device installation, or deployment was performed.
- Delivery state remains `TERMINAL_DELIVERED`. The underlying regression correction is in the previously delivered commit; this follow-up commits the canonical playbook and discoverability/work-note updates separately. Human functional confirmation is recorded; reproducible device/build evidence remains limited as described above.
