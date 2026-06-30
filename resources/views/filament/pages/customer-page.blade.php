<x-filament-panels::page>
    <div class="space-y-2">
        @forelse($customers as $customer)
            <div class="flex items-center gap-3 px-4 py-3 bg-white rounded-2xl shadow-sm">
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-[#191c1e] text-sm truncate">{{ $customer->name }}</p>
                    @if($customer->address)
                        <p class="text-xs text-[#737685] truncate">{{ $customer->address }}</p>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-[#737685] text-center py-10">No customers found.</p>
        @endforelse
    </div>
</x-filament-panels::page>
