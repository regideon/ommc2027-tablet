<?php

namespace App\Services;

class StartupMigrationResult
{
    /**
     * @param  list<string>  $appliedMigrations
     */
    public function __construct(
        public readonly string $status,
        public readonly string $connection,
        public readonly string $databasePath,
        public readonly array $appliedMigrations = [],
        public readonly ?string $failedMigration = null,
        public readonly ?string $errorClass = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function failed(
        string $connection,
        string $databasePath,
        ?string $errorClass,
        ?string $errorMessage,
        ?string $failedMigration = null,
        array $appliedMigrations = [],
    ): self {
        return new self(
            status: 'failed',
            connection: $connection,
            databasePath: $databasePath,
            appliedMigrations: $appliedMigrations,
            failedMigration: $failedMigration,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
        );
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
