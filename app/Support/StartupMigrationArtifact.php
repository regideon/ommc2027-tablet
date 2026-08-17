<?php

namespace App\Support;

use App\Services\StartupMigrationResult;
use Illuminate\Support\Facades\File;

class StartupMigrationArtifact
{
    public static function path(): string
    {
        return storage_path('framework/nativephp/startup-migration-status.json');
    }

    public static function write(string $runId, StartupMigrationResult $result): void
    {
        $path = self::path();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'run_id' => $runId,
            'status' => $result->status,
            'database_path' => $result->databasePath,
            'connection' => $result->connection,
            'applied_migrations' => $result->appliedMigrations,
            'failed_migration' => $result->failedMigration,
            'exception_class' => $result->errorClass,
            'exception_message' => $result->errorMessage,
            'timestamp' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
