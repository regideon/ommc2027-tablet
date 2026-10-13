<x-filament-panels::page>
    <div
        x-data="{
            giveawaySaving: false,
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

        <form x-ref="giveawayEntryForm" @submit.prevent="saveGiveawayForm()" class="space-y-4">
            <div>
                <label class="field-label">Name</label>
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
                <div class="flex items-center justify-between">
                    <h2 class="text-xs font-black text-[#737685] uppercase tracking-wider">Items</h2>
                    <button type="button" wire:click="addItemRow" class="inline-flex items-center gap-1 rounded-full bg-[#890f00] px-3 py-1.5 text-xs font-semibold text-white">
                        <span class="material-symbols-outlined text-base">add</span>
                        <span>Add Item</span>
                    </button>
                </div>

                <p class="field-error">{{ $formErrors['items'][0] ?? '' }}</p>

                @foreach ($items as $index => $item)
                    <div wire:key="giveaway-item-{{ $item['key'] }}" class="space-y-3 border border-gray-200 rounded-xl p-3 bg-white">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Item {{ $index + 1 }}</p>
                            <button type="button" wire:click="removeItemRow('{{ $item['key'] }}')" class="text-red-400 shrink-0" title="Remove item">
                                <span class="material-symbols-outlined text-lg">delete</span>
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Category</label>
                                <select wire:model="items.{{ $index }}.category" class="field-input bg-white">
                                    @foreach ($categories as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="field-error">{{ $formErrors["items.{$index}.category"][0] ?? '' }}</p>
                            </div>
                            <div>
                                <label class="field-label">Quantity</label>
                                <input type="text" wire:model="items.{{ $index }}.answer" class="field-input" placeholder="Optional">
                                <p class="field-error">{{ $formErrors["items.{$index}.answer"][0] ?? '' }}</p>
                            </div>
                        </div>

                        <div>
                            <label class="field-label">Item Name</label>
                            <input type="text" wire:model="items.{{ $index }}.name" class="field-input" placeholder="e.g. Gift Check">
                            <p class="field-error">{{ $formErrors["items.{$index}.name"][0] ?? '' }}</p>
                        </div>

                        @php($signature = $item['signature'] ?? null)

                        <div x-data="giveawaySignaturePad('{{ $item['key'] }}')" class="space-y-2 p-3 bg-gray-50 border border-gray-200 rounded-xl">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Signature</p>
                                    <p class="text-xs text-[#737685] mt-1">Required · have the recipient sign below</p>
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
                                <div x-show="! captured" class="flex items-center gap-3 border border-gray-200 rounded-xl p-3 bg-white">
                                    <div class="w-10 h-10 rounded-lg bg-gray-50 border border-gray-200 flex items-center justify-center shrink-0 overflow-hidden">
                                        @if (! empty($signature['url']))
                                            <img src="{{ $signature['url'] }}" class="w-full h-full object-cover" alt="Saved signature">
                                        @else
                                            <span class="material-symbols-outlined text-[#737685] text-xl">draw</span>
                                        @endif
                                    </div>
                                    <span class="flex-1 min-w-0 text-xs font-medium text-[#191c1e] truncate">Saved signature — draw above to replace</span>
                                </div>
                            @endif

                            <p class="field-error">{{ $formErrors["items.{$index}.signature_id"][0] ?? '' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800">This Giveaway and its signatures will be saved locally and remain pending synchronization.</div>
            @if (isset($formErrors['giveaway']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['giveaway'][0] }}</div>
            @endif
            @if (isset($formErrors['form']))
                <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{{ $formErrors['form'][0] }}</div>
            @endif
            <div class="grid grid-cols-2 gap-3 pt-1">
                <button type="button" wire:click="cancel" class="h-12 rounded-xl border border-gray-200 text-[#434654] font-bold">Cancel</button>
                <button type="submit" :disabled="giveawaySaving" class="h-12 rounded-xl bg-[#890f00] text-white font-bold disabled:opacity-50">
                    <span x-show="! giveawaySaving">Save Locally</span>
                    <span x-show="giveawaySaving">Saving…</span>
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

@once
    <script>
        window.giveawaySignaturePad = function (key) {
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
                    this.$wire.stageSignature(key, {
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
                    this.$wire.removeSignature(key);
                },
            };
        };
    </script>
@endonce
