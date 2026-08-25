<?php

namespace App\Services;

class SyncResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $errorCode = null,
        public readonly int $syncedCount = 0,
        public readonly int $failedCount = 0,
        public readonly int $retryableCount = 0,
        /** @var list<string> */
        public readonly array $failureReasons = [],
    ) {}

    /**
     * @param  list<string>  $failureReasons
     */
    public static function ok(
        string $message = 'Success.',
        int $syncedCount = 0,
        int $failedCount = 0,
        int $retryableCount = 0,
        array $failureReasons = [],
    ): self
    {
        return new self(true, $message, null, $syncedCount, $failedCount, $retryableCount, $failureReasons);
    }

    /**
     * @param  list<string>  $failureReasons
     */
    public static function fail(
        string $message,
        ?string $errorCode = null,
        int $syncedCount = 0,
        int $failedCount = 0,
        int $retryableCount = 0,
        array $failureReasons = [],
    ): self
    {
        return new self(false, $message, $errorCode, $syncedCount, $failedCount, $retryableCount, $failureReasons);
    }
}
