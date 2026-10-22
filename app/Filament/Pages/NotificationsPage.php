<?php

namespace App\Filament\Pages;

use App\Services\SyncService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationsPage extends Page
{
    /**
     * Module tag to display label.
     *
     * @var array<string, string>
     */
    protected const MODULE_LABELS = [
        'crr' => 'CRR',
        'crr-signage' => 'CRR Signage',
        'giveaway' => 'Giveaway',
        'marketing' => 'Marketing',
        'repair' => 'Repair',
        'trade-check' => 'Trade Check',
        'trade-request' => 'CRR - LSM',
        'training' => 'Training',
    ];

    protected string $view = 'filament.pages.notifications-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $slug = 'notifications';

    protected static ?string $title = '';

    protected static bool $shouldRegisterNavigation = false;

    public int $perPage = 20;

    public ?string $expandedId = null;

    /**
     * Refresh from the Portal in the background; failures keep local data.
     */
    public function refresh(): void
    {
        try {
            $sync = app(SyncService::class);

            if ($sync->isReachable()) {
                $sync->pullNotifications();
            }
        } catch (Throwable $exception) {
            Log::debug('Notification refresh skipped: '.$exception->getMessage());
        }

        $this->dispatch('notifications-updated');
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function getNotifications(): Collection
    {
        return auth()->user()
            ?->notifications()
            ->latest()
            ->limit($this->perPage)
            ->get() ?? new Collection;
    }

    /**
     * Mark the notification read and expand or collapse its full body.
     */
    public function toggle(string $notificationId): void
    {
        $notification = auth()->user()
            ?->notifications()
            ->whereKey($notificationId)
            ->first();

        if (! $notification) {
            return;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $this->expandedId = $this->expandedId === $notificationId ? null : $notificationId;

        $this->dispatch('notifications-updated');
    }

    public function loadMore(): void
    {
        $this->perPage += 20;
    }

    public function markAllAsRead(): void
    {
        auth()->user()?->unreadNotifications()->update(['read_at' => now()]);

        $this->expandedId = null;

        $this->dispatch('notifications-updated');
    }

    public function moduleLabel(?string $tag): string
    {
        if ($tag === null || $tag === '') {
            return 'Form';
        }

        return self::MODULE_LABELS[$tag] ?? (string) str($tag)->headline();
    }
}
