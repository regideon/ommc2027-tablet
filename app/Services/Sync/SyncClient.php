<?php

namespace App\Services\Sync;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class SyncClient
{
    public function forUser(User $user, ?int $timeout = null): PendingRequest
    {
        return Http::withToken((string) $user->api_token)
            ->acceptJson()
            ->timeout($timeout ?? (int) config('sync.timeout', 15));
    }
}
