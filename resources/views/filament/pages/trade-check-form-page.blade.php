<x-filament-panels::page>
    <div
        x-data="{
            tradeCheckSaving: false,
            tradeCheckAttachments: @entangle('attachments'),
            attachmentsFor(subgroupId) {
                return this.tradeCheckAttachments[subgroupId] || [];
            },
            addTradeCheckAttachmentFile(subgroupId, file) {
                if (! file) return;
                const extension = (file.name.split('.').pop() || '').toLowerCase();
                const mimeType = file.type || (extension === 'pdf' ? 'application/pdf' : extension === 'png' ? 'image/png' : 'image/jpeg');
                const reader = new FileReader();
                reader.onload = (event) => {
                    $wire.stageSubgroupAttachment(subgroupId, {
                        data: event.target.result,
                        original_name: file.name,
                        mime_type: mimeType,
                        byte_size: file.size,
                    });
                };
                reader.readAsDataURL(file);
            },
            async saveTradeCheckForm() {
                if (this.tradeCheckSaving) return;
                if (! this.$refs.tradeCheckEntryForm.reportValidity()) return;
                this.tradeCheckSaving = true;
                await $wire.save();
                this.tradeCheckSaving = false;
            },
            cancelTradeCheck() {
                if (Object.values(this.tradeCheckAttachments).some((list) => (list || []).length > 0)) {
                    if (! window.confirm('Cancel this Trade Check without saving?')) return;
                }
                $wire.cancel();
            },
        }"
        class="space-y-6 pb-8"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">Forms · Trade Check</p>
                <h1 class="text-2xl font-extrabold text-[#191c1e]">{{ $isEdit ? 'Edit Trade Check' : 'Add Trade Check' }}</h1>
            </div>
            <button type="button" @click="cancelTradeCheck()" class="h-10 px-4 rounded-xl border border-gray-200 text-[#434654] font-bold">Back</button>
        </div>

            <form x-ref="tradeCheckEntryForm" @submit.prevent="saveTradeCheckForm()" class="space-y-4">
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

                @foreach ($groups as $group)
                    <div wire:key="group-{{ $group['id'] }}" class="space-y-4 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                        <div>
                            <p class="text-xs font-black text-[#737685] uppercase tracking-wider">{{ $group['category_label'] }}</p>
                            <p class="text-sm font-bold text-[#191c1e]">{{ $group['name'] }}</p>
                            @if (! empty($group['description']))
                                <p class="text-xs text-[#737685] mt-1">{{ $group['description'] }}</p>
                            @endif
                        </div>

                        @foreach ($group['subgroups'] as $subgroup)
                            <div wire:key="subgroup-{{ $subgroup['id'] }}" class="space-y-3 p-3 bg-white border border-gray-200 rounded-xl">
                                <div>
                                    <p class="text-sm font-bold text-[#191c1e]">{{ $subgroup['name'] }}</p>
                                    @if (! empty($subgroup['description']))
                                        <p class="text-xs text-[#737685]">{{ $subgroup['description'] }}</p>
                                    @endif
                                </div>

                                <div class="space-y-3">
                                    @foreach ($subgroup['items'] as $item)
                                        <div wire:key="item-{{ $item['id'] }}" class="space-y-2 border-b border-gray-100 pb-3 last:border-0 last:pb-0">
                                            <div>
                                                <label class="field-label">{{ $item['name'] }} @if ($item['is_required'])<span class="text-red-500">*</span>@endif</label>
                                                @if (! empty($item['description']))
                                                    <p class="text-xs text-[#737685]">{{ $item['description'] }}</p>
                                                @endif
                                            </div>

                                            @switch($item['type'])
                                                @case(1)
                                                @case(4)
                                                    <fieldset>
                                                        <legend class="sr-only">{{ $item['name'] }}</legend>
                                                        <div class="flex flex-wrap gap-2">
                                                            @foreach ($item['options'] as $option)
                                                                <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-[#191c1e]">
                                                                    <input type="radio" wire:model="answers.{{ $item['id'] }}" value="{{ $option }}" class="h-4 w-4 text-[#890f00] focus:ring-[#890f00]">
                                                                    <span>{{ $option }}</span>
                                                                </label>
                                                            @endforeach
                                                        </div>
                                                    </fieldset>
                                                    @break
                                                @case(2)
                                                    <input type="text" wire:model="answers.{{ $item['id'] }}" class="field-input">
                                                    @break
                                                @case(3)
                                                    <input type="number" wire:model="answers.{{ $item['id'] }}" class="field-input">
                                                    @break
                                            @endswitch

                                            <p class="field-error">{{ $formErrors["answers.{$item['id']}"][0] ?? '' }}</p>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($subgroup['is_image_required'] || ! empty($attachments[$subgroup['id']] ?? []))
                                    <div class="space-y-3 p-3 bg-gray-50 border border-gray-200 rounded-xl">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Attachments</p>
                                                <p class="text-xs text-[#737685] mt-1">
                                                    @if ($subgroup['is_image_required'])
                                                        Required ·
                                                    @endif
                                                    <span x-text="attachmentsFor({{ $subgroup['id'] }}).length"></span> / 10
                                                </p>
                                            </div>
                                            <span class="material-symbols-outlined text-[#737685]">attach_file</span>
                                        </div>
                                        <input type="file" x-ref="tradeCheckCameraInput{{ $subgroup['id'] }}" accept="image/jpeg,image/png" capture="environment" class="hidden" @change="addTradeCheckAttachmentFile({{ $subgroup['id'] }}, $event.target.files[0]); $event.target.value = ''">
                                        <input type="file" x-ref="tradeCheckGalleryInput{{ $subgroup['id'] }}" accept="image/jpeg,image/png,image/*" class="hidden" @change="addTradeCheckAttachmentFile({{ $subgroup['id'] }}, $event.target.files[0]); $event.target.value = ''">
                                        <input type="file" x-ref="tradeCheckFilesInput{{ $subgroup['id'] }}" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" class="hidden" @change="addTradeCheckAttachmentFile({{ $subgroup['id'] }}, $event.target.files[0]); $event.target.value = ''">
                                        <div class="grid grid-cols-3 gap-2">
                                            <button type="button" :disabled="attachmentsFor({{ $subgroup['id'] }}).length >= 10" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.takeAttachmentPhoto({{ $subgroup['id'] }}); } else { $refs.tradeCheckCameraInput{{ $subgroup['id'] }}.click(); }" class="attachment-button"><span class="material-symbols-outlined text-xl">photo_camera</span><span>Camera</span></button>
                                            <button type="button" :disabled="attachmentsFor({{ $subgroup['id'] }}).length >= 10" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.pickAttachmentFromGallery({{ $subgroup['id'] }}); } else { $refs.tradeCheckGalleryInput{{ $subgroup['id'] }}.click(); }" class="attachment-button"><span class="material-symbols-outlined text-xl">photo_library</span><span>Gallery</span></button>
                                            <button type="button" :disabled="attachmentsFor({{ $subgroup['id'] }}).length >= 10" @click="$refs.tradeCheckFilesInput{{ $subgroup['id'] }}.click()" class="attachment-button"><span class="material-symbols-outlined text-xl">folder_open</span><span>Files</span></button>
                                        </div>
                                        <p class="field-error">{{ $formErrors["attachments.{$subgroup['id']}"][0] ?? '' }}</p>
                                        <div class="space-y-2">
                                            <template x-for="(attachment, index) in attachmentsFor({{ $subgroup['id'] }})" :key="attachment.id || index">
                                                <div class="flex items-center gap-3 border border-gray-200 rounded-xl p-3 bg-white">
                                                    <div class="w-10 h-10 rounded-lg bg-gray-50 border border-gray-200 flex items-center justify-center shrink-0 overflow-hidden">
                                                        <template x-if="attachment.mime_type !== 'application/pdf' && attachment.url"><img :src="attachment.url" class="w-full h-full object-cover"></template>
                                                        <template x-if="attachment.mime_type === 'application/pdf'"><span class="material-symbols-outlined text-red-500 text-xl">picture_as_pdf</span></template>
                                                    </div>
                                                    <span class="flex-1 min-w-0 text-xs font-medium text-[#191c1e] truncate" x-text="attachment.original_name"></span>
                                                    <button type="button" @click="$wire.removeSubgroupAttachment({{ $subgroup['id'] }}, index)" class="text-red-400 shrink-0"><span class="material-symbols-outlined text-lg">delete</span></button>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach

                <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800">This Trade Check and any attachments will be saved locally and remain pending synchronization.</div>
                @if (isset($formErrors['trade_check']))
                    <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['trade_check'][0] }}</div>
                @endif
                @if (isset($formErrors['trade_check_id']))
                    <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['trade_check_id'][0] }}</div>
                @endif
                @if (isset($formErrors['form']))
                    <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['form'][0] }}</div>
                @endif
                <div class="grid grid-cols-2 gap-3 pt-1">
                    <button type="button" @click="cancelTradeCheck()" class="h-12 rounded-xl border border-gray-200 text-[#434654] font-bold">Cancel</button>
                    <button type="submit" :disabled="tradeCheckSaving" class="h-12 rounded-xl bg-[#890f00] text-white font-bold disabled:opacity-50">
                        <span x-show="! tradeCheckSaving">Save</span>
                        <span x-show="tradeCheckSaving">Saving…</span>
                    </button>
                </div>
            </form>
    </div>

    <style>
        .field-label { display: block; margin-bottom: .25rem; font-size: .75rem; font-weight: 700; color: #737685; }
        .field-input { width: 100%; border: 1px solid #e5e7eb; border-radius: .75rem; padding: .625rem .75rem; font-size: .875rem; }
        .field-error { margin-top: .25rem; font-size: .75rem; color: #dc2626; }
        .attachment-button { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem; padding: .75rem; border: 2px solid #e5e7eb; border-radius: .75rem; color: #434654; font-size: .625rem; font-weight: 700; }
        .attachment-button:disabled { opacity: .4; }
    </style>
</x-filament-panels::page>
