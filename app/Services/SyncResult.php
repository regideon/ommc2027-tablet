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
        public readonly ?int $httpStatus = null,
        public readonly ?string $exceptionClass = null,
        public readonly ?int $durationMs = null,
        public readonly ?int $responseBytes = null,
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
    ): self {
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
        ?int $httpStatus = null,
        ?string $exceptionClass = null,
        ?int $durationMs = null,
        ?int $responseBytes = null,
    ): self {
        return new self(false, $message, $errorCode, $syncedCount, $failedCount, $retryableCount, $failureReasons, $httpStatus, $exceptionClass, $durationMs, $responseBytes);
    }

    /** @param list<string> $failureReasons */
    public static function aggregate(int $pushed, int $failed, int $retryable, array $failureReasons): self
    {
        if ($pushed === 0 && $failed === 0) {
            return self::ok('Nothing to push.', 0, 0, 0, []);
        }

        if ($failed > 0 && $pushed === 0) {
            return self::fail(
                $retryable > 0
                    ? ($failed === 1 ? '1 item could not be uploaded and will retry later.' : "{$failed} items could not be uploaded and will retry later.")
                    : ($failed === 1 ? '1 item failed and reached its automatic retry limit. Review the item and use its available recovery action.' : "{$failed} items failed and reached their automatic retry limit. Review each item and use its available recovery action."),
                'push_failed',
                0,
                $failed,
                $retryable,
                $failureReasons,
            );
        }

        if ($failed > 0) {
            return self::ok(
                "{$pushed} item".($pushed === 1 ? '' : 's')." synced. {$failed} item".($failed === 1 ? '' : 's').($retryable > 0 ? ' could not be uploaded and will retry later.' : ' failed and reached their automatic retry limit.'),
                $pushed,
                $failed,
                $retryable,
                $failureReasons,
            );
        }

        $snapshotNotice = in_array('customer_snapshot_unsupported', $failureReasons, true)
            ? ' Portal lacks the authoritative aggregate snapshot contract; aggregate refresh is not claimed.'
            : (in_array('customer_snapshot_not_reconciled', $failureReasons, true)
                ? ' The Portal snapshot has not been applied by this Tablet build.'
                : '');

        return self::ok(
            "{$pushed} item".($pushed === 1 ? '' : 's').' delivery acknowledged successfully.'.$snapshotNotice,
            $pushed,
            0,
            0,
            $failureReasons,
        );
    }
}
