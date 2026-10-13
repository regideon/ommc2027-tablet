<a href="{{ route('filament.saleshub.pages.notifications') }}" title="Notifications"
   wire:poll.60s="refreshUnreadCount"
   style="position:relative; display:inline-flex; align-items:center; justify-content:center;
          width:2rem; height:2rem; border-radius:9999px; background:#000; color:#fff;
          margin-right:0.25rem; text-decoration:none; font-size:1.1rem;">
    <span class="material-symbols-outlined" style="font-size:1.1rem;">notifications</span>
    @if ($unreadCount > 0)
        <span style="position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; padding:0 4px;
                     border-radius:9999px; background:#dc2626; color:#fff; font-size:10px; font-weight:800;
                     line-height:16px; text-align:center;">
            {{ $unreadCount > 9 ? '9+' : $unreadCount }}
        </span>
    @endif
</a>
