<?php

namespace App\Console\Commands;

use App\Services\StartupMigrationService;
use App\Services\StartupMigrationResult;
use App\Support\StartupMigrationArtifact;
use App\Support\StartupDiagnosticTrace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class StartupMigrateCommand extends Command
{
    protected $signature = 'app:startup-migrate
        {--path=* : Migration paths to evaluate instead of the default app migrations}
        {--run-id= : Correlate the startup artifact to a specific native invocation}';

    protected $description = 'Apply pending migrations against the active runtime database before app startup.';

    public function handle(StartupMigrationService $service): int
    {
        $paths = collect((array) $this->option('path'))
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->values()
            ->all();

        $runId = (string) ($this->option('run-id') ?: '');
        if ($runId === '') {
            $runId = (string) str()->uuid();
        }

        StartupDiagnosticTrace::checkpoint('startup_migrate_handle_entered', [
            'run_id' => $runId,
            'command' => 'app:startup-migrate',
            'storage_path' => app()->storagePath(),
            'database_path' => (string) config('database.connections.'.config('database.default', 'sqlite').'.database', ''),
            'connection' => (string) config('database.default', 'sqlite'),
        ]);

        try {
            $result = $service->run($paths !== [] ? $paths : null);
        } catch (Throwable $e) {
            StartupDiagnosticTrace::checkpoint('startup_migrate_handle_exception', [
                'run_id' => $runId,
                'command' => 'app:startup-migrate',
                'storage_path' => app()->storagePath(),
                'database_path' => (string) config('database.connections.'.config('database.default', 'sqlite').'.database', ''),
                'connection' => (string) config('database.default', 'sqlite'),
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            Log::error('Startup migration command failed before service returned a result', [
                'run_id' => $runId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            $result = StartupMigrationResult::failed(
                connection: (string) config('database.default', 'sqlite'),
                databasePath: (string) config('database.connections.'.config('database.default', 'sqlite').'.database', ''),
                errorClass: $e::class,
                errorMessage: $e->getMessage(),
            );
        }

        try {
            StartupMigrationArtifact::write($runId, $result);

            StartupDiagnosticTrace::checkpoint('startup_migration_result_artifact_written', [
                'run_id' => $runId,
                'command' => 'app:startup-migrate',
                'storage_path' => app()->storagePath(),
                'database_path' => $result->databasePath,
                'connection' => $result->connection,
                'artifact_path' => StartupMigrationArtifact::path(),
            ]);
        } catch (Throwable $e) {
            StartupDiagnosticTrace::checkpoint('startup_migration_result_artifact_write_failed', [
                'run_id' => $runId,
                'command' => 'app:startup-migrate',
                'storage_path' => app()->storagePath(),
                'database_path' => $result->databasePath,
                'connection' => $result->connection,
                'artifact_path' => StartupMigrationArtifact::path(),
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            Log::error('Startup migration artifact write failed', [
                'run_id' => $runId,
                'artifact_path' => StartupMigrationArtifact::path(),
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            $result = StartupMigrationResult::failed(
                connection: $result->connection,
                databasePath: $result->databasePath,
                errorClass: $e::class,
                errorMessage: 'Failed to write startup migration artifact: '.$e->getMessage(),
                failedMigration: $result->failedMigration,
                appliedMigrations: $result->appliedMigrations,
            );
        }

        $this->line("STARTUP_MIGRATION_RUN_ID={$runId}");
        $this->line("STARTUP_MIGRATION_STATUS={$result->status}");
        $this->line("STARTUP_MIGRATION_CONNECTION={$result->connection}");
        $this->line("STARTUP_MIGRATION_DB_PATH={$result->databasePath}");
        $this->line('STARTUP_MIGRATION_ARTIFACT='.StartupMigrationArtifact::path());

        if ($result->appliedMigrations !== []) {
            $this->line('STARTUP_MIGRATION_APPLIED='.implode(',', $result->appliedMigrations));
        }

        if ($result->failedMigration !== null) {
            $this->line("STARTUP_MIGRATION_FAILED_MIGRATION={$result->failedMigration}");
        }

        if ($result->errorClass !== null) {
            $this->line("STARTUP_MIGRATION_ERROR_CLASS={$result->errorClass}");
        }

        if ($result->errorMessage !== null) {
            $this->line("STARTUP_MIGRATION_ERROR_MESSAGE={$result->errorMessage}");
        }

        return $result->isFailed() ? self::FAILURE : self::SUCCESS;
    }
}
