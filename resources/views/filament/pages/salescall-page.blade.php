<x-filament-panels::page>

<div
    id="salescall-page-root"
    x-data="{
        showMap: false,
        leafletMap: null,
        openMap() {
            this.showMap = true;
            this.$nextTick(() => {
                if (this.leafletMap) { this.leafletMap.remove(); this.leafletMap = null; }
                const map = L.map('salescall-map');
                // Street:    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
                // Satellite: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'
                // Light:     'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png'
                // Dark:      'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
                L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                    attribution: '&copy; OpenStreetMap &copy; CartoDB', maxZoom: 18
                }).addTo(map);
                const valid = this.filteredCalls.filter(c => c.lat && c.lng);
                const grouped = {};
                valid.forEach(c => {
                    const key = c.lat + ',' + c.lng;
                    if (!grouped[key]) grouped[key] = { lat: c.lat, lng: c.lng, name: c.name, visits: [] };
                    grouped[key].visits.push(c.date_label + ' · ' + c.time);
                });
                const locations = Object.values(grouped);
                locations.forEach(loc => {
                    let popup = '<b style=\'font-size:18px; display:block; margin-bottom:4px;\'>' + loc.name + '</b>';
                    loc.visits.forEach(v => { 
                        popup += '<br><span style=\'font-size:15px; display:block; line-height:0.7; color:#555;\'>' + v + '</span>'; 
                    });
                    L.marker([loc.lat, loc.lng]).addTo(map).bindPopup(popup, { minWidth: 160 });
                });
                const bounds = locations.map(loc => [loc.lat, loc.lng]);
                if (bounds.length > 1) {
                    map.fitBounds(L.latLngBounds(bounds), { padding: [30, 30] });
                } else if (bounds.length === 1) {
                    map.setView(bounds[0], 14);
                } else {
                    map.setView([14.5995, 120.9842], 10);
                }
                this.leafletMap = map;
            });
        },
        closeMap() {
            this.showMap = false;
            if (this.leafletMap) { this.leafletMap.remove(); this.leafletMap = null; }
        },


        filter: 'today',
        tab: 'overview',
        
        selected: {{ $preselectedId ?? 'null' }},
        showDetail: {{ $preselectedId ? 'true' : 'false' }},

        maximized: false,
        isMobile: window.innerWidth < 1024,
        checkedIn: false,
        syncStatus: null,
        pulling: false,
        isOnline: true,
        showBackOnline: false,
        showLegend: false,

        // null | 'checking' | 'syncing' | 'success' | 'skipped-slow' | 'failed'
        autoSyncStatus: null,
        autoSyncMbps: null,
        autoSyncRetries: 0,
        autoSyncMaxRetries: 5,
        autoSyncTimer: null,
        async attemptAutoSync() {
            if (!this.isOnline || this.autoSyncStatus === 'checking' || this.autoSyncStatus === 'syncing') return;
            clearTimeout(this.autoSyncTimer);
            this.autoSyncStatus = 'checking';
            await $wire.autoSyncIfFast();
        },
        scheduleAutoSyncRetry() {
            if (this.autoSyncRetries >= this.autoSyncMaxRetries) return;
            this.autoSyncRetries++;
            this.autoSyncTimer = setTimeout(() => this.attemptAutoSync(), 30000);
        },
        async checkConnectivity() {
            const wasOnline = this.isOnline;
            try {
                await fetch('{{ config("services.sync.url") }}/api/ping', { method: 'GET', cache: 'no-store', signal: AbortSignal.timeout(3000) });
                this.isOnline = true;
                if (!wasOnline) {
                    this.showBackOnline = true;
                    setTimeout(() => this.showBackOnline = false, 10000);
                }
            } catch {
                this.isOnline = false;
                this.showBackOnline = false;
            }
        },

        syncIconClass(s) {
            return {
                synced:  'text-green-500',
                pending: 'text-red-400',
                failed:  'text-amber-500',
            }[s] ?? 'text-gray-400';
        },
        syncIcon(s) {
            return { synced: 'cloud_done', pending: 'cloud_upload', failed: 'cloud_off' }[s] ?? 'cloud_upload';
        },
        syncIconTitle(s) {
            return {
                synced:  'Synced — data uploaded to server',
                pending: 'Pending — waiting to sync',
                failed:  'Sync failed — will retry on next sync',
            }[s] ?? 'Unknown sync status';
        },

        async startSync() {
            await this.checkConnectivity();
            if (!this.isOnline) return;
            this.syncStatus = 'syncing';
            $wire.syncNow();
        },
        pullStatus: null,
        async startPull() {
            await this.checkConnectivity();
            if (!this.isOnline) return;
            this.pulling = true;
            await $wire.pullNow();
        },
        lastPullAt: 0,
        async autoPull() {
            if (Date.now() - this.lastPullAt < 60000) return;
            this.lastPullAt = Date.now();
            await this.startPull();
        },

        brandForm: {
            1: [{ brand_id: '', quantity: '', brand_other: '' }],
            2: [{ brand_id: '', quantity: '', brand_other: '' }],
            3: [{ brand_id: '', quantity: '', brand_other: '' }],
            4: [{ brand_id: '', quantity: '', brand_other: '' }],
            5: [{ brand_id: '', quantity: '', brand_other: '' }],
        },
        currentBrands: @entangle('currentBrands'),
        brandsSaving: false,
        brandsForGroup(groupId) {
            return this.brands.filter(b => b.material_group_id === groupId);
        },
        isNoneBrand(groupId, brandId) {
            return this.brandsForGroup(groupId).find(b => b.id == brandId)?.name === 'None';
        },
        isOthersBrand(groupId, brandId) {
            return this.brandsForGroup(groupId).find(b => b.id == brandId)?.name === 'Others';
        },
        groupAddDisabled(groupId) {
            return (this.brandForm[groupId] || []).some(r => this.isNoneBrand(groupId, r.brand_id));
        },
        onBrandRowChange(groupId, idx) {
            const row = this.brandForm[groupId][idx];
            if (!this.isOthersBrand(groupId, row.brand_id)) row.brand_other = '';
            if (this.isNoneBrand(groupId, row.brand_id)) this.brandForm[groupId] = [row];
        },
        addBrandItem(groupId) {
            if (this.groupAddDisabled(groupId)) return;
            this.brandForm[groupId].push({ brand_id: '', quantity: '', brand_other: '' });
        },
        removeBrandItem(groupId, idx) {
            const rows = this.brandForm[groupId];
            if (rows.length === 1) { rows[0] = { brand_id: '', quantity: '', brand_other: '' }; return; }
            rows.splice(idx, 1);
        },
        groupIsValid(groupId) {
            const rows = this.brandForm[groupId] || [];
            if (rows.some(r => this.isNoneBrand(groupId, r.brand_id))) return true;
            return rows.some(r => r.brand_id && r.quantity !== '' && r.quantity !== null && Number(r.quantity) > 0);
        },
        async saveBrands() {
            const invalidGroups = [1, 2, 3, 4, 5].filter(g => !this.groupIsValid(g));
            if (invalidGroups.length) {
                const names = invalidGroups.map(g => '- ' + (this.materialGroups.find(mg => mg.id === g)?.name || ('Group ' + g))).join('\n');
                alert('Please select at least one brand with a quantity for:\n' + names + '\n(Or choose None for that group.)');
                return;
            }

            this.brandsSaving = true;
            try {
                await $wire.saveBrands(this.selected, this.brandForm);
            } finally {
                this.brandsSaving = false;
            }
        },

        // Photo capture wizard state (photoStep / category / type) lives in a nested
        // Alpine island under wire:ignore so Livewire morphs cannot reset it. See
        // #salescall-photo-wizard. Preview/delete + list stay on this root component.
        previewPhoto: null,
        photoToDelete: null,
        deletingPhoto: false,
        async confirmDeletePhoto() {
            if (!this.photoToDelete || this.deletingPhoto) return;
            this.deletingPhoto = true;
            try {
                await $wire.deleteImage(this.photoToDelete.id);
            } finally {
                this.deletingPhoto = false;
                this.photoToDelete = null;
            }
        },
        get photosGrouped() {
            const groups = {};
            ($wire.callPhotos || []).forEach(p => {
                if (!groups[p.category]) groups[p.category] = { name: p.category, photos: [] };
                groups[p.category].photos.push(p);
            });
            return Object.values(groups);
        },
        resetPhotoWizard() {
            window.dispatchEvent(new CustomEvent('salescall-photo-wizard-reset'));
        },
        calls: {{ $callsJson }},

        materialGroups: {{ $materialGroupsJson }},
        brands: {{ $brandsJson }},

        customers: {{ $customersJson }},
        showAddCall: false,
        callSearch: '',
        callSearchQuery: '',
        addCallSearch: '',
        addCallCustomerId: null,
        addCallScheduledAt: '',
        addingCall: false,
        get filteredCustomers() {
            const q = this.addCallSearch.trim().toLowerCase();
            if (!q) return this.customers;
            return this.customers.filter(c => c.name.toLowerCase().includes(q));
        },
        nowLocalDatetimeString() {
            const d = new Date();
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            return d.toISOString().slice(0, 16);
        },
        openAddCall() {
            this.showAddCall = true;
            this.addCallSearch = '';
            this.addCallCustomerId = null;
            this.addCallScheduledAt = this.nowLocalDatetimeString();
        },
        closeAddCall() { this.showAddCall = false; },
        selectAddCallCustomer(customerId) { this.addCallCustomerId = customerId; },
        async addCall() {
            if (this.addingCall || !this.addCallCustomerId || !this.addCallScheduledAt) return;
            this.addingCall = true;
            const newCall = await $wire.createUnplannedSalescall(this.addCallCustomerId, this.addCallScheduledAt, this.isOnline);
            this.addingCall = false;
            if (!newCall) return;
            this.calls.push(newCall);
            this.showAddCall = false;
            this.selectCall(newCall.id);
        },

        customerNotes: @entangle('customerNotes'),
        showNoteModal: false,
        noteModalMode: 'add',
        noteForm: { id: null, title: '', body: '' },
        noteSaving: false,
        noteDeletingId: null,
        openAddNote() {
            if (this.atNoteLimit) return;
            this.noteModalMode = 'add';
            this.noteForm = { id: null, title: '', body: '' };
            this.showNoteModal = true;
        },
        openEditNote(note) {
            this.noteModalMode = 'edit';
            this.noteForm = { id: note.id, title: note.title || '', body: note.body };
            this.showNoteModal = true;
        },
        closeNoteModal() { this.showNoteModal = false; },
        noteLimit: 50,
        get atNoteLimit() { return this.customerNotes.length >= this.noteLimit; },
        async saveNote() {
            if (this.noteSaving || !this.noteForm.body.trim()) return;
            this.noteSaving = true;
            if (this.noteModalMode === 'edit') {
                await $wire.updateCustomerNote(this.noteForm.id, this.noteForm.title.trim() || null, this.noteForm.body.trim());
            } else {
                await $wire.saveCustomerNote(this.selectedCall?.customer_id, this.noteForm.title.trim() || null, this.noteForm.body.trim());
            }
            this.noteSaving = false;
            this.showNoteModal = false;
        },
        async deleteNote(noteId) {
            if (this.noteDeletingId) return;
            this.noteDeletingId = noteId;
            await $wire.deleteCustomerNote(noteId);
            this.noteDeletingId = null;
        },

        get inProgressCall() {
            return this.calls.find(c => c.status === 'in_progress') ?? null;
        },

        get filteredCalls() {
            const base = this.inProgressCall
                ? this.calls.filter(c => c.status !== 'in_progress')
                : this.calls;
            const q = this.callSearchQuery.trim().toLowerCase();
            const searched = q
                ? base.filter(c =>
                    (c.name ?? '').toLowerCase().includes(q) ||
                    (c.unique_id ?? '').toLowerCase().includes(q)
                )
                : base;
            if (this.filter === 'today') return searched.filter(c => c.filter_group === 'today');
            if (this.filter === 'week')  return searched.filter(c => ['today','week'].includes(c.filter_group));
            return searched;
        },

        get syncButtonClass() {
            if (this.calls.some(c => c.sync_status === 'pending' || c.sync_status === 'failed')) {
                return 'text-red-500 hover:text-red-600';
            }
            if (this.calls.length > 0 && this.calls.every(c => c.sync_status === 'synced')) {
                return 'text-green-500 hover:text-green-600';
            }
            return 'text-[#434654] hover:text-[#890f00]';
        },
        get syncButtonIcon() {
            if (this.calls.some(c => c.sync_status === 'pending' || c.sync_status === 'failed')) {
                return 'cloud_off';
            }
            if (this.calls.length > 0 && this.calls.every(c => c.sync_status === 'synced')) {
                return 'cloud_done';
            }
            return 'sync';
        },
        get syncButtonTitle() {
            if (this.calls.some(c => c.sync_status === 'pending' || c.sync_status === 'failed')) {
                return 'Pending data — tap to sync to server';
            }
            if (this.calls.length > 0 && this.calls.every(c => c.sync_status === 'synced')) {
                return 'All data synced';
            }
            return 'Sync data to server';
        },

        get selectedCall() { return this.calls.find(c => c.id === this.selected); },
        selectCall(id) {
            if (!id) return;
            this.selected = id;
            this.tab = 'overview';

            // Persist the selection in the URL (no reload, no new history entry) so
            // navigating away and back — e.g. tapping Sales Calls in the sidebar,
            // which does a fresh full page load — restores this call instead of
            // resetting to the in-progress/first-of-month default every time.
            // See mount(): preselectedId reads this back and takes priority over
            // that default.
            const url = new URL(window.location.href);
            url.searchParams.set('call', id);
            window.history.replaceState({}, '', url);

            if (this.miniMap) { this.miniMap.remove(); this.miniMap = null; }

            $wire.loadPhotos(id);
            $wire.loadBrands(id);
            $wire.loadCategories(id);
            const call = this.calls.find(c => c.id === id);
            if (call?.customer_id) { $wire.loadCustomerNotes(call.customer_id); }
            this.resetPhotoWizard();

            // Always reset these regardless of tab — $watch('tab') only fires on changes,
            // so if tab was already 'overview' these would silently persist to the new call.
            this.showCancelReason = false;
            this.cancelReason = '';
            this.showPartialReason = false;
            this.partialReason = '';
            this.previewPhoto = null;

            this.checkedIn = call ? (call.status !== 'scheduled' && call.status !== 'cancelled') : false;
            this.showDetail = true;
            this.initMiniMap();
        },
        doCheckIn() {
            if (this.anyOtherInProgress) return;
            $wire.initiateCheckIn(this.selected);
            const call = this.calls.find(c => c.id === this.selected);
            if (call) { call.status = 'in_progress'; call.sync_status = 'pending'; }
            this.checkedIn = true;
        },
        continueVisit() {
            if (this.anyOtherInProgress) return;
            $wire.resumeVisit(this.selected, this.isOnline);
            const call = this.calls.find(c => c.id === this.selected);
            if (call) { call.status = 'in_progress'; call.sync_status = 'pending'; }
            this.checkedIn = true;
        },
        _persistCheckIn(lat, lng) {
            $wire.checkIn(this.selected, lat, lng, this.isOnline);
            const call = this.calls.find(c => c.id === this.selected);
            if (call) { call.status = 'in_progress'; call.sync_status = 'pending'; }
            this.checkedIn = true;
        },

        get anyOtherInProgress() {
            return this.calls.some(c => c.status === 'in_progress' && c.id !== this.selected);
        },

        hasSavedBrands: @entangle('hasSavedBrands'),
        photosComplete: @entangle('photosComplete'),
        finishing: false,
        showCancelReason: false,
        cancelReason: '',
        showPartialReason: false,
        partialReason: '',
        get canSubmitSalescall() {
            // Photo requirement temporarily disabled (optional for now) — uncomment
            // to re-enable photo-in-every-subcategory as a submit requirement:
            // return this.hasSavedBrands && this.photosComplete;
            return this.hasSavedBrands;
        },
        finishVisit(outcome, reason = null) {
            if (this.finishing) return;
            if (outcome === 'cancelled' && !reason) { this.showCancelReason = true; return; }
            if (outcome === 'partially_completed' && !reason) { this.showPartialReason = true; return; }
            this.finishing = true;
            // Safety net: reset finishing if finish-done event never fires (network/Livewire failure)
            setTimeout(() => { this.finishing = false; }, 15000);
            $wire.initiateFinish(this.selected, outcome, reason);
            const call = this.calls.find(c => c.id === this.selected);
            if (call) { call.status = outcome; call.sync_status = 'pending'; }
            this.showCancelReason = false;
            this.cancelReason = '';
            this.showPartialReason = false;
            this.partialReason = '';

            // Reset the detail panel to empty so the rep has to explicitly pick
            // the next call, rather than lingering on the one just finished.
            this.selected = null;
            this.checkedIn = false;
            this.showDetail = false;
            const url = new URL(window.location.href);
            url.searchParams.delete('call');
            window.history.replaceState({}, '', url);
        },
        confirmCancel() {
            if (!this.cancelReason.trim()) return;
            this.finishVisit('cancelled', this.cancelReason.trim());
        },
        confirmPartial() {
            if (!this.partialReason.trim()) return;
            this.finishVisit('partially_completed', this.partialReason.trim());
        },
        _persistFinishLocation(lat, lng) {
            $wire.finishLocation(this.selected, lat, lng, this.isOnline);
        },

        statusLabel(s) {
            return {
                in_progress: 'In Progress',
                scheduled: 'Scheduled',
                completed: 'Completed',
                partially_completed: 'Partially Completed',
                cancelled: 'Cancelled',
            }[s] ?? s;
        },
        statusBadgeClass(s) {
            return {
                in_progress:          'bg-amber-100 text-amber-700',
                scheduled:            'bg-blue-100 text-blue-700',
                completed:            'bg-green-100 text-green-700',
                partially_completed:  'bg-orange-100 text-orange-700',
                cancelled:            'bg-gray-200 text-gray-600',
            }[s] ?? '';
        },
        seqBgClass(s) {
            return {
                in_progress:          'bg-primary-fixed text-primary',
                scheduled:            'bg-surface-high text-on-surface-var',
                completed:            'bg-secondary-cont text-on-secondary-cont',
                partially_completed:  'bg-orange-100 text-orange-700',
                cancelled:            'bg-gray-200 text-gray-600',
            }[s] ?? '';
        },
        tabLabel(t) {
            return { overview: 'Overview', brands: 'Brands', ccr: 'CCR', mrf: 'MRF', photos: 'Photos', profile: 'Change Profile', activity: 'Activity Log' }[t] ?? t;
        },

        miniMap: null,
        initMiniMap() {
            // $nextTick alone isn't enough — x-show removes display:none but the browser
            // may not have painted and measured the container yet. A 150ms delay lets the
            // layout settle before Leaflet reads the container dimensions.
            this.$nextTick(() => setTimeout(() => {
                if (!this.checkedIn || this.tab !== 'overview') return;
                const el = document.getElementById('salescall-mini-map');
                if (!el) return;
                if (this.miniMap) { this.miniMap.remove(); this.miniMap = null; }
                const lat = this.selectedCall?.lat;
                const lng = this.selectedCall?.lng;
                if (!lat || !lng) return;
                const map = L.map('salescall-mini-map', {
                    zoomControl: false,
                    dragging: false,
                    scrollWheelZoom: false,
                    doubleClickZoom: false,
                    touchZoom: false,
                    attributionControl: false,
                });
                L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { maxZoom: 18 }).addTo(map);
                map.setView([lat, lng], 17);
                L.marker([lat, lng]).addTo(map);
                this.miniMap = map;
                // Force Leaflet to recalculate container size after rendering
                map.invalidateSize();
            }, 150));
        },

        currentProfile: @entangle('currentProfile'),
        categories: {{ $categoriesJson }},
        subCategories: {{ $subCategoriesJson }},
        profileCategoryId: '',
        profileSubCategoryId: null,
        profileWithForm: false,
        profileFormType: null,
        currentCategory: @entangle('currentCategory'),
        categorySaving: false,
        profile: {
            registered_name: '', owner_name: '', address: '', tin: '',
            landline: '', mobile: '', classification: '',
            incentive_type: 'lumpsum_monthly',
            birthday: '', gender: '', marital_status: '',
            brand_products: [], has_signature: false,
        },
        profileSignatureData: null,
        profileSigCanvas: null,
        profileSigCtx: null,
        profileSigDrawing: false,
        profileSaving: false,

        get profileSubCategoryOptions() {
            if (!this.profileCategoryId) return [];
            return this.subCategories.filter(s => s.category_id == this.profileCategoryId);
        },
        get categoryChanged() {
            if (!this.profileSubCategoryId) return false;
            if (!this.currentCategory?.category_id) return true;
            return String(this.profileCategoryId) !== String(this.currentCategory.category_id)
                || String(this.profileSubCategoryId) !== String(this.currentCategory.sub_category_id);
        },
        onProfileSubCategoryChange() {
            const sub = this.subCategories.find(s => s.id == this.profileSubCategoryId);
            this.profileWithForm = !!sub?.with_form;
            const n = (sub?.name || '').toLowerCase();
            if (n.includes('madp')) this.profileFormType = 'madp';
            else if (n.includes('smdp')) this.profileFormType = 'smdp';
            else if (n.includes('vip')) this.profileFormType = 'vip';
            else this.profileFormType = null;
            if (this.profileWithForm) this.$nextTick(() => this.initProfileSig());
        },
        async saveCategory() {
            if (!this.profileCategoryId || !this.profileSubCategoryId) return;
            this.categorySaving = true;
            try {
                await $wire.saveCategory(this.selected, this.profileCategoryId, this.profileSubCategoryId);
            } finally {
                this.categorySaving = false;
            }
        },
        profileComputedAge() {
            if (!this.profile.birthday) return '';
            const dob = new Date(this.profile.birthday), today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
            return isNaN(age) ? '' : age;
        },
        addBrandRow() { this.profile.brand_products.push({ brand: '', supplier: '', monthly_volume: '' }); },
        removeBrandRow(i) { this.profile.brand_products.splice(i, 1); },
        initProfileSig() {
            this.$nextTick(() => {
                const canvas = document.getElementById('profile-sig-pad');
                if (!canvas || !canvas.offsetWidth) return;
                this.profileSigCanvas = canvas;
                this.profileSigCtx = canvas.getContext('2d');
                canvas.width = canvas.offsetWidth;
                canvas.height = canvas.offsetHeight;
                this.profileSigCtx.strokeStyle = '#1e293b';
                this.profileSigCtx.lineWidth = 2;
                this.profileSigCtx.lineCap = 'round';
                this.profileSigCtx.lineJoin = 'round';
            });
        },
        profileSigGetPos(e) {
            const rect = this.profileSigCanvas.getBoundingClientRect();
            const src = e.touches ? e.touches[0] : e;
            return {
                x: (src.clientX - rect.left) * (this.profileSigCanvas.width / rect.width),
                y: (src.clientY - rect.top) * (this.profileSigCanvas.height / rect.height),
            };
        },
        profileSigStart(e) {
            if (!this.profileSigCanvas) { this.initProfileSig(); return; }
            this.profileSigDrawing = true;
            const pos = this.profileSigGetPos(e);
            this.profileSigCtx.beginPath();
            this.profileSigCtx.moveTo(pos.x, pos.y);
        },
        profileSigDraw(e) {
            if (!this.profileSigDrawing || !this.profileSigCtx) return;
            const pos = this.profileSigGetPos(e);
            this.profileSigCtx.lineTo(pos.x, pos.y);
            this.profileSigCtx.stroke();
        },
        profileSigEnd() {
            this.profileSigDrawing = false;
            if (this.profileSigCanvas) this.profileSignatureData = this.profileSigCanvas.toDataURL('image/png');
        },
        clearProfileSig() {
            if (this.profileSigCtx && this.profileSigCanvas) {
                this.profileSigCtx.clearRect(0, 0, this.profileSigCanvas.width, this.profileSigCanvas.height);
                this.profileSignatureData = null;
            }
        },
        async submitProfile() {
            if (!this.profileSubCategoryId) return;
            this.profileSaving = true;
            try {
                await $wire.saveCategory(this.selected, this.profileCategoryId, this.profileSubCategoryId, true);
                await $wire.saveProfile(
                    this.selected, this.profileSubCategoryId,
                    this.profile.registered_name, this.profile.owner_name, this.profile.address,
                    this.profile.tin, this.profile.landline, this.profile.mobile, this.profile.classification,
                    this.profileFormType === 'madp' ? this.profile.incentive_type : null,
                    this.profile.birthday, this.profile.gender, this.profile.marital_status,
                    this.profileFormType === 'vip' ? this.profile.brand_products : [],
                    this.profileSignatureData,
                );
                this.profile.has_signature = true;
            } finally {
                this.profileSaving = false;
            }
        }

    }"
    
    x-init="
        // Shared wizard step for list visibility outside the wire:ignore island.
        if (!Alpine.store('salescallPhotoWizard')) {
            Alpine.store('salescallPhotoWizard', { photoStep: 0, photoCategory: null, photoType: null });
        }

        checkedIn = selectedCall ? (selectedCall.status !== 'scheduled' && selectedCall.status !== 'cancelled') : false;
        
        if (selected) { $wire.loadPhotos(selected); $wire.loadBrands(selected); $wire.loadCategories(selected); }

        $watch('currentBrands', (b) => {
            if (!b || !Object.keys(b).length) return;
            brandForm = JSON.parse(JSON.stringify(b));
        });
        $watch('currentCategory', (c) => {
            if (!c) return;
            profileCategoryId = c.category_id ?? '';
            profileSubCategoryId = c.sub_category_id ?? null;
            if (profileSubCategoryId) {
                onProfileSubCategoryChange();
            } else {
                profileWithForm = false;
                profileFormType = null;
            }
        });
        $watch('currentProfile', (p) => {
            if (!p || !p.registered_name) return;
            profile = { ...profile, ...p };
        });
        $watch('tab', (value) => {
            if (value === 'profile' && selected) { $wire.loadProfile(selected); $wire.loadCategories(selected); }
            if (value === 'overview') initMiniMap();
            // Defensive reset: don't let a half-finished cancel-reason panel or photo
            // modal from a previous tab silently hide the finish-action buttons.
            showCancelReason = false;
            cancelReason = '';
            showPartialReason = false;
            partialReason = '';
            photoToDelete = null;
            previewPhoto = null;
        });
        $watch('checkedIn', (v) => { if (v) initMiniMap(); });
        // $watch doesn't fire for the initial value, so manually boot the map
        // if the page loads with a call already in a checked-in state.
        if (checkedIn) initMiniMap();


        checkConnectivity().then(() => attemptAutoSync());
        autoPull();
        window.addEventListener('resize', () => { isMobile = window.innerWidth < 1024; });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') autoPull();
        });

        document.addEventListener('focusin', (e) => {
            const tag = e.target.tagName;
            if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') return;
            document.querySelectorAll('.keyboard-scroll').forEach(el => {
                el.style.paddingBottom = '350px';
            });
            setTimeout(() => e.target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 350);
        });

        document.addEventListener('focusout', (e) => {
            const tag = e.target.tagName;
            if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') return;
            setTimeout(() => {
                const activeTag = document.activeElement ? document.activeElement.tagName : '';
                if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(activeTag)) {
                    document.querySelectorAll('.keyboard-scroll').forEach(el => {
                        el.style.paddingBottom = '';
                    });
                }
            }, 200);
        });
    "





    @pull-done.window="
        pulling = false;
        pullStatus = 'success';
        setTimeout(() => pullStatus = null, 3000)
    "

    @pull-failed.window="
        pulling = false;
        pullStatus = 'failed';
        setTimeout(() => pullStatus = null, 3000)
    "

    @online.window="isOnline = true; attemptAutoSync()"
    @offline.window="isOnline = false; autoSyncStatus = null; clearTimeout(autoSyncTimer)"

    @auto-sync-skipped.window="
        autoSyncMbps = $event.detail.mbps ?? null;
        if ($event.detail.reason === 'slow-connection') {
            autoSyncStatus = 'skipped-slow';
            scheduleAutoSyncRetry();
        } else {
            autoSyncStatus = null;
        }
        setTimeout(() => { if (autoSyncStatus === 'skipped-slow') autoSyncStatus = null; }, 6000);
    "
    @auto-sync-started.window="autoSyncStatus = 'syncing'; autoSyncRetries = 0;"
    @auto-sync-done.window="
        autoSyncStatus = $event.detail.success ? 'success' : 'failed';
        setTimeout(() => autoSyncStatus = null, 4000);
    "

    @calls-sync-refreshed.window="
        const statuses = $event.detail.statuses;
        calls = calls.map(c => ({ ...c, sync_status: statuses[c.id] ?? c.sync_status }));
    "

    x-on:use-browser-geolocation.window="
        const id = $event.detail.salescallId;
        if (!navigator.geolocation) { $wire.checkIn(id, 0, 0, isOnline); return; }
        navigator.geolocation.getCurrentPosition(
            (pos) => $wire.checkIn(id, pos.coords.latitude, pos.coords.longitude, isOnline),
            (err) => { console.warn('GPS error:', err.code, err.message); $wire.checkIn(id, 0, 0, isOnline); },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        )
    "

    x-on:use-browser-geolocation-submit.window="
        const id = $event.detail.salescallId;
        if (!navigator.geolocation) { _persistFinishLocation(0, 0); return; }
        navigator.geolocation.getCurrentPosition(
            (pos) => _persistFinishLocation(pos.coords.latitude, pos.coords.longitude),
            (err) => { console.warn('GPS error:', err.code, err.message); _persistFinishLocation(0, 0); },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        )
    "

    @finish-done.window="
        finishing = false;
        const { salescallId, outcome } = $event.detail;
        const doneCall = calls.find(c => c.id === salescallId);
        if (doneCall && outcome) {
            doneCall.status = outcome;
            doneCall.sync_status = 'pending';
        }
        if (doneCall && salescallId === selected) {
            checkedIn = false;
        }
    "
    @sync-done.window="syncStatus = 'success'; setTimeout(() => syncStatus = null, 2500)"

    class="flex flex-col bg-gray-50"
    style="height: calc(100dvh - 5rem); overflow: hidden;"
>

    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-family: 'Material Symbols Outlined';
            font-style: normal;
            line-height: 1;
            display: inline-block;
            vertical-align: middle;
        }
        .mat-fill { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        .fi-page-content { padding: 0 !important; }
    </style>

    {{-- SYNC MODAL --}}
    <div
        x-cloak
        x-show="syncStatus !== null"
        x-transition.opacity
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center">
        <div x-transition.scale class="bg-white rounded-3xl px-10 py-10 shadow-2xl flex flex-col items-center gap-5 w-72">
            <div x-show="syncStatus === 'syncing'" class="flex flex-col items-center gap-4">
                <div class="w-16 h-16 border-4 border-[#890f00] border-t-transparent rounded-full animate-spin"></div>
                <div class="text-center">
                    <p class="font-black text-xl text-[#191c1e]">Syncing...</p>
                    <p class="text-sm text-[#737685] mt-1">Uploading your sales calls</p>
                </div>
            </div>
            <div x-show="syncStatus === 'success'" class="flex flex-col items-center gap-4">
                <span class="material-symbols-outlined mat-fill text-green-500" style="font-size: 64px;">check_circle</span>
                <div class="text-center">
                    <p class="font-black text-xl text-[#191c1e]">All Synced!</p>
                    <p class="text-sm text-[#737685] mt-1">Data uploaded successfully</p>
                </div>
            </div>
        </div>
    </div>

    {{-- PHOTO PREVIEW MODAL --}}
    <div
        x-cloak
        x-show="previewPhoto"
        x-transition.opacity
        @click.self="previewPhoto = null"
        class="fixed inset-0 bg-black/80 z-50 flex items-center justify-center">
        <div class="relative w-[90vw] h-[90vh] flex flex-col items-center justify-center">
            <button @click="previewPhoto = null"
                class="absolute -top-2 right-0 w-10 h-10 rounded-full bg-white/10 flex items-center justify-center hover:bg-white/20 transition-colors">
                <span class="material-symbols-outlined text-white text-2xl">close</span>
            </button>
            <img :src="previewPhoto?.url" class="flex-1 min-h-0 w-full object-contain rounded-2xl shadow-2xl" />
            <div class="mt-3 text-center shrink-0">
                <p class="text-white font-bold text-sm" x-text="previewPhoto?.type"></p>
                <p class="text-white/60 text-xs" x-text="previewPhoto?.category"></p>
            </div>
        </div>
    </div>

    {{-- DELETE PHOTO CONFIRMATION MODAL --}}
    <div
        x-cloak
        x-show="photoToDelete"
        x-transition.opacity
        @click.self="photoToDelete = null"
        class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div x-transition.scale class="bg-white rounded-3xl p-6 shadow-2xl w-full max-w-xs flex flex-col items-center gap-4">
            <div class="w-14 h-14 rounded-full bg-red-50 flex items-center justify-center">
                <span class="material-symbols-outlined text-red-600 text-3xl">warning</span>
            </div>
            <div class="text-center">
                <p class="font-black text-[#191c1e]">Delete this photo?</p>
                <p class="text-xs text-[#737685] mt-1">This can't be undone. If it's already synced, it may still exist on the portal.</p>
            </div>
            <img :src="photoToDelete?.url" class="w-24 h-24 object-cover rounded-xl border border-gray-100" />
            <div class="grid grid-cols-2 gap-2 w-full">
                <button @click="photoToDelete = null" :disabled="deletingPhoto"
                    class="h-11 bg-gray-100 text-[#737685] rounded-2xl font-bold text-sm disabled:opacity-50">
                    Cancel
                </button>
                <button @click="confirmDeletePhoto()" :disabled="deletingPhoto"
                    class="h-11 bg-red-600 text-white rounded-2xl font-bold text-sm disabled:opacity-50">
                    <span x-show="!deletingPhoto">Delete</span>
                    <span x-show="deletingPhoto" class="flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                    </span>
                </button>
            </div>
        </div>
    </div>

    {{-- ADD UNPLANNED SALESCALL MODAL --}}
    <div
        x-cloak
        x-show="showAddCall"
        x-transition.opacity
        @click.self="closeAddCall()"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end lg:items-center justify-center">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-4 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            class="bg-white rounded-t-3xl lg:rounded-3xl w-full lg:max-w-md shadow-2xl overflow-hidden flex flex-col"
            style="max-height: 80vh;">

            {{-- Modal Header --}}
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100 shrink-0">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined mat-fill text-[#890f00] text-2xl">add_circle</span>
                    <h2 class="text-lg font-extrabold text-[#191c1e]">Add Unplanned Salescall</h2>
                </div>
                <button @click="closeAddCall()"
                    class="w-8 h-8 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors">
                    <span class="material-symbols-outlined text-[#434654] text-lg">close</span>
                </button>
            </div>

            {{-- Scheduled At --}}
            <div class="px-6 py-4 border-b border-gray-100 shrink-0">
                <label class="text-[11px] font-extrabold text-[#737685] uppercase tracking-wider block mb-1.5">Scheduled At</label>
                <input
                    x-model="addCallScheduledAt"
                    type="datetime-local"
                    class="w-full bg-[#edeef0] border-none rounded-xl px-4 py-2.5 text-sm text-[#191c1e] focus:ring-2 focus:ring-[#890f00]"
                />
            </div>

            {{-- Search --}}
            <div class="px-6 py-4 border-b border-gray-100 shrink-0">
                <div class="flex items-center bg-[#edeef0] rounded-full px-4 py-2 gap-2">
                    <span class="material-symbols-outlined text-[#737685] text-lg">search</span>
                    <input
                        x-model="addCallSearch"
                        class="bg-transparent border-none focus:ring-0 text-sm w-full text-[#191c1e]"
                        placeholder="Search customer name..."
                        type="text"
                    />
                </div>
            </div>

            {{-- Customer List --}}
            <div class="flex-1 overflow-y-auto px-3 py-2">
                <template x-for="customer in filteredCustomers" :key="customer.id">
                    <button
                        @click="selectAddCallCustomer(customer.id)"
                        :class="addCallCustomerId === customer.id ? 'bg-red-50 border-2 border-[#890f00]' : 'border-2 border-transparent hover:bg-gray-50'"
                        class="w-full text-left px-3 py-3 rounded-2xl transition-colors flex items-center justify-between gap-2">
                        <span class="min-w-0">
                            <span class="text-sm font-semibold text-[#191c1e] block truncate" x-text="customer.name"></span>
                            <span class="text-xs text-[#737685] block truncate" x-text="customer.address || '—'"></span>
                        </span>
                        <span x-show="addCallCustomerId === customer.id" class="material-symbols-outlined text-[#890f00] shrink-0">check_circle</span>
                    </button>
                </template>
                <p x-show="filteredCustomers.length === 0" class="text-center text-sm text-[#737685] py-8">No customers found.</p>
            </div>

            {{-- Footer --}}
            <div class="px-6 py-4 border-t border-gray-100 shrink-0">
                <button
                    @click="addCall()"
                    :disabled="addingCall || !addCallCustomerId || !addCallScheduledAt"
                    class="w-full bg-[#890f00] text-white font-bold text-sm rounded-full py-3 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-[#6f0c00] transition-colors">
                    <span x-text="addingCall ? 'Adding...' : 'Add Salescall'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- QUICK NOTE MODAL (add / edit) --}}
    <div
        x-cloak
        x-show="showNoteModal"
        x-transition.opacity
        @click.self="closeNoteModal()"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end lg:items-center justify-center">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-4 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            class="bg-white rounded-t-3xl lg:rounded-3xl w-full lg:max-w-md shadow-2xl overflow-hidden flex flex-col">

            {{-- Modal Header --}}
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100 shrink-0">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined mat-fill text-[#890f00] text-2xl">note_alt</span>
                    <h2 class="text-lg font-extrabold text-[#191c1e]" x-text="noteModalMode === 'edit' ? 'Edit Note' : 'Add Quick Note'"></h2>
                </div>
                <button @click="closeNoteModal()"
                    class="w-8 h-8 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors">
                    <span class="material-symbols-outlined text-[#434654] text-lg">close</span>
                </button>
            </div>

            {{-- Form --}}
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="text-[11px] font-extrabold text-[#737685] uppercase tracking-wider block mb-1.5">Title <span class="normal-case font-medium text-gray-400">(optional)</span></label>
                    <input
                        x-model="noteForm.title"
                        type="text"
                        placeholder="e.g. Owner preferences"
                        class="w-full bg-[#edeef0] border-none rounded-xl px-4 py-2.5 text-sm text-[#191c1e] focus:ring-2 focus:ring-[#890f00]"
                    />
                </div>
                <div>
                    <label class="text-[11px] font-extrabold text-[#737685] uppercase tracking-wider block mb-1.5">Note</label>
                    <textarea
                        x-model="noteForm.body"
                        rows="4"
                        placeholder="Write a short note about this customer..."
                        class="w-full bg-[#edeef0] border-none rounded-xl px-4 py-2.5 text-sm text-[#191c1e] focus:ring-2 focus:ring-[#890f00] resize-none"
                    ></textarea>
                </div>
            </div>

            {{-- Footer --}}
            <div class="px-6 pb-6 shrink-0">
                <button
                    @click="saveNote()"
                    :disabled="noteSaving || !noteForm.body.trim() || (noteModalMode === 'add' && atNoteLimit)"
                    class="w-full bg-[#890f00] text-white font-bold text-sm rounded-full py-3 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-[#6f0c00] transition-colors">
                    <span x-text="noteSaving ? 'Saving...' : (noteModalMode === 'edit' ? 'Save Changes' : 'Add Note')"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- LEGENDS MODAL --}}
    <div
        x-cloak
        x-show="showLegend"
        x-transition.opacity
        @click.self="showLegend = false"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-end lg:items-center justify-center">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-4 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            class="bg-white rounded-t-3xl lg:rounded-3xl w-full lg:max-w-md shadow-2xl overflow-hidden">

            {{-- Modal Header --}}
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined mat-fill text-[#890f00] text-2xl">info</span>
                    <h2 class="text-lg font-extrabold text-[#191c1e]">Icon Legend</h2>
                </div>
                <button @click="showLegend = false"
                    class="w-8 h-8 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors">
                    <span class="material-symbols-outlined text-[#434654] text-lg">close</span>
                </button>
            </div>

            <div class="overflow-y-auto max-h-[70vh] scrollbar-hide p-6 space-y-6">

                {{-- Visit Status --}}
                <div>
                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-3">Visit Status</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-blue-100 text-blue-700 shrink-0 w-24 text-center">Scheduled</span>
                            <p class="text-sm text-[#434654]">Visit is planned but not yet started</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-amber-100 text-amber-700 shrink-0 w-24 text-center">In Progress</span>
                            <p class="text-sm text-[#434654]">Checked in — visit is currently ongoing</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase bg-green-100 text-green-700 shrink-0 w-24 text-center">Completed</span>
                            <p class="text-sm text-[#434654]">Visit submitted and done</p>
                        </div>
                    </div>
                </div>

                {{-- Sync Status --}}
                <div>
                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-3">Sync Status</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-green-500 text-xl shrink-0 w-6 text-center">cloud_done</span>
                            <p class="text-sm text-[#434654]">Synced — data uploaded to server</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-red-400 text-xl shrink-0 w-6 text-center">cloud_upload</span>
                            <p class="text-sm text-[#434654]">Pending — saved locally, waiting to sync</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-amber-500 text-xl shrink-0 w-6 text-center">cloud_off</span>
                            <p class="text-sm text-[#434654]">Sync failed — will retry on next sync</p>
                        </div>
                    </div>
                </div>

                {{-- Action Icons --}}
                <div>
                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-3">Header Actions</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#434654] text-xl shrink-0 w-6 text-center">sync</span>
                            <p class="text-sm text-[#434654]">Push pending salescalls up to the server</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-[#434654] text-xl shrink-0 w-6 text-center">cloud_download</span>
                            <p class="text-sm text-[#434654]">Pull latest itinerary schedule from server</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-[#434654] text-xl shrink-0 w-6 text-center">open_in_full</span>
                            <p class="text-sm text-[#434654]">Expand detail panel to full width</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-[#434654] text-xl shrink-0 w-6 text-center">close_fullscreen</span>
                            <p class="text-sm text-[#434654]">Restore split view (list + detail)</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#434654] text-xl shrink-0 w-6 text-center">arrow_back</span>
                            <p class="text-sm text-[#434654]">Go back to salescall list</p>
                        </div>
                    </div>
                </div>

                {{-- Visit Actions --}}
                <div>
                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-3">Visit Actions</p>
                    <div class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined mat-fill text-[#890f00] text-xl shrink-0 w-6 text-center">my_location</span>
                            <p class="text-sm text-[#434654]">Mark Arrival — records GPS arrival location</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#737685] text-xl shrink-0 w-6 text-center">assignment</span>
                            <p class="text-sm text-[#434654]">CCR — Customer Call Report form</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#737685] text-xl shrink-0 w-6 text-center">inventory</span>
                            <p class="text-sm text-[#434654]">MRF — Merchandising Report Form</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#737685] text-xl shrink-0 w-6 text-center">photo_camera</span>
                            <p class="text-sm text-[#434654]">Upload photos of the store display</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[#737685] text-xl shrink-0 w-6 text-center">note_alt</span>
                            <p class="text-sm text-[#434654]">Add a quick note or instant feedback</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- MAP OVERLAY --}}
    <div
        x-cloak
        x-show="showMap"
        x-transition.opacity
        @click.self="closeMap()"
        class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">

        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="scale-95 opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            class="bg-white rounded-3xl overflow-hidden shadow-2xl w-full"
            style="height: 80vh; width: 90vw; max-width: 90vw;">

            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined mat-fill text-[#890f00]">map</span>
                    <h3 class="font-bold text-[#191c1e]"
                        x-text="filter === 'today' ? 'Today\'s Calls' : filter === 'week' ? 'This Week\'s Calls' : 'This Month\'s Calls'"></h3>
                    <span class="text-xs text-[#737685]" x-text="'(' + filteredCalls.filter(c => c.lat && c.lng).length + ' locations)'"></span>
                </div>
                <button @click="closeMap()"
                    class="w-8 h-8 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors">
                    <span class="material-symbols-outlined text-[#434654] text-lg">close</span>
                </button>
            </div>
            <div id="salescall-map" style="height: calc(80vh - 61px);"></div>
        </div>
    </div>



    {{-- TOP APP BAR --}}
    <header class="w-full bg-white/90 backdrop-blur-md border-b border-gray-100 shrink-0 px-4 lg:px-6">

        {{-- Mobile header --}}
        <div x-show="isMobile" class="flex items-center justify-between h-14">
            <button
                x-show="showDetail"
                @click="showDetail = false"
                title="Back to salescall list"
                class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center mr-2 shrink-0">
                <span class="material-symbols-outlined text-[#434654] text-xl">arrow_back</span>
            </button>
            <div x-show="!showDetail" class="shrink-0">
                <h1 class="text-lg font-bold text-on-surface">Sales Calls</h1>
            </div>
            <div x-show="showDetail" class="flex-1 min-w-0">
                {{-- Hidden for now — see the two matching blocks below.
                <p class="text-xs font-black text-[#890f00] uppercase tracking-wider truncate">
                    <span x-text="'SALESCALL #' + (selectedCall?.id ?? '')"></span>
                </p>
                --}}
                <h1 class="text-base font-bold text-[#191c1e] leading-tight truncate" x-text="selectedCall?.name"></h1>
            </div>
            <button
                @click="startSync()"
                :title="syncButtonTitle"
                :class="syncButtonClass"
                class="material-symbols-outlined mat-fill transition-colors ml-2 shrink-0"
                x-text="syncButtonIcon">
            </button>
            <button
                @click="startPull()"
                title="Pull latest schedule from server"
                class="material-symbols-outlined mat-fill transition-colors ml-1 shrink-0"
                :class="pulling ? 'text-blue-500 animate-spin' : 'text-[#434654] hover:text-[#890f00]'">
                cloud_download
            </button>
        </div>

        {{-- Mobile search + legend (list view only) --}}
        <div x-show="isMobile && !showDetail" class="pb-3">
            <div class="flex items-center gap-2">
                <div class="flex items-center bg-[#edeef0] rounded-full px-4 py-2 flex-1 gap-2">
                    <span class="material-symbols-outlined text-[#737685] text-lg">search</span>
                    <input
                        x-model="callSearch"
                        @keydown.enter="callSearchQuery = callSearch"
                        @keydown.escape="callSearch = ''; callSearchQuery = ''"
                        class="bg-transparent border-none focus:ring-0 text-sm w-full text-[#191c1e]"
                        placeholder="Search customer"
                        type="search"
                        enterkeyhint="search"/>
                    <button x-show="callSearchQuery" @click="callSearch = ''; callSearchQuery = ''"
                        class="text-gray-400 hover:text-gray-600 text-sm leading-none">&times;</button>
                </div>
                <button
                    @click="showLegend = true"
                    title="Icon legend & reference guide"
                    class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors shrink-0">
                    <span class="material-symbols-outlined text-[#155dfc] text-xl">info</span>
                </button>
                @if($canAddSalescall)
                <button
                    @click="openAddCall()"
                    title="Add unplanned salescall"
                    class="w-9 h-9 rounded-full bg-[#890f00] flex items-center justify-center hover:bg-[#6f0c00] transition-colors shrink-0">
                    <span class="material-symbols-outlined text-white text-xl">add</span>
                </button>
                @endif
            </div>
        </div>

        {{-- Tablet/Desktop header --}}
        <div x-show="!isMobile" class="flex items-center justify-between h-16">
            <div>
                <h1 class="text-2xl font-bold text-on-surface">Sales Calls</h1>
                <p class="text-xs text-[#434654] mt-0.5">All scheduled visits from your itineraries</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center bg-[#edeef0] rounded-full px-4 py-2 w-64 gap-2">
                    <span class="material-symbols-outlined text-[#737685] text-lg">search</span>
                    <input
                        x-model="callSearch"
                        @keydown.enter="callSearchQuery = callSearch"
                        @keydown.escape="callSearch = ''; callSearchQuery = ''"
                        class="bg-transparent border-none focus:ring-0 text-sm w-full text-[#191c1e]"
                        placeholder="Search customer"
                        type="search"
                        enterkeyhint="search"/>
                    <button x-show="callSearchQuery" @click="callSearch = ''; callSearchQuery = ''"
                        class="text-gray-400 hover:text-gray-600 text-sm leading-none">&times;</button>
                </div>
                <button
                    @click="showLegend = true"
                    title="Icon legend & reference guide"
                    class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors shrink-0">
                    <span class="material-symbols-outlined text-[#434654] text-xl">info</span>
                </button>
                @if($canAddSalescall)
                <button
                    @click="openAddCall()"
                    title="Add unplanned salescall"
                    class="w-9 h-9 rounded-full bg-[#890f00] flex items-center justify-center hover:bg-[#6f0c00] transition-colors shrink-0">
                    <span class="material-symbols-outlined text-white text-xl">add</span>
                </button>
                @endif
                <button
                    @click="startSync()"
                    :class="syncButtonClass"
                    :title="syncButtonTitle"
                    class="material-symbols-outlined mat-fill transition-colors"
                    x-text="syncButtonIcon">
                </button>
                <button
                    @click="startPull()"
                    title="Pull latest schedule from server"
                    class="material-symbols-outlined mat-fill transition-colors"
                    :class="pulling ? 'text-blue-500 animate-spin' : 'text-[#434654] hover:text-[#890f00]'">
                    cloud_download
                </button>
            </div>
        </div>

    </header>

    {{-- OFFLINE BANNER --}}
    <div
        x-show="!isOnline"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">wifi_off</span>
        <div>
            <p class="font-bold text-sm leading-tight">You're offline</p>
            <p class="text-xs opacity-80">Changes are saved locally and will sync when you reconnect.</p>
        </div>
    </div>

    {{-- BACK ONLINE BANNER --}}
    <div
        x-show="showBackOnline"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">wifi</span>
        <div>
            <p class="font-bold text-sm leading-tight">You're back online</p>
            <p class="text-xs opacity-80">Pending data will auto-sync once the connection is fast enough.</p>
        </div>
    </div>

    {{-- AUTO-SYNC: checking / slow connection --}}
    <div
        x-show="autoSyncStatus === 'checking' || autoSyncStatus === 'skipped-slow'"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0 animate-pulse">speed</span>
        <div>
            <template x-if="autoSyncStatus === 'checking'">
                <p class="font-bold text-sm leading-tight">Checking connection speed...</p>
            </template>
            <template x-if="autoSyncStatus === 'skipped-slow'">
                <p class="font-bold text-sm leading-tight">
                    Connection too slow to auto-sync<span x-show="autoSyncMbps"> (~<span x-text="autoSyncMbps ? autoSyncMbps.toFixed(1) : ''"></span> Mbps)</span>.
                    <span class="font-normal opacity-80">Will retry, or tap sync to force it now.</span>
                </p>
            </template>
        </div>
    </div>

    {{-- AUTO-SYNC: uploading --}}
    <div
        x-show="autoSyncStatus === 'syncing'"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0 animate-spin">progress_activity</span>
        <p class="font-bold text-sm">Auto-syncing pending data...</p>
    </div>

    {{-- AUTO-SYNC: done --}}
    <div
        x-show="autoSyncStatus === 'success'"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">cloud_done</span>
        <p class="font-bold text-sm">Auto-synced successfully.</p>
    </div>

    {{-- AUTO-SYNC: failed --}}
    <div
        x-show="autoSyncStatus === 'failed'"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">cloud_off</span>
        <p class="font-bold text-sm">Auto-sync failed. Will retry, or tap sync to try now.</p>
    </div>

    {{-- PULL SUCCESS BANNER --}}
    <div
        x-show="pullStatus === 'success'"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">cloud_done</span>
        <p class="font-bold text-sm">Data updated successfully.</p>
    </div>

    {{-- PULL FAILED BANNER --}}
    <div
        x-show="pullStatus === 'failed'"
        x-transition
        class="mx-4 lg:mx-6 mt-2 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl shrink-0">
        <span class="material-symbols-outlined mat-fill text-xl shrink-0">cloud_off</span>
        <p class="font-bold text-sm">Pull failed. Please try again.</p>
    </div>



    {{-- SPLIT VIEW --}}
    <div class="flex flex-1 overflow-hidden px-4 lg:px-6 pb-4 lg:pb-6 gap-5 mt-3">

        {{--
            LEFT PANEL
            Mobile:  visible when !showDetail (full list view), hidden when showDetail
            Tablet+: visible when !maximized (split), hidden when maximized
        --}}
        <div
            x-show="!(isMobile && showDetail) && !(!isMobile && maximized)"
            :class="isMobile ? 'w-full' : 'w-2/5'"
            class="flex flex-col gap-3 overflow-hidden">

            {{-- In Progress Pin --}}
            <template x-if="inProgressCall">
                <div
                    @click="selectCall(inProgressCall?.id)"
                    :class="selected === inProgressCall?.id
                        ? 'border-2 border-orange-500 shadow-md bg-orange-50'
                        : 'border-2 border-orange-300 bg-orange-50 hover:bg-orange-100'"
                    class="rounded-2xl p-4 lg:p-5 flex items-center justify-between cursor-pointer transition-all active:scale-[0.98] shrink-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="relative shrink-0">
                            <div class="w-10 h-10 rounded-full bg-orange-500 flex items-center justify-center font-bold text-sm text-white"
                                 x-text="inProgressCall?.seq"></div>
                            <span class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-orange-400 rounded-full animate-ping"></span>
                            <span class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-orange-500 rounded-full"></span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-[9px] font-extrabold text-orange-600 uppercase tracking-widest mb-0.5">In Progress</p>
                            <h4 class="font-semibold text-sm text-[#191c1e] leading-tight truncate" x-text="inProgressCall?.name"></h4>
                            <p class="text-[11px] text-[#737685] mt-0.5 truncate" x-text="(inProgressCall?.location ?? '') + ' • ' + (inProgressCall?.time ?? '')"></p>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1.5 shrink-0 ml-2">
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase whitespace-nowrap bg-orange-200 text-orange-700">Active</span>
                        <span class="material-symbols-outlined text-base text-orange-500">chevron_right</span>
                        <span
                            class="material-symbols-outlined text-sm mat-fill"
                            :class="syncIconClass(inProgressCall?.sync_status)"
                            :title="syncIconTitle(inProgressCall?.sync_status)"
                            x-text="syncIcon(inProgressCall?.sync_status)">
                        </span>
                    </div>
                </div>
            </template>

            {{-- Filter Pills --}}
            <div class="flex items-center gap-2 pb-1 shrink-0">
                <div class="flex items-center gap-2 overflow-x-auto scrollbar-hide flex-1">
                    <button @click="filter = 'today'; if (showMap) openMap()"
                        :class="filter === 'today' ? 'bg-[#890f00] text-white' : 'bg-white border border-gray-200 text-[#434654]'"
                        class="whitespace-nowrap px-4 py-1.5 rounded-full text-xs font-semibold transition-all">Today</button>
                    <button @click="filter = 'week'; if (showMap) openMap()"
                        :class="filter === 'week' ? 'bg-[#890f00] text-white' : 'bg-white border border-gray-200 text-[#434654]'"
                        class="whitespace-nowrap px-4 py-1.5 rounded-full text-xs font-semibold transition-all">This Week</button>
                    <button @click="filter = 'month'; if (showMap) openMap()"
                        :class="filter === 'month' ? 'bg-[#890f00] text-white' : 'bg-white border border-gray-200 text-[#434654]'"
                        class="whitespace-nowrap px-4 py-1.5 rounded-full text-xs font-semibold transition-all">This Month</button>
                </div>
                <button @click="openMap()"
                    class="shrink-0 flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-600 text-white hover:bg-blue-700 transition-all">
                    <span class="material-symbols-outlined text-white" style="font-size:14px;">map</span>
                    Map
                </button>

            </div>



            {{-- Active search chip --}}
            <div x-show="callSearchQuery" x-transition class="flex items-center gap-2 pb-1 shrink-0">
                <div class="flex items-center gap-1.5 bg-[#890f00] text-white rounded-full px-3 py-1 text-xs font-semibold" style="max-width:100%;">
                    <span class="material-symbols-outlined shrink-0" style="font-size:13px;">search</span>
                    <span class="min-w-0 flex-1 truncate" x-text="callSearchQuery"></span>
                    <button
                        @click="callSearch = ''; callSearchQuery = ''"
                        class="ml-1 shrink-0 flex items-center justify-center w-4 h-4 rounded-full bg-white/20 hover:bg-white/40 transition-colors"
                        title="Clear search">
                        <span class="material-symbols-outlined" style="font-size:11px; line-height:1;">close</span>
                    </button>
                </div>
                <span class="text-xs text-[#737685]" x-text="filteredCalls.length + ' result' + (filteredCalls.length !== 1 ? 's' : '')"></span>
            </div>

            {{-- Call List --}}
            <div class="flex-1 overflow-y-auto space-y-3 pr-1 scrollbar-hide">

                <div class="sticky top-0 bg-gray-50/90 backdrop-blur py-1.5 shrink-0">
                    <h3 class="text-[11px] font-extrabold text-[#737685] tracking-widest uppercase"
                        x-text="filter === 'today' ? 'Today, {{ now()->format('M j') }}' :
                                filter === 'week'  ? 'This Week' : 'This Month'">
                    </h3>
                </div>

                <template x-for="call in filteredCalls" :key="call.id">
                    <div
                        @click="selectCall(call.id)"
                        :class="selected === call.id
                            ? 'border-2 border-[#890f00] shadow-md'
                            : 'border border-gray-100 shadow-sm hover:bg-gray-50'"
                        class="bg-white rounded-2xl p-4 lg:p-5 flex items-center justify-between cursor-pointer transition-all active:scale-[0.98]">
                        <div class="flex items-center gap-3 min-w-0">
                            <div
                                class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm shrink-0"
                                :class="seqBgClass(call.status)"
                                x-text="call.seq">
                            </div>
                            <div class="min-w-0">
                                <h4 class="font-semibold text-sm text-[#191c1e] leading-tight truncate flex items-center gap-1.5">
                                    <span x-text="call.name"></span>
                                    <span x-show="call.type === 'Unplanned'" class="shrink-0 px-1.5 py-0.5 rounded text-[9px] font-black uppercase bg-purple-100 text-purple-700">Unplanned</span>
                                </h4>
                                <p x-show="call.unique_id" class="text-[10px] text-[#890f00] font-mono truncate" x-text="call.unique_id"></p>
                                <p class="text-[10px] text-gray-400 font-mono truncate" x-text="'#' + call.id"></p>
                                <p class="text-[11px] text-[#737685] mt-0.5 truncate"
                                   x-text="filter === 'today'
                                       ? call.location + ' • ' + call.time
                                       : call.date_label + ' • ' + call.time">
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1.5 shrink-0 ml-2">
                            <span
                                class="px-2.5 py-1 rounded-full text-[10px] font-black uppercase whitespace-nowrap"
                                :class="statusBadgeClass(call.status)"
                                x-text="statusLabel(call.status)">
                            </span>
                            <span
                                class="material-symbols-outlined text-base"
                                :class="['completed','partially_completed','cancelled'].includes(call.status) ? 'text-green-600' : 'text-[#737685]'"
                                :title="['completed','partially_completed','cancelled'].includes(call.status) ? 'Visit finished' : 'View details'"
                                x-text="['completed','partially_completed','cancelled'].includes(call.status) ? 'check_circle' : 'chevron_right'">
                            </span>
                            <span
                                class="material-symbols-outlined text-sm mat-fill"
                                :class="syncIconClass(call.sync_status)"
                                :title="syncIconTitle(call.sync_status)"
                                x-text="syncIcon(call.sync_status)">
                            </span>
                        </div>
                    </div>
                </template>

            </div>
        </div>{{-- end left panel --}}


        {{--
            RIGHT PANEL
            Mobile:  visible only when showDetail (full detail view)
            Tablet+: always visible, flex-1 (takes remaining space)
        --}}
        <div
            x-show="!isMobile || showDetail"
            :class="isMobile ? 'w-full' : 'flex-1'"
            class="flex flex-col bg-white rounded-[28px] shadow-xl border border-gray-100 overflow-hidden">

            {{-- EMPTY STATE — nothing selected (first landing, or right after finishing a visit) --}}
            <div x-show="!selectedCall" x-transition class="flex-1 flex flex-col items-center justify-center gap-4 px-8 text-center">
                <div class="w-16 h-16 rounded-2xl bg-gray-50 border border-gray-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-gray-300 text-4xl">touch_app</span>
                </div>
                <div>
                    <p class="font-bold text-base text-[#191c1e]">Select a Salescall</p>
                    <p class="text-sm text-[#737685] mt-1">Choose a visit from the list on the left to view its details.</p>
                </div>
            </div>

            {{-- ARRIVAL VIEW — before Check In --}}
            <div x-show="!checkedIn && selectedCall" x-transition class="flex-1 flex flex-col overflow-hidden">

                <div class="flex items-center gap-4 px-5 lg:px-7 py-5 border-b border-gray-100 shrink-0">
                    <div class="w-12 h-12 rounded-2xl bg-gray-50 border border-gray-100 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-gray-400 text-3xl" title="Customer store">storefront</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        {{-- Hidden for now — see the matching blocks in the mobile header and checked-in view.
                        <span class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">
                            SALESCALL #<span x-text="selectedCall?.id"></span>
                        </span>
                        --}}
                        <h2 class="text-xl lg:text-2xl font-extrabold text-[#191c1e] leading-tight truncate flex items-center gap-2">
                            <span x-text="selectedCall?.name"></span>
                            <span x-show="selectedCall?.type === 'Unplanned'" class="shrink-0 px-2 py-0.5 rounded text-[10px] font-black uppercase bg-purple-100 text-purple-700">Unplanned</span>
                        </h2>
                        <p x-show="selectedCall?.unique_id" class="text-xs text-[#890f00] font-mono truncate" x-text="selectedCall?.unique_id"></p>
                        <p class="text-sm text-[#737685] flex items-center gap-1">
                            <span class="material-symbols-outlined text-base shrink-0" title="Store address">location_on</span>
                            <span class="truncate" x-text="selectedCall?.location"></span>
                        </p>
                        <p class="text-[10px] text-gray-300 font-mono truncate mt-0.5" x-text="selectedCall?.local_uuid"></p>
                    </div>
                    {{-- Maximize toggle (tablet+ only) --}}
                    <button
                        x-show="!isMobile"
                        @click="maximized = !maximized"
                        class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors shrink-0"
                        :title="maximized ? 'Restore split view' : 'Expand detail view'">
                        <span class="material-symbols-outlined text-[#434654] text-xl"
                              x-text="maximized ? 'close_fullscreen' : 'open_in_full'"></span>
                    </button>
                </div>

                <div class="flex-1 flex flex-col items-center justify-center gap-6 px-8">

                    {{-- Cancelled notice --}}
                    <template x-if="selectedCall?.status === 'cancelled'">
                        <div class="flex flex-col items-center gap-4 text-center">
                            <div class="w-20 h-20 rounded-3xl bg-gray-100 flex items-center justify-center">
                                <span class="material-symbols-outlined text-gray-400 text-5xl">cancel</span>
                            </div>
                            <div>
                                <p class="font-black text-xl text-gray-500 leading-none tracking-wide">Cancelled</p>
                                <p class="text-xs text-gray-400 mt-1">This visit has been cancelled.</p>
                            </div>
                        </div>
                    </template>

                    {{-- Mark Arrival button --}}
                    <template x-if="selectedCall?.status !== 'cancelled'">
                        <div class="flex flex-col items-center gap-6">
                            <button
                                @click="doCheckIn()"
                                :disabled="anyOtherInProgress"
                                :class="anyOtherInProgress ? 'opacity-40 cursor-not-allowed' : 'hover:opacity-90 active:scale-95'"
                                title="Tap to record your GPS arrival location and start the visit"
                                class="flex flex-col items-center gap-3 bg-[#890f00] text-white w-48 lg:w-52 py-8 rounded-3xl shadow-xl transition-all">
                                <span class="material-symbols-outlined text-5xl mat-fill">my_location</span>
                                <div class="text-center">
                                    <p class="font-black text-2xl leading-none tracking-wide">Mark Arrival</p>
                                    <p class="text-xs opacity-75 mt-1">Capture GPS location</p>
                                </div>
                            </button>
                            <p x-show="!anyOtherInProgress" class="text-xs text-[#737685] text-center max-w-xs leading-relaxed">
                                Tap <strong class="text-[#191c1e]">Mark Arrival</strong> when you arrive at the store to record your GPS location and start the visit.
                            </p>
                            <p x-show="anyOtherInProgress" class="text-xs text-amber-700 text-center max-w-xs leading-relaxed font-medium">
                                Finish your current in-progress visit before starting a new one.
                            </p>
                        </div>
                    </template>

                </div>

            </div>

            {{-- FULL DETAIL — after Check In --}}
            <div x-show="checkedIn && selectedCall" class="flex flex-col flex-1 overflow-hidden relative">

                {{-- Detail Header --}}
                <div class="px-5 lg:px-7 pt-5 pb-0 border-b border-gray-100 shrink-0">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center gap-3 lg:gap-4 min-w-0">
                            <div class="w-12 h-12 lg:w-14 lg:h-14 rounded-2xl overflow-hidden bg-gray-50 border border-gray-100 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-gray-400 text-3xl" title="Customer store">storefront</span>
                            </div>
                            <div class="min-w-0">
                                {{-- Hidden for now — see the matching blocks in the mobile header and arrival view.
                                <span class="text-[10px] font-black text-[#890f00] tracking-widest uppercase">
                                    SALESCALL #<span x-text="selectedCall?.id"></span>
                                </span>
                                --}}
                                <h2 class="text-lg lg:text-2xl font-extrabold text-[#191c1e] leading-tight truncate" x-text="selectedCall?.name"></h2>
                                <p x-show="selectedCall?.unique_id" class="text-xs text-[#890f00] font-mono truncate" x-text="selectedCall?.unique_id"></p>
                                <p class="text-sm text-[#737685] truncate" x-text="selectedCall?.location"></p>
                                <p class="text-[10px] text-gray-300 font-mono truncate mt-0.5" x-text="selectedCall?.local_uuid"></p>

                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 ml-2">
                            <span
                                class="px-3 lg:px-5 py-2 rounded-2xl text-xs font-black uppercase shadow-sm"
                                :class="statusBadgeClass(selectedCall?.status)"
                                x-text="statusLabel(selectedCall?.status)">
                            </span>
                            {{-- Maximize toggle (tablet+ only) --}}
                            <button
                                x-show="!isMobile"
                                @click="maximized = !maximized"
                                class="w-9 h-9 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-[#e7e8ea] transition-colors"
                                :title="maximized ? 'Restore split view' : 'Expand detail view'">
                                <span class="material-symbols-outlined text-[#434654] text-xl"
                                        x-text="maximized ? 'close_fullscreen' : 'open_in_full'"></span>
                            </button>
                        </div>
                    </div>

                    {{-- Tabs --}}
                    {{-- CCR, MRF, and Activity Log are hidden for now — phase 2. Content blocks below are commented out, not deleted. --}}
                    <div class="flex gap-4 lg:gap-6 border-b border-gray-100 overflow-x-auto scrollbar-hide">
                        <template x-for="t in ['overview','brands','profile','photos']">
                            <button
                                @click="tab = t"
                                :class="tab === t
                                    ? 'border-b-[3px] border-[#890f00] text-[#191c1e] font-bold'
                                    : 'border-b-[3px] border-transparent text-[#737685] font-medium hover:text-[#191c1e]'"
                                class="pb-3 text-sm transition-all whitespace-nowrap"
                                x-text="tabLabel(t)">
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Scrollable Content --}}
                <div class="flex-1 overflow-y-auto p-5 lg:p-7 space-y-6 keyboard-scroll">

                    {{-- OVERVIEW TAB --}}
                    <div x-show="tab === 'overview'" class="space-y-6">

                        <div class="grid grid-cols-2 gap-3 lg:gap-4">
                            <div class="p-4 lg:p-5 bg-[#f3f4f6] rounded-2xl flex items-center gap-3 lg:gap-4">
                                <span class="material-symbols-outlined text-3xl lg:text-4xl text-[#006c47] mat-fill shrink-0" title="GPS check-in verified">location_on</span>
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[#737685] uppercase font-bold tracking-wider">GPS Check-In</p>
                                    <p class="text-base lg:text-lg font-bold text-[#191c1e]">GPS Verified</p>
                                    <p class="text-xs text-[#006c47] font-bold">Within 20m of site</p>
                                </div>
                            </div>
                            <div class="p-4 lg:p-5 bg-[#f3f4f6] rounded-2xl flex items-center gap-3 lg:gap-4">
                                <span class="material-symbols-outlined text-3xl lg:text-4xl text-blue-600 shrink-0" title="Time spent on site">schedule</span>
                                <div class="min-w-0">
                                    <p class="text-[10px] text-[#737685] uppercase font-bold tracking-wider">Time on Site</p>
                                    <p class="text-base lg:text-lg font-bold text-[#191c1e]">00:42:15</p>
                                    <p class="text-xs text-[#737685] font-medium">Started at 09:02 AM</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-base font-bold text-[#191c1e] mb-3">Visit Actions</h3>
                            <div class="grid grid-cols-2 gap-3">
                                @foreach([
                                    {{-- ['assignment','Fill CCR','Customer Call Report', null], --}}
                                    {{-- ['inventory','Fill TSF','Merchandising Report', null], --}}
                                    ['photo_camera','Upload Photo','Store display audit', 'photos'],
                                ] as [$icon, $label, $sub, $targetTab])
                                <button
                                    title="{{ $label }} — {{ $sub }}"
                                    @if($targetTab) @click="tab = '{{ $targetTab }}'" @endif
                                    class="h-18 lg:h-20 bg-white border border-gray-200 rounded-2xl flex items-center px-4 lg:px-5 gap-3 lg:gap-4 hover:border-[#890f00] hover:bg-red-50 group transition-all">
                                    <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-full bg-[#edeef0] group-hover:bg-[#ffdad3] flex items-center justify-center shrink-0">
                                        <span class="material-symbols-outlined text-lg text-[#737685] group-hover:text-[#890f00]">{{ $icon }}</span>
                                    </div>
                                    <div class="text-left min-w-0">
                                        <p class="font-bold text-sm text-[#191c1e]">{{ $label }}</p>
                                        <p class="text-xs text-[#737685] truncate">{{ $sub }}</p>
                                    </div>
                                </button>
                                @endforeach
                                <button
                                    :title="atNoteLimit ? `Limit reached — ${noteLimit} notes max for this customer` : 'Add Quick Note — Capture instant feedback'"
                                    @click="openAddNote()"
                                    :disabled="atNoteLimit"
                                    :class="atNoteLimit ? 'opacity-50 cursor-not-allowed' : 'hover:border-[#890f00] hover:bg-red-50'"
                                    class="h-18 lg:h-20 bg-white border border-gray-200 rounded-2xl flex items-center px-4 lg:px-5 gap-3 lg:gap-4 group transition-all">
                                    <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-full bg-[#edeef0] group-hover:bg-[#ffdad3] flex items-center justify-center shrink-0">
                                        <span class="material-symbols-outlined text-lg text-[#737685] group-hover:text-[#890f00]">note_alt</span>
                                    </div>
                                    <div class="text-left min-w-0">
                                        <p class="font-bold text-sm text-[#191c1e]">Add Quick Note</p>
                                        <p class="text-xs text-[#737685] truncate" x-text="atNoteLimit ? `Limit reached (${noteLimit})` : 'Capture instant feedback'"></p>
                                    </div>
                                </button>
                            </div>
                        </div>

                        {{-- Quick Notes — customer-level, private to author, shown regardless of which visit is open --}}
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-base font-bold text-[#191c1e]">Quick Notes</h3>
                                <span class="text-xs" :class="atNoteLimit ? 'text-red-500 font-bold' : 'text-[#737685]'" x-text="customerNotes.length + ' / ' + noteLimit + ' notes'"></span>
                            </div>
                            <div class="space-y-2">
                                <template x-for="note in customerNotes" :key="note.id">
                                    <div class="bg-[#fef9e7] border border-[#f5e6a8] rounded-2xl p-4">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0 flex-1">
                                                <h4 x-show="note.title" class="font-bold text-sm text-[#191c1e] mb-0.5" x-text="note.title"></h4>
                                                <p class="text-sm text-[#434654] whitespace-pre-wrap break-words" x-text="note.body"></p>
                                                <p class="text-[10px] text-[#8a7f3f] font-medium mt-1.5" x-text="note.created_at"></p>
                                            </div>
                                            <div class="flex items-center gap-1 shrink-0">
                                                <button @click="openEditNote(note)" title="Edit note"
                                                    class="w-7 h-7 rounded-full hover:bg-black/5 flex items-center justify-center transition-colors">
                                                    <span class="material-symbols-outlined text-[#8a7f3f] text-base">edit</span>
                                                </button>
                                                <button @click="deleteNote(note.id)" :disabled="noteDeletingId === note.id" title="Delete note"
                                                    class="w-7 h-7 rounded-full hover:bg-black/5 flex items-center justify-center transition-colors disabled:opacity-40">
                                                    <span class="material-symbols-outlined text-[#8a7f3f] text-base">delete</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                                <p x-show="customerNotes.length === 0" class="text-center text-sm text-[#737685] py-6 bg-gray-50 rounded-2xl">No notes yet for this customer.</p>
                            </div>
                        </div>

                        <div class="rounded-2xl h-44 lg:h-52 overflow-hidden relative border border-gray-200 isolate">
                            <div id="salescall-mini-map" class="w-full h-full"></div>
                            {{-- No-location fallback --}}
                            <div x-show="!selectedCall?.lat || !selectedCall?.lng"
                                 class="absolute inset-0 bg-gray-100 flex flex-col items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-gray-400 text-4xl">location_off</span>
                                <p class="text-xs text-[#737685]">No location data for this customer</p>
                            </div>
                            {{-- GPS badge overlay --}}
                            <div x-show="selectedCall?.lat && selectedCall?.lng"
                                 class="absolute bottom-3 left-3 bg-white/90 backdrop-blur px-3 py-1.5 rounded-xl border border-gray-200 shadow-sm flex items-center gap-1.5 pointer-events-none">
                                <span class="material-symbols-outlined text-[#006c47] mat-fill text-base">my_location</span>
                                <span class="text-xs font-bold text-[#191c1e]">GPS Locked</span>
                            </div>
                        </div>

                    </div>

                    {{-- BRANDS TAB --}}
                    <div x-show="tab === 'brands'" class="space-y-4">
                        <template x-for="groupId in [1, 2, 3, 4, 5]" :key="groupId">
                            <div class="p-4 lg:p-5 bg-[#f8f9fa] rounded-2xl space-y-3">
                                <select disabled
                                    class="w-full px-4 py-3 bg-[#edeef0] border-0 rounded-2xl text-[#191c1e] text-sm font-bold opacity-80 appearance-none">
                                    <option x-text="materialGroups.find(g => g.id === groupId)?.name"></option>
                                </select>

                                <template x-for="(row, idx) in brandForm[groupId]" :key="idx">
                                    <div class="flex flex-wrap gap-3 items-start">
                                        <select x-model="row.brand_id" @change="onBrandRowChange(groupId, idx)"
                                            class="flex-1 min-w-[140px] px-4 py-3 bg-white border border-gray-200 rounded-2xl text-[#191c1e] text-sm focus:ring-2 focus:ring-[#890f00] outline-none appearance-none">
                                            <option value="">— Select Brand —</option>
                                            <template x-for="brand in brandsForGroup(groupId)" :key="brand.id">
                                                <option :value="brand.id" x-text="brand.name"></option>
                                            </template>
                                        </select>

                                        <input type="number" min="0" x-model="row.quantity" placeholder="Qty"
                                            x-show="!isNoneBrand(groupId, row.brand_id)"
                                            class="w-24 px-3 py-3 bg-white border border-gray-200 rounded-2xl text-[#191c1e] text-sm focus:ring-2 focus:ring-[#890f00] outline-none" />

                                        <input type="text" x-model="row.brand_other" placeholder="Specify brand"
                                            x-show="isOthersBrand(groupId, row.brand_id)" x-transition
                                            class="flex-1 min-w-[140px] px-4 py-3 bg-white border border-gray-200 rounded-2xl text-[#191c1e] text-sm focus:ring-2 focus:ring-[#890f00] outline-none" />

                                        <button @click="removeBrandItem(groupId, idx)"
                                            title="Remove item"
                                            class="w-10 h-10 rounded-full bg-[#edeef0] flex items-center justify-center hover:bg-red-50 shrink-0">
                                            <span class="material-symbols-outlined text-[#890f00] text-lg">close</span>
                                        </button>
                                    </div>
                                </template>

                                <button @click="addBrandItem(groupId)" :disabled="groupAddDisabled(groupId)"
                                    class="text-sm font-bold text-[#890f00] disabled:opacity-30 disabled:cursor-not-allowed">
                                    + Add Item
                                </button>
                            </div>
                        </template>

                        <button @click="saveBrands()" :disabled="brandsSaving"
                            class="w-full h-12 bg-[#890f00] text-white rounded-2xl font-black text-base shadow-lg hover:opacity-95 active:scale-[0.98] transition-all disabled:opacity-50">
                            <span x-show="!brandsSaving">Save Brands</span>
                            <span x-show="brandsSaving" class="flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                                Saving...
                            </span>
                        </button>
                    </div>

                    {{--
                    CCR TAB
                    <div x-show="tab === 'ccr'" class="flex items-center justify-center h-40">
                        <p class="text-[#737685] text-sm">CCR form will appear here.</p>
                    </div>

                    MRF TAB
                    <div x-show="tab === 'mrf'" class="flex items-center justify-center h-40">
                        <p class="text-[#737685] text-sm">MRF form will appear here.</p>
                    </div>
                    --}}

                    {{-- PHOTOS TAB --}}
                    <div x-show="tab === 'photos'" class="space-y-4">

                        {{-- Photo list stays outside wire:ignore so Livewire/$wire.callPhotos updates still refresh thumbnails. --}}
                        <div x-show="($store.salescallPhotoWizard?.photoStep ?? 0) === 0">

                            {{-- Empty state --}}
                            <div x-show="($wire.callPhotos || []).length === 0"
                                 class="flex flex-col items-center justify-center py-10 text-center">
                                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                    <span class="material-symbols-outlined text-gray-400 text-3xl">photo_camera</span>
                                </div>
                                <p class="font-bold text-[#191c1e] mb-1">No Photos Yet</p>
                                <p class="text-sm text-[#737685]">Capture store visit photos below</p>
                            </div>

                            {{-- Photos grouped by category --}}
                            <template x-for="group in photosGrouped" :key="group.name">
                                <div class="mb-5">
                                    <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest mb-2"
                                       x-text="typeof group !== 'undefined' ? group?.name : ''"></p>
                                    <div class="grid grid-cols-3 gap-2">
                                        <template x-for="photo in (typeof group !== 'undefined' ? group?.photos : [])" :key="photo.id">
                                            <div @click="previewPhoto = photo"
                                                 class="aspect-square rounded-xl overflow-hidden bg-gray-100 relative cursor-pointer active:scale-[0.97] transition-transform">
                                                <img :src="photo.url" class="w-full h-full object-cover" loading="lazy" />
                                                <button @click.stop="photoToDelete = photo"
                                                    class="absolute top-1 right-1 w-6 h-6 rounded-full bg-black/60 flex items-center justify-center hover:bg-red-600 transition-colors">
                                                    <span class="material-symbols-outlined text-white text-sm">delete</span>
                                                </button>
                                                <div class="absolute bottom-0 left-0 right-0 bg-black/50 px-2 py-1">
                                                    <p class="text-white text-[9px] font-bold truncate" x-text="photo.type"></p>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Capture wizard island: nested Alpine + wire:ignore so Livewire morph cannot reset photoStep. --}}
                        <div wire:ignore id="salescall-photo-wizard">
                            <div
                                x-data="{
                                    photoStep: 0,
                                    photoCategory: null,
                                    photoType: null,
                                    imageCategories: {{ $imageCategoriesJson }},
                                    syncWizardStore() {
                                        let store = Alpine.store('salescallPhotoWizard');
                                        if (!store) {
                                            Alpine.store('salescallPhotoWizard', {
                                                photoStep: 0,
                                                photoCategory: null,
                                                photoType: null,
                                            });
                                            store = Alpine.store('salescallPhotoWizard');
                                        }
                                        store.photoStep = this.photoStep;
                                        store.photoCategory = this.photoCategory;
                                        store.photoType = this.photoType;
                                    },
                                    init() {
                                        this.syncWizardStore();
                                        this.$watch('photoStep', () => this.syncWizardStore());
                                        this.$watch('photoCategory', () => this.syncWizardStore());
                                        this.$watch('photoType', () => this.syncWizardStore());
                                    },
                                    parentSelected() {
                                        const root = document.getElementById('salescall-page-root');
                                        if (!root) return null;
                                        try {
                                            return Alpine.$data(root).selected ?? null;
                                        } catch (error) {
                                            return null;
                                        }
                                    },
                                    categoryCoverage(cat) {
                                        const types = cat.types || [];
                                        if (!types.length) return 'none';
                                        const covered = new Set(
                                            ($wire.callPhotos || [])
                                                .filter(p => p.category === cat.name)
                                                .map(p => p.type)
                                        );
                                        if (covered.size === 0) return 'none';
                                        return covered.size >= types.length ? 'complete' : 'partial';
                                    },
                                    startPhotoFlow() {
                                        this.photoStep = 1;
                                    },
                                    selectPhotoCategory(cat) {
                                        this.photoCategory = cat;
                                        this.photoStep = 2;
                                    },
                                    selectPhotoType(type) {
                                        this.photoType = type;
                                        this.photoStep = 3;
                                    },
                                    cancelPhoto() {
                                        this.photoStep = 0;
                                        this.photoCategory = null;
                                        this.photoType = null;
                                    },
                                    hasPhotoForType(type) {
                                        try {
                                            return ($wire.callPhotos || []).some(
                                                photo => photo && photo.type === type.name
                                            );
                                        } catch (error) {
                                            return false;
                                        }
                                    },
                                }"
                                @salescall-photo-wizard-reset.window="cancelPhoto()"
                            >
                                {{-- Step 0: Add Photo only (list is outside this island) --}}
                                <div x-show="photoStep === 0">
                                    <button type="button" @click="startPhotoFlow()"
                                        class="w-full flex items-center justify-center gap-2 h-12 border-2 border-dashed border-gray-300 rounded-2xl text-[#737685] font-bold text-sm hover:border-[#890f00] hover:text-[#890f00] transition-colors">
                                        <span class="material-symbols-outlined text-xl">add_a_photo</span>
                                        Add Photo
                                    </button>
                                </div>

                                {{-- Step 1: Choose Category --}}
                                <div x-show="photoStep === 1" x-transition>
                                    <div class="flex items-center gap-3 mb-5">
                                        <button type="button" @click="cancelPhoto()"
                                            class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-gray-500 text-xl">arrow_back</span>
                                        </button>
                                        <p class="font-black text-[#191c1e]">Choose Category</p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <template x-for="cat in imageCategories" :key="cat.id">
                                            <button type="button" @click="selectPhotoCategory(cat)"
                                                class="relative flex flex-col items-center justify-center gap-3 h-32 bg-white border-2 rounded-2xl hover:border-[#890f00] hover:bg-red-50 active:scale-[0.97] transition-all"
                                                :class="{
                                                    'border-green-400': categoryCoverage(cat) === 'complete',
                                                    'border-orange-400': categoryCoverage(cat) === 'partial',
                                                    'border-gray-200': categoryCoverage(cat) === 'none',
                                                }">
                                                <span x-show="categoryCoverage(cat) === 'complete'"
                                                      class="material-symbols-outlined mat-fill absolute top-2 right-2 text-green-600 text-lg">check_circle</span>
                                                <span x-show="categoryCoverage(cat) === 'partial'"
                                                      class="material-symbols-outlined mat-fill absolute top-2 right-2 text-orange-500 text-lg">check_circle</span>
                                                <span class="material-symbols-outlined text-4xl text-[#890f00]"
                                                      x-text="cat.slug === 'exterior' ? 'storefront' : 'battery_charging_full'"></span>
                                                <p class="font-black text-sm text-[#191c1e]" x-text="cat.name"></p>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                {{-- Step 2: Choose Type --}}
                                <div x-show="photoStep === 2" x-transition>
                                    <div class="flex items-center gap-3 mb-5">
                                        <button type="button" @click="photoStep = 1; photoType = null;"
                                            class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-gray-500 text-xl">arrow_back</span>
                                        </button>
                                        <div>
                                            <p class="text-[10px] font-black text-[#890f00] uppercase tracking-widest"
                                               x-text="photoCategory?.name"></p>
                                            <p class="font-black text-[#191c1e]">Choose Type</p>
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <template x-for="type in (photoCategory?.types || [])" :key="type.id">
                                            <button type="button"
                                                @click="selectPhotoType(type)"
                                                @keydown.enter="selectPhotoType(type)"
                                                class="w-full flex items-center gap-4 px-5 py-4 bg-white border border-gray-200 rounded-2xl text-left transition-all cursor-pointer touch-manipulation select-none active:scale-[0.98] active:border-[#890f00] active:bg-red-50"
                                                :class="hasPhotoForType(type) ? 'border-green-400' : 'border-gray-200'">
                                                <span class="material-symbols-outlined text-[#890f00]">photo_camera</span>
                                                <p class="font-bold text-sm text-[#191c1e]" x-text="type.name"></p>
                                                <span x-show="hasPhotoForType(type)"
                                                      class="material-symbols-outlined mat-fill text-green-600 ml-auto">check_circle</span>
                                                <span x-show="!hasPhotoForType(type)"
                                                      class="material-symbols-outlined text-gray-300 ml-auto">chevron_right</span>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                {{-- Step 3: Capture or Gallery --}}
                                <div x-show="photoStep === 3" x-transition data-photo-step3>
                                    <div class="flex items-center gap-3 mb-5">
                                        <button type="button" @click="photoStep = 2; photoType = null;"
                                            class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-gray-500 text-xl">arrow_back</span>
                                        </button>
                                        <div>
                                            <p class="text-[10px] font-black text-[#890f00] uppercase tracking-widest"
                                               x-text="(photoCategory?.name ?? '') + ' · ' + (photoType?.name ?? '')"></p>
                                            <p class="font-black text-[#191c1e]">Capture Photo</p>
                                        </div>
                                    </div>
                                    {{-- Hidden file inputs for browser fallback (ignored on Android WebView) --}}
                                    <input type="file" id="browser-camera-input" accept="image/*" capture="camera" class="hidden"
                                        @change="
                                            const file = $event.target.files[0];
                                            if (!file) return;
                                            const scId = parentSelected();
                                            const typeId = photoType ? photoType.id : null;
                                            cancelPhoto();
                                            if (!scId || !typeId) return;
                                            const reader = new FileReader();
                                            reader.onload = e => { $wire.saveImage(scId, typeId, e.target.result); };
                                            reader.readAsDataURL(file);
                                            $event.target.value = '';
                                        ">
                                    <input type="file" id="browser-gallery-input" accept="image/*" class="hidden"
                                        @change="
                                            const file = $event.target.files[0];
                                            if (!file) return;
                                            const scId = parentSelected();
                                            const typeId = photoType ? photoType.id : null;
                                            cancelPhoto();
                                            if (!scId || !typeId) return;
                                            const reader = new FileReader();
                                            reader.onload = e => { $wire.saveImage(scId, typeId, e.target.result); };
                                            reader.readAsDataURL(file);
                                            $event.target.value = '';
                                        ">
                                    <div class="grid grid-cols-2 gap-3">
                                        <button type="button" @click="
                                                const scId = parentSelected();
                                                if (!scId || !photoType) return;
                                                if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) {
                                                    $wire.takePhoto(scId, photoType.id); cancelPhoto();
                                                } else {
                                                    document.getElementById('browser-camera-input').click();
                                                }
                                            "
                                            class="flex flex-col items-center justify-center gap-3 h-36 bg-[#890f00] text-white rounded-2xl cursor-pointer hover:opacity-95 active:scale-[0.97] transition-all">
                                            <span class="material-symbols-outlined text-4xl">photo_camera</span>
                                            <p class="font-black text-sm">Take Photo</p>
                                        </button>
                                        <button type="button" @click="
                                                const scId = parentSelected();
                                                if (!scId || !photoType) return;
                                                if (document.body.classList.contains('nativephp-android') || document.body.classList.contains('nativephp-ios')) {
                                                    $wire.pickFromGallery(scId, photoType.id); cancelPhoto();
                                                } else {
                                                    document.getElementById('browser-gallery-input').click();
                                                }
                                            "
                                            class="flex flex-col items-center justify-center gap-3 h-36 bg-white border-2 border-gray-200 rounded-2xl cursor-pointer hover:border-[#890f00] hover:bg-red-50 active:scale-[0.97] transition-all">
                                            <span class="material-symbols-outlined text-4xl text-[#890f00]">photo_library</span>
                                            <p class="font-black text-sm text-[#191c1e]">Gallery</p>
                                        </button>
                                    </div>
                                    <button type="button" @click="cancelPhoto()"
                                        class="w-full mt-4 h-11 bg-gray-100 text-[#737685] rounded-2xl font-bold text-sm hover:bg-gray-200 transition-colors">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>


                    {{-- CHANGE PROFILE TAB --}}
                    <div x-show="tab === 'profile'" class="space-y-5">

                        {{-- Category / Sub Category selector --}}
                        <div>
                            <label class="block text-xs font-bold text-[#737685] uppercase tracking-wider mb-2">Category</label>
                            <div class="grid grid-cols-2 gap-3">
                                <select x-model="profileCategoryId"
                                    @change="profileSubCategoryId = ''; profileWithForm = false; profileFormType = null"
                                    class="w-full px-4 py-3 bg-[#f3f4f6] border-0 rounded-2xl text-[#191c1e] text-sm focus:ring-2 focus:ring-[#890f00] outline-none appearance-none">
                                    <option value="">— Select —</option>
                                    <template x-for="cat in categories" :key="cat.id">
                                        <option :value="cat.id" x-text="cat.name"></option>
                                    </template>
                                </select>

                                <select x-model="profileSubCategoryId"
                                    @change="onProfileSubCategoryChange()"
                                    :disabled="!profileCategoryId"
                                    :class="!profileCategoryId ? 'opacity-40 cursor-not-allowed' : ''"
                                    class="w-full px-4 py-3 bg-[#f3f4f6] border-0 rounded-2xl text-[#191c1e] text-sm focus:ring-2 focus:ring-[#890f00] outline-none appearance-none">
                                    <option value="">— Select —</option>
                                    <template x-for="sub in profileSubCategoryOptions" :key="sub.id">
                                        <option :value="sub.id" x-text="sub.name"></option>
                                    </template>
                                </select>
                            </div>

                            {{-- Category-change approval warning --}}
                            <div x-show="categoryChanged" x-transition class="mt-3 flex items-start gap-2 p-3 bg-amber-50 border border-amber-200 rounded-xl">
                                <span class="material-symbols-outlined mat-fill text-amber-600 text-lg shrink-0">warning</span>
                                <p class="text-xs text-amber-800 leading-relaxed">
                                    You're changing this customer's category. Saving will submit it to the approvers before it takes effect — the customer's current category stays active until then.
                                </p>
                            </div>

                            <button @click="saveCategory()" :disabled="categorySaving || !profileSubCategoryId" x-show="!profileWithForm"
                                class="mt-3 w-full h-11 bg-[#890f00] text-white rounded-2xl font-black text-sm shadow-lg hover:opacity-95 active:scale-[0.98] transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="!categorySaving">Save Category</span>
                                <span x-show="categorySaving" class="flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                                    Saving...
                                </span>
                            </button>
                        </div>

                        {{-- No-form notice --}}
                        <div x-show="profileSubCategoryId && !profileWithForm" class="p-4 bg-blue-50 border border-blue-200 rounded-xl">
                            <p class="text-sm text-blue-700 font-medium">No enrollment form required for this program.</p>
                        </div>

                        {{-- Form (MADP / SMDP / VIP) --}}
                        <div x-show="profileWithForm" class="space-y-6">

                            {{-- Business Information --}}
                            <div class="space-y-3">
                                <h4 class="text-sm font-bold text-[#191c1e] border-b border-gray-100 pb-2">Business Information</h4>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">Registered Name <span class="text-red-500">*</span></label>
                                    <input type="text" x-model="profile.registered_name" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                </div>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">Name of Owner <span class="text-red-500">*</span></label>
                                    <input type="text" x-model="profile.owner_name" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                </div>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">Address <span class="text-red-500">*</span></label>
                                    <textarea x-model="profile.address" rows="2" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00] resize-none"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">TIN <span class="text-red-500">*</span></label>
                                    <input type="text" x-model="profile.tin" placeholder="000-000-000-000" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                </div>
                            </div>

                            {{-- Contact Details --}}
                            <div class="space-y-3">
                                <h4 class="text-sm font-bold text-[#191c1e] border-b border-gray-100 pb-2">Business Contact Details</h4>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">Business Landline No</label>
                                    <input type="text" x-model="profile.landline" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                </div>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">Business Mobile No <span class="text-red-500">*</span></label>
                                    <input type="text" x-model="profile.mobile" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                </div>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">Classification</label>
                                    <input type="text" x-model="profile.classification" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                </div>
                            </div>

                            {{-- MADP only: Incentive Type --}}
                            <div x-show="profileFormType === 'madp'" class="space-y-3">
                                <h4 class="text-sm font-bold text-[#191c1e] border-b border-gray-100 pb-2">I prefer to get my incentive <span class="text-red-500">*</span></h4>
                                <label class="flex items-center gap-3 p-3 border-2 rounded-xl cursor-pointer transition-all"
                                    :class="profile.incentive_type === 'lumpsum_monthly' ? 'border-[#890f00] bg-[#fdf4f4]' : 'border-gray-200'">
                                    <input type="radio" x-model="profile.incentive_type" value="lumpsum_monthly" class="accent-[#890f00]">
                                    <span class="text-sm font-medium text-[#191c1e]">Lumpsum - Monthly</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 border-2 rounded-xl cursor-pointer transition-all"
                                    :class="profile.incentive_type === 'outright' ? 'border-[#890f00] bg-[#fdf4f4]' : 'border-gray-200'">
                                    <input type="radio" x-model="profile.incentive_type" value="outright" class="accent-[#890f00]">
                                    <span class="text-sm font-medium text-[#191c1e]">Outright</span>
                                </label>
                            </div>

                            {{-- VIP only: Brand/Products table --}}
                            <div x-show="profileFormType === 'vip'" class="space-y-3">
                                <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                                    <h4 class="text-sm font-bold text-[#191c1e]">Brand / Product / Services <span class="text-red-500">*</span></h4>
                                    <button @click="addBrandRow()" type="button" class="flex items-center gap-1 text-xs text-[#890f00] font-bold">
                                        <span class="material-symbols-outlined text-base">add_circle</span> Add Row
                                    </button>
                                </div>
                                <p x-show="profile.brand_products.length === 0" class="text-xs text-[#737685] italic py-1">Tap "Add Row" to add entries.</p>
                                <template x-for="(row, i) in profile.brand_products" :key="i">
                                    <div class="border border-gray-200 rounded-xl p-3 space-y-2 bg-gray-50">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-[#737685]" x-text="'Entry #' + (i + 1)"></span>
                                            <button @click="removeBrandRow(i)" type="button" class="text-red-400 hover:text-red-600">
                                                <span class="material-symbols-outlined text-base">remove_circle</span>
                                            </button>
                                        </div>
                                        <div>
                                            <label class="block text-xs text-[#737685] mb-1">Brand / Product / Services</label>
                                            <input type="text" x-model="row.brand" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-[#737685] mb-1">Supplier(s)</label>
                                            <input type="text" x-model="row.supplier" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-[#737685] mb-1">Monthly Volume</label>
                                            <input type="number" x-model="row.monthly_volume" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                        </div>
                                    </div>
                                </template>
                            </div>

                            {{-- SMDP only: Commitment text --}}
                            <div x-show="profileFormType === 'smdp'" class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-2">
                                <p class="text-xs font-bold text-[#434654]">In cognizance of this enrollment, I confirm the following commitments:</p>
                                <ol class="list-decimal list-inside space-y-1 text-xs text-[#434654] leading-relaxed">
                                    <li>To carry only Philippine Batteries Inc. products</li>
                                    <li>To fully support OMMC in various sales and marketing activities</li>
                                    <li>To acknowledge OMMC's ownership of trademarks and promotional materials</li>
                                </ol>
                            </div>

                            {{-- Owner's Personal Information --}}
                            <div class="space-y-3">
                                <h4 class="text-sm font-bold text-[#191c1e] border-b border-gray-100 pb-2">Owner's Personal Information</h4>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs text-[#737685] font-medium mb-1">Birthday <span class="text-red-500">*</span></label>
                                        <input type="date" x-model="profile.birthday" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00]">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-[#737685] font-medium mb-1">Age</label>
                                        <div class="w-full border border-gray-100 rounded-xl px-3 py-2.5 text-sm text-[#434654] bg-gray-50" x-text="profileComputedAge() || '—'"></div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">Gender <span class="text-red-500">*</span></label>
                                    <select x-model="profile.gender" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00] bg-white">
                                        <option value="">Select gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Prefer Not to Say">Prefer Not to Say</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs text-[#737685] font-medium mb-1">Marital Status <span class="text-red-500">*</span></label>
                                    <select x-model="profile.marital_status" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00] bg-white">
                                        <option value="">Select status</option>
                                        <option value="Single">Single</option>
                                        <option value="Married">Married</option>
                                        <option value="Widow">Widow</option>
                                        <option value="Separated">Separated</option>
                                        <option value="Prefer Not to Say">Prefer Not to Say</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Declaration & Signature --}}
                            <div class="space-y-3">
                                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                                    <p class="text-xs text-amber-800 italic leading-relaxed">By my signature affixed below, I hereby declare that all information contained in this document are true and correct. I have signed this document freely and voluntarily without any inducement, assurance, or guarantee being made to me.</p>
                                </div>
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-xs font-bold text-[#737685] uppercase tracking-wider">Signature of Owner <span class="text-red-500">*</span></label>
                                        <button @click="clearProfileSig()" type="button" class="text-xs text-[#890f00] font-medium">Clear</button>
                                    </div>
                                    <div x-show="profile.has_signature && !profileSignatureData" class="mb-2 flex items-center gap-1.5 text-xs text-green-600 font-medium">
                                        <span class="material-symbols-outlined text-base mat-fill">check_circle</span>
                                        Signature saved — draw below to replace
                                    </div>
                                    <canvas id="profile-sig-pad"
                                        style="touch-action: none; height: 160px;"
                                        class="w-full border-2 border-dashed border-gray-300 rounded-xl bg-white cursor-crosshair"
                                        @mousedown.prevent="profileSigStart($event)"
                                        @mousemove.prevent="profileSigDraw($event)"
                                        @mouseup="profileSigEnd()"
                                        @mouseleave="profileSigEnd()"
                                        @touchstart.prevent="profileSigStart($event)"
                                        @touchmove.prevent="profileSigDraw($event)"
                                        @touchend="profileSigEnd()">
                                    </canvas>
                                    <div x-show="profileSignatureData" class="mt-1 text-xs text-green-600 font-medium flex items-center gap-1">
                                        <span class="material-symbols-outlined text-base mat-fill">check_circle</span> Signature captured
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-[#737685]">
                                    <span class="material-symbols-outlined text-base">calendar_today</span>
                                    Date: <span class="font-medium text-[#191c1e]">{{ now()->format('F j, Y') }}</span>
                                </div>
                            </div>

                            {{-- Save --}}
                            <button
                                @click="submitProfile()"
                                :disabled="profileSaving || !profileSubCategoryId"
                                class="w-full h-12 bg-[#890f00] text-white rounded-2xl font-black text-base shadow-lg hover:opacity-95 active:scale-[0.98] transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="!profileSaving">Save Profile</span>
                                <span x-show="profileSaving" class="flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                                    Saving...
                                </span>
                            </button>

                        </div>
                    </div>

                    {{--
                    ACTIVITY TAB — phase 2
                    <div x-show="tab === 'activity'" class="flex items-center justify-center h-40">
                        <p class="text-[#737685] text-sm">Activity log will appear here.</p>
                    </div>
                    --}}


                </div>

                {{-- Sticky Footer --}}
                <div class="p-5 lg:p-6 border-t border-gray-100 bg-white shrink-0 space-y-3">

                    {{-- IN PROGRESS: finish actions --}}
                    <div x-show="selectedCall?.status === 'in_progress'" class="space-y-2">
                            <div x-show="!showCancelReason && !showPartialReason" class="space-y-2">
                                <button
                                    @click="finishVisit('completed')"
                                    :disabled="!canSubmitSalescall || finishing"
                                    :class="(!canSubmitSalescall || finishing) ? 'opacity-40 cursor-not-allowed' : 'hover:opacity-95 active:scale-[0.98]'"
                                    class="w-full h-12 bg-[#890f00] text-white rounded-2xl font-black text-sm shadow-lg transition-all">
                                    Submit Salescall
                                </button>
                                <div class="text-[11px] leading-relaxed px-1">
                                    <p class="font-bold text-[10px] uppercase tracking-wider text-[#737685] mb-1">Requires:</p>
                                    <p class="flex flex-nowrap items-center gap-x-3 overflow-x-auto whitespace-nowrap scrollbar-hide">
                                        <span class="flex items-center gap-1.5 shrink-0" :class="hasSavedBrands ? 'text-green-600' : 'text-red-500'">
                                            <span class="material-symbols-outlined text-sm mat-fill" x-text="hasSavedBrands ? 'check_circle' : 'radio_button_unchecked'"></span>
                                            <span>Brands<span x-show="!hasSavedBrands"> (missing)</span></span>
                                        </span>
                                        {{-- Photo requirement temporarily disabled (optional for now) — uncomment
                                             to re-enable "photo in every subcategory" as a submit requirement:
                                        <span class="flex items-center gap-1.5 shrink-0" :class="photosComplete ? 'text-green-600' : 'text-red-500'">
                                            <span class="material-symbols-outlined text-sm mat-fill" x-text="photosComplete ? 'check_circle' : 'radio_button_unchecked'"></span>
                                            <span>Photo in every subcategory<span x-show="!photosComplete"> (missing)</span></span>
                                        </span>
                                        --}}
                                    </p>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        @click="showPartialReason = true"
                                        :disabled="finishing"
                                        class="h-11 bg-orange-50 text-orange-700 border border-orange-200 rounded-2xl font-bold text-xs hover:bg-orange-100 transition-colors disabled:opacity-50">
                                        Partially Completed
                                    </button>
                                    <button
                                        @click="showCancelReason = true"
                                        :disabled="finishing"
                                        class="h-11 bg-gray-50 text-gray-600 border border-gray-200 rounded-2xl font-bold text-xs hover:bg-gray-100 transition-colors disabled:opacity-50">
                                        Cancel / Abandon Visit
                                    </button>
                                </div>
                            </div>

                            {{-- Partial reason panel --}}
                            <div x-show="showPartialReason" x-transition class="space-y-2 p-3 bg-orange-50 border border-orange-200 rounded-2xl">
                                <div class="grid grid-cols-2 gap-2">
                                    <button @click="showPartialReason = false; partialReason = ''"
                                        class="h-10 bg-white border border-gray-200 text-[#737685] rounded-xl font-bold text-xs">
                                        Back
                                    </button>
                                    <button @click="confirmPartial()" :disabled="!partialReason.trim() || finishing"
                                        class="h-10 bg-orange-600 text-white rounded-xl font-bold text-xs disabled:opacity-40">
                                        Confirm Partial
                                    </button>
                                </div>
                                <label class="block text-xs font-bold text-[#737685] uppercase tracking-wider">Reason for partial completion</label>
                                <textarea x-model="partialReason" rows="2" placeholder="e.g. Some items not available, follow-up needed..."
                                    class="w-full border border-orange-200 rounded-xl px-3 py-2 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-400 resize-none"></textarea>
                            </div>

                            {{-- Cancel reason panel --}}
                            <div x-show="showCancelReason" x-transition class="space-y-2 p-3 bg-gray-50 border border-gray-200 rounded-2xl">
                                <div class="grid grid-cols-2 gap-2">
                                    <button @click="showCancelReason = false; cancelReason = ''"
                                        class="h-10 bg-white border border-gray-200 text-[#737685] rounded-xl font-bold text-xs">
                                        Back
                                    </button>
                                    <button @click="confirmCancel()" :disabled="!cancelReason.trim() || finishing"
                                        class="h-10 bg-red-600 text-white rounded-xl font-bold text-xs disabled:opacity-40">
                                        Confirm Cancel
                                    </button>
                                </div>
                                <label class="block text-xs font-bold text-[#737685] uppercase tracking-wider">Reason for cancelling</label>
                                <textarea x-model="cancelReason" rows="2" placeholder="e.g. Store was closed, owner not around..."
                                    class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm text-[#191c1e] focus:outline-none focus:ring-2 focus:ring-[#890f00]/30 focus:border-[#890f00] resize-none"></textarea>
                            </div>
                    </div>

                    {{-- TERMINAL STATES --}}
                    <div
                        x-show="selectedCall?.status === 'completed'"
                        class="flex items-center justify-center gap-2 h-12 bg-green-50 rounded-2xl border border-green-200">
                        <span class="material-symbols-outlined text-green-600 mat-fill"
                            :title="selectedCall?.sync_status === 'synced' ? 'Visit completed and synced' : 'Visit completed and pending upload'"
                            x-text="selectedCall?.sync_status === 'synced' ? 'check_circle' : 'cloud_upload'">check_circle</span>
                        <span class="font-bold text-sm text-green-700"
                            x-text="selectedCall?.sync_status === 'synced' ? 'Visit Completed — Synced' : 'Visit Completed — Pending Sync'">
                        </span>
                    </div>
                    <div x-show="selectedCall?.status === 'partially_completed'" class="space-y-2">
                        <div class="flex items-center justify-center gap-2 h-12 bg-orange-50 rounded-2xl border border-orange-200">
                            <span class="material-symbols-outlined text-orange-600 mat-fill"
                                :title="selectedCall?.sync_status === 'synced' ? 'Visit partially completed and synced' : 'Visit partially completed'"
                                x-text="selectedCall?.sync_status === 'synced' ? 'incomplete_circle' : 'cloud_upload'">incomplete_circle</span>
                            <span class="font-bold text-sm text-orange-700"
                                x-text="selectedCall?.sync_status === 'synced' ? 'Partially Completed — Synced' : 'Partially Completed — Pending Sync'">
                            </span>
                        </div>
                        <button
                            @click="continueVisit()"
                            :disabled="anyOtherInProgress"
                            :class="anyOtherInProgress ? 'opacity-40 cursor-not-allowed' : 'hover:opacity-95 active:scale-[0.98]'"
                            class="w-full h-12 bg-[#890f00] text-white rounded-2xl font-black text-sm shadow-lg transition-all">
                            Continue Visit
                        </button>
                    </div>
                    <div
                        x-show="selectedCall?.status === 'cancelled'"
                        class="flex items-center justify-center gap-2 h-12 bg-gray-100 rounded-2xl border border-gray-200">
                        <span class="material-symbols-outlined text-gray-500 mat-fill"
                            :title="selectedCall?.sync_status === 'synced' ? 'Visit cancelled and synced' : 'Visit cancelled'"
                            x-text="selectedCall?.sync_status === 'synced' ? 'cancel' : 'cloud_upload'">cancel</span>
                        <span class="font-bold text-sm text-gray-600"
                            x-text="selectedCall?.sync_status === 'synced' ? 'Visit Cancelled — Synced' : 'Visit Cancelled — Pending Sync'">
                        </span>
                    </div>
                </div>

            </div>

        </div>{{-- end right panel --}}

    </div>{{-- end split view --}}

</div>{{-- end x-data root --}}

</x-filament-panels::page>
