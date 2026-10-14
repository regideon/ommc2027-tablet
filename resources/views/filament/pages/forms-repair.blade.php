@php
    $badgeClass = fn (?string $color): string => match ($color) {
        'warning' => 'bg-amber-100 text-amber-800',
        'success' => 'bg-green-100 text-green-800',
        'danger' => 'bg-red-100 text-red-800',
        'info' => 'bg-blue-100 text-blue-800',
        default => 'bg-gray-100 text-gray-700',
    };

    $statusBadge = function (?int $value, string $enum) use ($badgeClass): array {
        $case = $value === null ? null : $enum::tryFrom($value);

        return [
            'title' => $case?->meta('title') ?? '—',
            'class' => $badgeClass($case?->meta('color')),
        ];
    };

    $selected = $this->selectedRow();
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

<div class="space-y-4 min-[900px]:grid min-[900px]:grid-cols-[minmax(13rem,0.42fr)_minmax(0,0.58fr)] min-[900px]:items-start min-[900px]:gap-4 min-[900px]:space-y-0">
    {{-- Submissions list --}}
    <div class="{{ $selected !== null ? 'hidden min-[900px]:block' : '' }} min-w-0 rounded-2xl border border-gray-200 bg-white min-[900px]:max-h-[calc(100dvh-11rem)] min-[900px]:overflow-y-auto min-[900px]:overscroll-contain" style="-webkit-overflow-scrolling: touch;">
        <div class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-gray-100 bg-white/95 px-4 py-3 backdrop-blur">
            <div class="min-w-0">
                <h3 class="text-base font-bold text-[#191c1e]">Repair</h3>
                <p class="text-xs text-[#737685]">Your Repairs</p>
            </div>
            <div class="flex shrink-0 items-center gap-2 ml-auto">
                <button type="button" wire:click="syncNow" @disabled($syncing) class="flex h-9 items-center gap-1.5 rounded-full bg-[#890f00] px-3 text-xs font-semibold text-white transition-colors hover:bg-[#6f0c00] disabled:opacity-50" title="Sync pending Repairs and refresh the list">
                    <span class="material-symbols-outlined text-lg {{ $syncing ? 'animate-spin' : '' }}">{{ $syncing ? 'sync' : 'refresh' }}</span>
                    <span class="hidden sm:inline">{{ $syncing ? 'Syncing…' : 'Sync' }}</span>
                </button>
                <a
                    href="{{ \App\Filament\Pages\RepairFormPage::getUrl() }}"
                    class="inline-flex items-center gap-1.5 rounded-full bg-[#890f00] px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-[#6f0c00]">
                    <span class="material-symbols-outlined mat-fill text-lg">add_circle</span>
                    <span class="hidden sm:inline">Create Repair</span>
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
                @php($rsm = $statusBadge(isset($row['rsm_status']) ? (int) $row['rsm_status'] : null, \App\Enums\RSMStatus::class))
                @php($sps = $statusBadge(isset($row['sps_status']) ? (int) $row['sps_status'] : null, \App\Enums\SPSStatus::class))
                @php($spm = $statusBadge(isset($row['spm_status']) ? (int) $row['spm_status'] : null, \App\Enums\SPMStatus::class))
                <button
                    type="button"
                    wire:click="selectRow('{{ $key }}')"
                    aria-pressed="{{ $selectedRowKey === $key ? 'true' : 'false' }}"
                    class="w-full min-h-[88px] border-l-4 px-4 py-3 text-left transition-colors {{ $selectedRowKey === $key ? 'border-[#890f00] bg-red-50' : 'border-transparent bg-white hover:bg-red-50' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-[#191c1e]">{{ $row['plate_number'] ?? 'Repair' }}</p>
                            <p class="mt-1 truncate text-xs text-[#434654]">{{ $row['sales_call'] ?? '—' }} · ₱{{ $row['amount'] ?? '—' }}</p>
                        </div>
                        <div class="flex max-w-[45%] shrink-0 flex-col items-end gap-1">
                            <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $rsm['class'] }}">RSM: {{ $rsm['title'] }}</span>
                            <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $sps['class'] }}">SPS: {{ $sps['title'] }}</span>
                            <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $spm['class'] }}">SPM: {{ $spm['title'] }}</span>
                            @if (($row['sync_status'] ?? '') !== 'synced')
                                <span class="inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Pending Sync</span>
                            @endif
                        </div>
                    </div>
                </button>
            @empty
                <p class="bg-gray-50 py-10 text-center text-sm text-[#737685]">No Repair submissions found.</p>
            @endforelse
        </div>
    </div>

    {{-- Detail panel --}}
    <div class="{{ $selected === null ? 'hidden min-[900px]:block' : '' }} min-w-0 space-y-4 min-[900px]:max-h-[calc(100dvh-11rem)] min-[900px]:overflow-y-auto min-[900px]:overscroll-contain" style="-webkit-overflow-scrolling: touch;">
        @if ($selected !== null)
            @php($rsm = $statusBadge(isset($selected['rsm_status']) ? (int) $selected['rsm_status'] : null, \App\Enums\RSMStatus::class))
            @php($sps = $statusBadge(isset($selected['sps_status']) ? (int) $selected['sps_status'] : null, \App\Enums\SPSStatus::class))
            @php($spm = $statusBadge(isset($selected['spm_status']) ? (int) $selected['spm_status'] : null, \App\Enums\SPMStatus::class))

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    wire:click="clearRowSelection"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 min-[900px]:hidden"
                    title="Back to submissions">
                    <span class="material-symbols-outlined text-[#434654]">arrow_back</span>
                </button>
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-widest text-[#890f00]">Repair Details</p>
                    <h3 class="truncate text-base font-bold text-[#191c1e]">{{ $selected['plate_number'] ?? 'Repair' }}</h3>
                </div>
                @if (($selected['sync_status'] ?? '') !== 'synced')
                    <span class="ml-auto shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Pending Sync</span>
                @endif
            </div>

            <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="text-xs font-black uppercase tracking-wider text-[#737685]">Header</h4>
                @foreach ([
                    ['Plate Number', $selected['plate_number'] ?? '—'],
                    ['Invoice Number', $selected['invoice_number'] ?? '—'],
                    ['Amount', '₱'.($selected['amount'] ?? '—')],
                    ['Sales Call', $selected['sales_call'] ?? '—'],
                    ['SAP Document Number', $selected['sap_doc_number'] ?? '—'],
                ] as $field)
                    <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                        <span class="text-xs font-bold text-[#737685]">{{ $field[0] }}</span>
                        <span class="whitespace-pre-line break-words text-right text-sm text-[#191c1e]">{{ $field[1] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="text-xs font-black uppercase tracking-wider text-[#737685]">Details</h4>
                <p class="mt-2 whitespace-pre-line break-words text-sm text-[#191c1e]">{{ $selected['details'] ?? '—' }}</p>
                @if (! empty($selected['remarks']))
                    <p class="mt-3 text-xs font-bold text-[#737685]">Remarks</p>
                    <p class="mt-1 whitespace-pre-line break-words text-sm text-[#191c1e]">{{ $selected['remarks'] }}</p>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="mb-3 text-xs font-black uppercase tracking-wider text-[#737685]">Attachments</h4>
                @forelse (($selected['attachments'] ?? []) as $attachment)
                    <div class="flex items-start justify-between gap-4 border-b border-gray-100 py-2 last:border-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-[#737685]">{{ $attachment['type_label'] ?? '—' }}</p>
                            <p class="truncate text-sm text-[#191c1e]">{{ $attachment['original_name'] ?? '—' }}</p>
                            @if (! empty($attachment['url']))
                                <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener" class="mt-1 block h-14 w-24 overflow-hidden rounded-lg border border-gray-200">
                                    <img src="{{ $attachment['url'] }}" alt="{{ $attachment['original_name'] ?? 'Attachment' }}" class="h-full w-full object-cover">
                                </a>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-[#737685]">No attachments recorded.</p>
                @endforelse
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="mb-3 text-xs font-black uppercase tracking-wider text-[#737685]">Approval Status</h4>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ $rsm['class'] }}">
                        RSM: {{ $rsm['title'] }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ $sps['class'] }}">
                        SPS: {{ $sps['title'] }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ $spm['class'] }}">
                        SPM: {{ $spm['title'] }}
                    </span>
                </div>
            </div>

            @if ($selected['is_editable'] ?? false)
                <button
                    type="button"
                    wire:click="editSelectedRow"
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-full bg-[#890f00] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#6f0c00]">
                    <span class="material-symbols-outlined text-lg">edit</span>
                    <span>Edit Repair</span>
                </button>
            @endif
        @else
            <div class="flex min-h-64 items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white px-6 text-center">
                <p class="text-sm text-[#737685]">Select a Repair to view its details.</p>
            </div>
        @endif
    </div>
</div>
