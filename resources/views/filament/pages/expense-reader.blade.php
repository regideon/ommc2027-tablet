@php($listProperty = $context === 'salescall' ? 'callExpenses' : 'expenses')

@once
    <script>
        window.ommcExpenseReader = window.ommcExpenseReader || ((expenseItems, context, salescallId) => ({
            expenseItems,
            context,
            salescallId,
            selectedExpenseKey: null,
            isWide: window.matchMedia('(min-width: 1024px)').matches,
            init() {
                const media = window.matchMedia('(min-width: 1024px)');
                media.addEventListener('change', event => { this.isWide = event.matches; });
                this.ensureSelection();
                this.$watch('expenseItems', () => this.ensureSelection());
            },
            expenseKey(expense) {
                return expense.server_id ? `server:${expense.server_id}` : (expense.local_uuid ? `local:${expense.local_uuid}` : `id:${expense.id}`);
            },
            get selectedExpense() {
                return this.expenseItems.find(expense => this.expenseKey(expense) === this.selectedExpenseKey) || null;
            },
            ensureSelection() {
                if (this.context !== 'global') return;
                if (!this.expenseItems.some(expense => this.expenseKey(expense) === this.selectedExpenseKey)) {
                    this.selectedExpenseKey = this.expenseItems.length ? this.expenseKey(this.expenseItems[0]) : null;
                }
            },
            refreshList() {
                if (this.context === 'salescall') {
                    const id = this.salescallId();
                    if (id) $wire.loadExpenses(id);
                    return;
                }
                $wire.loadExpenses();
            },
            backToList() {
                this.selectedExpenseKey = null;
                if (this.context === 'salescall') this.refreshList();
            },
            resetContext() {
                this.expenseItems = [];
                this.selectedExpenseKey = null;
            },
            openExpense(expense) {
                this.selectedExpenseKey = this.expenseKey(expense);
            },
            expenseDetailGroups(expense) {
                const form = expense.form_data || {};
                const formatValue = value => {
                    if (Array.isArray(value)) return value.map(item => String(item).trim()).filter(Boolean).join(', ');
                    return value === null || value === undefined ? '' : String(value).trim();
                };
                const typeFields = [
                    ['Total Days', form.number_of_days], ['Number of Nights', form.number_of_nights],
                    ['Hotel', form.hotel], ['Expense Kind', form.expense_kind],
                    ['Other Expense Kind', form.expense_kind_other], ['Route', form.route],
                    ['Number of People', form.number_of_people], ['Contact Person', form.contact_person],
                    ['Meal', form.meal],
                    ['Names Included', Array.isArray(form.names_included)
                        ? form.names_included.map(name => String(name).trim()).filter(Boolean).map((name, index) => (index + 1) + '. ' + name).join('\n')
                        : form.names_included],
                    ['Meeting Agenda', form.meeting_agenda], ['Initial Odometer', form.initial_odometer],
                    ['Last Odometer', form.last_odometer], ['Distance Travelled', form.distance_travelled],
                    ['Liters', form.liters],
                ].map(([label, value]) => [label, formatValue(value)]).filter(([, value]) => value !== '');
                const generalFields = [
                    ['Location', expense.location], ['Establishment', expense.establishment],
                    ['Purpose', expense.purpose], ['TIN', expense.tin],
                ].map(([label, value]) => [label, formatValue(value)]).filter(([, value]) => value !== '');

                return [{ title: 'Expense Details', fields: [...generalFields, ...typeFields] }]
                    .filter(group => group.fields.length > 0);
            },
            expenseIdentityFields(expense) {
                return [
                    ['Filed By', expense.creator],
                    ['Created At', expense.created_at ? new Date(expense.created_at).toLocaleString('en-PH') : ''],
                    ['Updated At', expense.updated_at ? new Date(expense.updated_at).toLocaleString('en-PH') : ''],
                ].filter(([, value]) => value !== null && value !== undefined && value !== '');
            },
            expenseStatus(expense) {
                if (expense.approved === true) return `APPROVED${expense.approver_remarks ? ` (${expense.approver_remarks})` : ''}`;
                if (expense.approved === false) return `DISAPPROVED${expense.approver_remarks ? ` (${expense.approver_remarks})` : ''}`;
                return expense.sync_status === 'pending' ? 'PENDING SYNC' : 'PROCESSING';
            },
        }));
    </script>
@endonce

<div
    @if($context === 'salescall') x-show="tab === 'expenses'" @endif
    x-data="ommcExpenseReader(@entangle($listProperty), @js($context), () => {{ $context === 'salescall' ? 'selected' : 'null' }})"
    x-init="init()"
    @expense-context-reset.window="resetContext()"
    class="space-y-4 {{ $context === 'global' ? 'lg:grid lg:grid-cols-[minmax(17rem,0.38fr)_minmax(0,0.62fr)] lg:gap-4 lg:space-y-0' : '' }}">
    <section
        x-show="{{ $context === 'global' ? 'isWide || !selectedExpense' : '!selectedExpense' }}"
        class="min-w-0 bg-white rounded-2xl border border-gray-200 {{ $context === 'global' ? 'max-h-[calc(100dvh-11rem)] overflow-y-auto overscroll-contain' : 'overflow-y-auto overscroll-contain' }}"
        style="-webkit-overflow-scrolling: touch;">
        <div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-gray-100 bg-white/95 px-4 py-3 backdrop-blur">
            <div>
                <h3 class="text-base font-bold text-[#191c1e]">Expenses</h3>
                <p class="text-xs text-[#737685]">{{ $context === 'global' ? 'Expenses filed across your accessible Sales Calls' : 'Expenses filed for this Sales Call' }}</p>
            </div>
            <button type="button" @click="refreshList()" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center shrink-0" title="Refresh Expenses">
                <span class="material-symbols-outlined text-[#434654]">refresh</span>
            </button>
        </div>

        <div class="divide-y divide-gray-100">
            <template x-for="expense in expenseItems" :key="expenseKey(expense)">
                <button type="button" @click="openExpense(expense)"
                    :aria-pressed="selectedExpenseKey === expenseKey(expense)"
                    :class="selectedExpenseKey === expenseKey(expense) ? 'bg-red-50 border-l-4 border-[#890f00]' : 'bg-white border-l-4 border-transparent hover:bg-red-50'"
                    class="w-full min-h-[88px] text-left px-4 py-3 transition-colors">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-[#191c1e] truncate" x-text="expense.expense_type || 'Expense'"></p>
                            <p class="text-xs text-[#434654] mt-1 truncate" x-text="expense.customer_name || '---'"></p>
                            <p class="text-xs text-[#737685] mt-1" x-text="expense.date_filed || '—'"></p>
                        </div>
                        <span class="max-w-[45%] text-right text-[10px] font-bold uppercase tracking-wide text-[#737685] break-words" x-text="expenseStatus(expense)"></span>
                    </div>
                </button>
            </template>
            <p x-show="expenseItems.length === 0" class="text-center text-sm text-[#737685] py-10 bg-gray-50">{{ $context === 'global' ? 'No Expenses found.' : 'No Expenses filed for this Sales Call.' }}</p>
        </div>
    </section>

    <section
        x-show="{{ $context === 'global' ? 'isWide || selectedExpense' : 'selectedExpense' }}"
        class="min-w-0 {{ $context === 'global' ? 'max-h-[calc(100dvh-11rem)] overflow-y-auto overscroll-contain' : 'overflow-y-auto overscroll-contain' }}"
        style="-webkit-overflow-scrolling: touch;">
        <template x-if="selectedExpense">
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    @if($context === 'salescall')
                        <button type="button" @click="backToList()" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-[#434654]">arrow_back</span>
                        </button>
                    @else
                        <button type="button" x-show="!isWide" @click="backToList()" class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-[#434654]">arrow_back</span>
                        </button>
                    @endif
                    <div class="min-w-0">
                        <p class="text-[10px] font-black text-[#890f00] uppercase tracking-widest">Expense Details</p>
                        <h3 class="text-base font-bold text-[#191c1e] truncate" x-text="selectedExpense.expense_type || 'Expense'"></h3>
                        <p class="text-xs text-[#737685] truncate" x-text="(selectedExpense.customer_name || '---') + (selectedExpense.customer_unique_id ? ' · ' + selectedExpense.customer_unique_id : '')"></p>
                    </div>
                </div>

                <template x-if="expenseIdentityFields(selectedExpense).length > 0">
                    <div class="bg-white border border-gray-200 rounded-2xl p-4 space-y-3">
                        <h4 class="text-xs font-black text-[#737685] uppercase tracking-wider">Record Information</h4>
                        <template x-for="field in expenseIdentityFields(selectedExpense)" :key="field[0]">
                            <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                                <span class="text-xs font-bold text-[#737685]" x-text="field[0]"></span>
                                <span class="text-sm text-[#191c1e] text-right break-words" x-text="field[1]"></span>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="bg-white border border-gray-200 rounded-2xl p-4">
                    <h4 class="text-xs font-black text-[#737685] uppercase tracking-wider mb-3">Approval Status</h4>
                    <p class="text-sm font-bold text-[#191c1e] break-words" x-text="expenseStatus(selectedExpense)"></p>
                </div>

                <div class="bg-white border border-gray-200 rounded-2xl p-4 space-y-3">
                    <h4 class="text-xs font-black text-[#737685] uppercase tracking-wider">Payment and Amount</h4>
                    <template x-for="field in [
                        ['Payment', selectedExpense.payment_type],
                        ['Payment Remarks', selectedExpense.payment_remarks],
                        ['Amount', selectedExpense.amount === null || selectedExpense.amount === undefined ? null : ('₱' + Number(selectedExpense.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 4 }))],
                        ['Date Filed', selectedExpense.date_filed]
                    ]" :key="field[0]">
                        <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                            <span class="text-xs font-bold text-[#737685]" x-text="field[0]"></span>
                            <span class="text-sm text-[#191c1e] text-right break-words" x-text="field[1] || '—'"></span>
                        </div>
                    </template>
                </div>

                <template x-for="group in expenseDetailGroups(selectedExpense)" :key="group.title">
                    <div class="bg-white border border-gray-200 rounded-2xl p-4 space-y-3">
                        <h4 class="text-xs font-black text-[#737685] uppercase tracking-wider" x-text="group.title"></h4>
                        <template x-for="field in group.fields" :key="field[0]">
                            <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-2 last:border-0 last:pb-0">
                                <span class="text-xs font-bold text-[#737685]" x-text="field[0]"></span>
                                <span class="text-sm text-[#191c1e] text-right break-words whitespace-pre-line" x-text="field[1]"></span>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="bg-white border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Attachments</p>
                        <span class="text-xs text-[#737685]" x-text="(selectedExpense.attachments || []).length"></span>
                    </div>
                    <template x-for="attachment in (selectedExpense.attachments || [])" :key="attachment.id || attachment.original_name">
                        <div class="flex items-center gap-2 py-2 border-b border-gray-100 last:border-0">
                            <span class="material-symbols-outlined text-[#737685]">attach_file</span>
                            <span class="text-sm text-[#191c1e] truncate" x-text="attachment.original_name || 'Attachment'"></span>
                            <a x-show="attachment.url" :href="attachment.url" target="_blank" rel="noopener" class="ml-auto text-xs font-bold text-[#890f00] shrink-0">View</a>
                        </div>
                    </template>
                    <p x-show="(selectedExpense.attachments || []).length === 0" class="text-sm text-[#737685]">No attachments.</p>
                </div>
            </div>
        </template>
        <div x-show="!selectedExpense" class="flex min-h-64 items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white px-6 text-center">
            <p class="text-sm text-[#737685]">Select an Expense to view its details.</p>
        </div>
    </section>
</div>
