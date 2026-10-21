# OMMC-20261020 — Tablet Hide Forgot Password

## Work state

**READY_FOR_TERMINAL_REVIEW** — Local implementation and automated validation are complete. Tablet manual acceptance and terminal delivery remain pending.

## Objective and repositories

- Tablet: `ommc2027-tablet` only.
- Hide the **Forgot password?** link on the login page.
- Keep the change easy to reverse.

## Authorization and constraints

- Human directive: “Hide the forgot password comment it out for now.”
- No commit, push, deployment, migration, production database change, or Portal change.
- Preserve the uncommitted Remember me-removal work and the committed required-marker work.
- Do not change authentication, password validation, or the trusted-login flow.

## Task-owned files

- `app/Providers/Filament/SaleshubPanelProvider.php`
- `tests/Feature/ForgotPasswordHiddenTest.php`
- This Work note

## Unrelated changes to preserve

- The uncommitted `OMMC-20261020-tablet-remove-remember-me` implementation and Work note.
- The committed `OMMC-20261020-tablet-required-field-markers` implementation.

## Decision

- Comment out the panel’s `->passwordReset()` registration. Filament only renders the Forgot password link when password reset is enabled, so this hides the link without touching the custom login authentication logic.
- The line remains in place and commented for a simple future restore.

## Implementation changes

- `SaleshubPanelProvider` now has `// ->passwordReset() - hidden for now.` in place of the active registration.
- Filament no longer renders the **Forgot password?** link on the login page.
- The password-reset route is also disabled while the line is commented. Uncomment the line to restore both.

## Validation evidence

- TDD RED: `ForgotPasswordHiddenTest` failed because the login page still rendered the link.
- TDD GREEN: login/forgot-password/trusted-login/native suite — **43 passed (197 assertions)**.
- `vendor/bin/pint --format agent` — **passed** for the panel provider and new test.
- `git diff --check` — **passed**.

## Manual acceptance and remaining work

- Rebuild/redeploy the tablet app bundle.
- Confirm the login page shows only Email address, Password, and Sign in; no Forgot password link.
- Confirm normal login still works.
- If password reset is needed later, uncomment `->passwordReset()` and rebuild.
- No commit, push, or deployment has been performed. Terminal delivery remains unauthorized.

## Worktree ownership

- Owned paths are listed above.
- No unrelated changes will be staged or delivered.
