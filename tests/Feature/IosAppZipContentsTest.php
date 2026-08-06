<?php

use App\Support\IosAppZipValidator;

test('ios app.zip contains required NativePHP bootstrap and runtime files', function () {
    $zipPath = base_path('nativephp/ios/NativePHP/app.zip');

    if (! is_file($zipPath)) {
        $this->markTestSkipped('nativephp/ios/NativePHP/app.zip is not present in this environment.');
    }

    $validator = new IosAppZipValidator;
    $missing = $validator->missingEntries($zipPath);

    expect($missing)->toBeEmpty(
        'app.zip is missing required entries: '.implode(', ', $missing)
    );

    $versionPath = base_path('nativephp/ios/NativePHP/bundled.version');
    expect(is_file($versionPath))->toBeTrue();
    expect(trim((string) file_get_contents($versionPath)))->not->toBeEmpty();
});

test('ios app zip validator reports missing entries', function () {
    $tempZip = tempnam(sys_get_temp_dir(), 'ios-zip-');
    unlink($tempZip);
    $tempZip .= '.zip';

    $zip = new ZipArchive;
    expect($zip->open($tempZip, ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('bootstrap/app.php', '<?php');
    $zip->close();

    $missing = (new IosAppZipValidator)->missingEntries($tempZip);

    expect($missing)->toContain('vendor/nativephp/mobile/bootstrap/ios/native.php');
    expect($missing)->toContain('vendor/autoload.php');

    unlink($tempZip);
});
