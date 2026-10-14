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

    $answerLabel = function (array $item): string {
        $answer = $item['answer'] ?? null;

        if ($answer === null || $answer === '') {
            return '—';
        }

        if ((int) ($item['type'] ?? 0) === \App\Enums\TradeCheck\FieldType::BOOLEAN->value) {
            return match ((string) $answer) {
                '1' => 'Yes',
                '0' => 'No',
                default => (string) $answer,
            };
        }

        return (string) $answer;
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
                <h3 class="text-base font-bold text-[#191c1e]">Trade Check</h3>
                <p class="text-xs text-[#737685]">Your Trade Checks</p>
            </div>
            <div class="flex shrink-0 items-center gap-2 ml-auto">
                <button type="button" wire:click="syncNow" @disabled($syncing) class="flex h-9 items-center gap-1.5 rounded-full bg-[#890f00] px-3 text-xs font-semibold text-white transition-colors hover:bg-[#6f0c00] disabled:opacity-50" title="Sync pending Trade Checks and refresh the list">
                    <span class="material-symbols-outlined text-lg {{ $syncing ? 'animate-spin' : '' }}">{{ $syncing ? 'sync' : 'refresh' }}</span>
                    <span class="hidden sm:inline">{{ $syncing ? 'Syncing…' : 'Sync' }}</span>
                </button>
                <a
                    href="{{ \App\Filament\Pages\TradeCheckFormPage::getUrl() }}"
                    class="inline-flex items-center gap-1.5 rounded-full bg-[#890f00] px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-[#6f0c00]">
                    <span class="material-symbols-outlined mat-fill text-lg">add_circle</span>
                    <span class="hidden sm:inline">Create Trade Check</span>
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
                @php($tdh = $statusBadge(isset($row['tdh_status']) ? (int) $row['tdh_status'] : null, \App\Enums\TDHStatus::class))
                <button
                    type="button"
                    wire:click="selectRow('{{ $key }}')"
                    aria-pressed="{{ $selectedRowKey === $key ? 'true' : 'false' }}"
                    class="w-full min-h-[88px] border-l-4 px-4 py-3 text-left transition-colors {{ $selectedRowKey === $key ? 'border-[#890f00] bg-red-50' : 'border-transparent bg-white hover:bg-red-50' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-[#191c1e]">{{ $row['name'] ?? 'Trade Check' }}</p>
                            <p class="mt-1 truncate text-xs text-[#434654]">{{ $row['sales_call'] ?? '—' }}</p>
                        </div>
                        <div class="flex max-w-[45%] shrink-0 flex-col items-end gap-1">
                            @if (($row['has_submission'] ?? false) === false)
                                <span class="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-700">Not submitted</span>
                            @else
                                <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $rsm['class'] }}">RSM: {{ $rsm['title'] }}</span>
                                <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $tdh['class'] }}">TDH: {{ $tdh['title'] }}</span>
                                @if (($row['sync_status'] ?? '') !== 'synced')
                                    <span class="inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Pending Sync</span>
                                @endif
                            @endif
                        </div>
                    </div>
                </button>
            @empty
                <p class="bg-gray-50 py-10 text-center text-sm text-[#737685]">No Trade Check submissions found.</p>
            @endforelse
        </div>
    </div>

    {{-- Detail panel --}}
    <div class="{{ $selected === null ? 'hidden min-[900px]:block' : '' }} min-w-0 space-y-4 min-[900px]:max-h-[calc(100dvh-11rem)] min-[900px]:overflow-y-auto min-[900px]:overscroll-contain" style="-webkit-overflow-scrolling: touch;">
        @if ($selected !== null)
            @php($rsm = $statusBadge(isset($selected['rsm_status']) ? (int) $selected['rsm_status'] : null, \App\Enums\RSMStatus::class))
            @php($tdh = $statusBadge(isset($selected['tdh_status']) ? (int) $selected['tdh_status'] : null, \App\Enums\TDHStatus::class))

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    wire:click="clearRowSelection"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 min-[900px]:hidden"
                    title="Back to submissions">
                    <span class="material-symbols-outlined text-[#434654]">arrow_back</span>
                </button>
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-widest text-[#890f00]">Trade Check Details</p>
                    <h3 class="truncate text-base font-bold text-[#191c1e]">{{ $selected['name'] ?? 'Trade Check' }}</h3>
                </div>
                @if (($selected['sync_status'] ?? '') !== 'synced')
                    <span class="ml-auto shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Pending Sync</span>
                @endif
            </div>

            <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="text-xs font-black uppercase tracking-wider text-[#737685]">Header</h4>
                @foreach ([
                    ['Name', $selected['name'] ?? '—'],
                    ['Sales Call', $selected['sales_call'] ?? '—'],
                ] as $field)
                    <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                        <span class="text-xs font-bold text-[#737685]">{{ $field[0] }}</span>
                        <span class="whitespace-pre-line break-words text-right text-sm text-[#191c1e]">{{ $field[1] }}</span>
                    </div>
                @endforeach
            </div>

            @if (($selected['has_submission'] ?? false) === false)
                <a
                    href="{{ \App\Filament\Pages\TradeCheckFormPage::getUrl(['template' => $selected['id']]) }}"
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-full bg-[#890f00] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#6f0c00]">
                    <span class="material-symbols-outlined text-lg">play_arrow</span>
                    <span>Start Trade Check</span>
                </a>
            @else
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <h4 class="mb-3 text-xs font-black uppercase tracking-wider text-[#737685]">Approval Status</h4>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ $rsm['class'] }}">
                        RSM: {{ $rsm['title'] }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ $tdh['class'] }}">
                        TDH: {{ $tdh['title'] }}
                    </span>
                </div>
            </div>

            @forelse (($selected['groups'] ?? []) as $group)
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <p class="text-[10px] font-black uppercase tracking-widest text-[#890f00]">{{ $group['category_label'] ?? 'Group' }}</p>
                    <h4 class="mb-1 text-sm font-bold text-[#191c1e]">{{ $group['name'] ?? '—' }}</h4>
                    @if (! empty($group['description']))
                        <p class="mb-3 text-xs text-[#737685]">{{ $group['description'] }}</p>
                    @endif

                    <div class="divide-y divide-gray-100">
                        @forelse (($group['items'] ?? []) as $item)
                            <div class="py-2 first:pt-0 last:pb-0">
                                <div class="flex items-start justify-between gap-4">
                                    <span class="text-xs font-bold text-[#737685]">{{ $item['name'] ?? '—' }}</span>
                                    <span class="whitespace-pre-line break-words text-right text-sm text-[#191c1e]">{{ $answerLabel($item) }}</span>
                                </div>
                                @if (! empty($item['attachments']))
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        @foreach ($item['attachments'] as $attachment)
                                            @if (! empty($attachment['url']) && str_starts_with((string) ($attachment['mime_type'] ?? ''), 'image/'))
                                                <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener" class="block h-12 w-12 overflow-hidden rounded-lg border border-gray-200">
                                                    <img src="{{ $attachment['url'] }}" alt="{{ $attachment['original_name'] ?? 'Attachment' }}" class="h-full w-full object-cover">
                                                </a>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2 py-1 text-xs text-[#737685]">
                                                    <span class="material-symbols-outlined text-sm">attach_file</span>
                                                    <span class="max-w-[10rem] truncate">{{ $attachment['original_name'] ?? 'Attachment' }}</span>
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="py-2 text-sm text-[#737685]">No items recorded.</p>
                        @endforelse
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-gray-200 bg-white p-4">
                    <p class="text-sm text-[#737685]">No groups recorded.</p>
                </div>
            @endforelse

            @if ($selected['is_editable'] ?? false)
                <button
                    type="button"
                    wire:click="editSelectedRow"
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-full bg-[#890f00] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#6f0c00]">
                    <span class="material-symbols-outlined text-lg">edit</span>
                    <span>Edit Trade Check</span>
                </button>
            @endif
            @endif
        @else
            <div class="flex min-h-64 items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white px-6 text-center">
                <p class="text-sm text-[#737685]">Select a Trade Check to view its details.</p>
            </div>
        @endif
    </div>
</div>
