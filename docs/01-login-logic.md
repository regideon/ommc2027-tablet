# Login Logic ng Tablet App

**File:** `app/Filament/Pages/Auth/Login.php`  
**Portal file:** `app/Http/Controllers/Api/SyncController.php` → method `tabletLogin()`

---

## Overview

Ang tablet app ay **offline-first**. Ibig sabihin, kahit walang internet, pwedeng mag-login ang user — **basta nakapag-login na sila dati** at naka-save na ang account sa local SQLite database ng device.

---

## Dalawang Scenario ng Login

### SCENARIO 1 — May local account na sa SQLite (offline or online)

```
User naglagay ng email + password
         ↓
May nahanap na User sa local SQLite?  → YES
         ↓
Tama ba ang password vs. hashed value sa SQLite? → NO → Error: Invalid credentials
         ↓ YES
Login agad (local) — walang internet needed
         ↓
May internet ba? (isReachable check)
    → NO  → Tapos na. Dashboard na.
    → YES → refreshToken() → pull() → re-login para ma-update ang user data
         ↓
Dashboard
```

**Code (Login.php):**
```php
$user = User::where('email', $email)->first();

if ($user) {
    // Step 1: I-check ang password sa local SQLite
    if (! Hash::check($password, $user->password)) {
        $this->throwFailureValidationException();  // Wrong password
    }

    // Step 2: Login agad kahit offline
    Filament::auth()->login($user, $remember);
    session()->regenerate();

    // Step 3: Kung may internet, i-refresh ang token at i-pull ang bagong data
    if ($sync->isReachable()) {
        $sync->refreshToken($email, $password);
        $sync->pull();

        $user = User::where('email', $email)->firstOrFail();
        Filament::auth()->login($user, $remember); // Re-login with updated data
    }

    return app(LoginResponse::class);  // Success!
}
```

---

### SCENARIO 2 — Wala pang local account (first login, kailangan ng internet)

```
User naglagay ng email + password
         ↓
May nahanap na User sa local SQLite? → NO
         ↓
May internet ba?
    → NO  → Error: "No local account found. Connect to the internet for first login."
    → YES → Tawag sa portal: POST /api/auth/tablet-login
         ↓
Valid credentials sa portal? → NO → Error: Invalid credentials
         ↓ YES
Portal nagbalik ng user data + api_token → Na-save sa SQLite
         ↓
pull() → I-download lahat ng kailangan (customers, itineraries, etc.)
         ↓
Login → Dashboard
```

**Code (Login.php):**
```php
// No local user found — need internet para sa first login
if (! $sync->isReachable()) {
    throw ValidationException::withMessages([
        'data.email' => 'No local account found. Connect to the internet for first login.',
    ]);
}

// Tawag sa portal para i-verify ang credentials at kunin ang token
$tokenResult = $sync->refreshToken($email, $password);

if (! $tokenResult->success) {
    $this->throwFailureValidationException();  // Wrong credentials sa portal
}

// Na-save na ang user sa SQLite ng refreshToken()
$user = User::where('email', $email)->firstOrFail();

Filament::auth()->login($user, $remember);
session()->regenerate();

// I-download lahat ng data para makapag-trabaho offline
$sync->pull();

// Re-login para ma-reflect ang pinakabagong user data
$user = User::where('email', $email)->firstOrFail();
Filament::auth()->login($user, $remember);

return app(LoginResponse::class);
```

---

## Paano Nag-verify ang Portal ng Credentials

**File:** `app/Http/Controllers/Api/SyncController.php` (sa portal/ommc2027)  
**Route:** `POST /api/auth/tablet-login`

```php
public function tabletLogin(Request $request): JsonResponse
{
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
        'name'      => $user->name,
        'email'     => $user->email,
        'password'  => $user->password,   // Hashed password (para ma-store sa SQLite)
        'api_token' => $user->api_token,  // Para sa lahat ng susunod na API calls
        'roles'     => $user->getRoleNames(),
        'rsm_id'    => $user->rsm_id,
    ]);
}
```

Ang tablet's `refreshToken()` sa **SyncService.php** ang tumatawag dito:

```php
// File: app/Services/SyncService.php
public function refreshToken(string $email, string $password): SyncResult
{
    $response = Http::timeout($this->timeout)
        ->post("{$this->serverUrl}/api/auth/tablet-login", compact('email', 'password'));

    $data = $response->json();

    // I-save o i-update ang user sa local SQLite
    $user = User::updateOrCreate(
        ['email' => $data['email']],
        [
            'name'      => $data['name'],
            'password'  => $data['password'],   // Hashed — para makapag-login offline
            'api_token' => $data['api_token'],
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

**Config:** `.env` file ng tablet
```
SYNC_SERVER_URL=https://your-portal-url.com
```

---

## Buod / Summary Table

| Sitwasyon | May SQLite? | May Internet? | Resulta |
|-----------|-------------|---------------|---------|
| Unang beses mag-login | ❌ Wala pa | ✅ Oo | Portal i-verify → save sa SQLite → pull data |
| Unang beses mag-login | ❌ Wala pa | ❌ Wala | **Error:** "Connect to the internet for first login" |
| Ulit na mag-login (naka-save na) | ✅ Meron | ✅ Oo | Login agad → refresh token → pull latest data |
| Ulit na mag-login (naka-save na) | ✅ Meron | ❌ Wala | Login offline lang — **walang pull, gumagana pa rin** |
| Maling password | ✅ Meron | kahit ano | **Error:** Invalid credentials |

---

## Bakit Gumagana ang Login Offline?

Kapag nag-login ka na dati, ino-save ng portal ang **hashed password** sa local SQLite ng tablet. Kaya kahit walang internet, ginagamit ng `Hash::check()` ang locally-stored hash para i-verify ang password — **hindi na kailangan ng portal**.

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

1. **SYNC_SERVER_URL** — Kailangan itong i-set sa `.env` para gumana ang internet features. Kung blank, palaging offline mode.
2. **Ang `isReachable()` check** — Mabilis lang (3 seconds timeout). Hindi error kung hindi ma-reach — offline mode na lang.
3. **Re-login after pull** — Ginagawa ito para ma-reflect ang pinakabagong user data (halimbawa, nagbago ang role sa portal).
4. **Roles** — Na-sync din ang roles (DRM, RSM, etc.) sa SQLite para gumana ang permission checks kahit offline.
