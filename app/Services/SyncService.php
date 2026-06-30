<?php

namespace App\Services;

use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use App\Models\SalescallImage;


class SyncService
{
    private string $serverUrl;
    private int $timeout;

    public function __construct()
    {
        $this->serverUrl = rtrim(config('sync.server_url', ''), '/');
        $this->timeout   = (int) config('sync.timeout', 15);
    }

    public function isReachable(): bool
    {
        if (blank($this->serverUrl)) {
            return false;
        }

        try {
            return Http::timeout(3)->get("{$this->serverUrl}/api/ping")->successful();
        } catch (\Exception) {
            return false;
        }
    }

    public function refreshToken(string $email, string $password): SyncResult
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->serverUrl}/api/auth/tablet-login", compact('email', 'password'));

            if ($response->status() === 401) {
                return SyncResult::fail('Invalid email or password.', 'invalid_credentials');
            }

            if ($response->failed()) {
                return SyncResult::fail("Server error ({$response->status()}).", 'server_error');
            }

            $data = $response->json();

            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'      => $data['name'],
                    'password'  => $data['password'],
                    'api_token' => $data['api_token'],
                    'rsm_id'    => $data['rsm_id'] ?? null,
                ]
            );

            if (! empty($data['roles'])) {
                foreach ($data['roles'] as $roleName) {
                    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
                }
                $user->syncRoles($data['roles']);
            }

            return SyncResult::ok('Token refreshed.');
        } catch (\Exception $e) {
            return SyncResult::fail('Could not reach server: ' . $e->getMessage(), 'connection_error');
        }
    }

    public function pull(): SyncResult
    {
        $user = User::whereNotNull('api_token')->first();

        if (! $user) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        try {
            $response = $this->client($user->api_token)->get("{$this->serverUrl}/api/sync/pull");

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
            }

            if ($response->failed()) {
                return SyncResult::fail("Pull failed ({$response->status()}).", 'server_error');
            }

            $data = $response->json();

            foreach ($data['itineraries'] ?? [] as $itinerary) {
                $local = Itinerary::updateOrCreate(
                    ['local_uuid' => $itinerary['local_uuid'] ?? (string) $itinerary['id']],
                    [
                        'server_id'           => $itinerary['id'],
                        'created_by'          => $user->id,
                        'date_month'          => $itinerary['date_month'] ?? null,
                        'date_year'           => $itinerary['date_year'] ?? null,
                        'remarks'             => $itinerary['remarks'] ?? null,
                        'itinerary_status_id' => $itinerary['itinerary_status_id'] ?? null,
                        'sync_status'         => 'synced',
                    ]
                );

                foreach ($itinerary['salescalls'] ?? [] as $sc) {
                    $visitDate = $sc['route_start_at'] ?? $sc['actual_in'] ?? null;

                    Salescall::updateOrCreate(
                        ['local_uuid' => $sc['local_uuid'] ?? (string) $sc['id']],
                        [
                            'server_id'         => $sc['id'],
                            'itinerary_id'      => $local->id,
                            'customer_id'       => $sc['customer_id'],
                            'visit_date'        => $visitDate,
                            'actual_in'         => $sc['actual_in'] ?? null,
                            'actual_out'        => $sc['actual_out'] ?? null,
                            'collection_amount' => $sc['collection_amount'] ?? null,
                            'remarks'           => $sc['remarks'] ?? null,
                            'concerns'          => $sc['concerns'] ?? null,
                            'sync_status'       => 'synced',
                        ]
                    );
                }
            }

            foreach ($data['customers'] ?? [] as $customer) {
                \Illuminate\Support\Facades\DB::table('customers')->updateOrInsert(
                    ['id' => $customer['id']],
                    [
                        'region_specific_id' => $customer['region_specific_id'] ?? null,
                        'municipality_id'    => $customer['municipality_id'] ?? null,
                        'name'               => $customer['name'],
                        'contact_person'     => $customer['contact_person'] ?? null,
                        'contact_number'     => $customer['contact_number'] ?? null,
                        'address'            => $customer['address'] ?? null,
                        'latitude'           => $customer['latitude'] ?? null,
                        'longitude'          => $customer['longitude'] ?? null,
                        'is_active'          => $customer['is_active'] ?? true,
                        'updated_at'         => now(),
                    ]
                );
            }


            $itineraryCount = count($data['itineraries'] ?? []);
            $salescallCount = array_sum(
                array_map(fn($i) => count($i['salescalls'] ?? []), $data['itineraries'] ?? [])
            );




            foreach ($data['material_groups'] ?? [] as $group) {
                \Illuminate\Support\Facades\DB::table('material_groups')->updateOrInsert(
                    ['id' => $group['id']],
                    ['name' => $group['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['brands'] ?? [] as $brand) {
                \Illuminate\Support\Facades\DB::table('brands')->updateOrInsert(
                    ['id' => $brand['id']],
                    [
                        'material_group_id' => $brand['material_group_id'],
                        'name'              => $brand['name'],
                        'enabled'           => $brand['enabled'],
                        'updated_at'        => now(),
                    ]
                );
            }

            // foreach ($data['customer_user'] ?? [] as $pivot) {
            //     \Illuminate\Support\Facades\DB::table('customer_user')->updateOrInsert(
            //         ['customer_id' => $pivot['customer_id'], 'user_id' => $pivot['user_id']],
            //         ['updated_at' => now()]
            //     );
            // }


            foreach ($data['categories'] ?? [] as $item) {
                \Illuminate\Support\Facades\DB::table('categories')->updateOrInsert(
                    ['id' => $item['id']],
                    ['name' => $item['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['sub_categories'] ?? [] as $item) {
                \Illuminate\Support\Facades\DB::table('sub_categories')->updateOrInsert(
                    ['id' => $item['id']],
                    ['category_id' => $item['category_id'], 'name' => $item['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['sub_sub_categories'] ?? [] as $item) {
                \Illuminate\Support\Facades\DB::table('sub_sub_categories')->updateOrInsert(
                    ['id' => $item['id']],
                    ['sub_category_id' => $item['sub_category_id'], 'name' => $item['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['salescall_image_categories'] ?? [] as $item) {
                \Illuminate\Support\Facades\DB::table('salescall_image_categories')->updateOrInsert(
                    ['id' => $item['id']],
                    ['name' => $item['name'], 'slug' => $item['slug'], 'sort' => $item['sort'] ?? 0, 'updated_at' => now()]
                );
            }

            foreach ($data['salescall_image_types'] ?? [] as $item) {
                \Illuminate\Support\Facades\DB::table('salescall_image_types')->updateOrInsert(
                    ['id' => $item['id']],
                    [
                        'salescall_image_category_id' => $item['salescall_image_category_id'],
                        'name'       => $item['name'],
                        'slug'       => $item['slug'],
                        'sort'       => $item['sort'] ?? 0,
                        'updated_at' => now(),
                    ]
                );
            }




            $customerCount = count($data['customers'] ?? []);

            return SyncResult::ok("Pulled {$itineraryCount} itineraries, {$salescallCount} salescalls, {$customerCount} customers.");
        } catch (\Exception $e) {
            return SyncResult::fail('Pull error: ' . $e->getMessage(), 'exception');
        }
    }

    public function push(): SyncResult
    {
        $user = User::whereNotNull('api_token')->first();

        if (! $user) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $client = $this->client($user->api_token);
        $pushed = 0;
        $failed = 0;

        $pendingItineraries = Itinerary::where('sync_status', 'pending')
            ->orWhere(fn($q) => $q->where('sync_status', 'failed')->where('sync_attempts', '<', 3))
            ->get();

        foreach ($pendingItineraries as $itinerary) {
            try {
                $response = $client->post("{$this->serverUrl}/api/sync/push/itinerary", [
                    'local_uuid'          => $itinerary->local_uuid,
                    'date_month'          => $itinerary->date_month,
                    'date_year'           => $itinerary->date_year,
                    'remarks'             => $itinerary->remarks,
                    'itinerary_status_id' => $itinerary->itinerary_status_id,
                ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $itinerary->update(['sync_status' => 'synced', 'server_id' => $response->json('server_id'), 'sync_error' => null]);
                    $pushed++;
                } else {
                    $this->markFailed($itinerary, $response->status() . ': ' . $response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($itinerary, $e->getMessage());
                $failed++;
            }
        }

        $pendingSalescalls = Salescall::with('itinerary')
            ->where('sync_status', 'pending')
            ->orWhere(fn($q) => $q->where('sync_status', 'failed')->where('sync_attempts', '<', 3))
            ->get();

        foreach ($pendingSalescalls as $salescall) {
            if (! $salescall->itinerary?->local_uuid) {
                continue;
            }

            try {
                $response = $client->post("{$this->serverUrl}/api/sync/push/salescall", [
                    'local_uuid'           => $salescall->local_uuid,
                    'itinerary_uuid'       => $salescall->itinerary->local_uuid,
                    'customer_id'          => $salescall->customer_id,
                    'salescall_type_id'    => $salescall->salescall_type_id,
                    'latitude'             => $salescall->latitude,
                    'longitude'            => $salescall->longitude,
                    'latitude_actual_in'   => $salescall->latitude_actual_in,
                    'longitude_actual_in'  => $salescall->longitude_actual_in,
                    'latitude_actual_out'  => $salescall->latitude_actual_out,
                    'longitude_actual_out' => $salescall->longitude_actual_out,
                    'actual_in'            => $salescall->actual_in?->toDateTimeString(),
                    'actual_out'           => $salescall->actual_out?->toDateTimeString(),

                    'material_group_id'    => $salescall->material_group_id,
                    'brand_id'             => $salescall->brand_id,
                    'brand_other'          => $salescall->brand_other,

                    'category_id'          => $salescall->category_id,
                    'sub_category_id'      => $salescall->sub_category_id,
                    'sub_sub_category_id'  => $salescall->sub_sub_category_id,

                    'collection_amount'    => $salescall->collection_amount,
                    'remarks'              => $salescall->remarks,
                    'concerns'             => $salescall->concerns,
                ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $salescall->update(['sync_status' => 'synced', 'server_id' => $response->json('server_id'), 'sync_error' => null]);
                    $pushed++;
                } else {
                    $this->markFailed($salescall, $response->status() . ': ' . $response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($salescall, $e->getMessage());
                $failed++;
            }
        }

        $pendingImages = SalescallImage::with('salescall')
            ->where(function ($q) {
                $q->where('sync_status', 'pending')
                    ->orWhere(fn($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
            })
            ->get();

        foreach ($pendingImages as $image) {
            if (! $image->salescall?->server_id) {
                continue; // wait for salescall to sync first
            }

            if (! file_exists($image->local_path)) {
                $this->markFailed($image, 'Local file not found: ' . $image->local_path);
                $failed++;
                continue;
            }

            try {
                $response = $client
                    ->attach('image', fopen($image->local_path, 'r'), basename($image->local_path))
                    ->post("{$this->serverUrl}/api/sync/push/salescall-image", [
                        'local_uuid'              => $image->local_uuid,
                        'salescall_server_id'     => $image->salescall->server_id,
                        'salescall_image_type_id' => $image->salescall_image_type_id,
                        'notes'                   => $image->notes,
                    ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $image->update([
                        'sync_status' => 'synced',
                        'server_id'   => $response->json('server_id'),
                        'sync_error'  => null,
                    ]);
                    $pushed++;
                } else {
                    $this->markFailed($image, $response->status() . ': ' . $response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($image, $e->getMessage());
                $failed++;
            }
        }


        if ($pushed === 0 && $failed === 0) {
            return SyncResult::ok('Nothing to push.');
        }

        if ($failed > 0 && $pushed === 0) {
            return SyncResult::fail("{$failed} item(s) failed to sync.", 'push_failed');
        }

        if ($failed > 0) {
            return SyncResult::ok("Pushed {$pushed} items. {$failed} failed and will retry.");
        }

        return SyncResult::ok("Pushed {$pushed} items successfully.");
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout($this->timeout);
    }

    private function markFailed(Model $model, string $error): void
    {
        $model->update([
            'sync_status'   => 'failed',
            'sync_attempts' => ($model->sync_attempts ?? 0) + 1,
            'sync_error'    => $error,
        ]);
    }
}
