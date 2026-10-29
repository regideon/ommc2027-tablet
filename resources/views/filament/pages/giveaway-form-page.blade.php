<x-filament-panels::page>
    <div
        x-data="{
            giveawaySaving: false,
            addPhotoFile(file) {
                if (! file) return;
                const reader = new FileReader();
                reader.onload = (event) => {
                    $wire.stagePhoto({
                        data: event.target.result,
                        original_name: file.name,
                    });
                };
                reader.readAsDataURL(file);
            },
            async saveGiveawayForm() {
                if (this.giveawaySaving) return;
                if (! this.$refs.giveawayEntryForm.reportValidity()) return;
                this.giveawaySaving = true;
                await $wire.save();
                this.giveawaySaving = false;
            },
        }"
        class="space-y-6 pb-8"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">Forms · Giveaway</p>
                <h1 class="text-2xl font-extrabold text-[#191c1e]">{{ $isEdit ? 'Edit Giveaway' : 'Add Giveaway' }}</h1>
            </div>
            <button type="button" wire:click="cancel" class="h-10 px-4 rounded-xl border border-gray-200 text-[#434654] font-bold">Back</button>
        </div>

        @if ($templateLoadError !== '')
            <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $templateLoadError }}</div>
        @endif

        <form x-ref="giveawayEntryForm" @submit.prevent="saveGiveawayForm()" class="space-y-4">
            <div>
                <label class="field-label">Name <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" class="field-input" placeholder="Giveaway name">
                <p class="field-error">{{ $formErrors['name'][0] ?? '' }}</p>
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

            <div class="space-y-3">
                <p class="field-error">{{ $formErrors['items'][0] ?? '' }}</p>

                @php($activeCategory = null)
                @foreach ($items as $index => $item)
                    @if ($activeCategory !== $item['category_label'])
                        @php($activeCategory = $item['category_label'])
                        <h2 class="pt-2 text-xs font-black text-[#737685] uppercase tracking-wider">{{ $item['category_label'] }}</h2>
                    @endif

                    <div wire:key="giveaway-item-{{ $item['key'] }}" class="flex flex-nowrap items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-3">
                        <span class="whitespace-nowrap text-sm font-medium text-[#191c1e]">{{ $item['name'] }}</span>
                        <input type="number" min="0" wire:model="items.{{ $index }}.answer" class="field-input shrink-0 text-right" style="width: 7rem;" placeholder="0">
                    </div>
                    <p class="field-error -mt-2">{{ $formErrors["items.{$index}.answer"][0] ?? '' }}</p>
                @endforeach
            </div>

            <div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4">
                <div>
                    <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Photos</p>
                    <p class="text-xs text-[#737685] mt-1">One photo per giveaway · optional</p>
                </div>

                <input type="file" x-ref="giveawayPhotoInput" accept="image/jpeg,image/png,image/*" class="hidden" @change="addPhotoFile($event.target.files[0]); $event.target.value = ''">
                <input type="file" x-ref="giveawayPhotoCameraInput" accept="image/jpeg,image/png" capture="environment" class="hidden" @change="addPhotoFile($event.target.files[0]); $event.target.value = ''">

                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.takePhoto(); } else { $refs.giveawayPhotoCameraInput.click(); }" class="attachment-button"><span class="material-symbols-outlined text-xl">photo_camera</span><span>Take Photo</span></button>
                    <button type="button" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.pickPhotoFromGallery(); } else { $refs.giveawayPhotoInput.click(); }" class="attachment-button"><span class="material-symbols-outlined text-xl">photo_library</span><span>Photo Library</span></button>
                </div>

                @if ($photo !== null)
                    <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3">
                        @if (! empty($photo['url']) && ($photo['mime_type'] ?? '') !== 'application/pdf')
                            <img src="{{ $photo['url'] }}" alt="{{ $photo['original_name'] ?? 'Photo' }}" class="h-14 w-14 rounded-lg border border-gray-200 object-cover">
                        @else
                            <span class="material-symbols-outlined text-[#737685] text-2xl">image</span>
                        @endif
                        <span class="flex-1 min-w-0 truncate text-xs font-medium text-[#191c1e]">{{ $photo['original_name'] ?? 'Photo' }}</span>
                        <button type="button" wire:click="removePhoto" class="text-red-400"><span class="material-symbols-outlined text-lg">delete</span></button>
                    </div>
                @endif
                <p class="field-error">{{ $formErrors['photo_id'][0] ?? '' }}</p>
            </div>

            <div x-data="giveawaySignaturePad()" class="space-y-2 rounded-2xl border border-gray-200 bg-white p-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Signature of recipient <span class="text-red-500">*</span></p>
                        <p class="text-xs text-[#737685] mt-1">One recipient signature per giveaway</p>
                    </div>
                    <button type="button" @click="clearPad()" class="text-xs text-[#890f00] font-medium">Clear</button>
                </div>

                <canvas
                    x-ref="signatureCanvas"
                    style="touch-action: none; height: 160px;"
                    class="w-full border-2 border-dashed border-gray-300 rounded-xl bg-white cursor-crosshair"
                    @mousedown.prevent="padStart($event)"
                    @mousemove.prevent="padDraw($event)"
                    @mouseup="padEnd()"
                    @mouseleave="padEnd()"
                    @touchstart.prevent="padStart($event)"
                    @touchmove.prevent="padDraw($event)"
                    @touchend="padEnd()">
                </canvas>

                <div x-show="captured" class="flex items-center gap-1 text-xs text-green-600 font-medium">
                    <span class="material-symbols-outlined text-base mat-fill">check_circle</span> Signature captured
                </div>

                @if (($signature['source'] ?? null) === 'existing')
                    <div x-show="! captured" class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3">
                        @if (! empty($signature['url']))
                            <img src="{{ $signature['url'] }}" class="h-12 w-20 rounded-lg border border-gray-200 object-cover" alt="Saved signature">
                        @else
                            <span class="material-symbols-outlined text-[#737685]">draw</span>
                        @endif
                        <span class="flex-1 min-w-0 text-xs font-medium text-[#191c1e]">Saved signature — draw above to replace</span>
                    </div>
                @endif

                <p class="field-error">{{ $formErrors['signature_id'][0] ?? '' }}</p>
            </div>

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800">This Giveaway, its photo, and signature will be saved locally and remain pending synchronization.</div>
            @if (isset($formErrors['giveaway']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['giveaway'][0] }}</div>
            @endif
            @if (isset($formErrors['form']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['form'][0] }}</div>
            @endif

            <div class="grid grid-cols-2 gap-3 pt-1">
                <button type="button" wire:click="cancel" class="h-12 rounded-xl border border-gray-200 text-[#434654] font-bold">Cancel</button>
                <button type="submit" :disabled="giveawaySaving" class="h-12 rounded-xl bg-[#890f00] text-white font-bold disabled:opacity-50">
                    <span x-show="! giveawaySaving">Save</span>
                    <span x-show="giveawaySaving">Saving…</span>
                </button>
            </div>
        </form>
    </div>

    <style>
        .field-label { display: block; margin-bottom: .25rem; font-size: .75rem; font-weight: 700; color: #737685; }
        .field-input { width: 100%; border: 1px solid #e5e7eb; border-radius: .75rem; padding: .625rem .75rem; font-size: .875rem; }
        .field-error { margin-top: .25rem; font-size: .75rem; color: #dc2626; }
        .attachment-button { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem; padding: .75rem; border: 2px solid #e5e7eb; border-radius: .75rem; color: #434654; font-size: .625rem; font-weight: 700; }
    </style>
</x-filament-panels::page>

@once
    <script>
        window.giveawaySignaturePad = function () {
            return {
                canvas: null,
                context: null,
                drawing: false,
                captured: false,
                stageTimer: null,

                padInit() {
                    if (this.canvas) return;
                    this.canvas = this.$refs.signatureCanvas;
                    if (! this.canvas) return;
                    this.canvas.width = this.canvas.offsetWidth;
                    this.canvas.height = this.canvas.offsetHeight;
                    this.context = this.canvas.getContext('2d');
                    this.context.strokeStyle = '#1e293b';
                    this.context.lineWidth = 2;
                    this.context.lineCap = 'round';
                    this.context.lineJoin = 'round';
                },

                padPosition(event) {
                    const rect = this.canvas.getBoundingClientRect();
                    const source = event.touches ? event.touches[0] : event;

                    return {
                        x: (source.clientX - rect.left) * (this.canvas.width / rect.width),
                        y: (source.clientY - rect.top) * (this.canvas.height / rect.height),
                    };
                },

                padStart(event) {
                    if (! this.canvas) { this.padInit(); }
                    if (! this.context) return;
                    this.drawing = true;
                    const position = this.padPosition(event);
                    this.context.beginPath();
                    this.context.moveTo(position.x, position.y);
                },

                padDraw(event) {
                    if (! this.drawing || ! this.context) return;
                    const position = this.padPosition(event);
                    this.context.lineTo(position.x, position.y);
                    this.context.stroke();
                },

                padEnd() {
                    this.drawing = false;
                    if (! this.canvas) return;
                    this.captured = true;
                    clearTimeout(this.stageTimer);
                    this.stageTimer = setTimeout(() => this.stage(), 500);
                },

                stage() {
                    if (! this.canvas) return;
                    this.$wire.stageSignature({
                        data: this.canvas.toDataURL('image/png'),
                        original_name: 'signature.png',
                    });
                },

                clearPad() {
                    clearTimeout(this.stageTimer);
                    if (this.context && this.canvas) {
                        this.context.clearRect(0, 0, this.canvas.width, this.canvas.height);
                    }
                    this.captured = false;
                    this.$wire.removeSignature();
                },
            };
        };
    </script>
@endonce
