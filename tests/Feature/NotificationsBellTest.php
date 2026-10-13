<?php

use App\Livewire\NotificationsBell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function notificationsBellCreate(User $user, ?string $readAt = null): string
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'templated-notification',
        'data' => ['title' => 'CRR approved', 'body' => 'Body', 'tag' => 'crr'],
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ])->id;
}

test('the bell counts only the current users unread notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);

    notificationsBellCreate($user);
    notificationsBellCreate($user, readAt: now()->toDateTimeString());
    notificationsBellCreate($other);

    Livewire::test(NotificationsBell::class)
        ->assertSet('unreadCount', 1)
        ->assertSee('1');
});

test('the bell refreshes when a notifications-updated event is dispatched', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(NotificationsBell::class)->assertSet('unreadCount', 0);

    notificationsBellCreate($user);

    $component->dispatch('notifications-updated')->assertSet('unreadCount', 1);
});

test('the bell links to the notifications page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(NotificationsBell::class)
        ->assertSee(route('filament.saleshub.pages.notifications'));
});

test('the panel topbar renders the notification bell for authenticated users', function () {
    config(['app.env' => 'local']);

    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/app')
        ->assertOk()
        ->assertSee(route('filament.saleshub.pages.notifications'));
});
