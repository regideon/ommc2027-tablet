# OMMC-20261015 — Tablet Online Authentication

## Work state

**TERMINAL_DELIVERED** — Human accepted the bounded implementation and explicitly authorized terminal delivery. No deployment was performed.

## Objective and repositories

- Portal: `/Users/highlite/Workspace-Kaisa/PHP/ommc2027`
- Tablet: `/Users/highlite/Workspace-Kaisa/PHP/ommc2027-tablet`
- Establish Portal as the authority for online Tablet credentials while preserving the approved cached-hash offline login.

## Authorization and constraints

- Human authorized bounded implementation and terminal delivery for this Work ID.
- No migrations, production database changes, or deployment.
- Customer Push and Pull implementation were out of scope. Existing pull-after-login behavior remains; no push or pull endpoints were changed.
- Both worktrees were clean before work began. No unrelated or RV changes were present at initial inspection.

## Confirmed root cause and contract

- Before correction, Tablet `Login::authenticate()` checked the cached SQLite password hash before checking Portal reachability. A changed Portal password could therefore be rejected without contacting Portal.
- A matching cached password established a session before Portal revalidation, and a failed refresh result was ignored.
- Online login now authenticates against `POST /api/auth/tablet-login` first. Portal 401, other HTTP errors, invalid responses, and local identity or role persistence failures reject login without a session or cached-password fallback.
- The login-specific Portal responsiveness probe treats any HTTP response as reachable, so HTTP errors cannot become offline authentication. Only a connection exception permits the existing offline path; offline login still verifies the supplied password against the cached hash. Other sync features retain their prior reachability behavior.
- Successful Portal responses reconcile the existing local identity by email, current hashed password, API token, server identity/profile fields, and roles in a local database transaction. The local session is established only after this transaction succeeds. The subsequent data pull and notification behavior are unchanged.
- No Portal endpoint, API token lifecycle, Portal guard, or synchronization endpoint was changed.

## Implementation changes

- Tablet `Login` now routes reachable login attempts through Portal before local password validation and creates a session only after successful identity reconciliation.
- Tablet `SyncService` adds a login-specific response-presence probe, distinguishes connection failures from HTTP and persistence errors, validates required response fields, and transactionally persists the returned identity and roles.
- Tablet tests cover changed/stale local hashes, new and existing identities, Portal rejection statuses 401/403/422/500/503, HTTP error responses from ping, genuine connection loss, valid and invalid offline credentials, persistence failure, and session establishment/rejection.
- Portal tests add invalid-password coverage alongside the existing successful tablet-login and token issuance checks.
- No passwords or tokens are logged. The Tablet stores the Portal-provided password hash, never the plaintext password.

## Validation evidence

- Tablet focused suites: `vendor/bin/pest tests/Feature/OnlineAuthenticationTest.php tests/Feature/FirstLoginPersistenceTest.php tests/Feature/TrustedLoginRestoreTest.php` — **37 passed, 174 assertions** using the test database and mocked Portal responses.
- Portal focused suites: `vendor/bin/pest tests/Feature/DrmUserSeederTest.php tests/Feature/TabletBaseLocationSyncTest.php` — **5 passed, 17 assertions**.
- `php -l` passed for all changed PHP files.
- `vendor/bin/pint --dirty --format agent` passed; it formatted `SyncService.php`.
- Portal `vendor/bin/pint --dirty --format agent` passed.
- Final `git diff --check` is recorded at review close.
- The `graphify` command and graph output were unavailable in this environment; no graph update was run.

## Remaining deployment checks and deferred security review

- Deferred review: device-side Portal password-hash exposure, long-term offline authentication architecture, and token expiration/revocation policy. The cached hash is not plaintext, but possession enables offline guessing against it.
- Deferred acceptance: verify the built Tablet’s effective `SYNC_SERVER_URL` and perform production Tablet login acceptance testing under the separately approved release process. No environment files or production credentials were inspected; no production deployment or database change was made.
- No implementation blocker remains. Delivery SHAs and remote push verification are reported with the terminal delivery; this note does not embed its own commit SHA.

## Worktree ownership

- Tablet owned paths: `app/Filament/Pages/Auth/Login.php`, `app/Services/SyncService.php`, `tests/Feature/FirstLoginPersistenceTest.php`, `tests/Feature/OnlineAuthenticationTest.php`, and this note.
- Portal owned paths: `tests/Feature/DrmUserSeederTest.php` and this note.
- No unrelated changes were intentionally included.
