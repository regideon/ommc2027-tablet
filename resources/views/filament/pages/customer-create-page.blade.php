<x-filament-panels::page>
    @once
        <style>
            .customer-page-layout-scope .customer-control-shell {
                display: flex;
                width: 100%;
                min-height: 2.25rem;
                border-radius: 0.5rem;
                background: #fff;
                box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
                outline: 1px solid rgb(3 7 18 / 0.1);
                transition: outline-color 75ms, box-shadow 75ms;
            }

            .customer-page-layout-scope .customer-control-shell:focus-within {
                outline: 2px solid var(--primary-600, #2563eb);
                outline-offset: -1px;
            }

            .customer-page-layout-scope .customer-control {
                display: block;
                width: 100%;
                min-width: 0;
                border: 0;
                border-radius: 0.5rem;
                background: transparent;
                padding: 0.375rem 0.75rem;
                color: inherit;
                outline: none;
            }

            .customer-page-layout-scope .customer-control:focus {
                outline: none;
                box-shadow: none;
            }

            .customer-page-layout-scope textarea.customer-control {
                min-height: 4.5rem;
                resize: vertical;
            }

            .customer-page-layout-scope select.customer-control:not([multiple]) {
                appearance: none;
                padding-right: 2.5rem;
                background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");
                background-position: right 0.5rem center;
                background-repeat: no-repeat;
                background-size: 1.5em 1.5em;
            }

            .customer-page-layout-scope select.customer-control[multiple] {
                min-height: 6rem;
            }

            .customer-page-layout-scope .customer-row {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 1rem;
            }

            .customer-page-layout-scope .customer-span-2,
            .customer-page-layout-scope .customer-span-3,
            .customer-page-layout-scope .customer-span-4,
            .customer-page-layout-scope .customer-span-6,
            .customer-page-layout-scope .customer-span-7,
            .customer-page-layout-scope .customer-span-12 {
                grid-column: span 12 / span 12;
                min-width: 0;
            }

            .customer-page-layout-scope .customer-history-grid {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 0.75rem;
            }

            .customer-page-layout-scope .customer-history-item {
                display: block;
                border: 1px solid rgb(3 7 18 / 0.08);
                border-radius: 0.75rem;
                padding: 0.75rem;
                background: #fff;
            }

            .customer-page-layout-scope .customer-history-header {
                margin: -0.75rem -0.75rem 0.75rem;
                border-bottom: 1px solid rgb(3 7 18 / 0.08);
                padding: 0.75rem;
                font-weight: 700;
            }

            @media (min-width: 768px) {
                .customer-page-layout-scope .customer-row {
                    display: grid;
                    grid-template-columns: repeat(12, minmax(0, 1fr));
                }

                .customer-page-layout-scope .customer-span-2 { grid-column: span 2 / span 2; }
                .customer-page-layout-scope .customer-span-3 { grid-column: span 3 / span 3; }
                .customer-page-layout-scope .customer-span-4 { grid-column: span 4 / span 4; }
                .customer-page-layout-scope .customer-span-6 { grid-column: span 6 / span 6; }
                .customer-page-layout-scope .customer-span-7 { grid-column: span 7 / span 7; }
                .customer-page-layout-scope .customer-span-12 { grid-column: span 12 / span 12; }
            }
        </style>
    @endonce

    <div class="customer-page-layout-scope">
    <form wire:submit="saveCustomer" class="customer-create-form space-y-5 pb-8">
        <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Customer Information</h2>
            <div class="space-y-4">
                <div class="customer-row">
                <div class="customer-span-6">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Store Name <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><x-filament::input class="customer-control" wire:model="name" /></div>
                </div>
                <div class="customer-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Customer Code</label>
                    <div class="customer-control-shell"><x-filament::input class="customer-control" wire:model="unique_id" placeholder="Optional" /></div>
                </div>
                <div class="customer-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Active</label>
                    <label class="flex min-h-10 items-center gap-2 text-sm font-medium text-gray-700"><input type="checkbox" wire:model="is_active" class="rounded"> Active</label>
                </div>
                </div>
                <div class="customer-row">
                <div class="customer-span-6">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Company <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><select wire:model="company_id" class="customer-control">
                        <option value="">Select company</option>
                        @foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach
                    </select></div>
                </div>
                <div class="customer-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">General Category <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><select wire:model="general_category_id" class="customer-control">
                        <option value="">Select category</option>
                        @foreach($generalCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                    </select></div>
                </div>
                <div class="customer-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Competitor Volume</label>
                    <div class="customer-control-shell"><select wire:model="competitor_volume" class="customer-control">
                        <option value="">Not specified</option><option value="1">High</option><option value="2">Medium</option><option value="3">Low</option>
                    </select></div>
                </div>
                </div>
                <div class="customer-row">
                <div class="customer-span-12">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Address <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><textarea wire:model="address" rows="3" class="customer-control"></textarea></div>
                </div>
                </div>
                <div class="customer-row">
                <div class="customer-span-6">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Contact Person</label>
                    <div class="customer-control-shell"><x-filament::input class="customer-control" wire:model="contact_person" /></div>
                </div>
                <div class="customer-span-6">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Contact Number</label>
                    <div class="customer-control-shell"><x-filament::input class="customer-control" wire:model="contact_number" /></div>
                </div>
                </div>
                </div>
            </div>
        </div>

        <div class="customer-page-layout-scope bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Location</h2>
            <div class="space-y-4">
                <div class="customer-row">
                <div class="customer-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Region <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><select wire:model.live="region_specific_id" class="customer-control"><option value="">Select region</option>@foreach($regions as $region)<option value="{{ $region->id }}">{{ $region->name }}</option>@endforeach</select></div>
                </div>
                <div class="customer-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">City / Province <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><select wire:model.live="province_id" class="customer-control"><option value="">Select province/city</option>@foreach($provinces->where('region_specific_id', $region_specific_id) as $province)<option value="{{ $province->id }}">{{ $province->name }}</option>@endforeach</select></div>
                </div>
                <div class="customer-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Municipality <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><select wire:model="municipality_id" class="customer-control"><option value="">Select municipality</option>@foreach($municipalities->where('province_id', $province_id) as $municipality)<option value="{{ $municipality->id }}">{{ $municipality->name }}</option>@endforeach</select></div>
                </div>
                </div>
                <div class="customer-row">
                <div class="customer-span-6">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Latitude <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><x-filament::input class="customer-control" wire:model="latitude" type="number" step="any" /></div>
                </div>
                <div class="customer-span-6">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Longitude <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><x-filament::input class="customer-control" wire:model="longitude" type="number" step="any" /></div>
                </div>
            </div>
        </div>
        </div>

        <div class="customer-page-layout-scope bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Trade Profile</h2>
            <div class="space-y-4">
                <div class="customer-row">
                <div class="customer-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">House Number</label>
                    <div class="customer-control-shell"><x-filament::input class="customer-control" wire:model="trade.house_number" /></div>
                </div>
                <div class="customer-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Entry Detail</label>
                    <div class="customer-control-shell"><select wire:model.live="trade.entry_detail" class="customer-control"><option value="">Select entry detail</option>@foreach(config('customer_trade_form.entry_details') as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div>
                </div>
                <div class="customer-span-6">
                    @php($options = config('customer_trade_form.classifications'))
                    <label class="mb-1 block text-sm font-medium text-gray-700">Classifications <span class="text-danger-600">*</span></label>
                    <div class="customer-control-shell"><select wire:model="trade.classifications" multiple class="customer-control">@foreach($options as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div>
                </div>
                </div>
                @foreach([
                    ['ommc_brands' => 'OMMC Brands', 'ommc_mcb_brands' => 'OMMC MCB Brands', 'tpl_pollux' => 'TPL / Pollux'],
                    ['other_competitor_brands' => 'Other Competitor Brands', 'mcb_competitors' => 'MCB Competitors'],
                    ['working_days' => 'Working Days', 'operating_hours' => 'Operating Hours'],
                ] as $row)
                    <div class="customer-row">
                    @foreach($row as $key => $label)
                        @php($options = config("customer_trade_form.brands.{$key}") ?? config("customer_trade_form.{$key}") ?? [])
                        <div class="customer-span-4">
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ $label }}</label>
                            <div class="customer-control-shell"><select wire:model="trade.{{ $key }}" multiple class="customer-control">@foreach($options as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div>
                        </div>
                    @endforeach
                    @if(count($row) < 3)
                        @if($row === ['other_competitor_brands' => 'Other Competitor Brands', 'mcb_competitors' => 'MCB Competitors'])
                            <div class="customer-span-4">
                                <label class="mb-1 block text-sm font-medium text-gray-700">If Others, Specify</label>
                                <div class="customer-control-shell"><textarea wire:model="trade.other_competitors_note" rows="2" class="customer-control"></textarea></div>
                            </div>
                        @else
                            <div class="customer-span-4">
                                <label class="mb-1 block text-sm font-medium text-gray-700">MOTIV User</label>
                                <label class="flex min-h-10 items-center gap-2 text-sm font-medium text-gray-700"><input type="checkbox" wire:model="trade.motiv_user" class="rounded"> MOTIV User</label>
                            </div>
                        @endif
                    @endif
                    </div>
                @endforeach
                <div class="customer-row">
                <div class="customer-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Delivery Method</label>
                    <div class="customer-control-shell"><select wire:model="trade.delivery_method" class="customer-control"><option value="">Select</option>@foreach(config('customer_trade_form.delivery_methods') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                </div>
                <div class="customer-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">ULAB</label>
                    <div class="customer-control-shell"><select wire:model="trade.ulab" class="customer-control"><option value="">Select</option>@foreach(config('customer_trade_form.ulab') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                </div>
                </div>
            </div>
        </div>

        <div class="customer-page-layout-scope bg-white rounded-2xl shadow-sm p-5 space-y-4">
            <h2 class="font-extrabold text-[#191c1e]">Annual Categories (2018–2026)</h2>
            <div class="customer-history-grid">
                @foreach($category_histories as $year => $history)
                    <div class="customer-history-item">
                        <div class="customer-history-header">{{ $year }}</div>
                        <div class="customer-row">
                        <div class="customer-span-6">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Year</label>
                            <div class="customer-control-shell"><input type="text" value="{{ $year }}" readonly class="customer-control"></div>
                        </div>
                        <div class="customer-span-6">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Category <span class="text-danger-600">*</span></label>
                            <div class="customer-control-shell"><select wire:model="category_histories.{{ $year }}.category" class="customer-control" required><option value="">Select category</option>@foreach($this->categoryOptions($trade['entry_detail'] ?? null) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                        </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @error('category_histories')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex justify-end gap-3"><a href="{{ \App\Filament\Pages\CustomerPage::getUrl() }}" class="fi-btn">Cancel</a><button type="submit" class="fi-btn fi-color-primary">Save Customer</button></div>
    </form>
    </div>
</x-filament-panels::page>
