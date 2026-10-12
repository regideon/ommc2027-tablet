@php
    $badgeClass = fn (?string $color): string => match ($color) {
        'warning' => 'bg-amber-100 text-amber-800',
        'success' => 'bg-green-100 text-green-800',
        'danger' => 'bg-red-100 text-red-800',
        'info' => 'bg-blue-100 text-blue-800',
        default => 'bg-gray-100 text-gray-700',
    };

    $statusBadge = function (int $value, string $enum) use ($badgeClass): array {
        $case = $enum::from($value);

        return [
            'title' => $case->meta('title'),
            'class' => $badgeClass($case->meta('color')),
        ];
    };

    $selected = $this->selectedRow();
    $money = fn ($value): string => '₱'.number_format((float) $value, 2);
    $number = fn ($value): string => number_format((float) $value, 2);
    $dateLabel = fn ($value): string => filled($value) ? \Illuminate\Support\Carbon::parse($value)->format('M j, Y') : '—';
    $dateTimeLabel = fn ($value): string => filled($value) ? \Illuminate\Support\Carbon::parse($value)->format('M j, Y g:i A') : '—';
    $rowKey = fn (array $row): string => ! empty($row['server_id']) ? 'server:'.$row['server_id'] : 'local:'.$row['local_uuid'];
@endphp

@if ($formLoadError !== '')
    <div role="alert" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ $formLoadError }}
    </div>
@endif

@if ($actionError !== '')
    <div role="alert" class="mb-4 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
        {{ $actionError }}
    </div>
@endif

<div class="space-y-4 md:grid md:grid-cols-[minmax(13rem,0.42fr)_minmax(0,0.58fr)] md:items-start md:gap-4 md:space-y-0">
    {{-- Submissions list --}}
    <div class="{{ $selected !== null ? 'hidden md:block' : '' }} min-w-0 rounded-2xl border border-gray-200 bg-white md:max-h-[calc(100dvh-11rem)] md:overflow-y-auto md:overscroll-contain" style="-webkit-overflow-scrolling: touch;">
        <div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-gray-100 bg-white/95 px-4 py-3 backdrop-blur">
            <div class="min-w-0">
                <h3 class="text-base font-bold text-[#191c1e]">Marketing</h3>
                <p class="text-xs text-[#737685]">Your Marketing recommendations</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" wire:click="syncNow" @disabled($syncing) class="flex h-9 items-center gap-1.5 rounded-full bg-[#890f00] px-3 text-xs font-semibold text-white transition-colors hover:bg-[#6f0c00] disabled:opacity-50" title="Sync pending Marketing recommendations and refresh the list">
                    <span class="material-symbols-outlined text-lg {{ $syncing ? 'animate-spin' : '' }}">{{ $syncing ? 'sync' : 'refresh' }}</span>
                    <span class="hidden sm:inline">{{ $syncing ? 'Syncing…' : 'Sync' }}</span>
                </button>
                <a
                    href="{{ \App\Filament\Pages\MarketingFormPage::getUrl() }}"
                    class="inline-flex items-center gap-1.5 rounded-full bg-[#890f00] px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-[#6f0c00]">
                    <span class="material-symbols-outlined mat-fill text-lg">add_circle</span>
                    <span class="hidden sm:inline">Create Marketing</span>
                </a>
            </div>
        </div>

        @if ($syncMessage !== '')
            <div role="status" class="border-b border-gray-100 bg-blue-50 px-4 py-2 text-xs text-blue-800">
                {{ $syncMessage }}
            </div>
        @endif

        <div class="divide-y divide-gray-100">
            @forelse ($rows as $row)
                @php($key = $rowKey($row))
                @php($sps = $statusBadge((int) ($row['sps_status'] ?? \App\Enums\SPSStatus::PENDING), \App\Enums\SPSStatus::class))
                @php($rac = $statusBadge((int) ($row['rac_status'] ?? \App\Enums\RACStatus::PENDING), \App\Enums\RACStatus::class))
                <button
                    type="button"
                    wire:click="selectRow('{{ $key }}')"
                    aria-pressed="{{ $selectedRowKey === $key ? 'true' : 'false' }}"
                    class="w-full min-h-[88px] border-l-4 px-4 py-3 text-left transition-colors {{ $selectedRowKey === $key ? 'border-[#890f00] bg-red-50' : 'border-transparent bg-white hover:bg-red-50' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-[#191c1e]">{{ $row['name'] ?? 'Marketing' }}</p>
                            <p class="mt-1 truncate text-xs text-[#434654]">{{ $row['program'] ?? '—' }}</p>
                            <p class="mt-1 truncate text-xs text-[#737685]">{{ $row['region_type'] ?? '—' }} · {{ $dateLabel($row['submitted_at'] ?? null) }}</p>
                        </div>
                        <div class="flex max-w-[45%] shrink-0 flex-col items-end gap-1">
                            <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $sps['class'] }}">SPS: {{ $sps['title'] }}</span>
                            <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $rac['class'] }}">RAC: {{ $rac['title'] }}</span>
                            @if (($row['sync_status'] ?? '') !== 'synced')
                                <span class="inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Pending Sync</span>
                            @endif
                        </div>
                    </div>
                </button>
            @empty
                <p class="bg-gray-50 py-10 text-center text-sm text-[#737685]">No Marketing recommendations found.</p>
            @endforelse
        </div>
    </div>

    {{-- Detail panel --}}
    <div class="{{ $selected === null ? 'hidden md:block' : '' }} min-w-0 space-y-4 md:max-h-[calc(100dvh-11rem)] md:overflow-y-auto md:overscroll-contain" style="-webkit-overflow-scrolling: touch;">
        @if ($selected !== null)
            @php($sps = $statusBadge((int) ($selected['sps_status'] ?? \App\Enums\SPSStatus::PENDING), \App\Enums\SPSStatus::class))
            @php($rac = $statusBadge((int) ($selected['rac_status'] ?? \App\Enums\RACStatus::PENDING), \App\Enums\RACStatus::class))

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    wire:click="clearRowSelection"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 md:hidden"
                    title="Back to submissions">
                    <span class="material-symbols-outlined text-[#434654]">arrow_back</span>
                </button>
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-widest text-[#890f00]">Marketing Details</p>
                    <h3 class="truncate text-base font-bold text-[#191c1e]">{{ $selected['name'] ?? 'Marketing' }}</h3>
                </div>
                @if (($selected['sync_status'] ?? '') !== 'synced')
                    <span class="ml-auto shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Pending Sync</span>
                @endif
            </div>

            <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="text-xs font-black uppercase tracking-wider text-[#737685]">Header</h4>
                @foreach ([
                    ['Name', $selected['name'] ?? '—'],
                    ['Program', $selected['program'] ?? '—'],
                    ['Region Type', $selected['region_type'] ?? '—'],
                    ['Submitted At', $dateTimeLabel($selected['submitted_at'] ?? null)],
                    ['Duration Start', $dateLabel($selected['duration_start_at'] ?? null)],
                    ['Duration End', $dateLabel($selected['duration_end_at'] ?? null)],
                    ['Objective', $selected['objective'] ?: '—'],
                    ['Mechanics Description', $selected['mechanics_description'] ?: '—'],
                ] as $field)
                    <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                        <span class="text-xs font-bold text-[#737685]">{{ $field[0] }}</span>
                        <span class="whitespace-pre-line break-words text-right text-sm text-[#191c1e]">{{ $field[1] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="mb-3 text-xs font-black uppercase tracking-wider text-[#737685]">Approval Status</h4>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ $sps['class'] }}">
                        SPS: {{ $sps['title'] }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ $rac['class'] }}">
                        RAC: {{ $rac['title'] }}
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="mb-3 text-xs font-black uppercase tracking-wider text-[#737685]">Volumes</h4>
                @if (! empty($selected['volumes']))
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-gray-100 text-[10px] font-bold uppercase tracking-wide text-[#737685]">
                                <th class="px-1 py-1.5">Before</th>
                                <th class="px-1 py-1.5">During</th>
                                <th class="px-1 py-1.5 text-right">Volume</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($selected['volumes'] as $volume)
                                <tr>
                                    <td class="px-1 py-1.5 text-[#191c1e]">{{ $number($volume['before_program'] ?? 0) }}</td>
                                    <td class="px-1 py-1.5 text-[#191c1e]">{{ $number($volume['during_program'] ?? 0) }}</td>
                                    <td class="px-1 py-1.5 text-right font-semibold text-[#191c1e]">{{ $number($volume['volume'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-sm text-[#737685]">No volumes recorded.</p>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="mb-3 text-xs font-black uppercase tracking-wider text-[#737685]">Realizations</h4>
                @if (! empty($selected['realizations']))
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-gray-100 text-[10px] font-bold uppercase tracking-wide text-[#737685]">
                                <th class="px-1 py-1.5">Stage</th>
                                <th class="px-1 py-1.5">Start</th>
                                <th class="px-1 py-1.5">End</th>
                                <th class="px-1 py-1.5 text-right">Volume</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($selected['realizations'] as $realization)
                                <tr>
                                    <td class="px-1 py-1.5 text-[#191c1e]">{{ $realization['stage_label'] ?? '—' }}</td>
                                    <td class="px-1 py-1.5 text-[#191c1e]">{{ $dateLabel($realization['start_at'] ?? null) }}</td>
                                    <td class="px-1 py-1.5 text-[#191c1e]">{{ $dateLabel($realization['end_at'] ?? null) }}</td>
                                    <td class="px-1 py-1.5 text-right font-semibold text-[#191c1e]">{{ $number($realization['volume'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-sm text-[#737685]">No realizations recorded.</p>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="mb-3 text-xs font-black uppercase tracking-wider text-[#737685]">Mechanics</h4>
                @if (! empty($selected['mechanics']))
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-gray-100 text-[10px] font-bold uppercase tracking-wide text-[#737685]">
                                <th class="px-1 py-1.5">Period</th>
                                <th class="px-1 py-1.5 text-right">Unit Price</th>
                                <th class="px-1 py-1.5 text-right">Sub Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($selected['mechanics'] as $mechanic)
                                <tr>
                                    <td class="px-1 py-1.5 text-[#191c1e]">{{ $dateLabel($mechanic['period'] ?? null) }}</td>
                                    <td class="px-1 py-1.5 text-right text-[#191c1e]">{{ $money($mechanic['unit_price'] ?? 0) }}</td>
                                    <td class="px-1 py-1.5 text-right font-semibold text-[#191c1e]">{{ $money($mechanic['sub_total'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-sm text-[#737685]">No mechanics recorded.</p>
                @endif
            </div>

            @if ($selected['is_editable'] ?? false)
                <button
                    type="button"
                    wire:click="editSelectedRow"
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-full bg-[#890f00] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#6f0c00]">
                    <span class="material-symbols-outlined text-lg">edit</span>
                    <span>Edit Marketing</span>
                </button>
            @endif
        @else
            <div class="flex min-h-64 items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white px-6 text-center">
                <p class="text-sm text-[#737685]">Select a Marketing recommendation to view its details.</p>
            </div>
        @endif
    </div>
</div>
