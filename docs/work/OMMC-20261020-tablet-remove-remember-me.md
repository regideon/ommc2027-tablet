# OMMC-20261020 — Tablet Remove Remember Me

## Work state

**READY_FOR_TERMINAL_REVIEW** — Local implementation and automated validation are complete. Tablet manual acceptance and terminal delivery remain pending.

## Objective and repositories

- Tablet: `ommc2027-tablet` only.
- Remove the Remember me checkbox from the login form.
- Restore the original tablet behavior: a successful login is remembered until explicit logout.
- Supersede `OMMC-20261020-tablet-remember-me`.

## Authorization and constraints

- Human selected “Stay signed in automatically” on 2026-10-20.
- No commit, push, deployment, migration, production database change, or Portal change.
- Preserve the separate uncommitted `OMMC-20261020-tablet-required-field-markers` work.
- Preserve the pre-existing deleted `storage/framework/lsp-b98a3a4d817a6042.php`.

## Confirmed behavior

- The previous Remember me implementation is removed.
- Every successful login writes the trusted-login marker again.
- The persistent native cookie stores keep the session across app restarts.
- Explicit logout still clears the marker and native/session cookies.
- The login form shows only Email address and Password.

## Task-owned files

- `app/Filament/Pages/Auth/Login.php`
- `app/Services/TrustedLoginService.php`
- `app/Console/Commands/BuildDeployCommand.php`
- `packages/ommc2027/ios-runtime/ContentView.swift`
- `packages/ommc2027/android-runtime/security/LaravelCookieStore.kt` (remove)
- `tests/Feature/TrustedLoginRestoreTest.php`
- `tests/Feature/IosRuntimeContentViewPatchTest.php`
- `tests/Feature/AndroidRuntimeRegenerationArtifactsTest.php`
- `tests/Feature/RememberMeTest.php` (remove)
- `tests/Feature/RememberMeRemovedTest.php`
- `docs/work/OMMC-20261020-tablet-remember-me.md` (remove)
- This Work note

## Unrelated changes to preserve

- The uncommitted `OMMC-20261020-tablet-required-field-markers` implementation and its Work note.
- The pre-existing deleted `storage/framework/lsp-b98a3a4d817a6042.php`.

## Decisions

- Revert the Remember me implementation instead of hiding a non-functional checkbox.
- Keep the trusted-login marker as the recovery mechanism and the persistent native cookie store as the primary session mechanism.
- Do not change validation, synchronization, Portal behavior, or session lifetime.
- Remove the old `OMMC-20261020-tablet-remember-me` Work note and its test/native cookie-store files because the feature is gone.

## Implementation changes

- `Login` overrides the Filament form to render only Email address and Password; the Remember me checkbox is no longer present.
- `Login::authenticate()` no longer reads or passes a remember flag; successful online and offline logins call `login($user)` and always write the trusted-login marker.
- `TrustedLoginService` is restored to the pre-feature behavior: `mark()` always writes, and `restore()` does not inspect a remember flag.
- iOS `ContentView.swift` no longer removes non-remember cookies at launch.
- `BuildDeployCommand` no longer patches/verifies the Android cookie store or the iOS remember-cookie cleanup.
- The old Remember me test and Work note are removed; the durable Android `LaravelCookieStore` patch is removed.

## Validation evidence

- TDD RED: `RememberMeRemovedTest` failed because the login form still rendered the Remember me checkbox.
- TDD GREEN: removal/login/native suites — **42 passed (195 assertions)**.
  - `tests/Feature/RememberMeRemovedTest.php`
  - `tests/Feature/TrustedLoginRestoreTest.php`
  - `tests/Feature/OnlineAuthenticationTest.php`
  - `tests/Feature/FirstLoginPersistenceTest.php`
  - `tests/Feature/IosRuntimeContentViewPatchTest.php`
  - `tests/Feature/AndroidRuntimeRegenerationArtifactsTest.php`
- Required-marker regression checks after the revert — **2 passed (62 assertions)** and **3 passed (60 assertions)**.
- `vendor/bin/pint --format agent` — **passed**.
- `git diff --check` — **passed**.
- `graphify update .` — **completed** (11,646 nodes, 32,888 edges, 411 communities).

## Manual acceptance and remaining work

- Rebuild/redeploy the tablet app bundle.
- Confirm the login screen shows only Email address and Password.
- Sign in, kill and reopen the app, and confirm the user returns to the dashboard without credentials.
- Confirm explicit logout clears the marker/session and a restart lands on login.
- No commit, push, or deployment has been performed. Terminal delivery remains unauthorized.

## Worktree ownership

- Owned paths are listed above.
- No unrelated changes will be staged or delivered.
