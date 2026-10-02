<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('customer_trade_profiles')
                ->where('profile_data->active->conversion_program', 'Inc Share of Wallet')
                ->select(['id', 'profile_data'])
                ->orderBy('id')
                ->get()
                ->each(function (object $profile): void {
                    $profileData = json_decode((string) $profile->profile_data, true);

                    if (! is_array($profileData)
                        || ($profileData['active']['conversion_program'] ?? null) !== 'Inc Share of Wallet') {
                        return;
                    }

                    $profileData['active']['conversion_program'] = 'Increase Share of Wallet';

                    DB::table('customer_trade_profiles')->where('id', $profile->id)->update([
                        'profile_data' => json_encode($profileData),
                        'updated_at' => now(),
                    ]);
                });
        });
    }

    public function down(): void
    {
        // Intentionally one-way: the legacy value must not be reintroduced.
    }
};
