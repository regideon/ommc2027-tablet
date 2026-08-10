<?php

namespace App\Support;

use ZipArchive;

class IosAppZipValidator
{
    /**
     * Required entries inside the NativePHP iOS app.zip.
     * Root `artisan` is intentionally excluded from packaging; CLI uses artisan.php.
     *
     * @var list<string>
     */
    public const REQUIRED_ENTRIES = [
        'vendor/nativephp/mobile/bootstrap/ios/native.php',
        'vendor/nativephp/mobile/bootstrap/ios/artisan.php',
        'bootstrap/app.php',
        'vendor/autoload.php',
        'public/index.php',
    ];

    /**
     * Needles that must appear in the Sales Call Blade embedded in app.zip.
     *
     * @var list<string>
     */
    public const SALESCALL_PHOTO_FLOW_NEEDLES = [
        'salescall-photo-wizard',
        'wire:ignore',
        'photoStep === 3',
        'Capture Photo',
        'hasPhotoForType',
        'nativephp-ios',
        '$wire.takePhoto',
        '$wire.pickFromGallery',
        'selectPhotoType',
    ];

    public const SALESCALL_PHOTO_FLOW_ENTRY = 'resources/views/filament/pages/salescall-page.blade.php';

    /**
     * @return list<string> Missing entry paths (empty when valid).
     */
    public function missingEntries(?string $zipPath = null): array
    {
        $zipPath ??= base_path('nativephp/ios/NativePHP/app.zip');

        if (! is_file($zipPath)) {
            return ['(app.zip missing: '.$zipPath.')'];
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return ['(unable to open app.zip)'];
        }

        $missing = [];

        foreach (self::REQUIRED_ENTRIES as $entry) {
            if ($zip->locateName($entry) === false) {
                $missing[] = $entry;
            }
        }

        $zip->close();

        return $missing;
    }

    /**
     * @return list<string> Missing photo-flow needles (empty when valid).
     */
    public function missingSalescallPhotoFlowMarkers(?string $zipPath = null): array
    {
        $zipPath ??= base_path('nativephp/ios/NativePHP/app.zip');

        if (! is_file($zipPath)) {
            return ['(app.zip missing: '.$zipPath.')'];
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return ['(unable to open app.zip)'];
        }

        $blade = $zip->getFromName(self::SALESCALL_PHOTO_FLOW_ENTRY);
        $zip->close();

        if ($blade === false) {
            return [self::SALESCALL_PHOTO_FLOW_ENTRY];
        }

        $missing = [];

        foreach (self::SALESCALL_PHOTO_FLOW_NEEDLES as $needle) {
            if (! str_contains($blade, $needle)) {
                $missing[] = $needle;
            }
        }

        return $missing;
    }

    public function isValid(?string $zipPath = null): bool
    {
        return $this->missingEntries($zipPath) === []
            && $this->missingSalescallPhotoFlowMarkers($zipPath) === [];
    }
}
