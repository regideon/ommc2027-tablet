<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * The on-device SQLite database is empty until the first successful pull
 * (lookup data like salescall_statuses is delivered by /api/sync/pull, not by
 * a migration seed). A missing table used to crash the dashboard / initial
 * pull with "no such table: salescall_statuses". The ensure migration creates
 * the table, and the pull must tolerate it being empty until the server sends
 * the first payload.
 */
test('pull succeeds when the salescall_statuses table exists but is empty', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    expect(DB::table('salescall_statuses')->count())->toBe(0);

    Http::fake([
        'portal.test/api/sync/pull' => Http::response([
            'customers' => [],
            'salescall_statuses' => [],
            'salescall_types' => [],
            'material_groups' => [],
            'brands' => [],
            'categories' => [],
            'sub_categories' => [],
            'sub_sub_categories' => [],
            'salescall_image_categories' => [],
            'salescall_image_types' => [],
            'customer_brands' => [],
            'customer_categories' => [],
            'customer_notes' => [],
            'itineraries' => [],
        ], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message);
    expect(DB::table('salescall_statuses')->count())->toBe(0);
});

/**
 * The sync payload inserts new statuses and updates existing ones by portal id.
 */
test('pull inserts and updates salescall_statuses from the payload', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'test-token']);
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull' => Http::sequence()
            ->push([
                'customers' => [],
                'salescall_statuses' => [['id' => 1, 'name' => 'Pending']],
            ], 200)
            ->push([
                'customers' => [],
                'salescall_statuses' => [
                    ['id' => 1, 'name' => 'Pending'],
                    ['id' => 2, 'name' => 'Completed'],
                ],
            ], 200),
    ]);

    expect(app(SyncService::class)->pull()->success)->toBeTrue();

    expect(DB::table('salescall_statuses')->where('id', 1)->value('name'))->toBe('Pending');

    expect(app(SyncService::class)->pull()->success)->toBeTrue();

    expect(DB::table('salescall_statuses')->where('id', 1)->value('name'))->toBe('Pending');
    expect(DB::table('salescall_statuses')->where('id', 2)->value('name'))->toBe('Completed');
    expect(DB::table('salescall_statuses')->count())->toBe(2);
});

/**
 * The ensure migration must be a no-op when the table already exists (fresh
 * installs get it from create_tablet_core_tables) so upgrades can re-run it
 * safely on devices whose SQLite schema drifted.
 */
test('ensure_salescall_statuses migration is idempotent', function () {
    expect(DB::getSchemaBuilder()->hasTable('salescall_statuses'))->toBeTrue();

    $migration = require database_path('migrations/2026_07_31_123423_ensure_salescall_statuses_table.php');
    $migration->up();

    expect(DB::getSchemaBuilder()->hasTable('salescall_statuses'))->toBeTrue();
    expect(DB::table('salescall_statuses')->count())->toBe(0);
});
