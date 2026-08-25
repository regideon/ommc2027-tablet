<?php

use App\Console\Commands\BuildDeployCommand;
use App\Providers\NativeServiceProvider;
use Ommc2027\Camera\Providers\CameraServiceProvider;
use Ommc2027\Geolocation\Providers\GeolocationServiceProvider;

test('native service provider allowlists camera and geolocation plugins', function () {
    $provider = new NativeServiceProvider(app());

    expect($provider->plugins())->toContain(
        CameraServiceProvider::class,
        GeolocationServiceProvider::class,
    );
});

test('durable camera plugin source retains gallery picker safeguards', function () {
    $path = base_path('packages/ommc2027/camera/resources/ios/CameraFunctions.swift');

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('copyImageToCache')
        ->toContain('preferredAssetRepresentationMode = .compatible')
        ->toContain('Camera.GetPhoto')
        ->toContain('Camera.PickMedia');
});

test('durable geolocation plugin source retains foreground location flow', function () {
    $path = base_path('packages/ommc2027/geolocation/resources/ios/GeolocationFunctions.swift');

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('Geolocation.GetCurrentPosition')
        ->toContain('requestLocation(')
        ->toContain('requestWhenInUseAuthorization()')
        ->toContain('manager.authorizationStatus')
        ->not->toContain('CLLocationManager.authorizationStatus()')
        ->not->toContain('manager.authorizationStatus()')
        ->not->toContain('requestAlwaysAuthorization');
});

test('build deploy command verifies generated camera and geolocation artifacts after package', function () {
    $path = base_path('app/Console/Commands/BuildDeployCommand.php');
    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('verifyGeneratedIosArtifacts')
        ->toContain('Bridge/Plugins/Camera/CameraFunctions.swift')
        ->toContain('Bridge/Plugins/Geolocation/GeolocationFunctions.swift')
        ->toContain('PluginBridgeFunctionRegistration.swift')
        ->toContain('IOS_TEAM_ID')
        ->toContain('Camera.GetPhoto')
        ->toContain('Camera.PickMedia')
        ->toContain('Geolocation.GetCurrentPosition')
        ->toContain('Geolocation.RequestPermissions')
        ->toContain('NSCameraUsageDescription')
        ->toContain('NSMicrophoneUsageDescription')
        ->toContain('NSLocationWhenInUseUsageDescription')
        ->toContain('IPHONEOS_DEPLOYMENT_TARGET = 15.6;')
        ->toContain('WKWebsiteDataStore.default()')
        ->toContain('WKWebsiteDataStore.nonPersistent()')
        ->toContain('NSLocationAlways')
        ->toContain('app:startup-migrate --run-id=')
        ->toContain('startupMigrationRunId')
        ->toContain('startupMigrationTraceURL')
        ->toContain('php_embed_init_result')
        ->toContain('php_execute_script_returned')
        ->toContain('deletePreviousStartupMigrationArtifact')
        ->toContain('loadStartupMigrationArtifact')
        ->toContain('startupMigrationArtifactURL')
        ->toContain('bootstrap/artisan.php')
        ->toContain('vendor/nativephp/mobile/bootstrap/ios/artisan.php')
        ->toContain('resolveIosTeamIdFromProject')
        ->toContain('findArtifact($platform)');
});

test('durable ios runtime source retains startup migration gate', function () {
    $path = base_path('packages/ommc2027/ios-runtime/NativePHPApp.swift');

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('app:startup-migrate --run-id=')
        ->toContain('startupMigrationRunId')
        ->toContain('startupMigrationTraceURL')
        ->toContain('recordStartupMigrationTrace')
        ->toContain('php_embed_init_result')
        ->toContain('php_execute_script_returned')
        ->toContain('deletePreviousStartupMigrationArtifact')
        ->toContain('loadStartupMigrationArtifact')
        ->toContain('startupMigrationArtifactURL')
        ->toContain('startupMigrationFailureDetail')
        ->toContain('run_id mismatch')
        ->not->toContain('artisan migrate START')
        ->not->toContain('PersistentPHPRuntime.shared.artisan(command: "migrate --force")');
});

test('durable ios app update manager fingerprints bundled app zip for re-extraction', function () {
    $path = base_path('packages/ommc2027/ios-runtime/AppUpdateManager.swift');

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('import CryptoKit')
        ->toContain('getBundledExtractionIdentity')
        ->toContain('getBundledAppZipFingerprint')
        ->toContain('zipsha256:')
        ->toContain('createInstalledVersionFile(preferBundledIdentity: true)')
        ->toContain('createInstalledVersionFile(preferBundledIdentity: false)')
        ->toContain('shouldUpdateWithIdentity');
});

test('durable ios classic bootstrap source retains startup diagnostic tracing', function () {
    $path = base_path('packages/ommc2027/ios-runtime/bootstrap/artisan.php');

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('startup_trace_update')
        ->toContain('artisan_php_entry')
        ->toContain('composer_autoload_loaded')
        ->toContain('laravel_bootstrap_app_loaded')
        ->toContain('startup_migrate_dispatch_target_resolved')
        ->toContain('laravel_command_handling_begin')
        ->toContain('artisan_php_exception');
});

test('build deploy command resolves the ios team id from the nativephp project file', function () {
    $command = new class extends BuildDeployCommand
    {
        public function teamIdFromProject(): ?string
        {
            return $this->resolveIosTeamIdFromProject();
        }
    };

    $teamId = $command->teamIdFromProject();

    expect($teamId)->not->toBeNull();

    $projectPath = base_path('nativephp/ios/NativePHP.xcodeproj/project.pbxproj');
    $contents = (string) file_get_contents($projectPath);

    expect($contents)->toContain('CODE_SIGN_ENTITLEMENTS = NativePHP/NativePHP.entitlements;');
    expect($contents)->toContain("DEVELOPMENT_TEAM = {$teamId};");
});

test('nativephp ios project excludes infoplist from synchronized resources', function () {
    $path = base_path('nativephp/ios/NativePHP.xcodeproj/project.pbxproj');
    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('PBXFileSystemSynchronizedBuildFileExceptionSet')
        ->toContain('membershipExceptions = (')
        ->toContain('Info.plist')
        ->toContain('exceptions = (');
});

test('camera and geolocation plugins are registered with nativephp', function () {
    $this->artisan('native:plugin:list')
        ->expectsOutputToContain('ommc2027/camera')
        ->expectsOutputToContain('ommc2027/geolocation')
        ->expectsOutputToContain('Camera.GetPhoto')
        ->expectsOutputToContain('Camera.PickMedia')
        ->expectsOutputToContain('Geolocation.GetCurrentPosition')
        ->assertSuccessful();
});
