<x-filament-panels::page>

<div class="max-w-lg mx-auto space-y-4 pb-8">

    {{-- Page Title --}}
    <div class="px-1 pt-2 pb-1">
        <h1 class="text-2xl font-black text-[#191c1e]">Settings</h1>
        <p class="text-sm text-[#737685] mt-0.5">Manage your account and preferences</p>
    </div>

    {{-- Account Section --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100">
            <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest">Account</p>
        </div>
        <div class="divide-y divide-gray-100">

            <a href="/app/profile"
               class="flex items-center gap-4 px-5 py-4 hover:bg-gray-50 transition-colors active:bg-gray-100">
                <div class="w-10 h-10 bg-red-50 rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[#890f00]">person</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm text-[#191c1e]">Profile</p>
                    <p class="text-xs text-[#737685]">Update your name, email and password</p>
                </div>
                <span class="material-symbols-outlined text-gray-300 text-xl">chevron_right</span>
            </a>

            <div class="flex items-center gap-4 px-5 py-4 opacity-50">
                <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-blue-600">notifications</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm text-[#191c1e]">Notifications</p>
                    <p class="text-xs text-[#737685]">Manage alerts and reminders</p>
                </div>
                <span class="text-[10px] font-black text-gray-400 uppercase tracking-wider">Soon</span>
            </div>

            <div class="flex items-center gap-4 px-5 py-4 opacity-50">
                <div class="w-10 h-10 bg-purple-50 rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-purple-600">lock</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm text-[#191c1e]">Privacy & Security</p>
                    <p class="text-xs text-[#737685]">PIN lock and data permissions</p>
                </div>
                <span class="text-[10px] font-black text-gray-400 uppercase tracking-wider">Soon</span>
            </div>

        </div>
    </div>

    {{-- Data Section --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100">
            <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest">Data & Sync</p>
        </div>
        <div class="divide-y divide-gray-100">

            <div class="flex items-center gap-4 px-5 py-4 opacity-50">
                <div class="w-10 h-10 bg-green-50 rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-green-600">storage</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm text-[#191c1e]">Local Storage</p>
                    <p class="text-xs text-[#737685]">View and manage offline data</p>
                </div>
                <span class="text-[10px] font-black text-gray-400 uppercase tracking-wider">Soon</span>
            </div>

            <div class="flex items-center gap-4 px-5 py-4 opacity-50">
                <div class="w-10 h-10 bg-orange-50 rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-orange-500">wifi</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm text-[#191c1e]">Connection Settings</p>
                    <p class="text-xs text-[#737685]">Server URL and sync preferences</p>
                </div>
                <span class="text-[10px] font-black text-gray-400 uppercase tracking-wider">Soon</span>
            </div>

        </div>
    </div>

    {{-- Support Section --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100">
            <p class="text-[10px] font-black text-[#737685] uppercase tracking-widest">Support</p>
        </div>
        <div class="divide-y divide-gray-100">

            <div class="flex items-center gap-4 px-5 py-4 opacity-50">
                <div class="w-10 h-10 bg-yellow-50 rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-yellow-600">help</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm text-[#191c1e]">Help & Support</p>
                    <p class="text-xs text-[#737685]">FAQs and contact information</p>
                </div>
                <span class="text-[10px] font-black text-gray-400 uppercase tracking-wider">Soon</span>
            </div>

            <div class="flex items-center gap-4 px-5 py-4 opacity-50">
                <div class="w-10 h-10 bg-gray-50 rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-gray-500">info</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm text-[#191c1e]">About</p>
                    <p class="text-xs text-[#737685]">Terms of use and licenses</p>
                </div>
                <span class="text-[10px] font-black text-gray-400 uppercase tracking-wider">Soon</span>
            </div>

        </div>
    </div>

    {{-- Logout --}}
    <button
        wire:click="logout"
        wire:confirm="Are you sure you want to log out?"
        class="w-full flex items-center justify-center gap-3 py-4 bg-white border border-red-200 text-[#890f00] rounded-2xl font-bold text-sm shadow-sm hover:bg-red-50 active:scale-[0.98] transition-all">
        <span class="material-symbols-outlined text-xl">logout</span>
        Log Out
    </button>

    {{-- App Version --}}
    <div class="text-center pt-2 pb-4">
        <p class="text-xs font-black text-[#737685] tracking-widest uppercase">OMMC Henri V2</p>
        <p class="text-[11px] text-gray-400 mt-0.5">Release 1.0.0.1</p>
    </div>

</div>

</x-filament-panels::page>
