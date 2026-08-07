<x-filament-panels::page>
    <div class="space-y-2">
        @forelse($customers as $customer)
            <button
                type="button"
                wire:click="viewCustomer({{ $customer->id }})"
                class="w-full flex items-center gap-3 px-4 py-3 bg-white rounded-2xl shadow-sm text-left hover:bg-gray-50 transition-colors">
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-[#191c1e] text-sm truncate">{{ $customer->name }}</p>
                    @if($customer->address)
                        <p class="text-xs text-[#737685] truncate">{{ $customer->address }}</p>
                    @endif
                </div>
                <span class="material-symbols-outlined text-[#737685] text-lg shrink-0">chevron_right</span>
            </button>
        @empty
            <p class="text-sm text-[#737685] text-center py-10">No customers found.</p>
        @endforelse
    </div>

    @php
        $selectedCustomer = $selectedCustomerId ? $customers->firstWhere('id', $selectedCustomerId) : null;

        $statusLabels = [
            'scheduled' => 'Scheduled',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'partially_completed' => 'Partially Completed',
            'cancelled' => 'Cancelled',
        ];
        $statusClasses = [
            'scheduled' => 'bg-blue-100 text-blue-700',
            'in_progress' => 'bg-amber-100 text-amber-700',
            'completed' => 'bg-green-100 text-green-700',
            'partially_completed' => 'bg-orange-100 text-orange-700',
            'cancelled' => 'bg-gray-200 text-gray-600',
        ];
    @endphp

    {{-- CUSTOMER DETAIL OVERLAY --}}
    @if($selectedCustomer)
        <div class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end lg:items-center justify-center" wire:click.self="closeCustomer">
            <div class="bg-white rounded-t-3xl lg:rounded-3xl w-full lg:max-w-lg shadow-2xl overflow-hidden flex flex-col" style="max-height: 90vh;">

                {{-- Header --}}
                <div class="flex items-start justify-between gap-3 px-6 py-5 border-b border-gray-100 shrink-0">
                    <div class="min-w-0">
                        <h2 class="text-lg font-extrabold text-[#191c1e] truncate">{{ $selectedCustomer->name }}</h2>
                        @if($selectedCustomer->address)
                            <p class="text-xs text-[#737685] truncate mt-0.5">{{ $selectedCustomer->address }}</p>
                        @endif
                    </div>
                    <button wire:click="closeCustomer" class="w-8 h-8 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors shrink-0">
                        <span class="material-symbols-outlined text-[#434654] text-lg">close</span>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6">

                    {{-- Change Profile --}}
                    <div>
                        <h3 class="text-xs font-extrabold text-[#737685] uppercase tracking-wider mb-2">Latest Change Profile</h3>
                        @if($customerDetail['profile'] ?? null)
                            <div class="bg-[#f3f4f6] rounded-2xl p-4 space-y-1">
                                <p class="text-sm font-bold text-[#191c1e]">{{ $customerDetail['profile']['registered_name'] ?? '—' }}</p>
                                <p class="text-xs text-[#737685]">Owner: {{ $customerDetail['profile']['owner_name'] ?? '—' }}</p>
                                <p class="text-xs text-[#737685]">Classification: {{ $customerDetail['profile']['classification'] ?? '—' }}</p>
                                @if($customerDetail['profile']['mobile'] ?? null)
                                    <p class="text-xs text-[#737685]">Mobile: {{ $customerDetail['profile']['mobile'] }}</p>
                                @endif
                                <p class="text-[10px] text-gray-400 mt-1">Submitted {{ $customerDetail['profile']['submitted_at'] }}</p>
                            </div>
                        @else
                            <p class="text-sm text-[#737685] bg-gray-50 rounded-2xl px-4 py-3">No profile submitted yet.</p>
                        @endif
                    </div>

                    {{-- Category --}}
                    @if($customerDetail['category'] ?? null)
                        <div>
                            <h3 class="text-xs font-extrabold text-[#737685] uppercase tracking-wider mb-2">Category</h3>
                            <div class="bg-[#f3f4f6] rounded-2xl p-4">
                                <p class="text-sm font-bold text-[#191c1e]">{{ $customerDetail['category']['category'] ?? '—' }}</p>
                                <p class="text-xs text-[#737685]">{{ $customerDetail['category']['sub_category'] ?? '—' }}</p>
                            </div>
                        </div>
                    @endif

                    {{-- Brand Submissions --}}
                    <div>
                        <h3 class="text-xs font-extrabold text-[#737685] uppercase tracking-wider mb-2">Brand Submissions</h3>
                        @if(!empty($customerDetail['brands']))
                            <div class="space-y-1.5">
                                @foreach($customerDetail['brands'] as $row)
                                    <div class="flex items-center justify-between bg-[#f3f4f6] rounded-xl px-4 py-2.5">
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold text-[#191c1e] truncate">{{ $row['material_group'] }}</p>
                                            <p class="text-xs text-[#737685] truncate">{{ $row['brand'] }}</p>
                                        </div>
                                        @if($row['quantity'])
                                            <span class="text-xs font-bold text-[#890f00] shrink-0 ml-2">{{ $row['quantity'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-[#737685] bg-gray-50 rounded-2xl px-4 py-3">No brand submissions yet.</p>
                        @endif
                    </div>

                    {{-- Quick Notes --}}
                    <div>
                        <h3 class="text-xs font-extrabold text-[#737685] uppercase tracking-wider mb-2">Your Quick Notes</h3>
                        @if(!empty($customerDetail['notes']))
                            <div class="space-y-1.5">
                                @foreach($customerDetail['notes'] as $note)
                                    <div class="bg-[#fef9e7] border border-[#f5e6a8] rounded-xl p-3">
                                        @if($note['title'])
                                            <p class="text-xs font-bold text-[#191c1e] mb-0.5">{{ $note['title'] }}</p>
                                        @endif
                                        <p class="text-xs text-[#434654] whitespace-pre-wrap">{{ $note['body'] }}</p>
                                        <p class="text-[10px] text-[#8a7f3f] font-medium mt-1">{{ $note['created_at'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-[#737685] bg-gray-50 rounded-2xl px-4 py-3">No notes yet for this customer.</p>
                        @endif
                    </div>

                    {{-- Recent Visits --}}
                    <div>
                        <h3 class="text-xs font-extrabold text-[#737685] uppercase tracking-wider mb-2">Recent Visits</h3>
                        @if(!empty($customerDetail['visits']))
                            <div class="space-y-1.5">
                                @foreach($customerDetail['visits'] as $visit)
                                    <div class="flex items-center justify-between bg-[#f3f4f6] rounded-xl px-4 py-2.5">
                                        <div class="min-w-0">
                                            <span class="text-xs font-medium text-[#191c1e] block">{{ $visit['date'] }}</span>
                                            <span class="text-[10px] text-[#737685] truncate block">{{ $visit['visited_by'] }}</span>
                                        </div>
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase shrink-0 ml-2 {{ $statusClasses[$visit['status']] ?? 'bg-gray-200 text-gray-600' }}">
                                            {{ $statusLabels[$visit['status']] ?? $visit['status'] }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-[#737685] bg-gray-50 rounded-2xl px-4 py-3">No visits recorded yet.</p>
                        @endif
                    </div>

                    {{-- Photos — deliberately lazy: nothing downloads until tapped --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-xs font-extrabold text-[#737685] uppercase tracking-wider">
                                Photos @if($customerDetail['photo_count'] ?? 0) ({{ $customerDetail['photo_count'] }}) @endif
                            </h3>
                            @if(($customerDetail['photo_count'] ?? 0) > 0 && !$showPhotos)
                                <button wire:click="loadCustomerPhotos({{ $selectedCustomerId }})" class="text-xs font-bold text-[#890f00]">
                                    Show Photos
                                </button>
                            @endif
                        </div>

                        @if(($customerDetail['photo_count'] ?? 0) === 0)
                            <p class="text-sm text-[#737685] bg-gray-50 rounded-2xl px-4 py-3">No photos yet.</p>
                        @elseif($showPhotos)
                            <div class="grid grid-cols-4 gap-2">
                                @foreach($customerPhotos as $photo)
                                    <div class="aspect-square rounded-xl overflow-hidden bg-gray-100">
                                        <img
                                            src="{{ url('/salescall-image/' . $photo['id']) }}"
                                            loading="lazy"
                                            title="{{ $photo['type'] }}"
                                            class="w-full h-full object-cover">
                                    </div>
                                @endforeach
                            </div>
                            @if($customerDetail['photo_count'] > count($customerPhotos))
                                <p class="text-[10px] text-gray-400 mt-2">Showing the {{ count($customerPhotos) }} most recent of {{ $customerDetail['photo_count'] }} photos.</p>
                            @endif
                        @endif
                    </div>

                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
