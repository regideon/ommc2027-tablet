<?php

namespace App\Support;

class StartupDiagnosticTrace
{
    public static function path(): ?string
    {
        return self::env('NATIVEPHP_STARTUP_TRACE_PATH');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function checkpoint(string $checkpoint, array $context = []): void
    {
        $path = self::path();

        if (! is_string($path) || $path === '') {
            return;
        }

        $runId = self::env('NATIVEPHP_STARTUP_RUN_ID') ?? ($context['run_id'] ?? null);
        $runtimeMode = self::env('NATIVEPHP_STARTUP_RUNTIME_MODE') ?? ($context['runtime_mode'] ?? 'classic');
        $timestamp = gmdate('c');

        $payload = self::loadExistingPayload($path, is_string($runId) ? $runId : null);
        $entry = self::buildEntry($checkpoint, $timestamp, $context);

        $history = $payload['history'] ?? [];
        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $entry;

        $next = [
            'run_id' => is_string($runId) ? $runId : '',
            'runtime_mode' => is_string($runtimeMode) ? $runtimeMode : 'classic',
            'checkpoint' => $checkpoint,
            'timestamp' => $timestamp,
            'history' => $history,
        ];

        foreach ($entry as $key => $value) {
            if (in_array($key, ['checkpoint', 'timestamp'], true)) {
                continue;
            }

            $next[$key] = $value;
        }

        if (! isset($next['app_path'])) {
            $next['app_path'] = self::env('NATIVEPHP_STARTUP_APP_PATH');
        }

        if (! isset($next['storage_path'])) {
            $next['storage_path'] = self::env('NATIVEPHP_STARTUP_STORAGE_PATH');
        }

        if (! isset($next['database_path'])) {
            $next['database_path'] = self::env('NATIVEPHP_STARTUP_DATABASE_PATH');
        }

        self::persist($path, $next);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadExistingPayload(string $path, ?string $runId): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        if ($runId !== null && $runId !== '' && ($decoded['run_id'] ?? null) !== $runId) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function buildEntry(string $checkpoint, string $timestamp, array $context): array
    {
        $entry = [
            'checkpoint' => $checkpoint,
            'timestamp' => $timestamp,
        ];

        $supportedKeys = [
            'app_path',
            'storage_path',
            'database_path',
            'connection',
            'command',
            'command_exit_status',
            'artifact_path',
            'php_embed_init_result',
            'php_execute_script_returned',
            'php_execute_script_has_return_value',
            'php_output_excerpt',
            'exception_class',
            'exception_message',
        ];

        foreach ($supportedKeys as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];

            if ($value === null || $value === '') {
                continue;
            }

            $entry[$key] = is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function persist(string $path, array $payload): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
