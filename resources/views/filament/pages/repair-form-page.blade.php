<x-filament-panels::page>
    <div
        x-data="{
            repairSaving: false,
            repairAttachments: @entangle('attachments'),
            addRepairAttachmentFile(type, file) {
                if (! file) return;
                const extension = (file.name.split('.').pop() || '').toLowerCase();
                const mimeType = file.type || (extension === 'png' ? 'image/png' : 'image/jpeg');
                const reader = new FileReader();
                reader.onload = (event) => {
                    $wire.stageTypedAttachment(type, {
                        data: event.target.result,
                        original_name: file.name,
                        mime_type: mimeType,
                        byte_size: file.size,
                    });
                };
                reader.readAsDataURL(file);
            },
            async saveRepairForm() {
                if (this.repairSaving) return;
                if (! this.$refs.repairEntryForm.reportValidity()) return;
                this.repairSaving = true;
                await $wire.save();
                this.repairSaving = false;
            },
            cancelRepair() {
                if (Object.values(this.repairAttachments).some((list) => (list || []).length > 0)) {
                    if (! window.confirm('Cancel this Repair without saving?')) return;
                }
                $wire.cancel();
            },
        }"
        class="space-y-6 pb-8"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">Forms · Repair</p>
                <h1 class="text-2xl font-extrabold text-[#191c1e]">{{ $isEdit ? 'Edit Repair' : 'Add Repair' }}</h1>
            </div>
            <button type="button" @click="cancelRepair()" class="h-10 px-4 rounded-xl border border-gray-200 text-[#434654] font-bold">Back</button>
        </div>

        <form x-ref="repairEntryForm" @submit.prevent="saveRepairForm()" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label">Plate Number *</label>
                    <input type="text" required wire:model="plate_number" class="field-input" placeholder="ABC 1234">
                    <p class="field-error">{{ $formErrors['plate_number'][0] ?? '' }}</p>
                </div>
                <div>
                    <label class="field-label">Invoice Number</label>
                    <input type="text" wire:model="invoice_number" class="field-input" placeholder="Optional">
                    <p class="field-error">{{ $formErrors['invoice_number'][0] ?? '' }}</p>
                </div>
            </div>

            <div>
                <label class="field-label">Amount *</label>
                <input type="text" inputmode="decimal" pattern="([0-9]+([.][0-9]{0,2})?|[.][0-9]{1,2})" required wire:model="amount" class="field-input" placeholder="0.00" autocomplete="off">
                <p class="field-error">{{ $formErrors['amount'][0] ?? '' }}</p>
            </div>

            <div>
                <label class="field-label">Sales Call</label>
                <select wire:model="salescall_uuid" class="field-input bg-white">
                    <option value="">No linked sales call</option>
                    @foreach ($salescalls as $salescall)
                        <option value="{{ $salescall['uuid'] }}">{{ $salescall['label'] }}</option>
                    @endforeach
                </select>
                <p class="field-error">{{ $formErrors['salescall_uuid'][0] ?? '' }}</p>
            </div>

            <div>
                <label class="field-label">Details *</label>
                <textarea rows="3" required wire:model="details" class="field-input"></textarea>
                <p class="field-error">{{ $formErrors['details'][0] ?? '' }}</p>
            </div>

            <div>
                <label class="field-label">Remarks</label>
                <textarea rows="3" wire:model="remarks" class="field-input"></textarea>
                <p class="field-error">{{ $formErrors['remarks'][0] ?? '' }}</p>
            </div>

            @foreach (\App\Enums\Repair\RepairAttachmentType::cases() as $attachmentType)
                @php($typeValue = $attachmentType->value)
                @php($typeAttachments = $attachments[$typeValue] ?? [])

                <div class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-black text-[#737685] uppercase tracking-wider">{{ $attachmentType->meta('title') }}</p>
                            <p class="text-xs text-[#737685] mt-1">Optional document photos · <span x-text="(repairAttachments[{{ $typeValue }}] || []).length"></span> added</p>
                        </div>
                        <span class="material-symbols-outlined text-[#737685]">attach_file</span>
                    </div>
                    <input type="file" x-ref="repairCameraInput{{ $typeValue }}" accept="image/jpeg,image/png" capture="environment" class="hidden" @change="addRepairAttachmentFile({{ $typeValue }}, $event.target.files[0]); $event.target.value = ''">
                    <input type="file" x-ref="repairGalleryInput{{ $typeValue }}" accept="image/jpeg,image/png,image/*" class="hidden" @change="addRepairAttachmentFile({{ $typeValue }}, $event.target.files[0]); $event.target.value = ''">
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.takeAttachmentPhoto({{ $typeValue }}); } else { $refs.repairCameraInput{{ $typeValue }}.click(); }" class="attachment-button">
                            <span class="material-symbols-outlined text-xl">photo_camera</span>
                            <span>Camera</span>
                        </button>
                        <button type="button" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.pickAttachmentFromGallery({{ $typeValue }}); } else { $refs.repairGalleryInput{{ $typeValue }}.click(); }" class="attachment-button">
                            <span class="material-symbols-outlined text-xl">photo_library</span>
                            <span>Gallery</span>
                        </button>
                    </div>
                    <div class="space-y-2">
                        @foreach ($typeAttachments as $index => $attachment)
                            <div class="flex items-center gap-3 border border-gray-200 rounded-xl p-3 bg-white">
                                <div class="w-10 h-10 rounded-lg bg-gray-50 border border-gray-200 flex items-center justify-center shrink-0 overflow-hidden">
                                    @if (! empty($attachment['url']))
                                        <img src="{{ $attachment['url'] }}" class="w-full h-full object-cover" alt="Attachment preview">
                                    @else
                                        <span class="material-symbols-outlined text-[#737685] text-xl">image</span>
                                    @endif
                                </div>
                                <span class="flex-1 min-w-0 text-xs font-medium text-[#191c1e] truncate">{{ $attachment['original_name'] ?? 'Attachment' }}</span>
                                <button type="button" wire:click="removeAttachment({{ $typeValue }}, {{ $index }})" class="text-red-400 shrink-0" title="Remove attachment">
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <p class="field-error">{{ $formErrors['attachments'][0] ?? '' }}</p>

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800">At least one attachment is required. This Repair and its photos will be saved locally and remain pending synchronization.</div>
            @if (isset($formErrors['repair']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['repair'][0] }}</div>
            @endif
            @if (isset($formErrors['form']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['form'][0] }}</div>
            @endif
            <div class="grid grid-cols-2 gap-3 pt-1">
                <button type="button" @click="cancelRepair()" class="h-12 rounded-xl border border-gray-200 text-[#434654] font-bold">Cancel</button>
                <button type="submit" :disabled="repairSaving" class="h-12 rounded-xl bg-[#890f00] text-white font-bold disabled:opacity-50">
                    <span x-show="! repairSaving">Save Locally</span>
                    <span x-show="repairSaving">Saving…</span>
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
