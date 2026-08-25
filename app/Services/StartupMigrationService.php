<?php

namespace App\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StartupMigrationService
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  list<string>|null  $paths
     */
    public function run(?array $paths = null): StartupMigrationResult
    {
        $connection = (string) config('database.default', 'sqlite');
        $databasePath = $this->resolveDatabasePath($connection);

        DB::purge($connection);
        DB::connection($connection)->getPdo();

        Log::info('Startup migration gate starting', [
            'connection' => $connection,
            'database_path' => $databasePath,
        ]);

        $this->migrator->setConnection($connection);
        $repository = $this->migrator->getRepository();

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $paths ??= [database_path('migrations')];
        $files = $this->migrator->getMigrationFiles($paths);
        $ran = $repository->getRan();
        $pending = array_values(array_filter(
            $files,
            fn (string $file): bool => ! in_array($this->migrator->getMigrationName($file), $ran, true),
        ));

        if ($pending === []) {
            Log::info('Startup migration gate found current schema', [
                'connection' => $connection,
                'database_path' => $databasePath,
            ]);

            return new StartupMigrationResult(
                status: 'current',
                connection: $connection,
                databasePath: $databasePath,
            );
        }

        $startedMigration = null;
        $appliedMigrations = [];

        $startedListener = function (MigrationStarted $event) use (&$startedMigration): void {
            $startedMigration = $event->name;
        };

        $endedListener = function (MigrationEnded $event) use (&$appliedMigrations): void {
            if ($event->name !== null) {
                $appliedMigrations[] = $event->name;
            }
        };

        $this->events->listen(MigrationStarted::class, $startedListener);
        $this->events->listen(MigrationEnded::class, $endedListener);

        try {
            $this->migrator->run($paths, ['step' => false, 'pretend' => false]);

            Log::info('Startup migration gate applied pending migrations', [
                'connection' => $connection,
                'database_path' => $databasePath,
                'applied_migrations' => $appliedMigrations,
            ]);

            return new StartupMigrationResult(
                status: 'migrated',
                connection: $connection,
                databasePath: $databasePath,
                appliedMigrations: $appliedMigrations,
            );
        } catch (Throwable $e) {
            Log::error('Startup migration gate failed', [
                'connection' => $connection,
                'database_path' => $databasePath,
                'failed_migration' => $startedMigration,
                'applied_migrations' => $appliedMigrations,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return new StartupMigrationResult(
                status: 'failed',
                connection: $connection,
                databasePath: $databasePath,
                appliedMigrations: $appliedMigrations,
                failedMigration: $startedMigration,
                errorClass: $e::class,
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function resolveDatabasePath(string $connection): string
    {
        $configured = config("database.connections.{$connection}.database");

        if (! is_string($configured) || $configured === '') {
            return '';
        }

        if ($configured === ':memory:') {
            return ':memory:';
        }

        return $configured;
    }
}
