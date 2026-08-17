<?php

use Illuminate\Support\Str;

test('classic ios artisan bootstrap trace records startup migrate checkpoints', function () {
    $root = storage_path('framework/testing/startup-classic-trace-'.Str::uuid());
    $migrationDir = $root.'/database/migrations';
    $storageDir = $root.'/storage';
    $dbPath = $root.'/database/database.sqlite';
    $tracePath = $storageDir.'/framework/nativephp/startup-migration-trace.json';
    $runId = (string) Str::uuid();

    if (! is_dir($migrationDir)) {
        mkdir($migrationDir, 0755, true);
    }

    if (! is_dir(dirname($dbPath))) {
        mkdir(dirname($dbPath), 0755, true);
    }
    touch($dbPath);

    file_put_contents(
        $migrationDir.'/2026_01_01_000000_create_trace_probe_table.php',
        <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_probe', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_probe');
    }
};
PHP
    );

    $command = [
        PHP_BINARY,
        base_path('vendor/nativephp/mobile/bootstrap/ios/artisan.php'),
        'app:startup-migrate',
        "--run-id={$runId}",
        "--path={$migrationDir}",
        '--no-interaction',
        '--no-ansi',
    ];

    $env = array_merge($_ENV, [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $dbPath,
        'LARAVEL_STORAGE_PATH' => $storageDir,
        'NATIVEPHP_STARTUP_TRACE_PATH' => $tracePath,
        'NATIVEPHP_STARTUP_RUN_ID' => $runId,
        'NATIVEPHP_STARTUP_RUNTIME_MODE' => 'classic',
        'NATIVEPHP_STARTUP_APP_PATH' => base_path(),
        'NATIVEPHP_STARTUP_STORAGE_PATH' => $storageDir,
        'NATIVEPHP_STARTUP_DATABASE_PATH' => $dbPath,
    ]);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, base_path(), $env);

    expect(is_resource($process))->toBeTrue();

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    expect($exitCode)
        ->toBe(0, "stdout:\n{$stdout}\n\nstderr:\n{$stderr}")
        ->and(is_file($tracePath))->toBeTrue();

    $trace = json_decode((string) file_get_contents($tracePath), true, 512, JSON_THROW_ON_ERROR);
    $history = collect($trace['history'] ?? [])->pluck('checkpoint')->all();

    expect($trace['run_id'])->toBe($runId)
        ->and($trace['runtime_mode'])->toBe('classic')
        ->and($trace['checkpoint'])->toBe('laravel_command_handled')
        ->and($trace['database_path'])->toBe($dbPath)
        ->and($history)->toContain(
            'artisan_php_entry',
            'composer_autoload_loaded',
            'laravel_bootstrap_app_loaded',
            'laravel_application_created',
            'startup_migrate_dispatch_target_resolved',
            'laravel_command_handling_begin',
            'startup_migrate_handle_entered',
            'startup_migration_result_artifact_written',
        );
});
