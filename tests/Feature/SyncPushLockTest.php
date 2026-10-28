<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('a concurrent sync is rejected while the push lock is held', function () {
    $user = User::factory()->create(['api_token' => 'sync-lock-test-token']);
    $this->actingAs($user);
    Http::fake();

    $lock = Cache::lock('sync:push', 60);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(SyncService::class)->push();
    } finally {
        $lock->release();
    }

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('sync_in_progress')
        ->and($result->message)->toBe('A sync is already in progress. Please wait for it to finish.');

    Http::assertNothingSent();
});
