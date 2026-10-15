# Login Logic ng Tablet App

**File:** `app/Filament/Pages/Auth/Login.php`  
**Portal file:** `app/Http/Controllers/Api/Sync/AuthController.php` → method `tabletLogin()`

---

## Overview

Ang tablet app ay **offline-first**. Ibig sabihin, kapag **walang sagot** ang Portal (connection failure), pwedeng mag-login pa rin ang user — **basta nakapag-login na sila dati** at naka-save na ang account sa local SQLite database ng device. Kapag reachable ang Portal (kahit anong HTTP response), sa Portal muna ang authentication — walang cached-password fallback.

---

## Login Behavior

**Portal-first ang authentication.** Ang `isPortalResponsive()` ay `true` kapag **may kahit anong HTTP response** ang Portal (success man o error) — hindi lang kapag `200`. Ang offline path ay para lang sa **talagang walang sagot** (connection failure).

1. **Reachable ang Portal** (may kahit anong HTTP response): sa Portal muna mag-authenticate — `POST /api/auth/tablet-login`. Ang `401` at iba pang HTTP errors ay tumatanggi sa login — **walang cached-password fallback kapag reachable ang Portal.**
2. **Kapag success:** i-reconcile ang local identity (email match), password hash, API token, at roles sa **isang local transaction**; saka pa lang magsimula ng session at tumakbo ng data pull.
3. **Walang sagot talaga** (connection failure): fall back sa offline path — i-verify ang binigay na password laban sa cached hash at magsimula ng local session. Ang sync features ay nananatili sa dating reachability behavior.

**Code (Login.php):**
```php
$user = User::where('email', $email)->first();

if (! $sync->isConfigured()) {
    throw ValidationException::withMessages([
        'data.email' => 'Portal authentication is not configured on this device.',
    ]);
}

// Reachable ang Portal kapag may kahit anong HTTP response (hindi lang 200).
if ($sync->isPortalResponsive()) {
    $tokenResult = $sync->refreshToken($email, $password);

    if ($tokenResult->success) {
        // Na-reconcile na ng refreshToken() ang identity, roles, password hash, at token.
        $user = User::where('email', $email)->firstOrFail();
        Filament::auth()->login($user, $remember);
        session()->regenerate();

        $sync->pull();

        return app(LoginResponse::class);
    }

    // 401 o ibang HTTP error → tanggihan. Bawal ang cached-password fallback.
    if ($tokenResult->errorCode !== 'connection_error') {
        $this->throwFailureValidationException();
    }
}

// Connection failure lang ang pumapasok sa offline path.
if (! $user) {
    throw ValidationException::withMessages([
        'data.email' => 'No local account found. Connect to the internet for first login.',
    ]);
}

if (! Hash::check($password, $user->password)) {
    $this->throwFailureValidationException();
}

Filament::auth()->login($user, $remember);
session()->regenerate();

return app(LoginResponse::class);
```

---

## Paano Nag-verify ang Portal ng Credentials

- **File:** `app/Http/Controllers/Api/Sync/AuthController.php` (sa portal/ommc2027)
- **Route:** `POST /api/auth/tablet-login`

```php
public function tabletLogin(Request $request): JsonResponse
{
    $request->validate([
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        return response()->json(['error' => 'Invalid credentials.'], 401);
    }

    // Gumawa ng API token kung wala pa
    if (! $user->api_token) {
        $user->generateApiToken();
    }

    // Ibalik ang user data para ma-save sa tablet's SQLite
    return response()->json([
        'id'        => $user->id,
        'name'      => $user->name,
        'email'     => $user->email,
        'password'  => $user->password,   // Hashed password (para ma-store sa SQLite)
        'api_token' => $user->api_token,  // Para sa lahat ng susunod na API calls
        'roles'     => $user->getRoleNames(),
        'rsm_id'    => $user->rsm_id,
        // ... at ang iba pang identity/base-location fields
    ]);
}
```

Ang tablet's `refreshToken()` sa **SyncService.php** ang tumatawag dito:

```php
// File: app/Services/SyncService.php
public function refreshToken(string $email, string $password): SyncResult
{
    try {
        $response = Http::timeout($this->timeout)
            ->post("{$this->serverUrl}/api/auth/tablet-login", compact('email', 'password'));
    } catch (ConnectionException) {
        // Walang sagot ang Portal — ito lang ang pwedeng mag-open ng offline path.
        return SyncResult::fail('Could not reach Portal.', 'connection_error');
    } catch (Throwable) {
        return SyncResult::fail('Could not complete Portal authentication.', 'request_error');
    }

    if ($response->status() === 401) {
        return SyncResult::fail('Invalid email or password.', 'invalid_credentials', httpStatus: 401);
    }

    if ($response->failed()) {
        return SyncResult::fail("Portal authentication failed ({$response->status()}).", 'server_error', httpStatus: $response->status());
    }

    $data = $response->json();

    // Isang transaction: identity, password hash, API token, at roles.
    try {
        DB::transaction(function () use ($data): void {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'      => $data['name'],
                    'password'  => $data['password'],   // Hashed — para makapag-login offline
                    'api_token' => $data['api_token'],
                    'server_id' => $data['id'] ?? null,
                    'rsm_id'    => $data['rsm_id'] ?? null,
                ]
            );

            // I-assign ang roles (DRM, RSM, etc.)
            if (! empty($data['roles'])) {
                foreach ($data['roles'] as $roleName) {
                    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
                }
                $user->syncRoles($data['roles']);
            }
        });
    } catch (Throwable) {
        return SyncResult::fail('Could not persist the Portal identity locally.', 'persistence_error');
    }

    return SyncResult::ok('Token refreshed.');
}
```

---

## Internet Check — Paano Nag-detect ng Connectivity

**File:** `app/Services/SyncService.php`

```php
public function isReachable(): bool
{
    if (blank($this->serverUrl)) {
        return false;  // Walang SYNC_SERVER_URL sa .env
    }

    try {
        // Mag-ping sa portal — 3 second timeout lang
        return Http::timeout(3)->get("{$this->serverUrl}/api/ping")->successful();
    } catch (\Exception) {
        return false;  // Timeout or no internet
    }
}
```

Para sa **login**, `isPortalResponsive()` ang ginagamit — **`true` kapag may kahit anong HTTP response** (kahit `401` o `500`), kaya hindi pumapasok sa offline path kapag sumagot ang Portal. Ang `isReachable()` (successful ping lang) ay para sa sync features (notifications, forms).

```php
public function isPortalResponsive(): bool
{
    if (! $this->isConfigured()) {
        return false;  // Walang SYNC_SERVER_URL sa .env
    }

    try {
        // May sagot ang Portal kahit anong status — hindi lang 200.
        Http::timeout(3)->get("{$this->serverUrl}/api/ping");

        return true;
    } catch (ConnectionException) {
        return false;  // Walang sagot — ito lang ang offline path.
    }
}
```

**Config:** `.env` file ng tablet
```
SYNC_SERVER_URL=https://your-portal-url.com
```

---

## Buod / Summary Table

| Sitwasyon | May SQLite? | Reachable ang Portal? | Resulta |
|-----------|-------------|-----------------------|---------|
| Unang beses mag-login | ❌ Wala pa | ✅ Oo (kahit anong HTTP response) | Sa Portal muna mag-authenticate → save sa SQLite sa isang transaction → pull data |
| Unang beses mag-login | ❌ Wala pa | ❌ Walang sagot (connection failure) | **Error:** "Connect to the internet for first login" |
| Ulit na mag-login (naka-save na) | ✅ Meron | ✅ Oo (kahit anong HTTP response) | Sa Portal muna mag-authenticate → pull latest data (**walang cached-password shortcut**) |
| Ulit na mag-login (naka-save na) | ✅ Meron | ❌ Walang sagot (connection failure) | Offline path: `Hash::check()` sa cached hash — **walang pull, gumagana pa rin** |
| Maling password | ✅ Meron | ✅ Oo | **Error:** Invalid credentials — **walang cached-password fallback** |
| Maling password | ✅ Meron | ❌ Walang sagot | Offline path: `Hash::check()` sa cached hash → **Error:** Invalid credentials |

---

## Bakit Gumagana ang Login Offline?

Kapag nag-login ka na dati, ino-save ng portal ang **hashed password** sa local SQLite ng tablet. Kaya kapag **walang sagot** ang Portal (connection failure), ginagamit ng `Hash::check()` ang locally-stored hash para i-verify ang password — **hindi na kailangan ng Portal**. Kapag may kahit anong HTTP response ang Portal, hindi na ginagamit ang cached hash — sa Portal muna ang authentication.

```
SQLite users table:
┌────┬──────────────────┬──────────────────────────────────────┬───────────────┐
│ id │ email            │ password (bcrypt hash)               │ api_token     │
├────┼──────────────────┼──────────────────────────────────────┼───────────────┤
│  1 │ drm@example.com  │ $2y$12$abc123...                     │ tok_xyz789... │
└────┴──────────────────┴──────────────────────────────────────┴───────────────┘
```

---

## Important Notes

1. **SYNC_SERVER_URL** — Kailangan itong i-set sa `.env` para gumana ang Portal authentication at internet features. Kung blank, hindi makakapag-authenticate sa Portal (not configured) at offline mode lang.
2. **Ang `isPortalResponsive()` check** — Mabilis lang (3 seconds timeout). `false` lang kapag **walang sagot** (connection failure); ang `401` o `500` ay "reachable" pa rin at hindi pumapasok sa offline path.
3. **Re-login after pull** — Ginagawa ito para ma-reflect ang pinakabagong user data (halimbawa, nagbago ang role sa portal).
4. **Roles** — Na-sync din ang roles (DRM, RSM, etc.) sa SQLite para gumana ang permission checks kahit offline.
