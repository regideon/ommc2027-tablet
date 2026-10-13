<?php

namespace App\Filament\Concerns;

/**
 * Preselects and locks the sales call of a form page when it is opened from
 * the sales call page with ?salescall=<local_uuid>.
 *
 * The host page must expose a nullable string $salescall_uuid property, the
 * user's own [uuid, label] $salescalls list, and a bool $isEdit flag.
 */
trait LocksRequestedSalescall
{
    public bool $salescallLocked = false;

    protected function applyRequestedSalescallLock(): void
    {
        $salescallUuid = trim((string) request()->query('salescall'));

        if ($salescallUuid === ''
            || $this->isEdit
            || ! collect($this->salescalls)->contains('uuid', $salescallUuid)) {
            return;
        }

        $this->salescall_uuid = $salescallUuid;
        $this->salescallLocked = true;
    }
}
