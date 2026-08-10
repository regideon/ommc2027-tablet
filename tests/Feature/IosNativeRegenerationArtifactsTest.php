<?php

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
        ->toContain('findArtifact($platform)');
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
