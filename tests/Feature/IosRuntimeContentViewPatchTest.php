<?php

test('durable ios runtime ContentView uses persistent website data store', function () {
    $path = base_path('packages/ommc2027/ios-runtime/ContentView.swift');

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('WKWebsiteDataStore.default()');
    expect($contents)->not->toContain('WKWebsiteDataStore.nonPersistent()');
});

test('build deploy command ships ContentView as a durable ios runtime patch', function () {
    $path = base_path('app/Console/Commands/BuildDeployCommand.php');
    $contents = (string) file_get_contents($path);

    expect($contents)->toContain("'ContentView.swift'");
    expect($contents)->toContain('WKWebsiteDataStore.default()');
    expect($contents)->toContain('WKWebsiteDataStore.nonPersistent()');
    expect($contents)->toContain('verifyGeneratedIosArtifacts');
});
