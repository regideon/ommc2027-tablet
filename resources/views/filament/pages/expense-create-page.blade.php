<x-filament-panels::page>
    <div
        x-data="{
            selectedCall: @js($salescallContext),
            expenseSelectedType: @js($selectedExpenseType),
            expenseSaving: false,
            expenseErrors: {},
            expenseForm: {},
            expenseAttachments: [],
            expenseAttachmentError: '',
            emptyExpenseForm() {
                const today = new Date();
                const date = new Date(today.getTime() - today.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
                return {
                    expense_type_code: this.expenseSelectedType.code,
                    amount: '',
                    date_filed: date,
                    payment_type: '',
                    payment_remarks: '',
                    invoice_number: '',
                    establishment: '',
                    location: this.selectedCall.location || '',
                    purpose: '',
                    tin: '',
                    latitude: this.selectedCall.lat || null,
                    longitude: this.selectedCall.lng || null,
                    form_data: this.expenseFormDataFor(this.expenseSelectedType.code),
                };
            },
            expenseFormDataFor(typeCode) {
                return {
                    lodging: { number_of_nights: '', hotel: '' },
                    per_diem: { meal: '', number_of_days: '' },
                    ancillary_expenses: { expense_kind: '', expense_kind_other: '' },
                    airfare: { route: '' },
                    representation: { number_of_people: '', contact_person: '', names_included: [], meeting_agenda: '' },
                    staff_meeting: { number_of_people: '', contact_person: '', names_included: [], meeting_agenda: '' },
                    repairs_and_maintenance: { odometer: '' },
                    transportation_toll: { initial_odometer: '', last_odometer: '', distance_travelled: null },
                    transportation_gas: { initial_odometer: '', last_odometer: '', distance_travelled: null, liters: '' },
                    transportation_parking: { initial_odometer: '', last_odometer: '', distance_travelled: null, number_of_days: '' },
                    transportation_commute: { initial_odometer: '', last_odometer: '', distance_travelled: null, expense_kind: '', expense_kind_other: '', route: '' },
                }[typeCode] || {};
            },
            expenseError(field) { return (this.expenseErrors[field] || [])[0] || ''; },
            isExpenseOther() {
                const value = (this.expenseForm.form_data?.expense_kind || '').trim().toLowerCase();
                return value === 'other' || value === 'others';
            },
            onExpenseKindInput() {
                if (!this.isExpenseOther()) this.expenseForm.form_data.expense_kind_other = '';
            },
            isCommuteOther() {
                const value = (this.expenseForm.form_data?.expense_kind || '').trim().toLowerCase();
                return value === 'other' || value === 'others';
            },
            onCommuteKindChange() {
                if (!this.isCommuteOther()) this.expenseForm.form_data.expense_kind_other = '';
            },
            updateExpenseDistance() {
                const initialValue = this.expenseForm.form_data.initial_odometer;
                const lastValue = this.expenseForm.form_data.last_odometer;
                const initial = Number(initialValue);
                const last = Number(lastValue);
                this.expenseForm.form_data.distance_travelled = initialValue !== '' && lastValue !== ''
                    && initialValue !== null && lastValue !== null
                    && Number.isFinite(initial) && Number.isFinite(last) ? last - initial : null;
            },
            addExpenseName() {
                if (!Array.isArray(this.expenseForm.form_data.names_included)) this.expenseForm.form_data.names_included = [];
                this.expenseForm.form_data.names_included.push('');
            },
            removeExpenseName(index) { this.expenseForm.form_data.names_included.splice(index, 1); },
            addExpenseAttachmentPayload(attachment) {
                this.expenseAttachmentError = '';
                if (!attachment?.data) return;
                if (this.expenseAttachments.length >= 10) {
                    this.expenseAttachmentError = 'An Expense may have at most 10 attachments.';
                    return;
                }
                const mimeType = attachment.mime_type || '';
                if (!['image/jpeg', 'image/png', 'application/pdf'].includes(mimeType)) {
                    this.expenseAttachmentError = 'Only JPEG, PNG, and PDF attachments are supported.';
                    return;
                }
                this.expenseAttachments.push({
                    data: attachment.data,
                    original_name: attachment.original_name || 'attachment',
                    mime_type: mimeType,
                    byte_size: attachment.byte_size || 0,
                });
            },
            addExpenseAttachmentFile(file) {
                this.expenseAttachmentError = '';
                if (!file) return;
                if (this.expenseAttachments.length >= 10) {
                    this.expenseAttachmentError = 'An Expense may have at most 10 attachments.';
                    return;
                }
                const extension = (file.name.split('.').pop() || '').toLowerCase();
                const allowed = ['image/jpeg', 'image/png', 'application/pdf'];
                if (!allowed.includes(file.type) && !['jpg', 'jpeg', 'png', 'pdf'].includes(extension)) {
                    this.expenseAttachmentError = 'Only JPEG, PNG, and PDF attachments are supported.';
                    return;
                }
                const reader = new FileReader();
                reader.onload = (event) => this.addExpenseAttachmentPayload({
                    data: event.target.result,
                    original_name: file.name,
                    mime_type: file.type || (extension === 'pdf' ? 'application/pdf' : extension === 'png' ? 'image/png' : 'image/jpeg'),
                    byte_size: file.size,
                });
                reader.onerror = () => { this.expenseAttachmentError = 'The selected attachment could not be read.'; };
                reader.readAsDataURL(file);
            },
            removeExpenseAttachment(index) { this.expenseAttachments.splice(index, 1); },
            async saveExpenseForm() {
                if (this.expenseSaving) return;
                this.expenseSaving = true;
                this.expenseErrors = {};
                if (['representation', 'staff_meeting'].includes(this.expenseSelectedType.code)) {
                    this.expenseForm.form_data.names_included = (this.expenseForm.form_data.names_included || [])
                        .map(name => String(name).trim()).filter(Boolean);
                }
                const result = await $wire.saveExpense(this.expenseForm, this.expenseAttachments);
                this.expenseSaving = false;
                if (!result?.ok) this.expenseErrors = result?.errors || { form: ['The Expense could not be saved.'] };
            },
            cancelExpense() {
                if (this.expenseAttachments.length || JSON.stringify(this.expenseForm) !== JSON.stringify(this.emptyExpenseForm())) {
                    if (!window.confirm('Cancel this Expense without saving?')) return;
                }
                $wire.cancelExpense();
            },
        }"
        x-init="
            expenseForm = emptyExpenseForm();
            window.addEventListener('expense-attachment-ready', (event) => addExpenseAttachmentPayload(event.detail?.attachment));
            document.addEventListener('focusin', (event) => {
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) {
                    setTimeout(() => event.target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 350);
                }
            });
        "
        class="space-y-6 pb-8"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">Sales Call Expense</p>
                <h1 class="text-2xl font-extrabold text-[#191c1e]">Add Expense</h1>
                <p class="text-sm text-[#737685]" x-text="selectedCall.name + ' · Salescall #' + (selectedCall.ref_number ?? selectedCall.id)"></p>
            </div>
            <button type="button" @click="cancelExpense()" class="h-10 px-4 rounded-xl border border-gray-200 text-[#434654] font-bold">Back</button>
        </div>

        <form @submit.prevent="saveExpenseForm()" class="space-y-4">
            <div class="p-3 bg-gray-50 border border-gray-200 rounded-xl">
                <p class="text-[10px] font-black text-[#737685] uppercase tracking-wider">Expense Type</p>
                <p class="font-bold text-[#191c1e]" x-text="expenseSelectedType.label"></p>
                <p class="text-xs text-[#737685] mt-1">Customer: <span x-text="selectedCall.name"></span></p>
            </div>

            <div x-show="expenseSelectedType.code === 'lodging'" class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Lodging Details</p>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="field-label">No. of Nights *</label><input type="number" min="1" step="1" x-model="expenseForm.form_data.number_of_nights" class="field-input" inputmode="numeric"><p class="field-error" x-text="expenseError('form_data.number_of_nights')"></p></div>
                    <div><label class="field-label">Hotel *</label><input type="text" x-model="expenseForm.form_data.hotel" class="field-input"><p class="field-error" x-text="expenseError('form_data.hotel')"></p></div>
                </div>
            </div>

            <div x-show="expenseSelectedType.code === 'per_diem'" class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Per Diem Details</p>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="field-label">Meal *</label><input type="text" x-model="expenseForm.form_data.meal" class="field-input"><p class="field-error" x-text="expenseError('form_data.meal')"></p></div>
                    <div><label class="field-label">Number of Days *</label><input type="number" min="1" step="1" x-model="expenseForm.form_data.number_of_days" class="field-input" inputmode="numeric"><p class="field-error" x-text="expenseError('form_data.number_of_days')"></p></div>
                </div>
            </div>

            <div x-show="expenseSelectedType.code === 'ancillary_expenses'" class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Ancillary Details</p>
                <div><label class="field-label">Expense Kind *</label><input type="text" x-model="expenseForm.form_data.expense_kind" @input="onExpenseKindInput()" placeholder="e.g. Supplies, parcel, or Others" class="field-input"><p class="field-error" x-text="expenseError('form_data.expense_kind')"></p></div>
                <div x-show="isExpenseOther()"><label class="field-label">Specify Other Expense Kind *</label><input type="text" x-model="expenseForm.form_data.expense_kind_other" class="field-input"><p class="field-error" x-text="expenseError('form_data.expense_kind_other')"></p></div>
            </div>

            <div x-show="expenseSelectedType.code === 'airfare'" class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Airfare Details</p>
                <div><label class="field-label">Route *</label><input type="text" x-model="expenseForm.form_data.route" placeholder="Origin to destination" class="field-input"><p class="field-error" x-text="expenseError('form_data.route')"></p></div>
            </div>

            <div x-show="['representation', 'staff_meeting'].includes(expenseSelectedType.code)" class="space-y-4 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Meeting Details</p>
                <div class="grid grid-cols-2 gap-3"><div><label class="field-label">Number of People</label><input type="number" min="0" step="1" x-model="expenseForm.form_data.number_of_people" class="field-input" inputmode="numeric"><p class="field-error" x-text="expenseError('form_data.number_of_people')"></p></div><div><label class="field-label">Contact Person</label><input type="text" x-model="expenseForm.form_data.contact_person" class="field-input"><p class="field-error" x-text="expenseError('form_data.contact_person')"></p></div></div>
                <div><div class="flex items-center justify-between mb-2"><label class="field-label">Names Included</label><button type="button" @click="addExpenseName()" class="text-xs font-bold text-[#890f00]">+ Add Name</button></div><div class="space-y-2"><template x-for="(name, index) in expenseForm.form_data.names_included" :key="index"><div class="flex items-center gap-2"><span class="w-6 text-xs font-bold text-[#737685] text-right" x-text="(index + 1) + '.'"></span><input type="text" x-model="expenseForm.form_data.names_included[index]" :placeholder="'Name ' + (index + 1)" class="flex-1 field-input"><button type="button" @click="removeExpenseName(index)" class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#890f00]">×</button></div></template><p x-show="expenseForm.form_data.names_included.length === 0" class="text-xs text-[#737685] italic">No names added.</p></div><p class="field-error" x-text="expenseError('form_data.names_included')"></p></div>
                <div><label class="field-label">Meeting Agenda</label><textarea rows="3" x-model="expenseForm.form_data.meeting_agenda" class="field-input"></textarea><p class="field-error" x-text="expenseError('form_data.meeting_agenda')"></p></div>
            </div>

            <div x-show="expenseSelectedType.code === 'repairs_and_maintenance'" class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl"><p class="text-xs font-black text-[#737685] uppercase tracking-wider">Repair Details</p><div><label class="field-label">Odometer</label><input type="number" min="0" step="0.01" x-model="expenseForm.form_data.odometer" class="field-input" inputmode="decimal"><p class="field-error" x-text="expenseError('form_data.odometer')"></p></div></div>

            <div x-show="['transportation_toll', 'transportation_gas', 'transportation_parking', 'transportation_commute'].includes(expenseSelectedType.code)" class="space-y-4 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <p class="text-xs font-black text-[#737685] uppercase tracking-wider">Transportation Details</p>
                <div class="grid grid-cols-2 gap-3"><div><label class="field-label">Initial Odometer</label><input type="number" min="0" step="0.01" @input="updateExpenseDistance()" x-model="expenseForm.form_data.initial_odometer" class="field-input" inputmode="decimal"><p class="field-error" x-text="expenseError('form_data.initial_odometer')"></p></div><div><label class="field-label">Last Odometer</label><input type="number" min="0" step="0.01" @input="updateExpenseDistance()" x-model="expenseForm.form_data.last_odometer" class="field-input" inputmode="decimal"><p class="field-error" x-text="expenseError('form_data.last_odometer')"></p></div></div>
                <div class="p-3 bg-white border border-gray-200 rounded-xl flex items-center justify-between"><span class="text-xs font-bold text-[#737685]">Distance Travelled</span><span class="font-black text-[#191c1e]" x-text="expenseForm.form_data.distance_travelled === null ? '—' : expenseForm.form_data.distance_travelled"></span></div><p class="field-error" x-text="expenseError('form_data.distance_travelled')"></p>
                <div x-show="expenseSelectedType.code === 'transportation_gas'"><label class="field-label">Liters</label><input type="number" min="0" step="0.01" x-model="expenseForm.form_data.liters" class="field-input" inputmode="decimal"><p class="field-error" x-text="expenseError('form_data.liters')"></p></div>
                <div x-show="expenseSelectedType.code === 'transportation_parking'"><label class="field-label">Number of Days</label><input type="number" min="0" step="1" x-model="expenseForm.form_data.number_of_days" class="field-input" inputmode="numeric"><p class="field-error" x-text="expenseError('form_data.number_of_days')"></p></div>
                <div x-show="expenseSelectedType.code === 'transportation_commute'" class="space-y-3"><div><label class="field-label">Expense Kind</label><select x-model="expenseForm.form_data.expense_kind" @change="onCommuteKindChange()" class="field-input bg-white"><option value="">Select expense kind</option><option>RORO Fare</option><option>Terminal Fee</option><option>Taxi Fare</option><option>Others</option></select><p class="field-error" x-text="expenseError('form_data.expense_kind')"></p></div><div x-show="isCommuteOther()"><label class="field-label">Specify Other Expense Kind</label><input type="text" x-model="expenseForm.form_data.expense_kind_other" class="field-input"><p class="field-error" x-text="expenseError('form_data.expense_kind_other')"></p></div><div><label class="field-label">Route</label><input type="text" x-model="expenseForm.form_data.route" class="field-input"><p class="field-error" x-text="expenseError('form_data.route')"></p></div></div>
            </div>

            <div class="grid grid-cols-2 gap-3"><div><label class="field-label">Amount *</label><input type="number" min="0.01" step="0.0001" x-model="expenseForm.amount" class="field-input" inputmode="decimal"><p class="field-error" x-text="expenseError('amount')"></p></div><div><label class="field-label">Date Filed *</label><input type="date" x-model="expenseForm.date_filed" class="field-input"><p class="field-error" x-text="expenseError('date_filed')"></p></div></div>
            <div><label class="field-label">Payment Type *</label><select x-model="expenseForm.payment_type" class="field-input bg-white"><option value="">Select payment type</option><option>Revolving Fund</option><option>Petty Cash Voucher (PCV)</option><option>SBC Credit Card</option><option>Cash Advance</option><option>Fleet Card</option></select><p class="field-error" x-text="expenseError('payment_type')"></p></div>
            <div><label class="field-label">Payment Remarks *</label><textarea rows="2" x-model="expenseForm.payment_remarks" class="field-input"></textarea><p class="field-error" x-text="expenseError('payment_remarks')"></p></div>
            <div class="grid grid-cols-2 gap-3"><div><label class="field-label">Invoice Number *</label><input type="text" x-model="expenseForm.invoice_number" class="field-input"><p class="field-error" x-text="expenseError('invoice_number')"></p></div><div><label class="field-label">TIN *</label><input type="text" x-model="expenseForm.tin" class="field-input"><p class="field-error" x-text="expenseError('tin')"></p></div></div>
            <div><label class="field-label">Establishment *</label><input type="text" x-model="expenseForm.establishment" class="field-input"><p class="field-error" x-text="expenseError('establishment')"></p></div>
            <div><label class="field-label">Location *</label><textarea rows="2" x-model="expenseForm.location" class="field-input"></textarea><p class="field-error" x-text="expenseError('location')"></p></div>
            <div><label class="field-label">Purpose *</label><textarea rows="2" x-model="expenseForm.purpose" class="field-input"></textarea><p class="field-error" x-text="expenseError('purpose')"></p></div>

            <div class="space-y-3 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <div class="flex items-center justify-between"><div><p class="text-xs font-black text-[#737685] uppercase tracking-wider">Attachments</p><p class="text-xs text-[#737685] mt-1">Optional · <span x-text="expenseAttachments.length"></span> / 10</p></div><span class="material-symbols-outlined text-[#737685]">attach_file</span></div>
                <input type="file" x-ref="expenseCameraInput" accept="image/jpeg,image/png" capture="environment" class="hidden" @change="addExpenseAttachmentFile($event.target.files[0]); $event.target.value = ''">
                <input type="file" x-ref="expenseGalleryInput" accept="image/jpeg,image/png,image/*" class="hidden" @change="addExpenseAttachmentFile($event.target.files[0]); $event.target.value = ''">
                <input type="file" x-ref="expenseFilesInput" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" class="hidden" @change="addExpenseAttachmentFile($event.target.files[0]); $event.target.value = ''">
                <div class="grid grid-cols-3 gap-2"><button type="button" :disabled="expenseAttachments.length >= 10" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.takeExpenseAttachmentPhoto(); } else { $refs.expenseCameraInput.click(); }" class="attachment-button"><span class="material-symbols-outlined text-xl">photo_camera</span><span>Camera</span></button><button type="button" :disabled="expenseAttachments.length >= 10" @click="if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) { $wire.pickExpenseAttachmentFromGallery(); } else { $refs.expenseGalleryInput.click(); }" class="attachment-button"><span class="material-symbols-outlined text-xl">photo_library</span><span>Gallery</span></button><button type="button" :disabled="expenseAttachments.length >= 10" @click="$refs.expenseFilesInput.click()" class="attachment-button"><span class="material-symbols-outlined text-xl">folder_open</span><span>Files</span></button></div>
                <p x-show="expenseAttachmentError" class="text-xs text-red-600" x-text="expenseAttachmentError"></p>
                <div class="space-y-2"><template x-for="(attachment, index) in expenseAttachments" :key="index"><div class="flex items-center gap-3 border border-gray-200 rounded-xl p-3 bg-white"><div class="w-10 h-10 rounded-lg bg-gray-50 border border-gray-200 flex items-center justify-center shrink-0 overflow-hidden"><template x-if="attachment.mime_type !== 'application/pdf'"><img :src="attachment.data" class="w-full h-full object-cover"></template><template x-if="attachment.mime_type === 'application/pdf'"><span class="material-symbols-outlined text-red-500 text-xl">picture_as_pdf</span></template></div><span class="flex-1 min-w-0 text-xs font-medium text-[#191c1e] truncate" x-text="attachment.original_name"></span><button type="button" @click="removeExpenseAttachment(index)" class="text-red-400 shrink-0"><span class="material-symbols-outlined text-lg">delete</span></button></div></template></div>
            </div>

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800">This Expense and any attachments will be saved locally and remain pending synchronization.</div>
            <div x-show="expenseErrors.form" class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700" x-text="expenseError('form')"></div>
            <div class="grid grid-cols-2 gap-3 pt-1"><button type="button" @click="cancelExpense()" class="h-12 rounded-xl border border-gray-200 text-[#434654] font-bold">Cancel</button><button type="submit" :disabled="expenseSaving" class="h-12 rounded-xl bg-[#890f00] text-white font-bold disabled:opacity-50"><span x-show="!expenseSaving">Save Locally</span><span x-show="expenseSaving">Saving…</span></button></div>
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
