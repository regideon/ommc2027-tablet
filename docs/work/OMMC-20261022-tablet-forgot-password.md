# OMMC-20261022 Tablet Forgot Password

- **Work ID:** `OMMC-20261022-tablet-forgot-password`
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Repositories:** Tablet `ommc2027-tablet`; Portal `ommc2027` (mirrored note).
- **Authorization:** Human authorized local implementation and validation only. Commit, push, and terminal delivery are not authorized.
- **Objective:** Let a Tablet user request a password reset email from the login page, and complete the reset through the existing signed Portal reset page.
- **Dependency:** The reset URL depends on `OMMC-20261022-admin-authentication-forgot-password` for the Portal signed reset route and `User::sendPasswordResetNotification()`. Deliver that dependency no later than this Work.

## Accepted decisions

- The Tablet requests an email reset link; it does not reset the password inside the app.
- Portal exposes a public `POST /api/auth/forgot-password` endpoint, rate-limited to 5 requests per minute.
- Portal returns the same generic `202` response for known, unknown, and throttled emails to prevent account enumeration.
- A dedicated `ForgotPasswordService` owns the reset-link request in each repository; it is not added to Tablet `SyncService`.
- Tablet Login links to a standalone `App\Filament\Pages\Auth\ForgotPassword` page at `/app/forgot-password`; the page owns the email form and submit action.
- Filament's panel-level password reset remains disabled on the Tablet; the new flow uses the Portal API.
- This Work supersedes the temporary hidden-link behavior from `OMMC-20261020-tablet-hide-forgot-password`.

## Constraints

- No migrations, seeded data, production database writes, or deployment.
- Preserve unrelated worktree changes.
- No commit or push.

## Task-owned files

Tablet:

- `app/Services/ForgotPasswordService.php` (new)
- `app/Filament/Pages/Auth/ForgotPassword.php` (new)
- `app/Filament/Pages/Auth/Login.php`
- `app/Providers/Filament/SaleshubPanelProvider.php`
- `tests/Feature/ForgotPasswordTest.php` (replaces `ForgotPasswordHiddenTest.php`)
- `tests/Feature/ForgotPasswordServiceTest.php` (new)
- This Work note

Portal:

- `app/Services/ForgotPasswordService.php` (new)
- `app/Http/Requests/Api/Sync/ForgotPasswordRequest.php` (new)
- `app/Http/Controllers/Api/Sync/AuthController.php`
- `routes/api.php`
- `tests/Feature/TabletForgotPasswordTest.php` (new)
- This Work note

## Unrelated worktree changes preserved

- Tablet: no unrelated changes were present when this Work started.
- Portal: the earlier admin forgot-password implementation and untracked `docs/ai-salescall-planner-guide.md`.

## Validation

- TDD RED: the page route returned 404, the page component did not exist, and the Login link was absent. The dedicated service test also failed before the service existed.
- Tablet focused: `ForgotPasswordServiceTest` + `ForgotPasswordTest` — 10 passed, 34 assertions. Covers the standalone page route, public access, Login navigation, successful submission, connection failure, Portal validation, rate limiting, server failure, and unconfigured Portal.
- Tablet related suites: the focused tests plus `OnlineAuthenticationTest`, `TrustedLoginRestoreTest`, and `FirstLoginPersistenceTest` — 47 passed, 208 assertions.
- Portal route check: the auth routes are grouped under `auth`; `POST api/auth/tablet-login` is `auth.tablet-login` and `POST api/auth/forgot-password` is `auth.forgot-password`. URLs and the `throttle:5,1` middleware are unchanged.
- Tablet route check: `GET app/forgot-password` is registered as `filament.saleshub.forgot-password`.
- Pint passed. `php -l` and `git diff --check` passed for changed PHP files.
- `graphify update .` rebuilt the Tablet graph; it reported existing non-UTF-8 documentation warnings and a non-zero PowerShell status for stderr warnings. No source code was changed by that command.

## Manual acceptance

- Pending. Verify the Tablet login link, standalone Forgot password page, successful submission, generic success message, error handling when Portal is unreachable, received Portal reset email, and successful password change through the signed Portal page.
- Rebuild/redeploy the Tablet app bundle before device acceptance.
- Confirm the Portal queue worker is running because the reset notification is queued.

## Delivery evidence

- Not delivered. Changes exist only in the local worktrees.
- No commit, push, or deployment was performed.
- Tablet worktree was clean at the start; this Work replaces the old hidden-link test and supersedes `OMMC-20261020-tablet-hide-forgot-password`.
