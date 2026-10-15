<?php

use App\Filament\Pages\NotificationsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function notificationsPageCreate(User $user, string $title, ?string $readAt = null, ?string $tag = 'crr'): string
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'templated-notification',
        'data' => ['title' => $title, 'body' => "Body for {$title}", 'tag' => $tag],
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ])->id;
}

test('the notifications page lists only the current users notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    notificationsPageCreate($user, 'Mine');
    notificationsPageCreate($other, 'Theirs');

    $this->actingAs($user);

    Livewire::test(NotificationsPage::class)
        ->assertSee('Mine')
        ->assertSee('CRR')
        ->assertDontSee('Theirs');
});

test('tapping a notification marks it read and expands it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $id = notificationsPageCreate($user, 'CRR approved');

    Livewire::test(NotificationsPage::class)
        ->call('toggle', $id)
        ->assertSet('expandedId', $id);

    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->not->toBeNull();
});

test('tapping an expanded notification collapses it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $id = notificationsPageCreate($user, 'CRR approved', readAt: now()->toDateTimeString());

    Livewire::test(NotificationsPage::class)
        ->call('toggle', $id)
        ->assertSet('expandedId', $id)
        ->call('toggle', $id)
        ->assertSet('expandedId', null);
});

test('mark all as read clears unread notifications and dispatches a refresh', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    notificationsPageCreate($user, 'One');
    notificationsPageCreate($user, 'Two');

    Livewire::test(NotificationsPage::class)
        ->call('markAllAsRead')
        ->assertDispatched('notifications-updated');

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('load more increases the visible window', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(NotificationsPage::class)
        ->assertSet('perPage', 20)
        ->call('loadMore')
        ->assertSet('perPage', 40);
});

test('a failed background refresh keeps local rows visible', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'page-token']);
    $this->actingAs($user);

    notificationsPageCreate($user, 'Offline visible');

    Http::fake([
        'portal.test/api/ping' => Http::response([], 200),
        'portal.test/api/notifications/pull*' => Http::response(['error' => 'Server error'], 500),
    ]);

    Livewire::test(NotificationsPage::class)
        ->call('refresh')
        ->assertSee('Offline visible');
});

test('refreshing the page pulls new notifications from the portal', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'page-pull-token', 'server_id' => 9002]);
    $this->actingAs($user);

    $id = (string) Str::uuid();

    Http::fake([
        'portal.test/api/ping' => Http::response([], 200),
        'portal.test/api/notifications/pull*' => Http::response([
            'notifications' => [[
                'id' => $id,
                'title' => 'Pulled on visit',
                'body' => 'Body for pulled row',
                'module' => 'crr',
                'created_at' => now()->toIso8601String(),
            ]],
            'has_more' => false,
        ], 200),
    ]);

    Livewire::test(NotificationsPage::class)
        ->call('refresh')
        ->assertSee('Pulled on visit');

    expect(DB::table('notifications')->where('id', $id)->exists())->toBeTrue();
});

test('the notifications page renders an empty state without rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(NotificationsPage::class)
        ->assertSee('No notifications yet');
});
