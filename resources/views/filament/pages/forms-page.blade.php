<x-filament-panels::page>
    {{-- Flexed split: form rail on the left, the selected form on the right --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
        {{-- Left: one card per form --}}
        <aside class="flex w-full shrink-0 flex-col gap-2 sm:w-40 md:w-44 lg:w-56">
            @foreach (\App\Enums\FormKey::cases() as $form)
                @php($isActive = $selectedForm === $form->slug())

                @if ($form->available())
                    <button
                        type="button"
                        wire:click="selectForm('{{ $form->slug() }}')"
                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                        class="flex w-full items-center gap-3 rounded-2xl border px-4 py-3 text-left transition-colors {{ $isActive ? 'border-[#890f00] bg-red-50' : 'border-gray-200 bg-white hover:bg-red-50' }}">
                        <x-filament::icon :icon="$form->icon()" class="h-5 w-5 shrink-0 {{ $isActive ? 'text-[#890f00]' : 'text-[#434654]' }}" />
                        <span class="min-w-0 flex-1 truncate text-sm font-semibold {{ $isActive ? 'text-[#890f00]' : 'text-[#191c1e]' }}">{{ $form->label() }}</span>
                    </button>
                @else
                    <div aria-disabled="true" class="flex w-full items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 opacity-70">
                        <x-filament::icon :icon="$form->icon()" class="h-5 w-5 shrink-0 text-[#737685]" />
                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-[#737685]">{{ $form->label() }}</span>
                        <span class="shrink-0 rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#434654]">Coming soon</span>
                    </div>
                @endif
            @endforeach
        </aside>

        {{-- Right: the selected form's submissions list + detail --}}
        <section class="min-w-0 sm:flex-1">
            @if ($selectedForm === 'crr')
                @include('filament.pages.forms-crr')
            @elseif ($selectedForm === 'marketing')
                @include('filament.pages.forms-marketing')
            @elseif ($selectedForm === 'trade-check')
                @include('filament.pages.forms-trade-check')
            @elseif ($selectedForm === 'giveaway')
                @include('filament.pages.forms-giveaway')
            @elseif ($selectedForm === 'repair')
                @include('filament.pages.forms-repair')
            @elseif ($selectedForm === 'crr-signage')
                @include('filament.pages.forms-signage')
            @else
                <div class="flex min-h-64 items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white px-6 text-center">
                    <p class="text-sm text-[#737685]">This form is coming soon.</p>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
