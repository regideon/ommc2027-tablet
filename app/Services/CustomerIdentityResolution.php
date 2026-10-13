<?php

namespace App\Services;

final readonly class CustomerIdentityResolution
{
    public const MATCHED = 'matched';

    public const NEW = 'new';

    public const CONFLICT = 'conflict';

    public function __construct(
        public string $status,
        public ?int $localId = null,
        public ?string $matchedBy = null,
        public ?string $reason = null,
    ) {}

    public function isConflict(): bool
    {
        return $this->status === self::CONFLICT;
    }
}
