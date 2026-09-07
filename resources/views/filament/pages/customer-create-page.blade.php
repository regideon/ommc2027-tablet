<x-filament-panels::page>
    <form wire:submit="saveCustomer" class="space-y-5 pb-8">
        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Customer Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-filament::input.wrapper label="Store Name" required>
                    <x-filament::input wire:model="name" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper label="Customer Code (optional)">
                    <x-filament::input wire:model="unique_id" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper label="Company" required>
                    <select wire:model="company_id" class="fi-select-input w-full rounded-lg border-gray-300">
                        <option value="">Select company</option>
                        @foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach
                    </select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper label="General Category" required>
                    <select wire:model="general_category_id" class="fi-select-input w-full rounded-lg border-gray-300">
                        <option value="">Select category</option>
                        @foreach($generalCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                    </select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper label="Competitor Volume">
                    <select wire:model="competitor_volume" class="fi-select-input w-full rounded-lg border-gray-300">
                        <option value="">Not specified</option><option value="1">High</option><option value="2">Medium</option><option value="3">Low</option>
                    </select>
                </x-filament::input.wrapper>
                <label class="flex items-center gap-2 text-sm font-medium text-gray-700 pt-7"><input type="checkbox" wire:model="is_active" class="rounded"> Active</label>
            </div>
            @foreach(['contact_person' => 'Contact Person', 'contact_number' => 'Contact Number'] as $field => $label)
                <x-filament::input.wrapper :label="$label">
                    <x-filament::input wire:model="{{ $field }}" />
                </x-filament::input.wrapper>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Location</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-filament::input.wrapper label="Region" required>
                    <select wire:model.live="region_specific_id" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select region</option>@foreach($regions as $region)<option value="{{ $region->id }}">{{ $region->name }}</option>@endforeach</select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper label="Province / City" required>
                    <select wire:model.live="province_id" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select province/city</option>@foreach($provinces->where('region_specific_id', $region_specific_id) as $province)<option value="{{ $province->id }}">{{ $province->name }}</option>@endforeach</select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper label="Municipality" required>
                    <select wire:model="municipality_id" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select municipality</option>@foreach($municipalities->where('province_id', $province_id) as $municipality)<option value="{{ $municipality->id }}">{{ $municipality->name }}</option>@endforeach</select>
                </x-filament::input.wrapper>
            </div>
            <x-filament::input.wrapper label="Address" required><textarea wire:model="address" rows="3" class="fi-input w-full rounded-lg border-gray-300"></textarea></x-filament::input.wrapper>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-filament::input.wrapper label="Latitude" required><x-filament::input wire:model="latitude" type="number" step="any" /></x-filament::input.wrapper>
                <x-filament::input.wrapper label="Longitude" required><x-filament::input wire:model="longitude" type="number" step="any" /></x-filament::input.wrapper>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Trade Profile</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-filament::input.wrapper label="House Number"><x-filament::input wire:model="trade.house_number" /></x-filament::input.wrapper>
                <x-filament::input.wrapper label="Entry Detail">
                    <select wire:model.live="trade.entry_detail" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select entry detail</option>@foreach(config('customer_trade_form.entry_details') as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
                </x-filament::input.wrapper>
            </div>
            @php($multi = ['classifications' => 'Classifications', 'ommc_brands' => 'OMMC Brands', 'ommc_mcb_brands' => 'OMMC MCB Brands', 'tpl_pollux' => 'TPL / Pollux', 'other_competitor_brands' => 'Other Competitor Brands', 'mcb_competitors' => 'MCB Competitors', 'working_days' => 'Working Days', 'operating_hours' => 'Operating Hours'])
            @foreach($multi as $key => $label)
                @php($options = $key === 'classifications' ? config('customer_trade_form.classifications') : (config("customer_trade_form.brands.{$key}") ?? config("customer_trade_form.{$key}") ?? []))
                <x-filament::input.wrapper :label="$label" :required="$key === 'classifications'">
                    <select wire:model="trade.{{ $key }}" multiple class="fi-select-input w-full rounded-lg border-gray-300 min-h-24">@foreach($options as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
                </x-filament::input.wrapper>
            @endforeach
            <x-filament::input.wrapper label="If Others, Specify"><textarea wire:model="trade.other_competitors_note" rows="2" class="fi-input w-full rounded-lg border-gray-300"></textarea></x-filament::input.wrapper>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="flex items-center gap-2 text-sm font-medium text-gray-700 pt-7"><input type="checkbox" wire:model="trade.motiv_user" class="rounded"> MOTIV User</label>
                <x-filament::input.wrapper label="Delivery Method"><select wire:model="trade.delivery_method" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select</option>@foreach(config('customer_trade_form.delivery_methods') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></x-filament::input.wrapper>
                <x-filament::input.wrapper label="ULAB"><select wire:model="trade.ulab" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select</option>@foreach(config('customer_trade_form.ulab') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></x-filament::input.wrapper>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Annual Categories (2018–2026)</h2>
            @foreach($category_histories as $year => $history)
                <div class="grid grid-cols-[5rem_1fr] gap-3 items-center">
                    <span class="font-bold text-sm">{{ $year }}</span>
                    <select wire:model="category_histories.{{ $year }}.category" class="fi-select-input w-full rounded-lg border-gray-300" required><option value="">Select category</option>@foreach($this->categoryOptions($trade['entry_detail'] ?? null) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                </div>
            @endforeach
            @error('category_histories')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex justify-end gap-3"><a href="{{ \App\Filament\Pages\CustomerPage::getUrl() }}" class="fi-btn">Cancel</a><button type="submit" class="fi-btn fi-color-primary">Save Customer</button></div>
    </form>
</x-filament-panels::page>
