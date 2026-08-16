<?php

test('android camera permission is declared in the durable camera plugin manifest', function () {
    $manifest = json_decode((string) file_get_contents(base_path('packages/ommc2027/camera/nativephp.json')), true);

    expect($manifest['android']['permissions'] ?? [])
        ->toContain('android.permission.CAMERA');
});

test('android geolocation manifest keeps foreground-only location permissions', function () {
    $manifest = json_decode((string) file_get_contents(base_path('packages/ommc2027/geolocation/nativephp.json')), true);
    $permissions = $manifest['android']['permissions'] ?? [];

    expect($permissions)
        ->toContain('android.permission.ACCESS_COARSE_LOCATION')
        ->toContain('android.permission.ACCESS_FINE_LOCATION')
        ->not->toContain('android.permission.ACCESS_BACKGROUND_LOCATION')
        ->not->toContain('android.permission.READ_EXTERNAL_STORAGE')
        ->not->toContain('android.permission.READ_MEDIA_IMAGES');
});

test('build deploy command verifies generated android manifest permissions after package', function () {
    $contents = (string) file_get_contents(base_path('app/Console/Commands/BuildDeployCommand.php'));

    expect($contents)
        ->toContain('verifyGeneratedAndroidArtifacts')
        ->toContain('android.permission.CAMERA')
        ->toContain('android.permission.ACCESS_COARSE_LOCATION')
        ->toContain('android.permission.ACCESS_FINE_LOCATION')
        ->toContain('android.permission.INTERNET')
        ->toContain('android.permission.ACCESS_NETWORK_STATE');
});

test('generated android manifest contains required permissions when present locally', function () {
    $manifestPath = base_path('nativephp/android/app/src/main/AndroidManifest.xml');

    if (! is_file($manifestPath)) {
        $this->markTestSkipped('nativephp/android/app/src/main/AndroidManifest.xml is not present in this environment.');
    }

    $contents = (string) file_get_contents($manifestPath);

    expect($contents)
        ->toContain('android.permission.CAMERA')
        ->toContain('android.permission.ACCESS_COARSE_LOCATION')
        ->toContain('android.permission.ACCESS_FINE_LOCATION')
        ->toContain('android.permission.INTERNET')
        ->toContain('android.permission.ACCESS_NETWORK_STATE');
});
