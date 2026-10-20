<?php

test('durable android runtime source retains startup migration gate', function () {
    $path = base_path('packages/ommc2027/android-runtime/bridge/LaravelEnvironment.kt');

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('STARTUP_MIGRATION_COMMAND = "app:startup-migrate --no-interaction"')
        ->toContain('runStartupMigrationGate()')
        ->toContain('STARTUP_MIGRATION_STATUS')
        ->toContain('Database migration failed for $dbPath.');
});

test('build deploy command preserves durable android runtime patches', function () {
    $path = base_path('app/Console/Commands/BuildDeployCommand.php');
    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('ANDROID_RUNTIME_FILES')
        ->toContain('applyAndroidRuntimePatches')
        ->toContain('packages/ommc2027/android-runtime')
        ->toContain('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt')
        ->toContain('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile')
        ->toContain('runStartupMigrationGate()')
        ->toContain('app:startup-migrate --no-interaction');
});
