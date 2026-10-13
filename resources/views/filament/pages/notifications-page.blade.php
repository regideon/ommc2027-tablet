<x-filament-panels::page>

@php($notifications = $this->getNotifications())

<div class="max-w-lg mx-auto space-y-4 pb-8" wire:init="refresh">

    <div class="px-1 pt-2 pb-1 flex items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-[#191c1e]">Notifications</h1>
            <p class="text-sm text-[#737685] mt-0.5">Form updates for your submissions</p>
        </div>

        @if ($notifications->whereNull('read_at')->isNotEmpty())
            <button type="button" wire:click="markAllAsRead"
                    class="text-xs font-bold text-[#890f00] uppercase tracking-wide whitespace-nowrap pb-1">
                Mark all read
            </button>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-6 py-12 text-center">
            <span class="material-symbols-outlined text-4xl text-gray-300">notifications_off</span>
            <p class="mt-3 font-semibold text-sm text-[#191c1e]">No notifications yet</p>
            <p class="text-xs text-[#737685] mt-1">Form approval updates will appear here.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($notifications as $notification)
                @php($isUnread = $notification->read_at === null)
                @php($isExpanded = $expandedId === $notification->id)

                <button type="button" wire:click="toggle('{{ $notification->id }}')"
                        class="w-full text-left bg-white rounded-2xl shadow-sm border border-gray-100 px-5 py-4 {{ $isUnread ? 'border-l-4 border-l-[#890f00]' : '' }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[10px] font-black uppercase tracking-widest text-[#890f00]">
                            {{ $this->moduleLabel($notification->data['tag'] ?? null) }}
                        </span>
                        <span class="text-[10px] text-[#737685]">{{ $notification->created_at?->diffForHumans() }}</span>
                    </div>
                    <div class="mt-1 flex items-start gap-2">
                        @if ($isUnread)
                            <span class="mt-1.5 w-2 h-2 rounded-full bg-[#890f00] shrink-0"></span>
                        @endif
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm text-[#191c1e]">{{ $notification->data['title'] ?? 'Form update' }}</p>
                            <p class="text-xs text-[#737685] mt-0.5 {{ $isExpanded ? '' : 'truncate' }}">
                                {{ $notification->data['body'] ?? '' }}
                            </p>
                        </div>
                    </div>
                </button>
            @endforeach
        </div>

        @if ($notifications->count() >= $perPage)
            <button type="button" wire:click="loadMore"
                    class="w-full text-center text-xs font-bold text-[#890f00] uppercase tracking-wide py-2">
                Load more
            </button>
        @endif
    @endif
</div>

</x-filament-panels::page>
