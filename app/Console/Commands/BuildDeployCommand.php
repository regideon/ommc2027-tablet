<?php

namespace App\Console\Commands;

use App\Support\IosAppZipValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class BuildDeployCommand extends Command
{
    protected $signature = 'app:build
        {platform : The platform to build for (ios/android)}
        {--rebuild : Remove any existing iOS archive before building}
        {--export-method=app-store : iOS export method (app-store|ad-hoc|enterprise|development)}
        {--team-id= : Apple Developer Team ID}
        {--provisioning-profile-path= : Path to provisioning profile (.mobileprovision)}
        {--certificate-path= : Path to iOS distribution certificate (.p12/.cer)}
        {--certificate-password= : iOS certificate password}';

    protected $description = 'Build the NativePHP app and deploy the artifact to the download directory.';

    /**
     * Durable iOS runtime patches applied before packaging so vendor template
     * overwrites cannot ship a broken extraction/startup lifecycle.
     *
     * @var list<string>
     */
    private const IOS_RUNTIME_FILES = [
        'AppUpdateManager.swift',
        'NativePHPApp.swift',
        'AppState.swift',
        'SplashView.swift',
    ];

    protected string $downloadDir;

    public function handle(): int
    {
        $platform = strtolower($this->argument('platform'));

        if (! in_array($platform, ['ios', 'android'])) {
            $this->error('Platform must be either "ios" or "android".');

            return self::FAILURE;
        }

        $this->downloadDir = storage_path('app/private/nativephp-download');

        if (! is_dir($this->downloadDir)) {
            File::makeDirectory($this->downloadDir, 0755, true);
        }

        if ($platform === 'ios') {
            // Must run BEFORE native:package/xcodebuild so the IPA compiles these sources.
            $this->applyIosRuntimePatches();

            if (! $this->verifyIosRuntimePatches()) {
                return self::FAILURE;
            }
        }

        $this->info("Building {$platform} app...");

        $args = [
            'platform' => $platform,
        ];

        if ($this->option('rebuild')) {
            $args['--rebuild'] = true;
        }

        if ($platform === 'android') {
            $args['--keystore'] = env('ANDROID_KEYSTORE_PATH', base_path('keystore/ommc2027-upload-key.jks'));
            $args['--key-alias'] = env('ANDROID_KEY_ALIAS', 'ommc2027-upload-key');
            $args['--key-password'] = env('ANDROID_KEY_PASSWORD', 'ommc2027');
            $args['--keystore-password'] = env('ANDROID_KEYSTORE_PASSWORD', 'ommc2027');
        }

        if ($platform === 'ios') {
            $iosEnvMap = [
                'export-method' => 'NATIVEPHP_IOS_EXPORT_METHOD',
                'team-id' => 'NATIVEPHP_DEVELOPMENT_TEAM',
                'provisioning-profile-path' => 'NATIVEPHP_IOS_PROVISIONING_PROFILE_PATH',
                'certificate-path' => 'NATIVEPHP_IOS_CERTIFICATE_PATH',
                'certificate-password' => 'NATIVEPHP_IOS_CERTIFICATE_PASSWORD',
            ];

            foreach ($iosEnvMap as $option => $envKey) {
                $value = $this->option($option) ?: env($envKey);
                if ($value) {
                    $args["--{$option}"] = $value;
                }
            }
        }

        $exitCode = Artisan::call('native:package', $args);

        $this->line(Artisan::output());

        if ($exitCode !== self::SUCCESS) {
            $this->error('Build failed.');

            return self::FAILURE;
        }

        if ($platform === 'ios') {
            // Verify FIRST (before re-applying) so we detect if native:package wiped
            // the safeguards that were compiled into the IPA.
            if (! $this->verifyIosRuntimePatches()) {
                $this->error('iOS runtime patches were missing after native:package. The IPA may be unsafe — do not ship; rebuild with app:build.');

                return self::FAILURE;
            }

            // Restore durable sources/vendor template for the next install/build.
            $this->applyIosRuntimePatches();

            if (! $this->validateIosAppZip()) {
                return self::FAILURE;
            }
        }

        $this->line('');
        $this->info('Build complete. Deploying artifact...');

        $artifactPath = $this->findArtifact($platform);

        if (! $artifactPath) {
            $this->error('Could not locate build artifact.');

            return self::FAILURE;
        }

        $filename = $platform === 'ios' ? 'NativePHP.ipa' : 'NativePHP.apk';
        $destination = $this->downloadDir.'/'.$filename;

        File::copy($artifactPath, $destination);

        $this->info("Deployed {$filename} (".round(filesize($destination) / 1024 / 1024, 2).' MB)');

        return self::SUCCESS;
    }

    private function applyIosRuntimePatches(): void
    {
        $sourceDir = base_path('packages/ommc2027/ios-runtime');
        $targets = [
            base_path('nativephp/ios/NativePHP'),
            base_path('vendor/nativephp/mobile/resources/xcode/NativePHP'),
        ];

        foreach (self::IOS_RUNTIME_FILES as $filename) {
            $source = $sourceDir.'/'.$filename;

            if (! is_file($source)) {
                $this->error("iOS runtime patch missing: {$source}");

                continue;
            }

            foreach ($targets as $targetDir) {
                if (! is_dir($targetDir)) {
                    continue;
                }

                File::copy($source, $targetDir.'/'.$filename);
            }
        }

        $this->info('Applied durable iOS runtime patches to nativephp/ios and vendor template.');
    }

    /**
     * Fail the build if the Xcode sources Xcode will compile do not contain the
     * bootstrap extraction safeguards. This is the guarantee that app:build ships
     * the fix into the IPA.
     */
    private function verifyIosRuntimePatches(): bool
    {
        $checks = [
            base_path('nativephp/ios/NativePHP/AppUpdateManager.swift') => [
                'requiredBootstrapRelativePath',
                'forceReextractFromBundle',
                'vendor/nativephp/mobile/bootstrap/ios/native.php',
                'isValidApp(at:',
            ],
            base_path('nativephp/ios/NativePHP/NativePHPApp.swift') => [
                'ensureBootstrapReady',
                'markStartupFailed',
                'forceReextractFromBundle',
            ],
            base_path('nativephp/ios/NativePHP/AppState.swift') => [
                'startupError',
                'markStartupFailed',
            ],
            base_path('packages/ommc2027/camera/resources/ios/CameraFunctions.swift') => [
                'copyImageToCache',
                'preferredAssetRepresentationMode = .compatible',
            ],
        ];

        $ok = true;

        foreach ($checks as $path => $needles) {
            if (! is_file($path)) {
                $this->error("Missing iOS source required for build: {$path}");
                $ok = false;

                continue;
            }

            $contents = (string) file_get_contents($path);

            if (str_contains($contents, 'mobile-lite/bootstrap/ios/native.php')) {
                $this->error("Unsafe mobile-lite bootstrap path still present in {$path}");
                $ok = false;
            }

            foreach ($needles as $needle) {
                if (! str_contains($contents, $needle)) {
                    $this->error("iOS runtime safeguard missing from {$path}: {$needle}");
                    $ok = false;
                }
            }
        }

        if ($ok) {
            $this->info('Verified iOS runtime/camera safeguards are present in sources Xcode will compile.');
        }

        return $ok;
    }

    private function validateIosAppZip(): bool
    {
        $zipPath = base_path('nativephp/ios/NativePHP/app.zip');
        $validator = new IosAppZipValidator;
        $missing = $validator->missingEntries($zipPath);

        if ($missing !== []) {
            $this->error('iOS app.zip failed runtime validation. Missing:');
            foreach ($missing as $entry) {
                $this->line('  - '.$entry);
            }

            return false;
        }

        $versionPath = base_path('nativephp/ios/NativePHP/bundled.version');

        if (! is_file($versionPath) || trim((string) file_get_contents($versionPath)) === '') {
            $this->error('iOS bundled.version is missing or empty.');

            return false;
        }

        $this->info('iOS app.zip contains required bootstrap/runtime files.');

        return true;
    }

    private function findArtifact(string $platform): ?string
    {
        if ($platform === 'ios') {
            return $this->findFile([
                base_path('nativephp/ios/build/export/NativePHP.ipa'),
            ]);
        }

        $basePath = base_path('nativephp/android/app/build/outputs');

        return $this->findFile([
            $basePath.'/apk/release/app-release.apk',
            ...glob($basePath.'/apk/release/*.apk') ?: [],
        ]);
    }

    private function findFile(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
