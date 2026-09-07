<x-filament-panels::page>
    <form wire:submit="saveCustomer" class="space-y-5 pb-8">
        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Customer Information</h2>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-6">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Store Name <span class="text-danger-600">*</span></label>
                    <x-filament::input wire:model="name" />
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Customer Code</label>
                    <x-filament::input wire:model="unique_id" placeholder="Optional" />
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Active</label>
                    <label class="flex min-h-10 items-center gap-2 text-sm font-medium text-gray-700"><input type="checkbox" wire:model="is_active" class="rounded"> Active</label>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Company <span class="text-danger-600">*</span></label>
                    <select wire:model="company_id" class="fi-select-input w-full rounded-lg border-gray-300">
                        <option value="">Select company</option>
                        @foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-span-12 md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">General Category <span class="text-danger-600">*</span></label>
                    <select wire:model="general_category_id" class="fi-select-input w-full rounded-lg border-gray-300">
                        <option value="">Select category</option>
                        @foreach($generalCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-span-12 md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Competitor Volume</label>
                    <select wire:model="competitor_volume" class="fi-select-input w-full rounded-lg border-gray-300">
                        <option value="">Not specified</option><option value="1">High</option><option value="2">Medium</option><option value="3">Low</option>
                    </select>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Contact Person</label>
                    <x-filament::input wire:model="contact_person" />
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Contact Number</label>
                    <x-filament::input wire:model="contact_number" />
                </div>
                <div class="col-span-12">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Address <span class="text-danger-600">*</span></label>
                    <textarea wire:model="address" rows="3" class="fi-input w-full rounded-lg border-gray-300"></textarea>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Location</h2>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Region <span class="text-danger-600">*</span></label>
                    <select wire:model.live="region_specific_id" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select region</option>@foreach($regions as $region)<option value="{{ $region->id }}">{{ $region->name }}</option>@endforeach</select>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">City / Province <span class="text-danger-600">*</span></label>
                    <select wire:model.live="province_id" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select province/city</option>@foreach($provinces->where('region_specific_id', $region_specific_id) as $province)<option value="{{ $province->id }}">{{ $province->name }}</option>@endforeach</select>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Municipality <span class="text-danger-600">*</span></label>
                    <select wire:model="municipality_id" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select municipality</option>@foreach($municipalities->where('province_id', $province_id) as $municipality)<option value="{{ $municipality->id }}">{{ $municipality->name }}</option>@endforeach</select>
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Latitude <span class="text-danger-600">*</span></label>
                    <x-filament::input wire:model="latitude" type="number" step="any" />
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Longitude <span class="text-danger-600">*</span></label>
                    <x-filament::input wire:model="longitude" type="number" step="any" />
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Trade Profile</h2>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">House Number</label>
                    <x-filament::input wire:model="trade.house_number" />
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Entry Detail</label>
                    <select wire:model.live="trade.entry_detail" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select entry detail</option>@foreach(config('customer_trade_form.entry_details') as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
                </div>
            @php($multi = ['classifications' => 'Classifications', 'ommc_brands' => 'OMMC Brands', 'ommc_mcb_brands' => 'OMMC MCB Brands', 'tpl_pollux' => 'TPL / Pollux', 'other_competitor_brands' => 'Other Competitor Brands', 'mcb_competitors' => 'MCB Competitors', 'working_days' => 'Working Days', 'operating_hours' => 'Operating Hours'])
            @foreach($multi as $key => $label)
                @php($options = $key === 'classifications' ? config('customer_trade_form.classifications') : (config("customer_trade_form.brands.{$key}") ?? config("customer_trade_form.{$key}") ?? []))
                @php($span = $key === 'classifications' ? 6 : 4)
                <div class="col-span-12 md:col-span-{{ $span }}">
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ $label }} @if($key === 'classifications')<span class="text-danger-600">*</span>@endif</label>
                    <select wire:model="trade.{{ $key }}" multiple class="fi-select-input w-full rounded-lg border-gray-300 min-h-24">@foreach($options as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
                </div>
            @endforeach
            <div class="col-span-12 md:col-span-4">
                <label class="mb-1 block text-sm font-medium text-gray-700">If Others, Specify</label>
                <textarea wire:model="trade.other_competitors_note" rows="2" class="fi-input w-full rounded-lg border-gray-300"></textarea>
            </div>
            <div class="col-span-12 md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">MOTIV User</label>
                    <label class="flex min-h-10 items-center gap-2 text-sm font-medium text-gray-700"><input type="checkbox" wire:model="trade.motiv_user" class="rounded"> MOTIV User</label>
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Delivery Method</label>
                    <select wire:model="trade.delivery_method" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select</option>@foreach(config('customer_trade_form.delivery_methods') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">ULAB</label>
                    <select wire:model="trade.ulab" class="fi-select-input w-full rounded-lg border-gray-300"><option value="">Select</option>@foreach(config('customer_trade_form.ulab') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Annual Categories (2018–2026)</h2>
            @foreach($category_histories as $year => $history)
                <div class="grid grid-cols-[5rem_1fr] gap-3 items-center">
                    <span class="font-bold text-sm">{{ $year }}</span>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Category <span class="text-danger-600">*</span></label>
                        <select wire:model="category_histories.{{ $year }}.category" class="fi-select-input w-full rounded-lg border-gray-300" required><option value="">Select category</option>@foreach($this->categoryOptions($trade['entry_detail'] ?? null) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    </div>
                </div>
            @endforeach
            @error('category_histories')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex justify-end gap-3"><a href="{{ \App\Filament\Pages\CustomerPage::getUrl() }}" class="fi-btn">Cancel</a><button type="submit" class="fi-btn fi-color-primary">Save Customer</button></div>
    </form>
</x-filament-panels::page>
