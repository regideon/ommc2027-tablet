<?php

namespace App\Services;

class SyncResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $errorCode = null,
    ) {}

    public static function ok(string $message = 'Success.'): self
    {
        return new self(true, $message);
    }

    public static function fail(string $message, ?string $errorCode = null): self
    {
        return new self(false, $message, $errorCode);
    }
}
