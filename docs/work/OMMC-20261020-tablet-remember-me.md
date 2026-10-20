# OMMC-20261020 — Tablet Remember Me

## Work state

**READY_FOR_TERMINAL_REVIEW** — Local implementation and automated validation are complete. Tablet manual acceptance and terminal delivery remain pending.

## Objective and repositories

- Tablet: `ommc2027-tablet` only.
- Make the Filament **Remember me** checkbox control whether a successful login survives a native app restart.
- Checked: restore the user after the app is closed and reopened.
- Unchecked: require the login screen on the next app launch.

## Authorization and constraints

- Human authorized local implementation and validation of this Work on 2026-10-20.
- No commit, push, deployment, migration, production database change, or Portal change.
- Preserve the approved Portal-authoritative online login and cached-hash offline login paths.
- No passwords or tokens are logged. The existing trusted-login marker stores only a user id and a hash of the API token.
- Terminal delivery remains unauthorized.

## Confirmed current behavior and design

- Laravel already receives the checkbox value and passes it to `Filament::auth()->login($user, $remember)`.
- The checkbox had no effect because `TrustedLoginService` wrote a marker for every successful login and `RestoreTrustedSession` restored it regardless of the checkbox.
- The persistent native cookie stores also kept the session cookie across app restarts.
- Design: only the Laravel `remember_web_*` cookie survives a native app restart. The trusted-login marker is written only when Remember me is checked. On unchecked login, the marker and any stale recaller cookie are cleared.
- Native launch cleanup: iOS removes every WebKit cookie whose name does not start with `remember_` before the first page load; Android persists only `remember_*` cookies in `LaravelCookieStore` and keeps session cookies in memory for the current process.
- Existing markers without a `remember` field remain valid once for compatibility; the new checkbox behavior applies from the next login.

## Task-owned files

- `app/Filament/Pages/Auth/Login.php`
- `app/Services/TrustedLoginService.php`
- `packages/ommc2027/ios-runtime/ContentView.swift`
- `packages/ommc2027/android-runtime/security/LaravelCookieStore.kt`
- `app/Console/Commands/BuildDeployCommand.php`
- `tests/Feature/RememberMeTest.php`
- `tests/Feature/TrustedLoginRestoreTest.php`
- `tests/Feature/IosRuntimeContentViewPatchTest.php`
- `tests/Feature/AndroidRuntimeRegenerationArtifactsTest.php`
- This Work note

## Unrelated changes to preserve

- Pre-existing worktree state: `storage/framework/lsp-b98a3a4d817a6042.php` is deleted and is not part of this Work.

## Decisions

- Use Laravel's standard remember-me recaller cookie as the durable credential; do not invent a second auth token.
- Keep `TrustedLoginService` as the recovery fallback when the recaller cookie is lost.
- Default legacy markers to remembered for one update cycle instead of logging every tablet user out.
- Native cookie changes are app-owned runtime patches so `native:install`/`native:build` cannot silently drop them.

## Implementation changes

- `TrustedLoginService::mark()` now accepts the Remember me decision. An unchecked login clears any previous marker; a checked login writes `remember: true` alongside the existing user id and token hash.
- `TrustedLoginService::restore()` refuses and removes markers explicitly recorded without `remember`. Legacy markers without the field remain valid once.
- `Login::finalizeAuthentication()` passes the checkbox to the marker service and queues deletion of a stale `remember_web_*` cookie when the checkbox is unchecked.
- iOS `ContentView.swift` removes every WebKit cookie whose name does not start with `remember_` before the first page load. The persistent `remember_web_*` cookie remains.
- Android `security/LaravelCookieStore.kt` persists only `remember_*` cookies. Session and CSRF cookies stay in memory for the current process only, so a process restart starts a fresh Laravel session.
- `BuildDeployCommand` now treats the Android cookie store as a durable runtime patch and verifies the new iOS and Android safeguards before and after packaging.

## Validation evidence

- TDD RED: 4 new PHP behavior tests failed before implementation (checked marker, unchecked marker/recaller cleanup, marker gating, explicit `remember: false` rejection); 4 native patch tests failed because the cleanup and durable Android source were absent.
- TDD GREEN: focused authentication and native-patch suites — **49 passed (222 assertions)**.
  - `tests/Feature/RememberMeTest.php`
  - `tests/Feature/TrustedLoginRestoreTest.php`
  - `tests/Feature/OnlineAuthenticationTest.php`
  - `tests/Feature/FirstLoginPersistenceTest.php`
  - `tests/Feature/IosRuntimeContentViewPatchTest.php`
  - `tests/Feature/AndroidRuntimeRegenerationArtifactsTest.php`
- `php -l` passed for all changed PHP files.
- `vendor/bin/pint --dirty --format agent` passed; it formatted `app/Filament/Pages/Auth/Login.php`.
- `git diff --check` passed.
- `graphify update .` completed: 11,662 nodes, 32,936 edges, 416 communities.
- Full suite with `php -d memory_limit=1G vendor/bin/pest --compact`: **742 passed, 29 failed, 4 skipped (5,530 assertions)**. No authentication, remember-me, or native-patch test failed. The failures observed in the final output are the existing environment/unrelated families: `CustomerCreatePageDiagnosticTest`, `CustomerMapLocationAutomationTest`, `ExampleTest`, `IosNativeRegenerationArtifactsTest` (missing generated iOS project on Windows), `SalescallPhotoUploadTest`, `SalescallStatusesMigrationTest`, `StartupMigrationClassicDiagnosticsTest`, and `SyncServiceSalescallPushTest`.

## Manual acceptance and remaining work

- Build a native artifact with `php artisan app:build` on the authorized build machine.
- Checked Remember me: sign in, kill and reopen the app, confirm the dashboard opens without credentials.
- Unchecked Remember me: sign in, kill and reopen the app, confirm the login screen appears.
- Explicit logout: confirm the marker and recaller are cleared and a restart lands on login.
- Confirm the iOS and Android build verification checks pass; the Android cookie store source must be copied into the generated project by `app:build`.
- No commit, push, or deployment has been performed. Terminal delivery remains unauthorized.

## Worktree ownership

- Owned paths are listed above.
- No unrelated changes will be staged or delivered.
