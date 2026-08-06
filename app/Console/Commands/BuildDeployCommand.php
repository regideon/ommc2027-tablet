<?php

namespace App\Console\Commands;

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
            ...glob($basePath.'/apk/release/*.apk'),
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
