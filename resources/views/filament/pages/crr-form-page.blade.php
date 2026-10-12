<x-filament-panels::page>
    <div
        x-data="{
            crrSaving: false,
            crrAttachments: @entangle('attachments'),
            crrAttachmentError: @js($formErrors['attachments'][0] ?? ''),
            lastValidCrrAmount: @js((string) ($amount ?? '')),
            crrAmountInputError: '',
            onCrrAmountInput(event) {
                const input = event.target;
                const candidate = input.value;
                const isAllowedPartialAmount = /^(?:\d+(?:\.\d{0,2})?|\.\d{0,2})?$/.test(candidate);

                if (isAllowedPartialAmount) {
                    this.lastValidCrrAmount = candidate;
                    this.crrAmountInputError = '';
                } else {
                    input.value = this.lastValidCrrAmount;
                    $wire.set('amount', this.lastValidCrrAmount);
                    this.crrAmountInputError = 'Use digits and an optional decimal point with at most two decimal places.';
                }

                const amount = input.value;
                const isNonPositive = amount !== '' && Number.isFinite(Number(amount)) && Number(amount) <= 0;
                input.setCustomValidity(this.crrAmountInputError || (isNonPositive ? 'Amount must be greater than zero.' : ''));
            },
            addCrrAttachmentFile(file) {
                this.crrAttachmentError = '';
                if (!file) return;
                if (this.crrAttachments.length >= 10) {
                    this.crrAttachmentError = 'A CRR may have at most 10 attachments.';
                    return;
                }
                const extension = (file.name.split('.').pop() || '').toLowerCase();
                const allowed = ['image/jpeg', 'image/png', 'application/pdf'];
                if (!allowed.includes(file.type) && !['jpg', 'jpeg', 'png', 'pdf'].includes(extension)) {
                    this.crrAttachmentError = 'Only JPEG, PNG, and PDF attachments are supported.';
                    return;
                }
                const reader = new FileReader();
                reader.onload = (event) => {
                    $wire.addAttachment({
                        data: event.target.result,
                        original_name: file.name,
                        mime_type: file.type || (extension === 'pdf' ? 'application/pdf' : extension === 'png' ? 'image/png' : 'image/jpeg'),
                        byte_size: file.size,
                    });
                };
                reader.onerror = () => { this.crrAttachmentError = 'The selected attachment could not be read.'; };
                reader.readAsDataURL(file);
            },
            async saveCrrForm() {
                if (this.crrSaving) return;
                if (! this.$refs.crrEntryForm.reportValidity()) return;
                this.crrSaving = true;
                await $wire.save();
                this.crrSaving = false;
            },
            cancelCrr() {
                if (this.crrAttachments.length) {
                    if (! window.confirm('Cancel this CRR without saving?')) return;
                }
                $wire.cancel();
            },
        }"
        x-init="
            window.addEventListener('crr-attachment-ready', (event) => {
                const attachment = event.detail?.attachment;
                if (attachment?.data) $wire.addAttachment(attachment);
            });
        "
        class="space-y-6 pb-8"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">Forms · CRR</p>
                <h1 class="text-2xl font-extrabold text-[#191c1e]">{{ $isEdit ? 'Edit CRR' : 'Add CRR' }}</h1>
            </div>
            <button type="button" @click="cancelCrr()" class="h-10 px-4 rounded-xl border border-gray-200 text-[#434654] font-bold">Back</button>
        </div>

        <form x-ref="crrEntryForm" @submit.prevent="saveCrrForm()" class="space-y-4">
            @if ($programLoadError !== '')
                <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ $programLoadError }}
                </div>
            @endif

            <div>
                <label class="field-label">CRR Program *</label>
                <select wire:model="crr_program_id" class="field-input bg-white">
                    <option value="">Select a program</option>
                    @foreach ($programs as $program)
                        <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                    @endforeach
                </select>
                <p class="field-error">@if (isset($formErrors['crr_program_id'])){{ $formErrors['crr_program_id'][0] }}@endif</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label">Date Implemented</label>
                    <input type="date" wire:model="date_implemented" class="field-input">
                    <p class="field-error">@if (isset($formErrors['date_implemented'])){{ $formErrors['date_implemented'][0] }}@endif</p>
                </div>
                <div>
                    <label class="field-label">Amount *</label>
                    <input type="text" inputmode="decimal" pattern="([0-9]+([.][0-9]{0,2})?|[.][0-9]{1,2})" required wire:model="amount" @input="onCrrAmountInput($event)" class="field-input" autocomplete="off">
                    <p class="field-error"><span x-text="crrAmountInputError"></span>@if (isset($formErrors['amount'])){{ $formErrors['amount'][0] }}@endif</p>
                </div>
            </div>

            <div>
                <label class="field-label">Sales Call</label>
                <select wire:model="salescall_uuid" class="field-input bg-white">
                    <option value="">No linked sales call</option>
                    @foreach ($salescalls as $salescall)
                        <option value="{{ $salescall['uuid'] }}">{{ $salescall['label'] }}</option>
                    @endforeach
                </select>
                <p class="field-error">@if (isset($formErrors['salescall_uuid'])){{ $formErrors['salescall_uuid'][0] }}@endif</p>
            </div>

            <div>
                <label class="field-label">Details</label>
                <textarea rows="3" wire:model="details" class="field-input"></textarea>
                <p class="field-error">@if (isset($formErrors['details'])){{ $formErrors['details'][0] }}@endif</p>
            </div>

            <div>
                <label class="field-label">Remarks</label>
                <textarea rows="3" wire:model="remarks" class="field-input"></textarea>
                <p class="field-error">@if (isset($formErrors['remarks'])){{ $formErrors['remarks'][0] }}@endif</p>
            </div>

            <div class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Attachments</p>
                        <p class="text-xs text-[#737685] mt-1">Optional · <span x-text="crrAttachments.length"></span> / 10</p>
                    </div>
                    <span class="material-symbols-outlined text-[#737685]">attach_file</span>
                </div>
                <input type="file" x-ref="crrCameraInput" accept="image/jpeg,image/png" capture="environment" class="hidden" @change="addCrrAttachmentFile($event.target.files[0]); $event.target.value = ''">
                <input type="file" x-ref="crrGalleryInput" accept="image/jpeg,image/png,image/*" class="hidden" @change="addCrrAttachmentFile($event.target.files[0]); $event.target.value = ''">
                <input type="file" x-ref="crrFilesInput" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" class="hidden" @change="addCrrAttachmentFile($event.target.files[0]); $event.target.value = ''">
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" :disabled="crrAttachments.length >= 10" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.takeAttachmentPhoto(); } else { $refs.crrCameraInput.click(); }" class="attachment-button"><span class="material-symbols-outlined text-xl">photo_camera</span><span>Camera</span></button>
                    <button type="button" :disabled="crrAttachments.length >= 10" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.pickAttachmentFromGallery(); } else { $refs.crrGalleryInput.click(); }" class="attachment-button"><span class="material-symbols-outlined text-xl">photo_library</span><span>Gallery</span></button>
                    <button type="button" :disabled="crrAttachments.length >= 10" @click="$refs.crrFilesInput.click()" class="attachment-button"><span class="material-symbols-outlined text-xl">folder_open</span><span>Files</span></button>
                </div>
                <p x-show="crrAttachmentError" class="text-xs text-red-600" x-text="crrAttachmentError"></p>
                @if (isset($formErrors['attachments']))
                    <p class="text-xs text-red-600">{{ $formErrors['attachments'][0] }}</p>
                @endif
                <div class="space-y-2">
                    <template x-for="(attachment, index) in crrAttachments" :key="index">
                        <div class="flex items-center gap-3 border border-gray-200 rounded-xl p-3 bg-white">
                            <div class="w-10 h-10 rounded-lg bg-gray-50 border border-gray-200 flex items-center justify-center shrink-0 overflow-hidden">
                                <template x-if="attachment.mime_type !== 'application/pdf'"><img :src="attachment.data" class="w-full h-full object-cover"></template>
                                <template x-if="attachment.mime_type === 'application/pdf'"><span class="material-symbols-outlined text-red-500 text-xl">picture_as_pdf</span></template>
                            </div>
                            <span class="flex-1 min-w-0 text-xs font-medium text-[#191c1e] truncate" x-text="attachment.original_name"></span>
                            <button type="button" @click="$wire.removeAttachment(index)" class="text-red-400 shrink-0"><span class="material-symbols-outlined text-lg">delete</span></button>
                        </div>
                    </template>
                </div>
            </div>

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800">This CRR and any attachments will be saved locally and remain pending synchronization.</div>
            @if (isset($formErrors['form']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['form'][0] }}</div>
            @endif
            <div class="grid grid-cols-2 gap-3 pt-1">
                <button type="button" @click="cancelCrr()" class="h-12 rounded-xl border border-gray-200 text-[#434654] font-bold">Cancel</button>
                <button type="submit" :disabled="crrSaving" class="h-12 rounded-xl bg-[#890f00] text-white font-bold disabled:opacity-50">
                    <span x-show="! crrSaving">Save Locally</span>
                    <span x-show="crrSaving">Saving…</span>
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
