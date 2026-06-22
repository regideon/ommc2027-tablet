<x-filament-widgets::widget>
<div class="space-y-6">

    {{-- KPI Row --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-4">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Planned Calls</span>
                <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined text-blue-600">event_note</span>
                </div>
            </div>
            <p class="text-5xl font-black text-gray-900">{{ $todayCalls->count() }}</p>
            <div class="mt-3 flex items-center gap-2">
                <span class="text-xs font-bold text-blue-600">Today</span>
            </div>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-4">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Completed</span>
                <div class="w-10 h-10 bg-green-50 rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined mat-fill text-green-600">check_circle</span>
                </div>
            </div>
            @php $completed = $todayCalls->where('status', 'completed')->count(); @endphp
            <p class="text-5xl font-black text-gray-900">{{ $completed }}</p>
            <div class="mt-3 flex items-center gap-2">
                @if($todayCalls->count() > 0)
                    @php $pct = round($completed / $todayCalls->count() * 100); @endphp
                    <span class="text-xs font-bold text-green-600">{{ $pct }}% Done</span>
                    <div class="flex-1 h-1.5 bg-green-100 rounded-full overflow-hidden">
                        <div class="bg-green-600 h-full rounded-full" style="width:{{ $pct }}%"></div>
                    </div>
                @else
                    <span class="text-xs text-gray-400">No calls today</span>
                @endif
            </div>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-4">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">In Progress</span>
                <div class="w-10 h-10 bg-amber-50 rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined text-amber-500">pending</span>
                </div>
            </div>
            <p class="text-5xl font-black text-gray-900">{{ $todayCalls->where('status', 'in_progress')->count() }}</p>
            <p class="text-xs text-gray-400 mt-3 font-medium">Currently active</p>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-4">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Scheduled</span>
                <div class="w-10 h-10 bg-orange-50 rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined text-orange-500">hourglass_empty</span>
                </div>
            </div>
            <p class="text-5xl font-black text-gray-900">{{ str_pad($todayCalls->where('status', 'scheduled')->count(), 2, '0', STR_PAD_LEFT) }}</p>
            <div class="mt-3">
                <span class="px-2 py-1 bg-yellow-100 text-yellow-700 text-[10px] font-black rounded uppercase">Pending</span>
            </div>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-4">
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Monthly Rate</span>
                <div class="w-10 h-10 bg-red-50 rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#890f00]">trending_up</span>
                </div>
            </div>
            <p class="text-5xl font-black text-gray-900">92%</p>
            <p class="text-xs font-bold text-[#890f00] mt-3">On Track</p>
        </div>

    </div>

    {{-- Main Grid --}}
    <div class="grid grid-cols-12 gap-6">

        {{-- Left Column --}}
        <div class="col-span-12 lg:col-span-8 space-y-6">

            {{-- Today's Route --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900">Today's Route</h3>
                    <span class="text-xs text-gray-400">{{ now()->format('l, M j') }}</span>
                </div>
                <div class="p-6 space-y-5">

                    @forelse($todayCalls as $call)
                        @php
                            $status     = $call->status;
                            $dotColor   = match($status) {
                                'completed'   => 'bg-green-500',
                                'in_progress' => 'bg-blue-500',
                                default       => 'bg-gray-400',
                            };
                            $cardClass  = match($status) {
                                'completed'   => 'bg-gray-50 border border-gray-100',
                                'in_progress' => 'bg-white border-2 border-blue-500 shadow-md',
                                default       => 'bg-white border border-gray-200',
                            };
                            $badgeClass = match($status) {
                                'completed'   => 'bg-green-100 text-green-700',
                                'in_progress' => 'bg-amber-100 text-amber-700',
                                default       => 'bg-gray-100 text-gray-600',
                            };
                            $badgeLabel = match($status) { 'completed' => 'Completed', 'in_progress' => 'In Progress', default => 'Scheduled' };
                            $timeLabel  = match($status) { 'completed' => 'Checked-in', 'in_progress' => 'Current', default => 'Scheduled' };
                            $timeClass  = match($status) {
                                'in_progress' => 'text-blue-600',
                                'completed'   => 'text-gray-500',
                                default       => 'text-gray-700',
                            };
                            $timeLabelClass = match($status) {
                                'completed'   => 'text-green-600',
                                'in_progress' => 'text-blue-600',
                                default       => 'text-gray-600',
                            };
                            $nameClass = match($status) {
                                'completed'   => 'text-gray-500',
                                default       => 'text-gray-900',
                            };
                        @endphp

                        <div class="relative pl-12">
                            @if(!$loop->last)
                                <div class="absolute left-4 top-0 h-full w-0.5 bg-gray-100"></div>
                            @else
                                <div class="absolute left-4 top-0 h-1/2 w-0.5 bg-gray-100"></div>
                            @endif
                            <div class="absolute left-[10px] top-1.5 w-5 h-5 rounded-full {{ $dotColor }} border-4 border-white shadow-sm z-10 {{ $status === 'in_progress' ? 'animate-pulse' : '' }}"></div>


                            <a href="/app/salescall-page?call={{ $call->id }}"
                            class="{{ $cardClass }} p-5 rounded-xl flex items-start gap-5 no-underline active:scale-[0.98] transition-all cursor-pointer">

                                {{-- Time Column --}}
                                <div class="text-center w-16 shrink-0 pt-0.5">
                                    <p class="text-xs font-bold {{ $timeClass }}">{{ $call->visit_date->format('h:i A') }}</p>
                                    <p class="text-[10px] {{ $timeLabelClass }} font-black mt-1 uppercase">{{ $timeLabel }}</p>
                                </div>

                                {{-- Divider --}}
                                <div class="w-px self-stretch bg-gray-200 shrink-0"></div>

                                {{-- Customer Info + Badge --}}
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="font-bold {{ $nameClass }} leading-snug">{{ $call->customer?->name ?? '—' }}</p>
                                        <span class="px-3 py-1 {{ $badgeClass }} text-xs font-bold rounded-full shrink-0 whitespace-nowrap">{{ $badgeLabel }}</span>
                                    </div>
                                    <p class="text-sm text-gray-500 mt-1">{{ $call->customer?->address ?? '' }}</p>
                                </div>

                            </a>



                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center py-10 text-gray-400">
                            <span class="material-symbols-outlined text-5xl mb-3">event_busy</span>
                            <p class="text-sm font-medium">No sales calls scheduled for today</p>
                        </div>
                    @endforelse

                </div>
            </div>

            {{-- Sales Call Status Overview --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
                <div class="px-6 py-5 border-b border-gray-100">
                    <h3 class="text-lg font-bold text-gray-900">Sales Call Status Overview</h3>
                </div>
                <div class="p-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-gray-50 p-4 rounded-xl flex items-center justify-between">
                        <span class="text-sm font-bold text-gray-600">Planned</span>
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 text-xs font-black rounded-lg">14</span>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl flex items-center justify-between">
                        <span class="text-sm font-bold text-gray-600">In Progress</span>
                        <span class="px-3 py-1 bg-orange-100 text-orange-700 text-xs font-black rounded-lg">02</span>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl flex items-center justify-between">
                        <span class="text-sm font-bold text-gray-600">Completed</span>
                        <span class="px-3 py-1 bg-green-100 text-green-700 text-xs font-black rounded-lg">45</span>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl flex items-center justify-between">
                        <span class="text-sm font-bold text-gray-600">Approved</span>
                        <span class="px-3 py-1 bg-indigo-100 text-indigo-700 text-xs font-black rounded-lg">38</span>
                    </div>
                </div>
            </div>

        </div>

        {{-- Right Column --}}
        <div class="col-span-12 lg:col-span-4 space-y-6">

            {{-- Priority Notification --}}
            <div class="bg-[#890f00] p-6 rounded-2xl text-white">
                <div class="flex items-center gap-3 mb-4">
                    <span class="material-symbols-outlined mat-fill">campaign</span>
                    <span class="text-xs font-black uppercase tracking-widest">Motolite Announcements</span>
                </div>

                <div class="space-y-4">
                    <div class="bg-white/10 rounded-xl p-4">
                        <p class="text-xs font-black uppercase tracking-wider text-white/60 mb-1">Sales Reminder</p>
                        <p class="font-bold text-sm leading-snug">Complete all sales calls on time and ensure accurate data entry before end of day.</p>
                    </div>

                    <div class="bg-white/10 rounded-xl p-4">
                        <p class="text-xs font-black uppercase tracking-wider text-white/60 mb-1">Sync Policy</p>
                        <p class="font-bold text-sm leading-snug">Sync your data every after completing a sales call while connected to the internet.</p>
                    </div>
                </div>
            </div>



            {{-- Today's Map --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden"
                x-data="{
                    showFullscreen: false,
                    leafletMap: null,
                    fullscreenMap: null,

                    initMap() {
                        const pins = {{ $mapPinsJson }};
                        const map = L.map('dashboard-map');
                        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                            attribution: '&copy; OpenStreetMap &copy; CartoDB', maxZoom: 18
                        }).addTo(map);
                        this.addPins(map, pins);
                        this.leafletMap = map;
                    },

                    openFullscreen() {
                        this.showFullscreen = true;
                        document.body.classList.add('dashboard-map-open');
                        this.$nextTick(() => {
                            const pins = {{ $mapPinsJson }};
                            if (this.fullscreenMap) { this.fullscreenMap.remove(); this.fullscreenMap = null; }
                            const map = L.map('dashboard-map-fullscreen');
                            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                                attribution: '&copy; OpenStreetMap &copy; CartoDB', maxZoom: 18
                            }).addTo(map);
                            this.addPins(map, pins);
                            this.fullscreenMap = map;
                        });
                    },

                    closeFullscreen() {
                        this.showFullscreen = false;
                        document.body.classList.remove('dashboard-map-open');
                        if (this.fullscreenMap) { this.fullscreenMap.remove(); this.fullscreenMap = null; }
                    },


                    closeFullscreen() {
                        this.showFullscreen = false;
                        if (this.fullscreenMap) { this.fullscreenMap.remove(); this.fullscreenMap = null; }
                    },

                    addPins(map, pins) {
                        const grouped = {};
                        pins.forEach(p => {
                            const key = p.lat + ',' + p.lng;
                            if (!grouped[key]) grouped[key] = { lat: p.lat, lng: p.lng, name: p.name, visits: [] };
                            grouped[key].visits.push(p.time);
                        });
                        const locations = Object.values(grouped);
                        locations.forEach(loc => {
                            let popup = '<b style=\'font-size:15px; display:block; margin-bottom:4px;\'>' + loc.name + '</b>';
                            loc.visits.forEach(v => {
                                popup += '<span style=\'font-size:13px; color:#555; display:block;\'>' + v + '</span>';
                            });
                            L.marker([loc.lat, loc.lng]).addTo(map).bindPopup(popup, { minWidth: 140 });
                        });
                        const bounds = locations.map(l => [l.lat, l.lng]);
                        if (bounds.length > 1) map.fitBounds(L.latLngBounds(bounds), { padding: [20, 20] });
                        else if (bounds.length === 1) map.setView(bounds[0], 14);
                        else map.setView([14.5995, 120.9842], 11);
                    }
                }"
                x-init="$nextTick(() => initMap())"
            >


                {{-- Fullscreen Overlay — teleported to body to escape card overflow & stacking context --}}
                <template x-teleport="body">
                    <div
                        x-cloak
                        x-show="showFullscreen"
                        x-transition.opacity
                        class="fixed inset-0 z-[9999] bg-white flex flex-col"
                        style="top: 80px;">

                        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0 bg-white">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined mat-fill text-[#890f00]">map</span>
                                <h3 class="font-bold text-[#191c1e]">Today's Map</h3>
                                <span class="text-xs text-gray-400">{{ $todayCalls->filter(fn($c) => $c->customer?->latitude)->count() }} stops</span>
                            </div>
                            <button @click="closeFullscreen()"
                                class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center hover:bg-gray-200 transition-colors">
                                <span class="material-symbols-outlined text-gray-600 text-lg">close</span>
                            </button>
                        </div>

                        <div wire:ignore id="dashboard-map-fullscreen" class="flex-1"></div>
                    </div>
                </template>


                {{-- Card Header --}}
                <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900">Today's Map</h3>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-400">
                            {{ $todayCalls->filter(fn($c) => $c->customer?->latitude)->count() }} stops mapped
                        </span>
                        <button @click="openFullscreen()"
                            class="text-gray-400 hover:text-[#890f00] transition-colors"
                            title="View fullscreen map">
                            <span class="material-symbols-outlined">fullscreen</span>
                        </button>
                    </div>
                </div>

                {{-- Inline Map — hidden while fullscreen is open --}}
                <div x-show="!showFullscreen" style="position: relative; z-index: 0; overflow: hidden;">
                    <div wire:ignore id="dashboard-map" style="height: 256px;"></div>
                </div>

                <div class="p-4 bg-gray-50 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined mat-fill text-[#890f00] text-lg">near_me</span>
                        <span class="text-xs font-bold text-gray-700">Today, {{ now()->format('M j, Y') }}</span>
                    </div>
                    <span class="text-xs text-gray-400">{{ $todayCalls->count() }} total calls</span>
                </div>

            </div>


            {{-- Quick Actions --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-5 py-5 border-b border-gray-100">
                    <h3 class="text-lg font-bold text-gray-900">Quick Actions</h3>
                </div>
                <div class="p-5 space-y-3">
                    @foreach([
                        ['playlist_add', 'New Itinerary',  'Plan future store visits'],
                        ['add_call',     'New Sales Call', 'Log an ad-hoc customer interaction'],
                        ['login',        'Start Visit',    'Check-in to current location'],
                        ['cloud_upload', 'Submit Forms',   'Upload pending reports and data'],
                    ] as [$icon, $label, $sub])
                    <button class="flex items-center gap-4 w-full h-16 px-5 rounded-xl border border-gray-100 hover:border-[#890f00] hover:bg-red-50 transition-all text-left group">
                        <div class="w-10 h-10 bg-gray-50 group-hover:bg-red-100 rounded-xl flex items-center justify-center shrink-0 transition-colors">
                            <span class="material-symbols-outlined text-[#890f00] text-lg">{{ $icon }}</span>
                        </div>
                        <div>
                            <p class="font-bold text-sm text-gray-900">{{ $label }}</p>
                            <p class="text-xs text-gray-500">{{ $sub }}</p>
                        </div>
                    </button>
                    @endforeach
                </div>
            </div>

            

        </div>

    </div>

</div>
</x-filament-widgets::widget>
