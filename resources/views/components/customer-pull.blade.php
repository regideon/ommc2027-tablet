{{--
    Paginated customer pull driver; the page must use the PullsCustomers trait.
    Calls pullCustomersStep() until it reports done, one portal page per
    request, so a large customer list never runs into a request timeout.

    auto           start automatically (first pull / resume an interrupted one)
    showButton     render the "Pull Customers" button (the slot renders beside it)
    refreshOnDone  re-render the Livewire page when the pull finishes
--}}
@props(['auto' => false, 'showButton' => false, 'refreshOnDone' => false])

<div
    x-data="{
        running: false,
        failed: false,
        finished: false,
        pulled: 0,
        total: null,
        message: '',
        get percent() {
            return this.total ? Math.min(100, Math.round((this.pulled / this.total) * 100)) : null;
        },
        async run() {
            if (this.running) return;
            if (!navigator.onLine) {
                this.failed = true;
                this.message = 'You are offline. Connect to the internet to pull customers.';
                return;
            }

            this.running = true;
            this.failed = false;
            this.finished = false;
            this.message = 'Starting customer pull…';

            try {
                while (true) {
                    const step = await $wire.pullCustomersStep();
                    this.pulled = step.pulled;
                    this.total = step.total;
                    this.message = step.message;

                    if (!step.success) {
                        this.failed = true;
                        break;
                    }

                    if (step.done) {
                        this.finished = true;
                        setTimeout(() => this.finished = false, 4000);
                        $dispatch('customers-pulled');
                        @if($refreshOnDone) await $wire.$refresh(); @endif
                        break;
                    }
                }
            } catch (e) {
                this.failed = true;
                this.message = 'Customer pull was interrupted. Tap Pull Customers to resume.';
            } finally {
                this.running = false;
            }
        },
    }"
    x-init="@if($auto) $nextTick(() => run()) @endif"
    {{ $attributes }}
>
    <div class="flex items-center justify-end gap-2">
        @if($showButton)
            <button
                type="button"
                @click="run()"
                :disabled="running"
                title="Pull latest Customers"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-2 text-xs font-semibold text-[#434654] transition-colors hover:bg-[#edeef0] hover:text-[#890f00] disabled:cursor-wait disabled:opacity-60">
                <span class="material-symbols-outlined mat-fill text-lg" :class="running && 'animate-spin'" x-text="running ? 'progress_activity' : 'cloud_download'"></span>
                <span x-text="running ? 'Pulling…' : 'Pull Customers'"></span>
            </button>
        @endif
        {{ $slot }}
    </div>

    <template x-if="running || failed || finished">
        <div class="mt-2 rounded-2xl px-4 py-3 text-xs"
             :class="failed ? 'bg-red-50 text-red-700' : 'bg-[#f3f4f6] text-[#434654]'">
            <p class="font-semibold" x-text="message"></p>
            <template x-if="running && percent !== null">
                <div class="mt-2">
                    <div class="h-1.5 w-full rounded-full bg-white overflow-hidden">
                        <div class="h-full bg-[#890f00] transition-all" :style="`width: ${percent}%`"></div>
                    </div>
                    <p class="mt-1 text-[#737685]" x-text="`${pulled.toLocaleString()} / ${total.toLocaleString()}`"></p>
                </div>
            </template>
        </div>
    </template>
</div>
