<?php

use App\Models\User;
use App\Services\Sync\SyncClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('SyncClient sends the bearer token and accepts json', function () {
    Http::fake();
    $user = User::factory()->create(['api_token' => 'client-token']);

    app(SyncClient::class)->forUser($user)->get('http://portal.test/api/ping');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://portal.test/api/ping'
        && $request->hasHeader('Authorization', 'Bearer client-token')
        && $request->hasHeader('Accept', 'application/json'));
});
