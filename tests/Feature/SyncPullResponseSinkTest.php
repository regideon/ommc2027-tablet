<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('pull streams the response to a file sink instead of the PHP temp dir', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    $capturedSink = null;

    Http::fake(function ($request, $options) use (&$capturedSink) {
        $capturedSink = $options['sink'] ?? null;

        if (str_ends_with($request->url(), '/api/sync/pull/locations')) {
            return Http::response(emptyLocationReferenceSnapshot(), 200);
        }

        return Http::response(['users' => [], 'customers' => []], 200);
    });

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and($capturedSink)->toBeString()
        ->and((string) $capturedSink)->toContain('sync-pull')
        ->and(dirname((string) $capturedSink))->toBe(storage_path('app/tmp'));
});

test('pull decodes a response larger than the php://temp memory threshold', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    // > 2 MB, so a php://temp-backed body would have to spill to disk.
    $body = json_encode([
        'users' => [
            ['id' => 1, 'email' => 'nobody@example.com', 'name' => str_repeat('A', 3 * 1024 * 1024)],
        ],
    ]);

    Http::fake(function ($request) use ($body) {
        if (str_ends_with($request->url(), '/api/sync/pull/locations')) {
            return Http::response(emptyLocationReferenceSnapshot(), 200);
        }

        return Http::response($body, 200, ['Content-Type' => 'application/json']);
    });

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message);
});
