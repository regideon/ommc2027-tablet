<x-filament-panels::page>

<div
    x-data="{
        showMap: false,
        leafletMap: null,
        openMap() {
            this.showMap = true;
            this.$nextTick(() => {
                if (this.leafletMap) { this.leafletMap.remove(); this.leafletMap = null; }
                const map = L.map('salescall-map');
                // Street:    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
                // Satellite: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'
                // Light:     'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png'
                // Dark:      'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
                L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                    attribution: '&copy; OpenStreetMap &copy; CartoDB', maxZoom: 18
                }).addTo(map);
                const valid = this.filteredCalls.filter(c => c.lat && c.lng);
                const grouped = {};
                valid.forEach(c => {
                    const key = c.lat + ',' + c.lng;
                    if (!grouped[key]) grouped[key] = { lat: c.lat, lng: c.lng, name: c.name, visits: [] };
                    grouped[key].visits.push(c.date_label + ' · ' + c.time);
                });
                const locations = Object.values(grouped);
                locations.forEach(loc => {
                    let popup = '<b style=\'font-size:18px; display:block; margin-bottom:4px;\'>' + loc.name + '</b>';
                    loc.visits.forEach(v => { 
                        popup += '<br><span style=\'font-size:15px; display:block; line-height:0.7; color:#555;\'>' + v + '</span>'; 
                    });
                    L.marker([loc.lat, loc.lng]).addTo(map).bindPopup(popup, { minWidth: 160 });
                });
                const bounds = locations.map(loc => [loc.lat, loc.lng]);
                if (bounds.length > 1) {
                    map.fitBounds(L.latLngBounds(bounds), { padding: [30, 30] });
                } else if (bounds.length === 1) {
                    map.setView(bounds[0], 14);
                } else {
                    map.setView([14.5995, 120.9842], 10);
                }
                this.leafletMap = map;
            });
        },
        closeMap() {
            this.showMap = false;
            if (this.leafletMap) { this.leafletMap.remove(); this.leafletMap = null; }
        },


        filter: 'today',
        tab: 'overview',
        
        selected: {{ $preselectedId ?? $firstId ?? 'null' }},
        showDetail: {{ $preselectedId ? 'true' : 'false' }},

        maximized: false,
        isMobile: window.innerWidth < 1024,
        checkedIn: false,
        submitting: false,
        syncStatus: null,
        pulling: false,
        isOnline: true,
        showBackOnline: false,
        showLegend: false,
        async checkConnectivity() {
            const wasOnline = this.isOnline;
            try {
                await fetch('{{ config("services.sync.url") }}/api/ping', { method: 'GET', cache: 'no-store', signal: AbortSignal.timeout(3000) });
                this.isOnline = true;
                if (!wasOnline) {
                    this.showBackOnline = true;
                    setTimeout(() => this.showBackOnline = false, 10000);
                }
            } catch {
                this.isOnline = false;
                this.showBackOnline = false;
            }
        },

        syncIconClass(s) {
            return {
                synced:  'text-green-500',
                pending: 'text-red-400',
                failed:  'text-amber-500',
            }[s] ?? 'text-gray-400';
        },
        syncIcon(s) {
            return { synced: 'cloud_done', pending: 'cloud_upload', failed: 'cloud_off' }[s] ?? 'cloud_upload';
        },
        syncIconTitle(s) {
            return {
                synced:  'Synced — data uploaded to server',
                pending: 'Pending — waiting to sync',
                failed:  'Sync failed — will retry on next sync',
            }[s] ?? 'Unknown sync status';
        },

        async startSync() {
            await this.checkConnectivity();
            if (!this.isOnline) return;
            this.syncStatus = 'syncing';
            $wire.syncNow();
        },
        pullStatus: null,
        async startPull() {
            await this.checkConnectivity();
            if (!this.isOnline) return;
            this.pulling = true;
            await $wire.pullNow();
        },

        submitForm: { collection_amount: '', remarks: '', concerns: '', material_group_id: null, brand_id: null, brand_other: '' },

        calls: {{ $callsJson }},

        materialGroups: {{ $materialGroupsJson }},
        brands: {{ $brandsJson }},
        get filteredBrands() {
            if (!this.submitForm.material_group_id) return [];
            return this.brands.filter(b => b.material_group_id == this.submitForm.material_group_id);
        },

        get filteredCalls() {
            if (this.filter === 'today') return this.calls.filter(c => c.filter_group === 'today');
            if (this.filter === 'week')  return this.calls.filter(c => ['today','week'].includes(c.filter_group));
            return this.calls;
        },

        get syncButtonClass() {
            if (this.calls.some(c => c.sync_status === 'pending' || c.sync_status === 'failed')) {
                return 'text-red-500 hover:text-red-600';
            }
            if (this.calls.length > 0 && this.calls.every(c => c.sync_status === 'synced')) {
                return 'text-green-500 hover:text-green-600';
            }
            return 'text-[#434654] hover:text-[#890f00]';
        },
        get syncButtonIcon() {
            if (this.calls.some(c => c.sync_status === 'pending' || c.sync_status === 'failed')) {
                return 'cloud_off';
            }
            if (this.calls.length > 0 && this.calls.every(c => c.sync_status === 'synced')) {
                return 'cloud_done';
            }
            return 'sync';
        },
        get syncButtonTitle() {
            if (this.calls.some(c => c.sync_status === 'pending' || c.sync_status === 'failed')) {
                return 'Pending data — tap to sync to server';
            }
            if (this.calls.length > 0 && this.calls.every(c => c.sync_status === 'synced')) {
                return 'All data synced';
            }
            return 'Sync data to server';
        },

        get selectedCall() { return this.calls.find(c => c.id === this.selected); },
        selectCall(id) {
            this.selected = id;
            this.tab = 'overview';
            this.submitting = false;
            this.submitForm = { collection_amount: '', remarks: '', concerns: '' };
            const call = this.calls.find(c => c.id === id);
            this.checkedIn = call ? call.status !== 'scheduled' : false;
            this.showDetail = true;
        },
        doCheckIn() {
            $wire.initiateCheckIn(this.selected);
            const call = this.calls.find(c => c.id === this.selected);
            if (call) { call.status = 'in_progress'; call.sync_status = 'pending'; }
            this.checkedIn = true;
        },
        _persistCheckIn(lat, lng) {
            $wire.checkIn(this.selected, lat, lng, this.isOnline);
            const call = this.calls.find(c => c.id === this.selected);
            if (call) { call.status = 'in_progress'; call.sync_status = 'pending'; }
            this.checkedIn = true;
        },

        doSubmit() {
            const id = this.selected;
            const data = {
                collection:      this.submitForm.collection_amount !== '' ? (parseFloat(this.submitForm.collection_amount) || 0) : null,
                remarks:         this.submitForm.remarks || null,
                concerns:        this.submitForm.concerns || null,
                materialGroupId: this.submitForm.material_group_id || null,
                brandId:         this.submitForm.brand_id || null,
                brandOther:      this.submitForm.brand_other || null,
            };

            const call = this.calls.find(c => c.id === id);
            if (call) { call.status = 'completed'; call.sync_status = 'pending'; }
            this.submitting = false;
            this.submitForm = { collection_amount: '', remarks: '', concerns: '', material_group_id: null, brand_id: null, brand_other: '' };

            $wire.initiateSubmit(id, data.collection, data.remarks, data.concerns, data.materialGroupId, data.brandId, data.brandOther, this.isOnline);
        },


        statusLabel(s) {
            return { in_progress: 'In Progress', scheduled: 'Scheduled', completed: 'Completed' }[s] ?? s;
        },
        statusBadgeClass(s) {
            return {
                in_progress: 'bg-amber-100 text-amber-700',
                scheduled:   'bg-blue-100 text-blue-700',
                completed:   'bg-green-100 text-green-700',
            }[s] ?? '';
        },
        seqBgClass(s) {
            return {
                in_progress: 'bg-primary-fixed text-primary',
                scheduled:   'bg-surface-high text-on-surface-var',
                completed:   'bg-secondary-cont text-on-secondary-cont',
            }[s] ?? '';
        },
        tabLabel(t) {
            return { overview: 'Overview', ccr: 'CCR', mrf: 'MRF', photos: 'Photos', activity: 'Activity Log' }[t] ?? t;
        }
    }"
    
    x-init="
        checkedIn = selectedCall ? selectedCall.status !== 'scheduled' : false;
        checkConnectivity();
        window.addEventListener('resize', () => { isMobile = window.innerWidth < 1024; });

        document.addEventListener('focusin', (e) => {
            const tag = e.target.tagName;
            if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') return;
            document.querySelectorAll('.keyboard-scroll').forEach(el => {
                el.style.paddingBottom = '350px';
            });
            setTimeout(() => e.target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 350);
        });

        document.addEventListener('focusout', (e) => {
            const tag = e.target.tagName;
            if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') return;
            setTimeout(() => {
                const activeTag = document.activeElement ? document.activeElement.tagName : '';
                if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(activeTag)) {
                    document.querySelectorAll('.keyboard-scroll').forEach(el => {
                        el.style.paddingBottom = '';
                    });
                }
            }, 200);
        });
    "





    @pull-done.window="
        pulling = false;
        pullStatus = 'success';
        setTimeout(() => pullStatus = null, 3000)
    "

    @pull-failed.window="
        pulling = false;
        pullStatus = 'failed';
        setTimeout(() => pullStatus = null, 3000)
    "

    @online.window="isOnline = true"
    @offline.window="isOnline = false"

    x-on:use-browser-geolocation.window="
        const id = $event.detail.salescallId;
        if (!navigator.geolocation) { $wire.checkIn(id, 0, 0, isOnline); return; }
        navigator.geolocation.getCurrentPosition(
            (pos) => $wire.checkIn(id, pos.coords.latitude, pos.coords.longitude, isOnline),
            (err) => { console.warn('GPS error:', err.code, err.message); $wire.checkIn(id, 0, 0, isOnline); },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        )
    "

    x-on:get-submit-location.window="
        const { salescallId, collectionAmount, remarks, concerns, materialGroupId, brandId, brandOther, isOnline: online } = $event.detail;
        const submit = (lat, lng) => $wire.submitSalesCall(salescallId, collectionAmount, remarks, concerns, materialGroupId, brandId, brandOther, lat, lng, online);
        if (!navigator.geolocation) { submit(0, 0); return; }
        navigator.geolocation.getCurrentPosition(
            (pos) => submit(pos.coords.latitude, pos.coords.longitude),
            (err) => {
                console.warn('GPS submit error:', err.code, err.message);
                if (err.code === 1) { submit(14.5995, 120.9842); } else { submit(0, 0); }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        )
    "


    class="flex flex-col bg-gray-50"
    style="height: calc(100dvh - 5rem); overflow: hidden;"
>

    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-family: 'Material Symbols Outlined';
            font-style: normal;
            line-height: 1;
            display: inline-block;
            vertical-align: middle;
        }
        .mat-fill { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        .fi-page-content { padding: 0 !important; }
    </style>

    {{-- SYNC MODAL --}}
    <div
        x-cloak
        x-show="syncStatus !== null"
        x-transition.opacity
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center">
        <div x-transition.scale class="bg-white rounded-3xl px-10 py-10 shadow-2xl flex flex-col items-center gap-5 w-72">
            <div x-show="syncStatus === 'syncing'" class="flex flex-col items-center gap-4">
                <div class="w-16 h-16 border-4 border-[#890f00] border-t-transparent rounded-full animate-spin"></div>
                <div class="text-center">
                    <p class="font-black text-xl text-[#191c1e]">Syncing...</p>
                    <p class="text-sm text-[#737685] mt-1">Uploading your sales calls</p>
                </div>
            </div>
            <div x-show="syncStatus === 'success'" class="flex flex-col items-center gap-4">
                <span class="material-symbols-outlined mat-fill text-green-500" style="font-size: 64px;">check_circle</span>
                <div class="text-center">
                    <p class="font-black text-xl text-[#191c1e]">All Synced!</p>
                    <p class="text-sm text-[#737685] mt-1">Data uploaded successfully</p>
                </div>
            </div>
        </div>
    </div>

    {{-- LEGENDS MODAL --}}
    <div
        x-cloak
        x-show="showLegend"
        x-transition.opacity
        @click.self="showLegend = false"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end lg:items-center justify-center">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-4 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            class="bg-white rounded-t-3xl lg:rounded-3xl w-full lg:max-w-md shadow-2xl overflow-hidden">

            {{-- Modal Header --}}
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined mat-fill text-[#890f00] text-2xl">info</span>
                    <h2 class="text-lg font-extrabold text-[#191c1e]">Icon Legend</h2>
                </div>
                <button @click="showLegend = false"
                    class="w-8 h-8 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors">
                    <span class="material-symbols-outlined text-[#434654] text-lg">close</span>
                </button>
            </div>

            <div class="overflow-y-auto max-h-[70vh] scrollbar-hide p-6 space-y-6">

                {{-- Visit Status --}}
                <div>
                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-3">Visit Status</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-blue-100 text-blue-700 shrink-0 w-24 text-center">Scheduled</span>
                            <p class="text-sm text-[#434654]">Visit is planned but not yet started</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-amber-100 text-amber-700 shrink-0 w-24 text-center">In Progress</span>
                            <p class="text-sm text-[#434654]">Checked in — visit is currently ongoing</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-green-100 text-green-700 shrink-0 w-24 text-center">Completed</span>
                            <p class="text-sm text-[#434654]">Visit submitted and done</p>
                        </div>
                    </div>
                </div>

                {{-- Sync Status --}}
                <div>
                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-3">Sync Status</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-green-500 text-xl shrink-0 w-6 text-center">cloud_done</span>
                            <p class="text-sm text-[#434654]">Synced — data uploaded to server</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-red-400 text-xl shrink-0 w-6 text-center">cloud_upload</span>
                            <p class="text-sm text-[#434654]">Pending — saved locally, waiting to sync</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-amber-500 text-xl shrink-0 w-6 text-center">cloud_off</span>
                            <p class="text-sm text-[#434654]">Sync failed — will retry on next sync</p>
                        </div>
                    </div>
                </div>

                {{-- Action Icons --}}
                <div>
                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-3">Header Actions</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#434654] text-xl shrink-0 w-6 text-center">sync</span>
                            <p class="text-sm text-[#434654]">Push pending salescalls up to the server</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-[#434654] text-xl shrink-0 w-6 text-center">cloud_download</span>
                            <p class="text-sm text-[#434654]">Pull latest itinerary schedule from server</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-[#434654] text-xl shrink-0 w-6 text-center">open_in_full</span>
                            <p class="text-sm text-[#434654]">Expand detail panel to full width</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-[#434654] text-xl shrink-0 w-6 text-center">close_fullscreen</span>
                            <p class="text-sm text-[#434654]">Restore split view (list + detail)</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#434654] text-xl shrink-0 w-6 text-center">arrow_back</span>
                            <p class="text-sm text-[#434654]">Go back to salescall list</p>
                        </div>
                    </div>
                </div>

                {{-- Visit Actions --}}
                <div>
                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-3">Visit Actions</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-[#890f00] text-xl shrink-0 w-6 text-center">my_location</span>
                            <p class="text-sm text-[#434654]">Mark Arrival — records GPS arrival location</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#737685] text-xl shrink-0 w-6 text-center">assignment</span>
                            <p class="text-sm text-[#434654]">CCR — Customer Call Report form</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#737685] text-xl shrink-0 w-6 text-center">inventory</span>
                            <p class="text-sm text-[#434654]">MRF — Merchandising Report Form</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#737685] text-xl shrink-0 w-6 text-center">photo_camera</span>
                            <p class="text-sm text-[#434654]">Upload photos of the store display</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#737685] text-xl shrink-0 w-6 text-center">note_alt</span>
                            <p class="text-sm text-[#434654]">Add a quick note or instant feedback</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- MAP OVERLAY --}}
    <div
        x-cloak
        x-show="showMap"
        x-transition.opacity
        @click.self="closeMap()"
        class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">

        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="scale-95 opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            class="bg-white rounded-3xl overflow-hidden shadow-2xl w-full"
            style="height: 80vh; width: 90vw; max-width: 90vw;">

            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined mat-fill text-[#890f00]">map</span>
                    <h3 class="font-bold text-[#191c1e]"
                        x-text="filter === 'today' ? 'Today\'s Calls' : filter === 'week' ? 'This Week\'s Calls' : 'This Month\'s Calls'"></h3>
                    <span class="text-xs text-[#737685]" x-text="'(' + filteredCalls.filter(c => c.lat && c.lng).length + ' locations)'"></span>
                </div>
                <button @click="closeMap()"
                    class="w-8 h-8 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors">
                    <span class="material-symbols-outlined text-[#434654] text-lg">close</span>
                </button>
            </div>
            <div id="salescall-map" style="height: calc(80vh - 61px);"></div>
        </div>
    </div>



    {{-- TOP APP BAR --}}
    <header class="w-full bg-white/90 backdrop-blur-md border-b border-gray-100 shrink-0 px-4 lg:px-6">

        {{-- Mobile header --}}
        <div x-show="isMobile" class="flex items-center justify-between h-14">
            <button
                x-show="showDetail"
                @click="showDetail = false"
                title="Back to salescall list"
                class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center mr-2 shrink-0">
                <span class="material-symbols-outlined text-[#434654] text-xl">arrow_back</span>
            </button>
            <div x-show="!showDetail" class="shrink-0">
                <h1 class="text-lg font-bold text-on-surface">Sales Calls</h1>
            </div>
            <div x-show="showDetail" class="flex-1 min-w-0">
                <p class="text-xs font-black text-[#890f00] uppercase tracking-wider truncate"
                   x-text="'Seq #' + (selectedCall?.seq ?? '')"></p>
                <h1 class="text-base font-bold text-[#191c1e] leading-tight truncate" x-text="selectedCall?.name"></h1>
            </div>
            <button
                @click="startSync()"
                :title="syncButtonTitle"
                :class="syncButtonClass"
                class="material-symbols-outlined mat-fill transition-colors ml-2 shrink-0"
                x-text="syncButtonIcon">
            </button>
            <button
                @click="startPull()"
                title="Pull latest schedule from server"
                class="material-symbols-outlined mat-fill transition-colors ml-1 shrink-0"
                :class="pulling ? 'text-blue-500 animate-spin' : 'text-[#434654] hover:text-[#890f00]'">
                cloud_download
            </button>
        </div>

        {{-- Mobile search + legend (list view only) --}}
        <div x-show="isMobile && !showDetail" class="pb-3">
            <div class="flex items-center gap-2">
                <div class="flex items-center bg-[#edeef0] rounded-full px-4 py-2 flex-1 gap-2">
                    <span class="material-symbols-outlined text-[#737685] text-lg">search</span>
                    <input class="bg-transparent border-none focus:ring-0 text-sm w-full text-[#191c1e]" placeholder="Search salescalls..." type="text"/>
                </div>
                <button
                    @click="showLegend = true"
                    title="Icon legend & reference guide"
                    class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors shrink-0">
                    <span class="material-symbols-outlined text-[#155dfc] text-xl">info</span>
                </button>
            </div>
        </div>

        {{-- Tablet/Desktop header --}}
        <div x-show="!isMobile" class="flex items-center justify-between h-16">
            <div>
                <h1 class="text-2xl font-bold text-on-surface">Sales Calls</h1>
                <p class="text-xs text-[#434654] mt-0.5">All scheduled visits from your itineraries</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center bg-[#edeef0] rounded-full px-4 py-2 w-56 gap-2">
                    <span class="material-symbols-outlined text-[#737685] text-lg">search</span>
                    <input class="bg-transparent border-none focus:ring-0 text-sm w-full text-[#191c1e]" placeholder="Search salescalls..." type="text"/>
                </div>
                <button
                    @click="showLegend = true"
                    title="Icon legend & reference guide"
                    class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors shrink-0">
                    <span class="material-symbols-outlined text-[#434654] text-xl">info</span>
                </button>
                <button
                    @click="startSync()"
                    :class="syncButtonClass"
                    :title="syncButtonTitle"
                    class="material-symbols-outlined mat-fill transition-colors"
                    x-text="syncButtonIcon">
                </button>
                <button
                    @click="startPull()"
                    title="Pull latest schedule from server"
                    class="material-symbols-outlined mat-fill transition-colors"
                    :class="pulling ? 'text-blue-500 animate-spin' : 'text-[#434654] hover:text-[#890f00]'">
                    cloud_download
                </button>
            </div>
        </div>

    </header>

    {{-- OFFLINE BANNER --}}
    <div
        x-show="!isOnline"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">wifi_off</span>
        <div>
            <p class="font-bold text-sm leading-tight">You're offline</p>
            <p class="text-xs opacity-80">Changes are saved locally and will sync when you reconnect.</p>
        </div>
    </div>

    {{-- BACK ONLINE BANNER --}}
    <div
        x-show="showBackOnline"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">wifi</span>
        <div>
            <p class="font-bold text-sm leading-tight">You're back online</p>
            <p class="text-xs opacity-80">Your pending data will sync on your next action.</p>
        </div>
    </div>

    {{-- PULL SUCCESS BANNER --}}
    <div
        x-show="pullStatus === 'success'"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">cloud_done</span>
        <p class="font-bold text-sm">Data updated successfully.</p>
    </div>

    {{-- PULL FAILED BANNER --}}
    <div
        x-show="pullStatus === 'failed'"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">cloud_off</span>
        <p class="font-bold text-sm">Pull failed. Please try again.</p>
    </div>



    {{-- SPLIT VIEW --}}
    <div class="flex flex-1 overflow-hidden px-4 lg:px-6 pb-4 lg:pb-6 gap-5 mt-3">

        {{--
            LEFT PANEL
            Mobile:  visible when !showDetail (full list view), hidden when showDetail
            Tablet+: visible when !maximized (split), hidden when maximized
        --}}
        <div
            x-show="!(isMobile && showDetail) && !(!isMobile && maximized)"
            :class="isMobile ? 'w-full' : 'w-2/5'"
            class="flex flex-col gap-3 overflow-hidden">

            {{-- Filter Pills --}}
            <div class="flex items-center gap-2 pb-1 shrink-0">
                <div class="flex items-center gap-2 overflow-x-auto scrollbar-hide flex-1">
                    <button @click="filter = 'today'; if (showMap) openMap()"
                        :class="filter === 'today' ? 'bg-[#890f00] text-white' : 'bg-white border border-gray-200 text-[#434654]'"
                        class="whitespace-nowrap px-4 py-1.5 rounded-full text-xs font-semibold transition-all">Today</button>
                    <button @click="filter = 'week'; if (showMap) openMap()"
                        :class="filter === 'week' ? 'bg-[#890f00] text-white' : 'bg-white border border-gray-200 text-[#434654]'"
                        class="whitespace-nowrap px-4 py-1.5 rounded-full text-xs font-semibold transition-all">This Week</button>
                    <button @click="filter = 'month'; if (showMap) openMap()"
                        :class="filter === 'month' ? 'bg-[#890f00] text-white' : 'bg-white border border-gray-200 text-[#434654]'"
                        class="whitespace-nowrap px-4 py-1.5 rounded-full text-xs font-semibold transition-all">This Month</button>
                </div>
                <button @click="openMap()"
                    class="shrink-0 flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-600 text-white hover:bg-blue-700 transition-all">
                    <span class="material-symbols-outlined text-white" style="font-size:14px;">map</span>
                    Map
                </button>

            </div>



            {{-- Call List --}}
            <div class="flex-1 overflow-y-auto space-y-3 pr-1 scrollbar-hide">

                <div class="sticky top-0 bg-gray-50/90 backdrop-blur py-1.5 shrink-0">
                    <h3 class="text-[11px] font-extrabold text-[#737685] tracking-widest uppercase"
                        x-text="filter === 'today' ? 'Today, {{ now()->format('M j') }}' :
                                filter === 'week'  ? 'This Week' : 'This Month'">
                    </h3>
                </div>

                <template x-for="call in filteredCalls" :key="call.id">
                    <div
                        @click="selectCall(call.id)"
                        :class="selected === call.id
                            ? 'border-2 border-[#890f00] shadow-md'
                            : 'border border-gray-100 shadow-sm hover:bg-gray-50'"
                        class="bg-white rounded-2xl p-4 lg:p-5 flex items-center justify-between cursor-pointer transition-all active:scale-[0.98]">
                        <div class="flex items-center gap-3 min-w-0">
                            <div
                                class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm shrink-0"
                                :class="seqBgClass(call.status)"
                                x-text="call.seq">
                            </div>
                            <div class="min-w-0">
                                <h4 class="font-semibold text-sm text-[#191c1e] leading-tight truncate" x-text="call.name"></h4>
                                <p class="text-[11px] text-[#737685] mt-0.5 truncate"
                                   x-text="filter === 'today'
                                       ? call.location + ' • ' + call.time
                                       : call.date_label + ' • ' + call.time">
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1.5 shrink-0 ml-2">
                            <span
                                class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase whitespace-nowrap"
                                :class="statusBadgeClass(call.status)"
                                x-text="statusLabel(call.status)">
                            </span>
                            <span
                                class="material-symbols-outlined text-base"
                                :class="call.status === 'completed' ? 'text-green-600' : 'text-[#737685]'"
                                :title="call.status === 'completed' ? 'Visit completed' : 'View details'"
                                x-text="call.status === 'completed' ? 'check_circle' : 'chevron_right'">
                            </span>
                            <span
                                class="material-symbols-outlined text-sm mat-fill"
                                :class="syncIconClass(call.sync_status)"
                                :title="syncIconTitle(call.sync_status)"
                                x-text="syncIcon(call.sync_status)">
                            </span>
                        </div>
                    </div>
                </template>

            </div>
        </div>{{-- end left panel --}}


        {{--
            RIGHT PANEL
            Mobile:  visible only when showDetail (full detail view)
            Tablet+: always visible, flex-1 (takes remaining space)
        --}}
        <div
            x-show="!isMobile || showDetail"
            :class="isMobile ? 'w-full' : 'flex-1'"
            class="flex flex-col bg-white rounded-[28px] shadow-xl border border-gray-100 overflow-hidden">

            {{-- ARRIVAL VIEW — before Check In --}}
            <div x-show="!checkedIn" x-transition class="flex-1 flex flex-col overflow-hidden">

                <div class="flex items-center gap-4 px-5 lg:px-7 py-5 border-b border-gray-100 shrink-0">
                    <div class="w-12 h-12 rounded-2xl bg-gray-50 border border-gray-100 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-gray-400 text-3xl" title="Customer store">storefront</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <span class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">
                            Sequence #<span x-text="selectedCall?.seq"></span>
                        </span>
                        <h2 class="text-xl lg:text-2xl font-extrabold text-[#191c1e] leading-tight truncate" x-text="selectedCall?.name"></h2>
                        <p class="text-sm text-[#737685] flex items-center gap-1">
                            <span class="material-symbols-outlined text-base shrink-0" title="Store address">location_on</span>
                            <span class="truncate" x-text="selectedCall?.location"></span>
                        </p>
                        <p class="text-[10px] text-gray-300 font-mono truncate mt-0.5" x-text="selectedCall?.local_uuid"></p>
                    </div>
                    {{-- Maximize toggle (tablet+ only) --}}
                    <button
                        x-show="!isMobile"
                        @click="maximized = !maximized"
                        class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors shrink-0"
                        :title="maximized ? 'Restore split view' : 'Expand detail view'">
                        <span class="material-symbols-outlined text-[#434654] text-xl"
                              x-text="maximized ? 'close_fullscreen' : 'open_in_full'"></span>
                    </button>
                </div>

                <div class="flex-1 flex flex-col items-center justify-center gap-6 px-8">
                    <button
                        @click="doCheckIn()"
                        title="Tap to record your GPS arrival location and start the visit"
                        class="flex flex-col items-center gap-3 bg-[#890f00] text-white w-48 lg:w-52 py-8 rounded-3xl shadow-xl hover:opacity-90 active:scale-95 transition-all">
                        <span class="material-symbols-outlined text-5xl mat-fill">my_location</span>
                        <div class="text-center">
                            <p class="font-black text-2xl leading-none tracking-wide">Mark Arrival</p>
                            <p class="text-xs opacity-75 mt-1">Capture GPS location</p>
                        </div>
                    </button>
                    <p class="text-xs text-[#737685] text-center max-w-xs leading-relaxed">
                        Tap <strong class="text-[#191c1e]">Mark Arrival</strong> when you arrive at the store to record your GPS location and start the visit.
                    </p>
                </div>

            </div>

            {{-- FULL DETAIL — after Check In --}}
            <div x-show="checkedIn" class="flex flex-col flex-1 overflow-hidden relative">

                {{-- SUBMIT FORM OVERLAY --}}
                <div
                    x-show="submitting"
                    x-transition
                    class="absolute inset-0 bg-white z-10 flex flex-col rounded-[28px] overflow-hidden">

                    <div class="flex items-center gap-4 px-5 lg:px-7 py-5 border-b border-gray-100 shrink-0">
                        <button
                            @click="submitting = false"
                            title="Cancel and go back"
                            class="w-10 h-10 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors shrink-0">
                            <span class="material-symbols-outlined text-[#434654]">arrow_back</span>
                        </button>
                        <div class="min-w-0">
                            <h2 class="text-lg lg:text-xl font-extrabold text-[#191c1e]">Process Sales Call</h2>
                            <p class="text-sm text-[#737685] truncate" x-text="selectedCall?.name"></p>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto p-5 lg:p-7 space-y-6 keyboard-scroll">
                        
                       {{-- Brand Observation --}}
                        <div class="pt-2 border-t border-gray-100">
                            <p class="text-xs font-black text-[#434654] uppercase tracking-wider mb-4">Brand Observation</p>

                            <div class="flex gap-3">
                                <div class="flex-1">
                                    <label class="block text-xs font-semibold text-[#737685] mb-2">Material Group</label>
                                    <select x-model="submitForm.material_group_id"
                                        @change="submitForm.brand_id = null; submitForm.brand_other = ''"
                                        class="w-full px-4 py-3.5 bg-[#f3f4f6] border-0 rounded-2xl text-[#191c1e] text-sm focus:ring-2 focus:ring-[#890f00] outline-none appearance-none">
                                        <option value="">— Select —</option>
                                        <template x-for="group in materialGroups" :key="group.id">
                                            <option :value="group.id" x-text="group.name"></option>
                                        </template>
                                    </select>
                                </div>

                                <div class="flex-1">
                                    <label class="block text-xs font-semibold text-[#737685] mb-2">Brand</label>
                                    <select x-model="submitForm.brand_id"
                                        @change="submitForm.brand_other = ''"
                                        :disabled="!submitForm.material_group_id"
                                        :class="!submitForm.material_group_id ? 'opacity-40 cursor-not-allowed' : ''"
                                        class="w-full px-4 py-3.5 bg-[#f3f4f6] border-0 rounded-2xl text-[#191c1e] text-sm focus:ring-2 focus:ring-[#890f00] outline-none appearance-none">
                                        <option value="">— Select —</option>
                                        <template x-for="brand in filteredBrands" :key="brand.id">
                                            <option :value="brand.id" x-text="brand.name"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>


                            <div class="mt-3" x-show="submitForm.brand_id && filteredBrands.find(b => b.id == submitForm.brand_id)?.name === 'Others'" x-transition>
                                <label class="block text-xs font-semibold text-[#737685] mb-2">Specify Brand</label>
                                <input type="text" x-model="submitForm.brand_other"
                                    placeholder="Enter brand name..."
                                    class="w-full px-4 py-3.5 bg-[#f3f4f6] border-0 rounded-2xl text-[#191c1e] text-sm focus:ring-2 focus:ring-[#890f00] outline-none" />
                            </div>
                        </div>







                        {{-- <div>
                            <label class="block text-xs font-black text-[#434654] uppercase tracking-wider mb-2">Collection Amount</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[#737685] font-bold text-sm">₱</span>
                                <input type="number" step="0.01" min="0" x-model="submitForm.collection_amount" placeholder="0.00"
                                    class="w-full pl-8 pr-4 py-3.5 bg-[#f3f4f6] border-0 rounded-2xl text-[#191c1e] font-semibold text-base focus:ring-2 focus:ring-[#890f00] outline-none" />
                            </div>
                            <p class="text-[11px] text-[#737685] mt-1.5 ml-1">Leave blank if no collection was made.</p>
                        </div> --}}
                        <div>
                            <label class="block text-xs font-black text-[#434654] uppercase tracking-wider mb-2">Remarks</label>
                            <textarea x-model="submitForm.remarks" placeholder="How did the visit go? Any observations?" rows="2"
                                class="w-full px-4 py-3.5 bg-[#f3f4f6] border-0 rounded-2xl text-[#191c1e] text-sm resize-none focus:ring-2 focus:ring-[#890f00] outline-none leading-relaxed">
                            </textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-black text-[#434654] uppercase tracking-wider mb-2">Concerns</label>
                            <textarea x-model="submitForm.concerns" placeholder="Any issues or concerns raised by the customer?" rows="2"
                                class="w-full px-4 py-3.5 bg-[#f3f4f6] border-0 rounded-2xl text-[#191c1e] text-sm resize-none focus:ring-2 focus:ring-[#890f00] outline-none leading-relaxed">
                            </textarea>
                        </div>
                    </div>

                    <div class="p-5 lg:p-6 border-t border-gray-100 bg-white flex gap-4 shrink-0">
                        <button @click="submitting = false"
                            class="flex-1 h-12 bg-[#edeef0] text-[#434654] rounded-2xl font-bold text-sm hover:bg-[#e7e8ea] transition-colors">
                            Cancel
                        </button>
                        <button @click="doSubmit()"
                            class="flex-2 h-12 bg-[#890f00] text-white rounded-2xl font-black text-base shadow-lg hover:opacity-95 active:scale-[0.98] transition-all px-8">
                            Confirm Submit
                        </button>
                    </div>

                </div>{{-- end overlay --}}

                {{-- Detail Header --}}
                <div class="px-5 lg:px-7 pt-5 pb-0 border-b border-gray-100 shrink-0">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center gap-3 lg:gap-4 min-w-0">
                            <div class="w-12 h-12 lg:w-14 lg:h-14 rounded-2xl overflow-hidden bg-gray-50 border border-gray-100 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-gray-400 text-3xl" title="Customer store">storefront</span>
                            </div>
                            <div class="min-w-0">
                                <span class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">
                                    Sequence #<span x-text="selectedCall?.seq"></span>
                                </span>
                                <h2 class="text-lg lg:text-2xl font-extrabold text-[#191c1e] leading-tight truncate" x-text="selectedCall?.name"></h2>
                                <p class="text-sm text-[#737685] truncate" x-text="selectedCall?.location"></p>
                                <p class="text-[10px] text-gray-300 font-mono truncate mt-0.5" x-text="selectedCall?.local_uuid"></p>

                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 ml-2">
                            <span
                                class="px-3 lg:px-5 py-2 rounded-2xl text-xs font-black uppercase shadow-sm"
                                :class="statusBadgeClass(selectedCall?.status)"
                                x-text="statusLabel(selectedCall?.status)">
                            </span>
                            {{-- Maximize toggle (tablet+ only) --}}
                            <button
                                x-show="!isMobile"
                                @click="maximized = !maximized"
                                class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors"
                                :title="maximized ? 'Restore split view' : 'Expand detail view'">
                                <span class="material-symbols-outlined text-[#434654] text-xl"
                                        x-text="maximized ? 'close_fullscreen' : 'open_in_full'"></span>
                            </button>
                        </div>
                    </div>

                    {{-- Tabs --}}
                    <div class="flex gap-4 lg:gap-6 border-b border-gray-100 overflow-x-auto scrollbar-hide">
                        <template x-for="t in ['overview','ccr','mrf','photos','activity']">
                            <button
                                @click="tab = t"
                                :class="tab === t
                                    ? 'border-b-[3px] border-[#890f00] text-[#191c1e] font-bold'
                                    : 'border-b-[3px] border-transparent text-[#737685] font-medium hover:text-[#191c1e]'"
                                class="pb-3 text-sm transition-all whitespace-nowrap"
                                x-text="tabLabel(t)">
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Scrollable Content --}}
                <div class="flex-1 overflow-y-auto p-5 lg:p-7 space-y-6 keyboard-scroll">

                    {{-- OVERVIEW TAB --}}
                    <div x-show="tab === 'overview'" class="space-y-6">

                        <div class="grid grid-cols-2 gap-3 lg:gap-4">
                            <div class="p-4 lg:p-5 bg-[#f3f4f6] rounded-2xl flex items-center gap-3 lg:gap-4">
                                <span class="material-symbols-outlined text-3xl lg:text-4xl text-[#006c47] mat-fill shrink-0" title="GPS check-in verified">location_on</span>
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[#737685] uppercase font-bold tracking-wider">GPS Check-In</p>
                                    <p class="text-base lg:text-lg font-bold text-[#191c1e]">GPS Verified</p>
                                    <p class="text-xs text-[#006c47] font-bold">Within 20m of site</p>
                                </div>
                            </div>
                            <div class="p-4 lg:p-5 bg-[#f3f4f6] rounded-2xl flex items-center gap-3 lg:gap-4">
                                <span class="material-symbols-outlined text-3xl lg:text-4xl text-blue-600 shrink-0" title="Time spent on site">schedule</span>
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[#737685] uppercase font-bold tracking-wider">Time on Site</p>
                                    <p class="text-base lg:text-lg font-bold text-[#191c1e]">00:42:15</p>
                                    <p class="text-xs text-[#737685] font-medium">Started at 09:02 AM</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-base font-bold text-[#191c1e] mb-3">Visit Actions</h3>
                            <div class="grid grid-cols-2 gap-3">
                                @foreach([
                                    ['assignment','Fill CRCR','Customer Call Report'],
                                    ['inventory','Fill TSF','Merchandising Report'],
                                    ['photo_camera','Upload Photo','Store display audit'],
                                    ['note_alt','Add Quick Note','Capture instant feedback'],
                                ] as [$icon, $label, $sub])
                                <button
                                    title="{{ $label }} — {{ $sub }}"
                                    class="h-18 lg:h-20 bg-white border border-gray-200 rounded-2xl flex items-center px-4 lg:px-5 gap-3 lg:gap-4 hover:border-[#890f00] hover:bg-red-50 group transition-all">
                                    <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-full bg-[#edeef0] group-hover:bg-[#ffdad3] flex items-center justify-center shrink-0">
                                        <span class="material-symbols-outlined text-lg text-[#737685] group-hover:text-[#890f00]">{{ $icon }}</span>
                                    </div>
                                    <div class="text-left min-w-0">
                                        <p class="font-bold text-sm text-[#191c1e]">{{ $label }}</p>
                                        <p class="text-xs text-[#737685] truncate">{{ $sub }}</p>
                                    </div>
                                </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-2xl h-44 lg:h-52 bg-gray-100 overflow-hidden relative border border-gray-200 flex items-center justify-center">
                            <span class="material-symbols-outlined text-gray-400 text-5xl" title="Store location map">map</span>
                            <div class="absolute bottom-4 left-4 bg-white/90 backdrop-blur px-4 py-2 rounded-xl border border-gray-200 shadow-sm flex items-center gap-2">
                                <span class="material-symbols-outlined text-[#006c47] mat-fill text-lg" title="GPS location locked">my_location</span>
                                <span class="text-xs font-bold text-[#191c1e]">GPS Locked</span>
                            </div>
                        </div>

                    </div>

                    {{-- CCR TAB --}}
                    <div x-show="tab === 'ccr'" class="flex items-center justify-center h-40">
                        <p class="text-[#737685] text-sm">CCR form will appear here.</p>
                    </div>

                    {{-- MRF TAB --}}
                    <div x-show="tab === 'mrf'" class="flex items-center justify-center h-40">
                        <p class="text-[#737685] text-sm">MRF form will appear here.</p>
                    </div>

                    {{-- PHOTOS TAB --}}
                    <div x-show="tab === 'photos'" class="flex items-center justify-center h-40">
                        <p class="text-[#737685] text-sm">Photos will appear here.</p>
                    </div>

                    {{-- ACTIVITY TAB --}}
                    <div x-show="tab === 'activity'" class="flex items-center justify-center h-40">
                        <p class="text-[#737685] text-sm">Activity log will appear here.</p>
                    </div>

                </div>

                {{-- Sticky Footer --}}
                <div class="p-5 lg:p-6 border-t border-gray-100 bg-white shrink-0">
                    <div
                        x-show="selectedCall?.status === 'completed'"
                        class="flex items-center justify-center gap-2 h-12 bg-green-50 rounded-2xl border border-green-200">
                        <span class="material-symbols-outlined text-green-600 mat-fill" title="Visit completed and pending upload">check_circle</span>
                        <span class="font-bold text-sm text-green-700">Visit Completed — Pending Sync</span>
                    </div>
                    <button
                        x-show="selectedCall?.status !== 'completed'"
                        @click="submitting = true"
                        title="Submit this sales call and record GPS checkout location"
                        class="w-full h-12 bg-[#890f00] text-white rounded-2xl font-black text-base shadow-lg hover:opacity-95 active:scale-[0.98] transition-all">
                        Continue Process Sales Call
                    </button>
                </div>

            </div>

        </div>{{-- end right panel --}}

    </div>{{-- end split view --}}

</div>{{-- end x-data root --}}

</x-filament-panels::page>
