<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationsBell extends Component
{
    public int $unreadCount = 0;

    public function mount(): void
    {
        $this->refreshUnreadCount();
    }

    #[On('notifications-updated')]
    public function refreshUnreadCount(): void
    {
        $this->unreadCount = (int) (auth()->user()?->unreadNotifications()->count() ?? 0);
    }

    public function render(): View
    {
        return view('livewire.notifications-bell');
    }
}
