<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function syncNotificationsUser(): User
{
    return User::factory()->create(['api_token' => 'notify-token', 'server_id' => 9001]);
}

function syncNotificationsRow(string $id, string $title, string $createdAt, string $tag = 'crr'): array
{
    return [
        'id' => $id,
        'title' => $title,
        'body' => "Body for {$title}",
        'module' => $tag,
        'created_at' => $createdAt,
    ];
}

test('pull notifications stores rows for the logged-in user with mapped data', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    $id = (string) Str::uuid();
    $createdAt = now()->subMinutes(5)->toIso8601String();

    Http::fake([
        'portal.test/api/sync/pull/notifications*' => Http::response([
            'notifications' => [syncNotificationsRow($id, 'CRR approved', $createdAt)],
            'has_more' => false,
        ], 200),
    ]);

    $result = app(SyncService::class)->pullNotifications();

    expect($result->success)->toBeTrue($result->message);

    $row = DB::table('notifications')->where('id', $id)->first();

    expect($row)->not->toBeNull()
        ->and($row->type)->toBe('templated-notification')
        ->and($row->notifiable_type)->toBe(User::class)
        ->and((int) $row->notifiable_id)->toBe($user->id)
        ->and($row->read_at)->toBeNull();

    $data = json_decode($row->data, true);

    expect($data['title'])->toBe('CRR approved')
        ->and($data['body'])->toBe('Body for CRR approved')
        ->and($data['tag'])->toBe('crr');
});

test('pull notifications is idempotent and preserves local read state', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    $id = (string) Str::uuid();

    Http::fake([
        'portal.test/api/sync/pull/notifications*' => Http::response([
            'notifications' => [syncNotificationsRow($id, 'CRR approved', now()->subMinute()->toIso8601String())],
            'has_more' => false,
        ], 200),
    ]);

    app(SyncService::class)->pullNotifications();

    DB::table('notifications')->where('id', $id)->update(['read_at' => now()]);
    $readAt = DB::table('notifications')->where('id', $id)->value('read_at');

    app(SyncService::class)->pullNotifications();

    expect(DB::table('notifications')->count())->toBe(1)
        ->and(DB::table('notifications')->where('id', $id)->value('read_at'))->toBe($readAt);
});

test('pull notifications sends the watermark as since on the next pull', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    $createdAt = now()->subMinutes(3)->toIso8601String();

    Http::fake([
        'portal.test/api/sync/pull/notifications*' => Http::response([
            'notifications' => [syncNotificationsRow((string) Str::uuid(), 'CRR approved', $createdAt)],
            'has_more' => false,
        ], 200),
    ]);

    app(SyncService::class)->pullNotifications();
    app(SyncService::class)->pullNotifications();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'since=')
        && str_contains(urldecode($request->url()), $createdAt));
});

test('pull notifications follows has_more pages', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    Http::fake(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $page = (int) ($query['page'] ?? 1);

        return Http::response([
            'notifications' => [syncNotificationsRow((string) Str::uuid(), "Page {$page}", now()->subMinutes(10 - $page)->toIso8601String())],
            'has_more' => $page === 1,
        ], 200);
    });

    $result = app(SyncService::class)->pullNotifications();

    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('notifications')->count())->toBe(2);
});

test('pull notifications fails with a token error on 401', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/notifications*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $result = app(SyncService::class)->pullNotifications();

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('token_expired');
});

test('the schedule pull does not pull notifications', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/locations' => Http::response(emptyLocationReferenceSnapshot(), 200),
        'portal.test/api/sync/pull/schedule' => Http::response([
            'customers' => [],
            'users' => [],
            'itineraries' => [],
            'customer_notes' => [],
        ], 200),
        'portal.test/api/sync/pull/notifications*' => Http::response(['notifications' => [], 'has_more' => false], 200),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/sync/pull/notifications'));
});
