<?php

namespace App\Support;

class NativeAppReleaseMetadata
{
    public function releaseLabel(): string
    {
        $metadata = $this->read();

        return sprintf('Release %s (%s)', $metadata['version'], $metadata['build']);
    }

    /**
     * @return array{version: string, build: string}
     */
    public function read(): array
    {
        $envPath = $this->envPath();

        if ($envPath !== null) {
            $version = $this->readEnvValue($envPath, 'NATIVEPHP_APP_VERSION');
            $build = $this->readEnvValue($envPath, 'NATIVEPHP_APP_VERSION_CODE');

            if ($version !== null && $build !== null) {
                return [
                    'version' => $version,
                    'build' => $build,
                ];
            }
        }

        return [
            'version' => (string) config('nativephp.version', config('app.version', '0.0.0')),
            'build' => (string) config('nativephp.version_code', '0'),
        ];
    }

    private function envPath(): ?string
    {
        $configured = config('nativephp.release_metadata_env_path');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $nativeEnv = base_path('.env');

        if (is_file($nativeEnv)) {
            return $nativeEnv;
        }

        return null;
    }

    private function readEnvValue(string $path, string $key): ?string
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        if (! preg_match('/^'.preg_quote($key, '/').'=(.+)$/m', $contents, $matches)) {
            return null;
        }

        return trim($matches[1], " \t\n\r\0\x0B\"'");
    }
}
