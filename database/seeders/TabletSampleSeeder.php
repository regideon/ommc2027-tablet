<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TabletSampleSeeder extends Seeder
{
    public const TEST_TOKEN = 'sync-test-token-0000000000000000000000000000000000000000000000000000000000000000';

    public function run(): void
    {
        // SQLite — delete in dependency order (no FK checks needed)
        DB::table('salescalls')->delete();
        DB::table('itineraries')->delete();
        DB::table('branches')->delete();
        DB::table('municipalities')->delete();
        DB::table('customers')->delete();
        DB::table('region_specifics')->delete();
        DB::table('regions')->delete();
        DB::table('salescall_types')->delete();
        DB::table('salescall_statuses')->delete();
        DB::table('itinerary_statuses')->delete();

        // ── Lookup tables ─────────────────────────────────
        DB::table('itinerary_statuses')->insert([
            ['id' => 1, 'name' => 'Pending',   'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Approved',  'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Revised',   'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Rejected',  'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('salescall_statuses')->insert([
            ['id' => 1, 'name' => 'Pending',     'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'In Progress', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Scheduled',   'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Completed',   'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'Approved',    'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'Revised',     'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'name' => 'Rejected',    'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('salescall_types')->insert([
            ['id' => 1, 'name' => 'Planned',   'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Unplanned', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Reference data (mirrors server) ───────────────
        DB::table('regions')->insert([
            ['id' => 1, 'code' => 'MM', 'name' => 'Metro Manila', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('region_specifics')->insert([
            ['id' => 1, 'region_id' => 1, 'name' => 'National Capital Region (NCR)', 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // province_id = null — no provinces table on tablet schema
        DB::table('municipalities')->insert([
            ['id' => 1, 'region_id' => 1, 'province_id' => null, 'name' => 'Quezon City', 'sort' => 1, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('customers')->insert([
            [
                'id'                 => 1,
                'region_specific_id' => 1,
                'municipality_id'    => null,
                'name'               => '1 Stop Battery Shop Inc.',
                'address'            => '#73 Emerson Condo, E. Rodriguez Sr. Ave, QC',
                'latitude'           => 14.626700,
                'longitude'          => 121.021400,
                'is_active'          => 1,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'id'                 => 2,
                'region_specific_id' => 1,
                'municipality_id'    => null,
                'name'               => 'MOTOLITE WEST AVE GR-8 VENTURES MKTG CORP',
                'address'            => 'West Avenue, Quezon City',
                'latitude'           => 14.644400,
                'longitude'          => 121.030600,
                'is_active'          => 1,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'id'                 => 3,
                'region_specific_id' => 1,
                'municipality_id'    => null,
                'name'               => 'Motolite Banawe QC - Old Timer Battery Shop',
                'address'            => 'Banawe Street, Quezon City',
                'latitude'           => 14.630200,
                'longitude'          => 121.010500,
                'is_active'          => 1,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
        ]);

        // tablet branches schema has no region_id/municipality_id
        DB::table('branches')->insert([
            ['id' => 1, 'customer_id' => 1, 'name' => 'E. Rodriguez Sr. Branch', 'address' => '#73 Emerson Condo, E. Rodriguez Sr. Ave, QC', 'latitude' => 14.626700, 'longitude' => 121.021400, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'customer_id' => 2, 'name' => 'West Ave Branch',         'address' => 'West Avenue, Quezon City',                    'latitude' => 14.644400, 'longitude' => 121.030600, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'customer_id' => 3, 'name' => 'Banawe Branch',           'address' => 'Banawe Street, Quezon City',                  'latitude' => 14.630200, 'longitude' => 121.010500, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── DRM user with pre-loaded test token ───────────
        \App\Models\User::updateOrCreate(
            ['email' => 'reg.ebalobor@kaisa.com'],
            [
                'name'      => 'Reg Ebalobor',
                'password'  => Hash::make('K@isaC0nsulting'),
                'api_token' => self::TEST_TOKEN,
            ]
        );

        // ── Date anchors ──────────────────────────────────
        $today      = Carbon::now();
        $yesterday  = Carbon::now()->subDay();
        $twoDaysAgo = Carbon::now()->subDays(2);
        $midMonth   = Carbon::now()->startOfMonth()->addDays(9);
        $earlyMonth = Carbon::now()->startOfMonth()->addDays(4);

        // ── Itinerary ─────────────────────────────────────
        $itineraryUuid = Str::uuid()->toString();

        DB::table('itineraries')->insert([
            [
                'id'                  => 1,
                'date_month'          => Carbon::now()->startOfMonth()->toDateString(),
                'date_year'           => Carbon::now()->startOfMonth()->toDateString(),
                'remarks'             => 'Sample itinerary for ' . Carbon::now()->format('F Y'),
                'itinerary_status_id' => 1,
                'created_by'          => 1,
                'approved_by'         => null,
                'local_uuid'          => $itineraryUuid,
                'server_id'           => null,
                'sync_status'         => 'pending',
                'sync_attempts'       => 0,
                'sync_error'          => null,
                'synced_at'           => null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ],
        ]);

        // ── Salescalls ────────────────────────────────────
        DB::table('salescalls')->insert([

            // TODAY (2) ────────────────────────────────────
            [
                'itinerary_id'        => 1,
                'customer_id'         => 1,
                'salescall_type_id'   => 1,
                'latitude'            => 14.626700,
                'longitude'           => 121.021400,
                'latitude_actual_in'  => 14.626710,
                'longitude_actual_in' => 121.021410,
                'actual_in'           => $today->copy()->setTime(9, 0)->toDateTimeString(),
                'actual_out'          => null,
                'collection_amount'   => null,
                'collection_remarks'  => null,
                'remarks'             => 'Currently on-site.',
                'concerns'            => null,
                'created_by'          => 1,
                'updated_by'          => null,
                'local_uuid'          => Str::uuid()->toString(),
                'server_id'           => null,
                'sync_status'         => 'pending',
                'sync_attempts'       => 0,
                'sync_error'          => null,
                'synced_at'           => null,
                'created_at'          => $today->copy()->setTime(8, 50)->toDateTimeString(),
                'updated_at'          => now(),
            ],
            [
                'itinerary_id'        => 1,
                'customer_id'         => 2,
                'salescall_type_id'   => 1,
                'latitude'            => 14.644400,
                'longitude'           => 121.030600,
                'latitude_actual_in'  => null,
                'longitude_actual_in' => null,
                'actual_in'           => null,
                'actual_out'          => null,
                'collection_amount'   => null,
                'collection_remarks'  => null,
                'remarks'             => 'Scheduled for afternoon.',
                'concerns'            => null,
                'created_by'          => 1,
                'updated_by'          => null,
                'local_uuid'          => Str::uuid()->toString(),
                'server_id'           => null,
                'sync_status'         => 'pending',
                'sync_attempts'       => 0,
                'sync_error'          => null,
                'synced_at'           => null,
                'created_at'          => $today->copy()->setTime(8, 55)->toDateTimeString(),
                'updated_at'          => now(),
            ],

            // THIS WEEK, NOT TODAY (2) ─────────────────────
            [
                'itinerary_id'        => 1,
                'customer_id'         => 3,
                'salescall_type_id'   => 1,
                'latitude'            => 14.630200,
                'longitude'           => 121.010500,
                'latitude_actual_in'  => 14.630210,
                'longitude_actual_in' => 121.010510,
                'actual_in'           => $yesterday->copy()->setTime(8, 0)->toDateTimeString(),
                'actual_out'          => $yesterday->copy()->setTime(8, 45)->toDateTimeString(),
                'collection_amount'   => 12000.00,
                'collection_remarks'  => 'Full payment collected.',
                'remarks'             => 'Good visit.',
                'concerns'            => null,
                'created_by'          => 1,
                'updated_by'          => null,
                'local_uuid'          => Str::uuid()->toString(),
                'server_id'           => null,
                'sync_status'         => 'pending',
                'sync_attempts'       => 0,
                'sync_error'          => null,
                'synced_at'           => null,
                'created_at'          => $yesterday->copy()->setTime(7, 50)->toDateTimeString(),
                'updated_at'          => now(),
            ],
            [
                'itinerary_id'        => 1,
                'customer_id'         => 1,
                'salescall_type_id'   => 1,
                'latitude'            => 14.626700,
                'longitude'           => 121.021400,
                'latitude_actual_in'  => 14.626710,
                'longitude_actual_in' => 121.021410,
                'actual_in'           => $twoDaysAgo->copy()->setTime(10, 30)->toDateTimeString(),
                'actual_out'          => $twoDaysAgo->copy()->setTime(11, 15)->toDateTimeString(),
                'collection_amount'   => 8500.00,
                'collection_remarks'  => 'Partial payment.',
                'remarks'             => 'Discussed promo materials.',
                'concerns'            => 'Requested additional stock.',
                'created_by'          => 1,
                'updated_by'          => null,
                'local_uuid'          => Str::uuid()->toString(),
                'server_id'           => null,
                'sync_status'         => 'pending',
                'sync_attempts'       => 0,
                'sync_error'          => null,
                'synced_at'           => null,
                'created_at'          => $twoDaysAgo->copy()->setTime(10, 20)->toDateTimeString(),
                'updated_at'          => now(),
            ],

            // THIS MONTH, NOT THIS WEEK (2) ────────────────
            [
                'itinerary_id'        => 1,
                'customer_id'         => 2,
                'salescall_type_id'   => 1,
                'latitude'            => 14.644400,
                'longitude'           => 121.030600,
                'latitude_actual_in'  => 14.644410,
                'longitude_actual_in' => 121.030610,
                'actual_in'           => $midMonth->copy()->setTime(9, 0)->toDateTimeString(),
                'actual_out'          => $midMonth->copy()->setTime(9, 50)->toDateTimeString(),
                'collection_amount'   => 22000.00,
                'collection_remarks'  => 'Full payment.',
                'remarks'             => 'Excellent visit.',
                'concerns'            => null,
                'created_by'          => 1,
                'updated_by'          => null,
                'local_uuid'          => Str::uuid()->toString(),
                'server_id'           => null,
                'sync_status'         => 'pending',
                'sync_attempts'       => 0,
                'sync_error'          => null,
                'synced_at'           => null,
                'created_at'          => $midMonth->copy()->setTime(8, 50)->toDateTimeString(),
                'updated_at'          => now(),
            ],
            [
                'itinerary_id'        => 1,
                'customer_id'         => 3,
                'salescall_type_id'   => 1,
                'latitude'            => 14.630200,
                'longitude'           => 121.010500,
                'latitude_actual_in'  => 14.630210,
                'longitude_actual_in' => 121.010510,
                'actual_in'           => $earlyMonth->copy()->setTime(14, 0)->toDateTimeString(),
                'actual_out'          => $earlyMonth->copy()->setTime(14, 30)->toDateTimeString(),
                'collection_amount'   => null,
                'collection_remarks'  => null,
                'remarks'             => 'No collection. Follow up next visit.',
                'concerns'            => 'Low inventory reported.',
                'created_by'          => 1,
                'updated_by'          => null,
                'local_uuid'          => Str::uuid()->toString(),
                'server_id'           => null,
                'sync_status'         => 'pending',
                'sync_attempts'       => 0,
                'sync_error'          => null,
                'synced_at'           => null,
                'created_at'          => $earlyMonth->copy()->setTime(13, 50)->toDateTimeString(),
                'updated_at'          => now(),
            ],
        ]);

        $this->command->info('Tablet SQLite seeded: 3 customers, 3 branches, 1 itinerary, 6 salescalls — all pending sync.');
        $this->command->info('API token pre-loaded: ' . self::TEST_TOKEN);
    }
}
