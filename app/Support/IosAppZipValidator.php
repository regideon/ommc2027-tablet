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

    public function isValid(?string $zipPath = null): bool
    {
        return $this->missingEntries($zipPath) === [];
    }
}
