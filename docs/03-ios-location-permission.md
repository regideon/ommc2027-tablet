# iOS Location Permission — Durable Config (WhenInUse)

**Config file:** `config/nativephp.php`  
**Test file:** `tests/Feature/IosPermissionPersistenceTest.php`

---

## Goal

Make the iOS Location permission **persist** every time the NativePHP iOS app is rebuilt. It must survive a clean regeneration of the `nativephp/ios` project, `native:install`, and `native:build`.

Only **foreground-only** (`NSLocationWhenInUseUsageDescription`) is needed because the app uses location strictly while in use:

- `Geolocation::requestPermissions()` on login → `app/Filament/Pages/Auth/Login.php:91`
- `Geolocation::getCurrentPosition()->fineAccuracy()` on check-in/out → `app/Filament/Pages/SalescallPage.php:887`
- `navigator.geolocation.getCurrentPosition()` browser fallback → `resources/views/filament/pages/salescall-page.blade.php:772,782`

There is no background/always location use, so **NO** `NSLocationAlwaysUsageDescription`, `NSLocationAlwaysAndWhenInUseUsageDescription`, or background location modes were added.

---

## Why this is the durable mechanism (reference: Camera)

The Camera/Microphone permissions already work this way and survive every rebuild because:

1. **`config/nativephp.php` → `permissions` array** is the app-owned source of truth.
2. On every `native:build`, `IOSPluginCompiler::mergeInfoPlistEntries()` reads it:
   `vendor/nativephp/mobile/src/Plugins/Compilers/IOSPluginCompiler.php` (`getAppInfoPlistOverrides()` → `config('nativephp.permissions', [])`).
3. It is injected **last** into the generated `nativephp/ios/NativePHP/Info.plist` and `nativephp/ios/NativePHP-simulator-Info.plist`, so it always wins over plugin manifests.
4. `native:install` copies the vendor baseline plist (no permissions); the following `native:build` injects the permissions from config.

**Do not** edit `nativephp/ios/NativePHP/Info.plist` directly — it is a generated file and can be overwritten.

---

## Changes Made

### 1. `config/nativephp.php` (one line added)

Inside the `permissions` array:

```php
'permissions' => [
    'NSCameraUsageDescription' => 'This app requires camera access to take photos and record videos.',
    'NSMicrophoneUsageDescription' => 'This app requires microphone access to record video with audio.',
    'NSLocationWhenInUseUsageDescription' => 'Location access is required to record your location during sales calls and related field activities.',
],
```

### 2. New test — `tests/Feature/IosPermissionPersistenceTest.php`

- `NSLocationWhenInUseUsageDescription` is present and non-empty.
- No `NSLocationAlways*` key in the config.
- Camera + Microphone entries remain intact.

Run:

```bash
php artisan test --compact --filter=IosPermissionPersistenceTest
```

Result: **3 passed (9 assertions)**.

---

## Validation Steps (as performed)

```bash
# 1. Run the focused test
php artisan test --compact --filter=IosPermissionPersistenceTest

# 2. Normal iOS build (simulator archive)
php artisan native:build --simulated --no-tty

# 3. Verify the generated plist
grep -A1 "NSLocationWhenInUseUsageDescription\|NSCameraUsageDescription\|NSMicrophoneUsageDescription" \
  nativephp/ios/NativePHP/Info.plist

# 4. Verify the built app bundle
plutil -p "nativephp/ios/build/NativePHP.xcarchive/Products/Applications/NativePHP-simulator.app/Info.plist" \
  | grep -i "location\|camera\|microphone"

# 5. Confirm no always-location / background modes
grep -c "NSLocationAlways\|UIBackgroundModes" nativephp/ios/NativePHP/Info.plist   # → 0

# 6. Deployment target
grep -rn "IPHONEOS_DEPLOYMENT_TARGET" nativephp/ios/NativePHP.xcodeproj/project.pbxproj  # → 15.6
```

### Clean-regeneration check

The compiler only merges into **existing** plist files (it `continue`s when the file is missing). So the correct "clean regeneration" simulation is:

```bash
# Restore the vendor baseline plist (same as native:install would)
cp vendor/nativephp/mobile/resources/xcode/NativePHP/Info.plist nativephp/ios/NativePHP/Info.plist

# Rebuild → all permissions must be re-injected from config
php artisan native:build --simulated --no-tty
```

Result: all three keys (`NSLocationWhenInUseUsageDescription`, `NSCameraUsageDescription`, `NSMicrophoneUsageDescription`) were re-injected into the project plist and the archived app bundle. `NSLocationAlways*` and `UIBackgroundModes` count is **0**.

---

## Simulator Smoke Test

```bash
xcrun simctl boot "iPhone 17"
xcrun simctl install "iPhone 17" "nativephp/ios/build/NativePHP.xcarchive/Products/Applications/NativePHP-simulator.app"
xcrun simctl launch "iPhone 17" com.kaisa.ommc2027tablet
```

Result: app installed and launched without crashing. The native location prompt itself appears when the feature is triggered (login → sales call check-in); it cannot be driven headlessly, and location status is still "not determined" beforehand, so the prompt will fire at the right time.

---

## Summary

| Item | Result |
|---|---|
| Permission key | `NSLocationWhenInUseUsageDescription` |
| Value | `Location access is required to record your location during sales calls and related field activities.` |
| Durable source | `config/nativephp.php` → `permissions` |
| Test | 3 passed (9 assertions) |
| Generated Info.plist | Location + Camera + Mic present |
| Clean regeneration | Survived (re-injected from config) |
| iOS build | Succeeded (simulator archive) |
| Deployment target | `15.6` — unchanged |
| Android / camera / session | Untouched |
