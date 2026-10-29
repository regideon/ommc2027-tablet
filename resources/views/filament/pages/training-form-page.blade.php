<x-filament-panels::page>
    <div
        x-data="{
            trainingSaving: false,
            trainingAttachments: @entangle('attachments'),
            addTrainingAttachmentFile(type, file) {
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
            async saveTrainingForm() {
                if (this.trainingSaving) return;
                if (! this.$refs.trainingEntryForm.reportValidity()) return;
                this.trainingSaving = true;
                await $wire.save();
                this.trainingSaving = false;
            },
            cancelTraining() {
                if (Object.values(this.trainingAttachments).some((list) => (list || []).length > 0)) {
                    if (! window.confirm('Cancel this Training without saving?')) return;
                }
                $wire.cancel();
            },
        }"
        class="space-y-6 pb-8"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">Forms · Training</p>
                <h1 class="text-2xl font-extrabold text-[#191c1e]">{{ $isEdit ? 'Edit Training' : 'Add Training' }}</h1>
            </div>
            <button type="button" @click="cancelTraining()" class="h-10 px-4 rounded-xl border border-gray-200 text-[#434654] font-bold">Back</button>
        </div>

        @if ($masterLoadError !== '')
            <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $masterLoadError }}
            </div>
        @endif

        <form x-ref="trainingEntryForm" @submit.prevent="saveTrainingForm()" class="space-y-4">
            <div>
                <label class="field-label">Topic <span class="text-red-500">*</span></label>
                <select wire:model="topic_choice" class="field-input bg-white">
                    <option value="">Select a topic</option>
                    @foreach ($topics as $topic)
                        <option value="{{ $topic['id'] }}">{{ $topic['name'] }}</option>
                    @endforeach
                    <option value="other">Other (specify)</option>
                </select>
                <p class="field-error">{{ $formErrors['topic_id'][0] ?? '' }}</p>
            </div>

            @if ($topic_choice === 'other')
                <div>
                    <label class="field-label">Other Topic <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="other_topic" class="field-input" placeholder="Specify the training topic">
                    <p class="field-error">{{ $formErrors['other_topic'][0] ?? '' }}</p>
                </div>
            @endif

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="field-label">Preferred At <span class="text-red-500">*</span></label>
                    <input type="datetime-local" required wire:model="preferred_at" class="field-input">
                    <p class="field-error">{{ $formErrors['preferred_at'][0] ?? '' }}</p>
                </div>
                <div>
                    <label class="field-label">OMCC Volume</label>
                    <input type="number" min="0" step="1" wire:model="omcc_volume" class="field-input" placeholder="Optional">
                    <p class="field-error">{{ $formErrors['omcc_volume'][0] ?? '' }}</p>
                </div>
                <div>
                    <label class="field-label">Competitor Volume</label>
                    <input type="number" min="0" step="1" wire:model="competitor_volume" class="field-input" placeholder="Optional">
                    <p class="field-error">{{ $formErrors['competitor_volume'][0] ?? '' }}</p>
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
                <p class="field-error">{{ $formErrors['salescall_uuid'][0] ?? '' }}</p>
            </div>

            <div class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Participants <span class="text-red-500">*</span></p>
                        <p class="text-xs text-[#737685] mt-1">At least one participant is required</p>
                    </div>
                    <button type="button" wire:click="addParticipantRow" class="inline-flex items-center gap-1 rounded-full bg-[#890f00] px-3 py-1.5 text-xs font-semibold text-white">
                        <span class="material-symbols-outlined text-base">add</span>
                        <span>Add Participant</span>
                    </button>
                </div>

                <p class="field-error">{{ $formErrors['participants'][0] ?? '' }}</p>

                @foreach ($participants as $index => $participant)
                    <div wire:key="training-participant-{{ $index }}" class="space-y-3 border border-gray-200 rounded-xl p-3 bg-white">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Participant {{ $index + 1 }}</p>
                            <button type="button" wire:click="removeParticipantRow({{ $index }})" class="text-red-400 shrink-0" title="Remove participant">
                                <span class="material-symbols-outlined text-lg">delete</span>
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Name <span class="text-red-500">*</span></label>
                                <input type="text" required wire:model="participants.{{ $index }}.name" class="field-input" placeholder="Full name">
                                <p class="field-error">{{ $formErrors["participants.{$index}.name"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">Position <span class="text-red-500">*</span></label>
                                <select wire:model="participants.{{ $index }}.position_id" class="field-input bg-white">
                                    <option value="">Select a position</option>
                                    @foreach ($positions as $position)
                                        <option value="{{ $position['id'] }}">{{ $position['name'] }}</option>
                                    @endforeach
                                </select>
                                <p class="field-error">{{ $formErrors["participants.{$index}.position_id"][0] ?? '' }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @foreach (\App\Enums\Training\TrainingAttachmentType::cases() as $attachmentType)
                @php($typeValue = $attachmentType->value)
                @php($typeAttachments = $attachments[$typeValue] ?? [])

                <div class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-black text-[#737685] uppercase tracking-wider">
                                {{ $attachmentType->meta('title') }}
                                @if ($attachmentType === \App\Enums\Training\TrainingAttachmentType::REQUEST)
                                    <span class="text-red-500">*</span>
                                @endif
                            </p>
                            <p class="text-xs text-[#737685] mt-1">
                                {{ $attachmentType === \App\Enums\Training\TrainingAttachmentType::REQUEST ? 'Required' : 'Optional' }} · <span x-text="(trainingAttachments[{{ $typeValue }}] || []).length"></span> added
                            </p>
                        </div>
                        <span class="material-symbols-outlined text-[#737685]">attach_file</span>
                    </div>
                    <input type="file" x-ref="trainingCameraInput{{ $typeValue }}" accept="image/jpeg,image/png" capture="environment" class="hidden" @change="addTrainingAttachmentFile({{ $typeValue }}, $event.target.files[0]); $event.target.value = ''">
                    <input type="file" x-ref="trainingGalleryInput{{ $typeValue }}" accept="image/jpeg,image/png,image/*" class="hidden" @change="addTrainingAttachmentFile({{ $typeValue }}, $event.target.files[0]); $event.target.value = ''">
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.takeAttachmentPhoto({{ $typeValue }}); } else { $refs.trainingCameraInput{{ $typeValue }}.click(); }" class="attachment-button">
                            <span class="material-symbols-outlined text-xl">photo_camera</span>
                            <span>Camera</span>
                        </button>
                        <button type="button" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.pickAttachmentFromGallery({{ $typeValue }}); } else { $refs.trainingGalleryInput{{ $typeValue }}.click(); }" class="attachment-button">
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

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800">A topic or an other topic, at least one participant, and one Request attachment are required. This Training will be saved locally and remain pending synchronization.</div>
            @if (isset($formErrors['training']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['training'][0] }}</div>
            @endif
            @if (isset($formErrors['form']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['form'][0] }}</div>
            @endif
            <div class="grid grid-cols-2 gap-3 pt-1">
                <button type="button" @click="cancelTraining()" class="h-12 rounded-xl border border-gray-200 text-[#434654] font-bold">Cancel</button>
                <button type="submit" :disabled="trainingSaving" class="h-12 rounded-xl bg-[#890f00] text-white font-bold disabled:opacity-50">
                    <span x-show="! trainingSaving">Save</span>
                    <span x-show="trainingSaving">Saving…</span>
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
