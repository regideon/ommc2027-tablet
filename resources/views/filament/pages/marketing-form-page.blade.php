<x-filament-panels::page>
    <div
        x-data="{
            marketingSaving: false,
            async saveMarketingForm() {
                if (this.marketingSaving) return;
                if (! this.$refs.marketingEntryForm.reportValidity()) return;
                this.marketingSaving = true;
                await $wire.save();
                this.marketingSaving = false;
            },
            cancelMarketing() {
                $wire.cancel();
            },
        }"
        class="space-y-6 pb-8"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">Forms · Marketing</p>
                <h1 class="text-2xl font-extrabold text-[#191c1e]">{{ $isEdit ? 'Edit Marketing Recommendation' : 'Add Marketing Recommendation' }}</h1>
            </div>
            <button type="button" @click="cancelMarketing()" class="h-10 px-4 rounded-xl border border-gray-200 text-[#434654] font-bold">Back</button>
        </div>

        <form x-ref="marketingEntryForm" @submit.prevent="saveMarketingForm()" class="space-y-4">
            @if ($programLoadError !== '')
                <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ $programLoadError }}
                </div>
            @endif

            <div>
                <label class="field-label">Name <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" class="field-input">
                <p class="field-error">{{ $formErrors['name'][0] ?? '' }}</p>
            </div>

            <div>
                <label class="field-label">Program <span class="text-red-500">*</span></label>
                <select wire:model="program_id" class="field-input bg-white">
                    <option value="">Select a program</option>
                    @foreach ($programs as $program)
                        <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                    @endforeach
                </select>
                <p class="field-error">{{ $formErrors['program_id'][0] ?? '' }}</p>
            </div>

            <div>
                <label class="field-label">Region Type <span class="text-red-500">*</span></label>
                <select wire:model="region_type_id" class="field-input bg-white">
                    <option value="">Select a region type</option>
                    @foreach ($regionTypes as $regionType)
                        <option value="{{ $regionType['id'] }}">{{ $regionType['name'] }}</option>
                    @endforeach
                </select>
                <p class="field-error">{{ $formErrors['region_type_id'][0] ?? '' }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label">Duration Start <span class="text-red-500">*</span></label>
                    <input type="date" wire:model="duration_start_at" class="field-input">
                    <p class="field-error">{{ $formErrors['duration_start_at'][0] ?? '' }}</p>
                </div>
                <div>
                    <label class="field-label">Duration End <span class="text-red-500">*</span></label>
                    <input type="date" wire:model="duration_end_at" class="field-input">
                    <p class="field-error">{{ $formErrors['duration_end_at'][0] ?? '' }}</p>
                </div>
            </div>

            <div>
                <label class="field-label">Sales Call</label>
                <select wire:model="salescall_uuid" class="field-input bg-white" @disabled($salescallLocked)>
                    <option value="">No linked sales call</option>
                    @foreach ($salescalls as $salescall)
                        <option value="{{ $salescall['uuid'] }}">{{ $salescall['label'] }}</option>
                    @endforeach
                </select>
                @if ($salescallLocked)
                    <p class="text-xs text-[#737685] mt-1">Started from this Sales Call — locked to this visit.</p>
                @endif
                <p class="field-error">{{ $formErrors['salescall_uuid'][0] ?? '' }}</p>
            </div>

            <div>
                <label class="field-label">Objective <span class="text-red-500">*</span></label>
                <textarea rows="3" wire:model="objective" class="field-input"></textarea>
                <p class="field-error">{{ $formErrors['objective'][0] ?? '' }}</p>
            </div>

            <div>
                <label class="field-label">Mechanics Description <span class="text-red-500">*</span></label>
                <textarea rows="3" wire:model="mechanics_description" class="field-input"></textarea>
                <p class="field-error">{{ $formErrors['mechanics_description'][0] ?? '' }}</p>
            </div>

            <div class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Volumes</p>
                    <button type="button" wire:click="addVolume" class="text-xs font-bold text-[#890f00]">+ Add Volume</button>
                </div>

                @foreach ($volumes as $index => $volume)
                    <div wire:key="volume-{{ $index }}" class="space-y-3 border border-gray-200 rounded-xl p-3 bg-white">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-[#737685]">Row {{ $index + 1 }}</span>
                            <button type="button" wire:click="removeVolume({{ $index }})" class="text-red-400 shrink-0"><span class="material-symbols-outlined text-lg">delete</span></button>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="field-label">Before Program <span class="text-red-500">*</span></label>
                                <input type="text" inputmode="decimal" wire:model="volumes.{{ $index }}.before_program" class="field-input">
                                <p class="field-error">{{ $formErrors["volumes.{$index}.before_program"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">During Program <span class="text-red-500">*</span></label>
                                <input type="text" inputmode="decimal" wire:model="volumes.{{ $index }}.during_program" class="field-input">
                                <p class="field-error">{{ $formErrors["volumes.{$index}.during_program"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">Volume</label>
                                <input type="text" inputmode="decimal" wire:model="volumes.{{ $index }}.volume" class="field-input">
                                <p class="field-error">{{ $formErrors["volumes.{$index}.volume"][0] ?? '' }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Realizations</p>
                    <button type="button" wire:click="addRealization" class="text-xs font-bold text-[#890f00]">+ Add Realization</button>
                </div>

                @foreach ($realizations as $index => $realization)
                    <div wire:key="realization-{{ $index }}" class="space-y-3 border border-gray-200 rounded-xl p-3 bg-white">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-[#737685]">Row {{ $index + 1 }}</span>
                            <button type="button" wire:click="removeRealization({{ $index }})" class="text-red-400 shrink-0"><span class="material-symbols-outlined text-lg">delete</span></button>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Stage <span class="text-red-500">*</span></label>
                                <select wire:model="realizations.{{ $index }}.stage" class="field-input bg-white">
                                    <option value="">Select a stage</option>
                                    @foreach (\App\Enums\Marketing\RealizationStage::cases() as $stageCase)
                                        <option value="{{ $stageCase->value }}">{{ $stageCase->meta('title') }}</option>
                                    @endforeach
                                </select>
                                <p class="field-error">{{ $formErrors["realizations.{$index}.stage"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">Volume <span class="text-red-500">*</span></label>
                                <input type="text" inputmode="decimal" wire:model="realizations.{{ $index }}.volume" class="field-input">
                                <p class="field-error">{{ $formErrors["realizations.{$index}.volume"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">Start <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="realizations.{{ $index }}.start_at" class="field-input">
                                <p class="field-error">{{ $formErrors["realizations.{$index}.start_at"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">End <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="realizations.{{ $index }}.end_at" class="field-input">
                                <p class="field-error">{{ $formErrors["realizations.{$index}.end_at"][0] ?? '' }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Mechanics</p>
                    <button type="button" wire:click="addMechanic" class="text-xs font-bold text-[#890f00]">+ Add Mechanic</button>
                </div>

                @foreach ($mechanics as $index => $mechanic)
                    <div wire:key="mechanic-{{ $index }}" class="space-y-3 border border-gray-200 rounded-xl p-3 bg-white">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-[#737685]">Row {{ $index + 1 }}</span>
                            <button type="button" wire:click="removeMechanic({{ $index }})" class="text-red-400 shrink-0"><span class="material-symbols-outlined text-lg">delete</span></button>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="field-label">Period <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="mechanics.{{ $index }}.period" class="field-input">
                                <p class="field-error">{{ $formErrors["mechanics.{$index}.period"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">Unit Price <span class="text-red-500">*</span></label>
                                <input type="text" inputmode="decimal" wire:model="mechanics.{{ $index }}.unit_price" class="field-input">
                                <p class="field-error">{{ $formErrors["mechanics.{$index}.unit_price"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">Sub Total <span class="text-red-500">*</span></label>
                                <input type="text" inputmode="decimal" wire:model="mechanics.{{ $index }}.sub_total" class="field-input">
                                <p class="field-error">{{ $formErrors["mechanics.{$index}.sub_total"][0] ?? '' }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800">This Marketing Recommendation will be saved locally and remain pending synchronization.</div>
            @if (isset($formErrors['marketing']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['marketing'][0] }}</div>
            @endif
            @if (isset($formErrors['form']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['form'][0] }}</div>
            @endif
            <div class="grid grid-cols-2 gap-3 pt-1">
                <button type="button" @click="cancelMarketing()" class="h-12 rounded-xl border border-gray-200 text-[#434654] font-bold">Cancel</button>
                <button type="submit" :disabled="marketingSaving" class="h-12 rounded-xl bg-[#890f00] text-white font-bold disabled:opacity-50">
                    <span x-show="! marketingSaving">Save Locally</span>
                    <span x-show="marketingSaving">Saving…</span>
                </button>
            </div>
        </form>
    </div>

    <style>
        .field-label { display: block; margin-bottom: .25rem; font-size: .75rem; font-weight: 700; color: #737685; }
        .field-input { width: 100%; border: 1px solid #e5e7eb; border-radius: .75rem; padding: .625rem .75rem; font-size: .875rem; }
        .field-error { margin-top: .25rem; font-size: .75rem; color: #dc2626; }
    </style>
</x-filament-panels::page>
