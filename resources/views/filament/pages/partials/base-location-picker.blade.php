<div x-data="baseLocationPicker()" class="space-y-2">
    <div class="flex flex-wrap items-center gap-3">
        <button
            type="button"
            x-on:click="openPicker()"
            class="fi-btn fi-btn-size-md fi-btn-color-gray inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold shadow-sm ring-1 ring-inset ring-gray-950/10"
        >
            <span class="material-symbols-outlined text-lg">location_on</span>
            Pick on Map
        </button>
        <span class="text-xs text-[#737685]">Pin your route start and end points on the map. Green marks the start, red marks the end.</span>
    </div>

    {{-- BASE LOCATION MAP MODAL --}}
    <div
        x-cloak
        x-show="showPicker"
        x-transition.opacity
        x-on:click.self="closePicker()"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
    >
        <div
            class="flex w-full max-w-3xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
            style="height: 80vh; max-height: 80vh;"
        >
            <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-5 py-4">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined mat-fill text-[#890f00]">place</span>
                    <h3 class="font-bold text-[#191c1e]">Set Base Location</h3>
                </div>
                <button
                    type="button"
                    x-on:click="closePicker()"
                    class="flex h-8 w-8 items-center justify-center rounded-full bg-[#edeef0] transition-colors hover:bg-[#e7e8ea]"
                >
                    <span class="material-symbols-outlined text-lg text-[#434654]">close</span>
                </button>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2 px-5 pt-3">
                <span class="text-[10px] font-black text-[#737685] uppercase tracking-widest">Setting</span>
                <button
                    type="button"
                    x-on:click="setMode('start')"
                    :class="mode === 'start' ? 'fi-btn fi-btn-size-sm fi-btn-color-primary' : 'fi-btn fi-btn-size-sm fi-btn-color-gray'"
                    class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold"
                >
                    <span class="material-symbols-outlined text-base">trip_origin</span> Start
                </button>
                <button
                    type="button"
                    x-on:click="setMode('end')"
                    :class="mode === 'end' ? 'fi-btn fi-btn-size-sm fi-btn-color-primary' : 'fi-btn fi-btn-size-sm fi-btn-color-gray'"
                    class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold"
                >
                    <span class="material-symbols-outlined text-base">flag</span> End
                </button>
            </div>

            <div
                x-ref="pickerMap"
                wire:ignore
                id="base-location-map"
                class="mx-4 my-4 flex-1 overflow-hidden rounded-xl border border-gray-200"
                style="min-height: 20rem;"
                role="application"
                aria-label="Base location map"
            ></div>

            <div class="flex shrink-0 flex-col gap-3 border-t border-gray-100 px-5 py-4 md:flex-row md:items-center md:justify-between">
                <p class="text-xs text-[#737685] md:max-w-[55%]">
                    <span x-text="message"></span>
                </p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        x-on:click="useCurrentLocation()"
                        class="fi-btn fi-btn-size-md fi-btn-color-gray inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold shadow-sm ring-1 ring-inset ring-gray-950/10"
                    >
                        <span class="material-symbols-outlined text-lg">my_location</span>
                        Use Current Location
                    </button>
                    <button
                        type="button"
                        x-on:click="confirmLocation()"
                        class="fi-btn fi-btn-size-md fi-btn-color-primary inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold"
                    >
                        Use This Location
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@once
    <script>
        window.baseLocationPicker = function () {
            return {
                showPicker: false,
                map: null,
                startMarker: null,
                endMarker: null,
                mode: 'start',
                message: '',
                neutralCenter: [14.5995, 120.9842],
                neutralZoom: 10,
                locationZoom: 16,

                coordinateInput(name) {
                    const scope = this.$root.closest('form') ?? this.$root;

                    return scope.querySelector('[data-base-coordinate="' + name + '"]');
                },

                inputNumber(name) {
                    const value = Number.parseFloat(this.coordinateInput(name)?.value ?? '');

                    return Number.isFinite(value) ? value : null;
                },

                coordinates(name) {
                    const latitude = this.inputNumber(name + '_latitude');
                    const longitude = this.inputNumber(name + '_longitude');

                    if (latitude === null || longitude === null
                        || latitude < -90 || latitude > 90
                        || longitude < -180 || longitude > 180) {
                        return null;
                    }

                    return [latitude, longitude];
                },

                openPicker() {
                    this.showPicker = true;
                    this.message = '';

                    // x-show only toggles display, so wait a paint before Leaflet
                    // measures the container (see the customer picker note).
                    this.$nextTick(() => setTimeout(() => this.buildMap(), 150));
                },

                closePicker() {
                    this.showPicker = false;
                    this.message = '';

                    if (this.map) {
                        this.map.remove();
                        this.map = null;
                        this.startMarker = null;
                        this.endMarker = null;
                    }
                },

                confirmLocation() {
                    this.closePicker();
                },

                buildMap() {
                    const el = this.$refs.pickerMap;

                    if (! el || ! window.L) {
                        this.message = 'The map is unavailable. Enter the coordinates directly.';
                        return;
                    }

                    if (this.map) {
                        this.map.remove();
                        this.map = null;
                        this.startMarker = null;
                        this.endMarker = null;
                    }

                    const start = this.coordinates('base_start');
                    const end = this.coordinates('base_end');
                    const initial = start ?? end;

                    this.map = L.map(el).setView(
                        initial ?? this.neutralCenter,
                        initial ? this.locationZoom : this.neutralZoom,
                    );

                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                        maxZoom: 18,
                    }).addTo(this.map);

                    if (start) {
                        this.placeMarker('start', start);
                    }

                    if (end) {
                        this.placeMarker('end', end);
                    }

                    this.map.on('click', (event) => {
                        this.setPoint(this.mode, event.latlng.lat, event.latlng.lng);
                    });

                    setTimeout(() => this.map?.invalidateSize(), 0);
                },

                markerFor(name) {
                    return name === 'start' ? this.startMarker : this.endMarker;
                },

                setMarker(name, marker) {
                    if (name === 'start') {
                        this.startMarker = marker;
                    } else {
                        this.endMarker = marker;
                    }
                },

                pinIcon(color) {
                    return L.divIcon({
                        className: 'base-location-pin',
                        html: '<span class="material-symbols-outlined" style="color:' + color + ';font-size:34px;line-height:1;text-shadow:0 1px 2px rgba(0,0,0,.35)">location_on</span>',
                        iconSize: [34, 34],
                        iconAnchor: [17, 34],
                    });
                },

                placeMarker(name, coordinates) {
                    const existing = this.markerFor(name);

                    if (existing) {
                        existing.setLatLng(coordinates);
                        return;
                    }

                    const marker = L.marker(coordinates, {
                        draggable: true,
                        icon: this.pinIcon(name === 'start' ? '#16a34a' : '#890f00'),
                    }).addTo(this.map);

                    marker.on('dragend', (event) => {
                        const position = event.target.getLatLng();
                        this.setCoordinates(name, position.lat, position.lng);
                    });

                    this.setMarker(name, marker);
                },

                setMode(mode) {
                    this.mode = mode;
                },

                setPoint(name, latitude, longitude) {
                    this.setCoordinates(name, latitude, longitude);
                    this.placeMarker(name, [latitude, longitude]);
                },

                setCoordinates(name, latitude, longitude) {
                    this.setInput(this.coordinateInput(name + '_latitude'), Number(latitude).toFixed(7));
                    this.setInput(this.coordinateInput(name + '_longitude'), Number(longitude).toFixed(7));
                },

                setInput(input, value) {
                    if (! input) {
                        return;
                    }

                    input.value = value;

                    const statePath = input.getAttribute('wire:model')
                        ?? input.getAttribute('wire:model.live')
                        ?? input.getAttribute('wire:model.blur');

                    if (statePath && this.$wire?.set) {
                        this.$wire.set(statePath, value, false);
                    }

                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                },

                useCurrentLocation() {
                    if (! navigator.geolocation) {
                        this.message = 'Current location is unavailable in this browser.';
                        return;
                    }

                    this.message = 'Requesting your current location…';

                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            const { latitude, longitude } = position.coords;
                            this.setPoint(this.mode, latitude, longitude);
                            this.map?.setView([latitude, longitude], this.locationZoom);
                            this.message = '';
                        },
                        () => {
                            this.message = 'Unable to obtain your current location. Existing coordinates were kept.';
                        },
                        { enableHighAccuracy: true, maximumAge: 0, timeout: 10000 },
                    );
                },
            };
        };
    </script>
@endonce
