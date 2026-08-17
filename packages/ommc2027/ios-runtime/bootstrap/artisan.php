<?php

use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

function startup_trace_env(string $key): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : null;
}

function startup_trace_path(): ?string
{
    return startup_trace_env('NATIVEPHP_STARTUP_TRACE_PATH');
}

/**
 * @param  array<string, mixed>  $context
 */
function startup_trace_update(string $checkpoint, array $context = []): void
{
    $path = startup_trace_path();

    if (! is_string($path) || $path === '') {
        return;
    }

    $runId = startup_trace_env('NATIVEPHP_STARTUP_RUN_ID') ?? '';
    $runtimeMode = startup_trace_env('NATIVEPHP_STARTUP_RUNTIME_MODE') ?? 'classic';
    $timestamp = gmdate('c');

    $payload = [];
    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded) && (($decoded['run_id'] ?? '') === '' || ($decoded['run_id'] ?? null) === $runId)) {
            $payload = $decoded;
        }
    }

    $entry = [
        'checkpoint' => $checkpoint,
        'timestamp' => $timestamp,
    ];

    $supportedKeys = [
        'app_path',
        'storage_path',
        'database_path',
        'command',
        'command_exit_status',
        'connection',
        'artifact_path',
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

    $history = $payload['history'] ?? [];
    if (! is_array($history)) {
        $history = [];
    }

    $history[] = $entry;

    $next = [
        'run_id' => $runId,
        'runtime_mode' => $runtimeMode,
        'checkpoint' => $checkpoint,
        'timestamp' => $timestamp,
        'app_path' => $context['app_path'] ?? startup_trace_env('NATIVEPHP_STARTUP_APP_PATH'),
        'storage_path' => $context['storage_path'] ?? startup_trace_env('NATIVEPHP_STARTUP_STORAGE_PATH'),
        'database_path' => $context['database_path'] ?? startup_trace_env('NATIVEPHP_STARTUP_DATABASE_PATH'),
        'history' => $history,
    ];

    foreach ($entry as $key => $value) {
        if (in_array($key, ['checkpoint', 'timestamp'], true)) {
            continue;
        }

        $next[$key] = $value;
    }

    $directory = dirname($path);
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents(
        $path,
        json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
}

register_shutdown_function(function (): void {
    $error = error_get_last();

    if (! is_array($error)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if (! in_array($error['type'] ?? null, $fatalTypes, true)) {
        return;
    }

    startup_trace_update('artisan_php_fatal', [
        'exception_class' => 'PHPFatalError',
        'exception_message' => $error['message'] ?? 'Unknown fatal error',
    ]);
});

startup_trace_update('artisan_php_entry');

try {
    require __DIR__.'/../../../../autoload.php';
    startup_trace_update('composer_autoload_loaded');

    $app = require_once __DIR__.'/../../../../../bootstrap/app.php';
    startup_trace_update('laravel_bootstrap_app_loaded', [
        'storage_path' => method_exists($app, 'storagePath') ? $app->storagePath() : null,
        'database_path' => startup_trace_env('DB_DATABASE'),
    ]);

    $input = new ArgvInput;
    $command = $input->getFirstArgument();

    startup_trace_update('laravel_application_created', [
        'storage_path' => method_exists($app, 'storagePath') ? $app->storagePath() : null,
        'command' => $command,
    ]);

    if ($command === 'app:startup-migrate') {
        startup_trace_update('startup_migrate_dispatch_target_resolved', [
            'command' => $command,
        ]);
    }

    startup_trace_update('laravel_command_handling_begin', [
        'command' => $command,
    ]);

    $status = $app->handleCommand($input);

    startup_trace_update('laravel_command_handled', [
        'command' => $command,
        'command_exit_status' => $status,
    ]);

    exit($status);
} catch (Throwable $e) {
    startup_trace_update('artisan_php_exception', [
        'exception_class' => $e::class,
        'exception_message' => $e->getMessage(),
    ]);

    throw $e;
}
