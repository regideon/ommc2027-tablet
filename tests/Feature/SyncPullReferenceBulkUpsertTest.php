<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('pull bulk-upserts a large reference table instead of one query per row', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    $barangays = collect(range(1, 2000))->map(fn (int $id): array => [
        'id' => $id,
        'municipality_id' => 1,
        'psgc_code' => null,
        'code' => "BRY-{$id}",
        'name' => "Barangay {$id}",
        'enabled' => true,
    ])->all();

    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    Http::fake([
        'portal.test/api/sync/pull' => Http::response(['barangays' => $barangays], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('barangays')->count())->toBe(2000)
        ->and($queryCount)->toBeLessThan(100);
});

test('pull updates existing reference rows in place', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    DB::table('barangays')->insert([
        'id' => 1,
        'municipality_id' => 1,
        'code' => 'OLD',
        'name' => 'Old name',
        'enabled' => true,
    ]);

    Http::fake([
        'portal.test/api/sync/pull' => Http::response(['barangays' => [[
            'id' => 1,
            'municipality_id' => 1,
            'psgc_code' => null,
            'code' => 'NEW',
            'name' => 'New name',
            'enabled' => false,
        ]]], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('barangays')->count())->toBe(1)
        ->and(DB::table('barangays')->where('id', 1)->value('name'))->toBe('New name')
        ->and((bool) DB::table('barangays')->where('id', 1)->value('enabled'))->toBeFalse();
});
