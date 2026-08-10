<?php

use Illuminate\Support\Str;

test('ios location permission is declared in the durable nativephp config', function () {
    $permissions = config('nativephp.permissions');

    expect($permissions)->toBeArray()
        ->toHaveKey('NSLocationWhenInUseUsageDescription')
        ->and($permissions['NSLocationWhenInUseUsageDescription'])->toBeString()->not->toBeEmpty();
});

test('ios always-location permissions are not configured', function () {
    $permissions = array_keys(config('nativephp.permissions', []));

    foreach ($permissions as $key) {
        expect(Str::startsWith($key, 'NSLocationAlways'))->toBeFalse(
            "Unexpected always-location permission declared: {$key}"
        );
    }
});

test('ios camera and microphone permissions remain declared', function () {
    $permissions = config('nativephp.permissions');

    expect($permissions)->toHaveKey('NSCameraUsageDescription')
        ->toHaveKey('NSMicrophoneUsageDescription');
});
