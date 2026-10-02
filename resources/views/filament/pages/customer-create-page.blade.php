<x-filament-panels::page>
    <form wire:submit="saveCustomer" x-data="customerLocationPicker()" class="space-y-5 pb-8">
        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold">Customer Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2"><label class="fi-fo-field-wrp-label">{{ match($this->profileType()) { 'fleet' => 'Fleet Account Name', 'oe' => 'OE Account Name', 'ib' => 'IB Account Name', default => 'Store Name' } }} *</label><x-filament::input wire:model="name" />@error('name')<p class="text-danger-600 text-sm">{{ $message }}</p>@enderror</div>
                <div><label class="fi-fo-field-wrp-label">Customer Code</label><x-filament::input wire:model="unique_id" readonly /></div>
                <div><label class="fi-fo-field-wrp-label">Company *</label><select wire:model.live="company_id" class="fi-input w-full"><option value="">Select company</option>@foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach</select></div>
                <div><label class="fi-fo-field-wrp-label">General Category</label><select wire:model="general_category_id" class="fi-input w-full"><option value="">Select category</option>@foreach($generalCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
                @if((int) $general_category_id === 1)<div><label class="fi-fo-field-wrp-label">Competitor Volume</label><select wire:model="competitor_volume" class="fi-input w-full"><option value="">Not specified</option><option value="1">High</option><option value="2">Medium</option><option value="3">Low</option></select></div>@endif
                <div><label class="fi-fo-field-wrp-label">Access</label><select wire:model="access_user_ids" multiple class="fi-input w-full h-24">@foreach($users as $user)<option value="{{ $user->id }}" @selected(in_array($user->id, $access_user_ids))>{{ $user->name }}</option>@endforeach</select></div>
                @if($this->profileType() === 'outlet')<div><label class="fi-fo-field-wrp-label">Person in Charge</label><select wire:model="person_in_charge_id" class="fi-input w-full"><option value="">Select person</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>@endif
                <div class="md:col-span-3"><label class="fi-fo-field-wrp-label">Address</label><textarea wire:model="address" data-location-address="address" class="fi-input w-full" rows="3"></textarea></div>
                <div><label class="fi-fo-field-wrp-label">Contact Person</label><x-filament::input wire:model="contact_person" /></div>
                <div><label class="fi-fo-field-wrp-label">Business Landline Number</label><x-filament::input wire:model="business_landline_number" /></div>
                <div><label class="fi-fo-field-wrp-label">Business Mobile Number</label><x-filament::input wire:model="business_mobile_number" /></div>
                <div><label class="fi-fo-field-wrp-label">Date Established</label><x-filament::input type="date" wire:model="date_established" /></div>
                <div class="flex items-center gap-2"><input type="checkbox" wire:model="is_active" class="rounded"><label>Active</label></div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold">Location</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div><label class="fi-fo-field-wrp-label">Region</label><select wire:model.live="physical_region_id" class="fi-input w-full"><option value="">Select region</option>@foreach($regions as $region)<option value="{{ $region->id }}">{{ $region->name }}</option>@endforeach</select></div>
                <div><label class="fi-fo-field-wrp-label">Specific Region</label><select wire:model="region_specific_id" class="fi-input w-full"><option value="">Select specific region</option>@foreach($regionSpecifics as $region)<option value="{{ $region->id }}">{{ $region->name }}</option>@endforeach</select></div>
                <div><label class="fi-fo-field-wrp-label">Province</label><select wire:model.live="province_id" class="fi-input w-full"><option value="">No province / independent locality</option>@foreach($provinces->where('region_id', $physical_region_id) as $province)<option value="{{ $province->id }}">{{ $province->name }}</option>@endforeach</select></div>
                <div><label class="fi-fo-field-wrp-label">City / Municipality</label><select wire:model="municipality_id" class="fi-input w-full"><option value="">Select municipality</option>@foreach($municipalities->where('region_id', $physical_region_id)->where('province_id', $province_id) as $municipality)<option value="{{ $municipality->id }}">{{ $municipality->name }}</option>@endforeach</select></div>
                <div><label class="fi-fo-field-wrp-label">Latitude</label><x-filament::input type="number" step="any" wire:model="latitude" data-location-coordinate="latitude" /></div>
                <div><label class="fi-fo-field-wrp-label">Longitude</label><x-filament::input type="number" step="any" wire:model="longitude" data-location-coordinate="longitude" /></div>
                <div class="md:col-span-3 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        x-on:click="openPicker()"
                        class="fi-btn fi-btn-size-md fi-btn-color-gray inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold shadow-sm ring-1 ring-inset ring-gray-950/10"
                    >
                        <span class="material-symbols-outlined text-lg">location_on</span>
                        Pick on Map
                    </button>
                    <span class="text-xs text-[#737685]">Pin the exact customer location on the map to fill latitude, longitude and address.</span>
                </div>
                <div><label class="fi-fo-field-wrp-label">Barangay</label><x-filament::input disabled placeholder="Unavailable" /></div>
                <div><label class="fi-fo-field-wrp-label">Area Cluster</label><x-filament::input disabled placeholder="Unavailable" /></div>
            </div>
        </div>

        @php($profile = $this->profileType())
        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold">{{ ucfirst($profile ?: 'Customer') }} Profile</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @if($profile === 'outlet' || $profile === 'oe')<div><label class="fi-fo-field-wrp-label">Entry Detail</label><select wire:model.live="trade.entry_detail" class="fi-input w-full"><option value="">Select entry detail</option>@foreach(config("customer_trade_form.profile_entry_details.{$profile}", []) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div>@endif
                @if($profile === 'outlet')
                    <div class="md:col-span-2"><label class="fi-fo-field-wrp-label">Classifications</label><select wire:model="trade.classifications" multiple class="fi-input w-full h-24">@foreach(config('customer_trade_form.profile_classifications.outlet', []) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div>
                    <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-3 gap-4"><div><label class="fi-fo-field-wrp-label">Conversion Program</label><select wire:model="active.conversion_program" class="fi-input w-full"><option value="">Select conversion program</option>@foreach(config('customer_trade_form.conversion_programs', []) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div><div><label class="fi-fo-field-wrp-label">Working Days</label><select wire:model="trade.working_days" multiple class="fi-input w-full h-24">@foreach(config('customer_trade_form.working_days', []) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div><div><label class="fi-fo-field-wrp-label">ULAB</label><select wire:model="active.ulab" class="fi-input w-full"><option value="">Select</option><option value="GRC">GRC</option><option value="Consolidator">Consolidator</option><option value="None">None</option></select></div><div><label class="fi-fo-field-wrp-label">Opening Time</label><x-filament::input type="time" wire:model="active.operating_hours.start" /></div><div><label class="fi-fo-field-wrp-label">Closing Time</label><x-filament::input type="time" wire:model="active.operating_hours.end" /></div><div class="flex items-center gap-2 pt-6"><input type="checkbox" wire:model.live="trade.motiv_user" class="rounded"><label>MOTIV User</label></div>@if($trade['motiv_user'] ?? false)<div><label class="fi-fo-field-wrp-label">Warehouse Code</label><x-filament::input wire:model="active.warehouse_code" /></div>@endif<div><label class="fi-fo-field-wrp-label">Delivery Type</label><select wire:model.live="active.delivery_type" class="fi-input w-full"><option value="">Select</option><option value="yes">Yes</option><option value="no">No</option></select></div>@if(($active['delivery_type'] ?? null) === 'yes')<div><label class="fi-fo-field-wrp-label">Delivery Detail</label><select wire:model="active.delivery_detail" class="fi-input w-full"><option value="">Select</option><option value="own_delivery">Own Delivery</option><option value="meh">MEH</option></select></div>@endif</div>
                @elseif($profile === 'fleet')
                    <div><label class="fi-fo-field-wrp-label">Type</label><select wire:model="active.account_type" class="fi-input w-full"><option value="">Select</option><option value="newly_mapped">Newly Mapped</option><option value="existing">Existing</option></select></div><div><label class="fi-fo-field-wrp-label">Classification</label><select wire:model="active.classifications" class="fi-input w-full"><option value="">Select</option>@foreach(config('customer_trade_form.profile_classifications.fleet', []) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div><div><label class="fi-fo-field-wrp-label">Battery Class</label><select wire:model="active.battery_class" class="fi-input w-full"><option value="">Select</option><option value="maintenance_free">Maintenance Free</option><option value="low_maintenance">Low Maintenance</option></select></div><div><label class="fi-fo-field-wrp-label">Status</label><select wire:model="active.status" class="fi-input w-full"><option value="">Select</option><option value="active">Active</option><option value="dormant">Dormant</option><option value="irregular">Irregular</option></select></div>
                @elseif($profile === 'oe')<div><label class="fi-fo-field-wrp-label">Classification</label><select wire:model="active.classifications" class="fi-input w-full"><option value="">Select</option>@foreach(config('customer_trade_form.profile_classifications.oe', []) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div><div><label class="fi-fo-field-wrp-label">Battery Class</label><select wire:model="active.battery_class" class="fi-input w-full"><option value="">Select</option><option value="maintenance_free">Maintenance Free</option><option value="low_maintenance">Low Maintenance</option></select></div>@if(($trade['entry_detail'] ?? null) === 'Acid')<div class="flex items-center gap-2 pt-6"><input type="checkbox" wire:model="active.sulfuric_acid" class="rounded"><label>Sulfuric Acid</label></div>@endif
                @elseif($profile === 'ib')<div><label class="fi-fo-field-wrp-label">Classification</label><select wire:model="active.classifications" class="fi-input w-full"><option value="">Select</option>@foreach(config('customer_trade_form.profile_classifications.ib', []) as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div>
                @endif
            </div>
        </div>

        @if($profile) @foreach(config("customer_trade_form.category_streams.{$profile}.streams", []) as $stream) @if($profile !== 'outlet' || ($trade['entry_detail'] ?? null) === 'AB and MCB' || ($stream === 'ab' && ($trade['entry_detail'] ?? null) === 'AB') || ($stream === 'mcb' && ($trade['entry_detail'] ?? null) === 'MCB'))
            <div class="bg-white rounded-2xl shadow-sm p-5 space-y-3"><h2 class="font-extrabold">{{ strtoupper($stream) }} Annual Categories</h2><div class="grid grid-cols-1 md:grid-cols-3 gap-3">@foreach($categories[$stream] ?? [] as $year => $category)<div><label class="fi-fo-field-wrp-label">{{ $year }}</label><select wire:model="categories.{{ $stream }}.{{ $year }}" class="fi-input w-full"><option value="">Select category</option>@foreach($this->categoryOptions($stream) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>@endforeach</div></div>
        @endif @endforeach @endif

        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-3"><h2 class="font-extrabold">Owner Profile</h2><div class="grid grid-cols-1 md:grid-cols-3 gap-4">@foreach(['name' => 'Name of Owner', 'birthday' => 'Birthday', 'nickname' => 'Nickname', 'successor_name' => 'Successor Name', 'successor_birthday' => 'Successor Birthday', 'relationship' => 'Relationship with the Owner', 'generation' => 'Generation', 'hobbies' => 'Hobbies'] as $key => $label)<div class="{{ $key === 'hobbies' ? 'md:col-span-2' : '' }}"><label class="fi-fo-field-wrp-label">{{ $label }}</label>@if(str_contains($key, 'birthday'))<x-filament::input type="date" wire:model="active.owner.{{ $key }}" />@elseif($key === 'hobbies')<textarea wire:model="active.owner.{{ $key }}" class="fi-input w-full"></textarea>@else<x-filament::input wire:model="active.owner.{{ $key }}" />@endif</div>@endforeach</div></div>

        @error('categories')<p class="text-danger-600">{{ $message }}</p>@enderror @error('active.*')<p class="text-danger-600">{{ $message }}</p>@enderror @error('access_user_ids')<p class="text-danger-600">{{ $message }}</p>@enderror @error('person_in_charge_id')<p class="text-danger-600">{{ $message }}</p>@enderror @error('trade')<p class="text-danger-600">{{ $message }}</p>@enderror @error('physical_region_id')<p class="text-danger-600">{{ $message }}</p>@enderror @error('province_id')<p class="text-danger-600">{{ $message }}</p>@enderror @error('municipality_id')<p class="text-danger-600">{{ $message }}</p>@enderror
        <div class="flex justify-end gap-3"><a href="{{ \App\Filament\Pages\CustomerPage::getUrl() }}" class="fi-btn">Cancel</a><button type="submit" class="fi-btn fi-color-primary">Save Customer</button></div>
        {{-- CUSTOMER LOCATION MAP MODAL --}}
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
                        <span class="material-symbols-outlined mat-fill text-[#890f00]">location_on</span>
                        <h3 class="font-bold text-[#191c1e]">Pin Customer Location</h3>
                    </div>
                    <button
                        type="button"
                        x-on:click="closePicker()"
                        class="flex h-8 w-8 items-center justify-center rounded-full bg-[#edeef0] transition-colors hover:bg-[#e7e8ea]"
                    >
                        <span class="material-symbols-outlined text-lg text-[#434654]">close</span>
                    </button>
                </div>

                <div
                    x-ref="pickerMap"
                    wire:ignore
                    id="customer-location-map"
                    class="mx-4 my-4 flex-1 overflow-hidden rounded-xl border border-gray-200"
                    style="min-height: 20rem;"
                    role="application"
                    aria-label="Customer location map"
                ></div>

                <div class="flex shrink-0 flex-col gap-3 border-t border-gray-100 px-5 py-4 md:flex-row md:items-center md:justify-between">
                    <p class="text-xs text-[#737685] md:max-w-[55%]">
                        <span x-show="addressLoading">Looking up address…</span>
                        <span x-show="!addressLoading && addressPreview" x-text="addressPreview"></span>
                        <span x-show="!addressLoading && !addressPreview" x-text="message"></span>
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
    </form>
</x-filament-panels::page>

@once
    <script>
        window.customerLocationPicker = function () {
            return {
                showPicker: false,
                map: null,
                marker: null,
                message: '',
                addressPreview: '',
                addressLoading: false,
                lookupToken: 0,
                neutralCenter: [14.5995, 120.9842],
                neutralZoom: 10,
                locationZoom: 16,

                latitudeInput() {
                    return this.$root.querySelector('[data-location-coordinate="latitude"]');
                },

                longitudeInput() {
                    return this.$root.querySelector('[data-location-coordinate="longitude"]');
                },

                addressInput() {
                    return this.$root.querySelector('[data-location-address]');
                },

                currentCoordinates() {
                    const latitude = Number.parseFloat(this.latitudeInput()?.value ?? '');
                    const longitude = Number.parseFloat(this.longitudeInput()?.value ?? '');

                    return Number.isFinite(latitude) && Number.isFinite(longitude)
                        && latitude >= -90 && latitude <= 90
                        && longitude >= -180 && longitude <= 180
                        ? [latitude, longitude]
                        : null;
                },

                openPicker() {
                    this.showPicker = true;
                    this.message = '';
                    this.addressPreview = '';
                    this.addressLoading = false;

                    // x-show only toggles display, so wait a paint before Leaflet
                    // measures the container (see the salescall mini-map note).
                    this.$nextTick(() => setTimeout(() => this.buildMap(), 150));
                },

                closePicker() {
                    this.showPicker = false;

                    if (this.map) {
                        this.map.remove();
                        this.map = null;
                        this.marker = null;
                    }
                },

                buildMap() {
                    const el = this.$refs.pickerMap;

                    if (! el || ! window.L) {
                        this.message = 'The map is unavailable. Enter coordinates directly.';
                        return;
                    }

                    if (this.map) {
                        this.map.remove();
                        this.map = null;
                        this.marker = null;
                    }

                    const start = this.currentCoordinates();

                    this.map = L.map(el).setView(
                        start ?? this.neutralCenter,
                        start ? this.locationZoom : this.neutralZoom,
                    );

                    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                        attribution: '&copy; OpenStreetMap &copy; CartoDB',
                        maxZoom: 18,
                    }).addTo(this.map);

                    if (start) {
                        this.placeMarker(start);
                    }

                    this.map.on('click', (event) => {
                        this.placeMarker([event.latlng.lat, event.latlng.lng]);
                        this.setCoordinates(event.latlng.lat, event.latlng.lng);
                        this.lookupAddress(event.latlng.lat, event.latlng.lng);
                    });

                    setTimeout(() => this.map?.invalidateSize(), 0);
                },

                placeMarker(coordinates) {
                    if (this.marker) {
                        this.marker.setLatLng(coordinates);
                        return;
                    }

                    this.marker = L.marker(coordinates, { draggable: true }).addTo(this.map);

                    this.marker.on('dragend', (event) => {
                        const position = event.target.getLatLng();
                        this.setCoordinates(position.lat, position.lng);
                        this.lookupAddress(position.lat, position.lng);
                    });
                },

                setCoordinates(latitude, longitude) {
                    this.setInput(this.latitudeInput(), Number(latitude).toFixed(7));
                    this.setInput(this.longitudeInput(), Number(longitude).toFixed(7));
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

                lookupAddress(latitude, longitude) {
                    const token = ++this.lookupToken;
                    this.addressLoading = true;
                    this.addressPreview = '';
                    this.message = '';

                    const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
                        + '&lat=' + encodeURIComponent(latitude)
                        + '&lon=' + encodeURIComponent(longitude)
                        + '&zoom=18&addressdetails=1';

                    fetch(url, { headers: { Accept: 'application/json' } })
                        .then((response) => (response.ok ? response.json() : Promise.reject(new Error('HTTP ' + response.status))))
                        .then((data) => {
                            if (token !== this.lookupToken) {
                                return;
                            }

                            this.addressPreview = typeof data?.display_name === 'string' ? data.display_name : '';
                        })
                        .catch(() => {
                            if (token !== this.lookupToken) {
                                return;
                            }

                            this.addressPreview = '';
                            this.message = 'Could not look up the address. You can type it manually.';
                        })
                        .finally(() => {
                            if (token === this.lookupToken) {
                                this.addressLoading = false;
                            }
                        });
                },

                confirmLocation() {
                    const address = (this.addressPreview || '').trim().slice(0, 500);

                    if (address) {
                        this.setInput(this.addressInput(), address);
                    }

                    this.closePicker();
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
                            this.setCoordinates(latitude, longitude);
                            this.placeMarker([latitude, longitude]);
                            this.map?.setView([latitude, longitude], this.locationZoom);
                            this.lookupAddress(latitude, longitude);
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
