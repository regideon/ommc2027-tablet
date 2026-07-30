<?php

namespace App\Services;

use App\Models\CustomerBrand;
use App\Models\CustomerCategory;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallCategory;
use App\Models\SalescallImage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

class SyncService
{
    private string $serverUrl;

    private int $timeout;

    public function __construct()
    {
        $this->serverUrl = rtrim(config('sync.server_url', ''), '/');
        $this->timeout = (int) config('sync.timeout', 15);
    }

    public function hasPendingChanges(): bool
    {
        $pendingOrRetryable = fn ($query) => $query
            ->where('sync_status', 'pending')
            ->orWhere(fn ($q) => $q->where('sync_status', 'failed')->where('sync_attempts', '<', 3));

        return $pendingOrRetryable(Itinerary::query())->exists()
            || $pendingOrRetryable(Salescall::query())->exists()
            || $pendingOrRetryable(SalescallBrand::query())->exists()
            || $pendingOrRetryable(SalescallCategory::query())->exists()
            || $pendingOrRetryable(SalescallImage::query())->exists()
            || $pendingOrRetryable(CustomerProfile::query())->exists()
            || $pendingOrRetryable(CustomerNote::query())->exists();
    }

    /**
     * Uploads a small timed payload to the sync server and returns the
     * measured throughput in Mbps, or null if the request failed.
     */
    public function measureSpeedMbps(): ?float
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return null;
        }

        $bytes = (int) config('sync.speedtest_bytes', 300_000);
        $payload = str_repeat('0', $bytes);

        try {
            $start = microtime(true);

            $response = $this->client($user->api_token)
                ->withBody($payload, 'application/octet-stream')
                ->post("{$this->serverUrl}/api/sync/speedtest");

            $elapsed = microtime(true) - $start;

            if (! $response->successful() || $elapsed <= 0) {
                return null;
            }

            return (($bytes * 8) / $elapsed) / 1_000_000;
        } catch (\Exception) {
            return null;
        }
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
                    'name' => $data['name'],
                    'password' => $data['password'],
                    'api_token' => $data['api_token'],
                    'rsm_id' => $data['rsm_id'] ?? null,
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
            return SyncResult::fail('Could not reach server: '.$e->getMessage(), 'connection_error');
        }
    }

    public function pull(): SyncResult
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
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

            // Reference/lookup tables must be populated before anything below that
            // holds a foreign key into them (salescall_brands -> material_groups/brands,
            // salescall_categories/customer_categories -> categories/sub_categories,
            // salescall_brands/salescall_categories -> customers). On a fresh install
            // with these tables still empty, a first pull whose itineraries already
            // carry salescall_brands/salescall_categories data (e.g. an RSM-added call)
            // would otherwise throw a foreign key integrity violation and abort the
            // entire pull before customers/brands/categories ever get written.
            foreach ($data['customers'] ?? [] as $customer) {
                DB::table('customers')->updateOrInsert(
                    ['id' => $customer['id']],
                    [
                        'region_specific_id' => $customer['region_specific_id'] ?? null,
                        'municipality_id' => $customer['municipality_id'] ?? null,
                        'name' => $customer['name'],
                        'unique_id' => $customer['unique_id'] ?? null,
                        'contact_person' => $customer['contact_person'] ?? null,
                        'contact_number' => $customer['contact_number'] ?? null,
                        'address' => $customer['address'] ?? null,
                        'latitude' => $customer['latitude'] ?? null,
                        'longitude' => $customer['longitude'] ?? null,
                        'is_active' => $customer['is_active'] ?? true,
                        'updated_at' => now(),
                    ]
                );
            }

            foreach ($data['salescall_statuses'] ?? [] as $status) {
                DB::table('salescall_statuses')->updateOrInsert(
                    ['id' => $status['id']],
                    ['name' => $status['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['salescall_types'] ?? [] as $type) {
                DB::table('salescall_types')->updateOrInsert(
                    ['id' => $type['id']],
                    ['name' => $type['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['material_groups'] ?? [] as $group) {
                DB::table('material_groups')->updateOrInsert(
                    ['id' => $group['id']],
                    ['name' => $group['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['brands'] ?? [] as $brand) {
                DB::table('brands')->updateOrInsert(
                    ['id' => $brand['id']],
                    [
                        'material_group_id' => $brand['material_group_id'],
                        'name' => $brand['name'],
                        'enabled' => $brand['enabled'],
                        'updated_at' => now(),
                    ]
                );
            }

            foreach ($data['categories'] ?? [] as $item) {
                DB::table('categories')->updateOrInsert(
                    ['id' => $item['id']],
                    ['name' => $item['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['sub_categories'] ?? [] as $item) {
                DB::table('sub_categories')->updateOrInsert(
                    ['id' => $item['id']],
                    ['category_id' => $item['category_id'], 'name' => $item['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['sub_sub_categories'] ?? [] as $item) {
                DB::table('sub_sub_categories')->updateOrInsert(
                    ['id' => $item['id']],
                    ['sub_category_id' => $item['sub_category_id'], 'name' => $item['name'], 'updated_at' => now()]
                );
            }

            foreach ($data['salescall_image_categories'] ?? [] as $item) {
                DB::table('salescall_image_categories')->updateOrInsert(
                    ['id' => $item['id']],
                    ['name' => $item['name'], 'slug' => $item['slug'], 'sort' => $item['sort'] ?? 0, 'updated_at' => now()]
                );
            }

            foreach ($data['salescall_image_types'] ?? [] as $item) {
                DB::table('salescall_image_types')->updateOrInsert(
                    ['id' => $item['id']],
                    [
                        'salescall_image_category_id' => $item['salescall_image_category_id'],
                        'name' => $item['name'],
                        'slug' => $item['slug'],
                        'sort' => $item['sort'] ?? 0,
                        'updated_at' => now(),
                    ]
                );
            }

            foreach ($data['itineraries'] ?? [] as $itinerary) {
                $local = Itinerary::updateOrCreate(
                    ['local_uuid' => $itinerary['local_uuid'] ?? (string) $itinerary['id']],
                    [
                        'server_id' => $itinerary['id'],
                        'created_by' => $user->id,
                        'date_month' => $itinerary['date_month'] ?? null,
                        'date_year' => $itinerary['date_year'] ?? null,
                        'remarks' => $itinerary['remarks'] ?? null,
                        'itinerary_status_id' => $itinerary['itinerary_status_id'] ?? null,
                        'sync_status' => 'synced',
                    ]
                );

                foreach ($itinerary['salescalls'] ?? [] as $sc) {
                    $visitDate = $sc['route_start_at'] ?? $sc['actual_in'] ?? null;
                    $localUuid = $sc['local_uuid'] ?? (string) $sc['id'];

                    // A salescall with unsynced local changes (e.g. a check-in or
                    // finish action not yet pushed) must not be clobbered by an
                    // incoming pull — the server's copy is stale until the push
                    // completes. Leave it untouched; the next push will resolve it.
                    $hasPendingLocalChanges = Salescall::where('local_uuid', $localUuid)
                        ->whereIn('sync_status', ['pending', 'failed'])
                        ->exists();

                    if ($hasPendingLocalChanges) {
                        $localSalescall = Salescall::where('local_uuid', $localUuid)->first();
                    } else {
                        $localSalescall = Salescall::updateOrCreate(
                            ['local_uuid' => $localUuid],
                            [
                                'server_id' => $sc['id'],
                                'itinerary_id' => $local->id,
                                'customer_id' => $sc['customer_id'],
                                'created_by' => $user->id,
                                'visit_date' => $visitDate,
                                'route_start_at' => $sc['route_start_at'] ?? null,
                                'actual_in' => $sc['actual_in'] ?? null,
                                'actual_out' => $sc['actual_out'] ?? null,
                                'salescall_status_id' => $sc['salescall_status_id'] ?? null,
                                'salescall_type_id' => $sc['salescall_type_id'] ?? null,
                                'outcome_reason' => $sc['outcome_reason'] ?? null,
                                'collection_amount' => $sc['collection_amount'] ?? null,
                                'remarks' => $sc['remarks'] ?? null,
                                'concerns' => $sc['concerns'] ?? null,
                                'sync_status' => 'synced',
                            ]
                        );
                    }

                    if (SalescallBrand::where('salescall_id', $localSalescall->id)->where('sync_status', 'pending')->doesntExist()) {
                        SalescallBrand::where('salescall_id', $localSalescall->id)->delete();

                        foreach ($sc['salescall_brands'] ?? [] as $brandRow) {
                            // A single row referencing a material_group_id/brand_id that
                            // doesn't exist locally (e.g. a brand disabled on the portal
                            // after this data was recorded) must not abort the entire pull
                            // and lose every other itinerary/customer for this user — skip
                            // just this row and report it so it's still visible in Pulse.
                            try {
                                SalescallBrand::create([
                                    'salescall_id' => $localSalescall->id,
                                    'customer_id' => $localSalescall->customer_id,
                                    'material_group_id' => $brandRow['material_group_id'],
                                    'brand_id' => $brandRow['brand_id'],
                                    'quantity' => $brandRow['quantity'] ?? null,
                                    'brand_other' => $brandRow['brand_other'] ?? null,
                                    'local_uuid' => (string) \Str::uuid(),
                                    'sync_status' => 'synced',
                                ]);
                            } catch (\Throwable $e) {
                                report($e);
                            }
                        }
                    }

                    $incomingCategory = $sc['salescall_category'] ?? null;

                    if ($incomingCategory && SalescallCategory::where('salescall_id', $localSalescall->id)->where('sync_status', 'pending')->doesntExist()) {
                        try {
                            $categoryRecord = SalescallCategory::firstOrNew(['salescall_id' => $localSalescall->id]);

                            if (! $categoryRecord->local_uuid) {
                                $categoryRecord->local_uuid = (string) \Str::uuid();
                            }

                            $categoryRecord->fill([
                                'customer_id' => $localSalescall->customer_id,
                                'category_id' => $incomingCategory['category_id'],
                                'sub_category_id' => $incomingCategory['sub_category_id'],
                                'sync_status' => 'synced',
                            ]);

                            $categoryRecord->save();
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                }
            }

            $itineraryCount = count($data['itineraries'] ?? []);
            $salescallCount = array_sum(
                array_map(fn ($i) => count($i['salescalls'] ?? []), $data['itineraries'] ?? [])
            );

            $incomingCustomerBrands = collect($data['customer_brands'] ?? [])->groupBy('customer_id');

            foreach ($incomingCustomerBrands as $customerId => $rows) {
                $hasPendingLocalChanges = SalescallBrand::where('customer_id', $customerId)
                    ->where('sync_status', 'pending')
                    ->exists();

                if ($hasPendingLocalChanges) {
                    continue;
                }

                CustomerBrand::where('customer_id', $customerId)->delete();

                foreach ($rows as $row) {
                    try {
                        CustomerBrand::create([
                            'customer_id' => $customerId,
                            'material_group_id' => $row['material_group_id'],
                            'brand_id' => $row['brand_id'],
                            'quantity' => $row['quantity'] ?? null,
                            'brand_other' => $row['brand_other'] ?? null,
                            'last_salescall_id' => $row['last_salescall_id'] ?? null,
                            'last_updated_by' => $row['last_updated_by'] ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            foreach ($data['customer_categories'] ?? [] as $categoryRow) {
                $hasPendingLocalChanges = SalescallCategory::where('customer_id', $categoryRow['customer_id'])
                    ->where('sync_status', 'pending')
                    ->exists();

                if ($hasPendingLocalChanges) {
                    continue;
                }

                try {
                    CustomerCategory::updateOrCreate(
                        ['customer_id' => $categoryRow['customer_id']],
                        [
                            'category_id' => $categoryRow['category_id'],
                            'sub_category_id' => $categoryRow['sub_category_id'],
                            'last_salescall_id' => $categoryRow['last_salescall_id'] ?? null,
                            'last_updated_by' => $categoryRow['last_updated_by'] ?? null,
                        ]
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            foreach ($data['customer_notes'] ?? [] as $noteRow) {
                $hasPendingLocalChanges = CustomerNote::where('local_uuid', $noteRow['local_uuid'])
                    ->where('sync_status', 'pending')
                    ->exists();

                if ($hasPendingLocalChanges) {
                    continue;
                }

                CustomerNote::updateOrCreate(
                    ['local_uuid' => $noteRow['local_uuid']],
                    [
                        'server_id' => $noteRow['id'],
                        'customer_id' => $noteRow['customer_id'],
                        // Portal already scopes the customer_notes payload to created_by = the
                        // syncing user (see SyncController::pull()), so every row here belongs
                        // to $user. Use the tablet's own local user id, not the portal's numeric
                        // id in the payload — portal and tablet user ids differ for the same
                        // person (see "Portal user IDs ≠ tablet user IDs" gotcha), so writing the
                        // portal's id here violates the local users FK.
                        'created_by' => $user->id,
                        'title' => $noteRow['title'] ?? null,
                        'body' => $noteRow['body'],
                        'sync_status' => 'synced',
                        'synced_at' => now(),
                    ]
                );
            }

            // foreach ($data['customer_user'] ?? [] as $pivot) {
            //     \Illuminate\Support\Facades\DB::table('customer_user')->updateOrInsert(
            //         ['customer_id' => $pivot['customer_id'], 'user_id' => $pivot['user_id']],
            //         ['updated_at' => now()]
            //     );
            // }

            $customerCount = count($data['customers'] ?? []);

            return SyncResult::ok("Pulled {$itineraryCount} itineraries, {$salescallCount} salescalls, {$customerCount} customers.");
        } catch (\Exception $e) {
            return SyncResult::fail('Pull error: '.$e->getMessage(), 'exception');
        }
    }

    public function push(): SyncResult
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $client = $this->client($user->api_token);
        $pushed = 0;
        $failed = 0;

        $pendingItineraries = Itinerary::where('sync_status', 'pending')
            ->orWhere(fn ($q) => $q->where('sync_status', 'failed')->where('sync_attempts', '<', 3))
            ->get();

        foreach ($pendingItineraries as $itinerary) {
            try {
                $response = $client->post("{$this->serverUrl}/api/sync/push/itinerary", [
                    'local_uuid' => $itinerary->local_uuid,
                    'date_month' => $itinerary->date_month,
                    'date_year' => $itinerary->date_year,
                    'remarks' => $itinerary->remarks,
                    'itinerary_status_id' => $itinerary->itinerary_status_id,
                ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $itinerary->update(['sync_status' => 'synced', 'server_id' => $response->json('server_id'), 'sync_error' => null]);
                    $pushed++;
                } else {
                    $this->markFailed($itinerary, $response->status().': '.$response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($itinerary, $e->getMessage());
                $failed++;
            }
        }

        $pendingSalescalls = Salescall::with('itinerary')
            ->where('sync_status', 'pending')
            ->orWhere(fn ($q) => $q->where('sync_status', 'failed')->where('sync_attempts', '<', 3))
            ->get();

        foreach ($pendingSalescalls as $salescall) {
            if (! $salescall->itinerary?->local_uuid) {
                continue;
            }

            try {
                $response = $client->post("{$this->serverUrl}/api/sync/push/salescall", [
                    'local_uuid' => $salescall->local_uuid,
                    'itinerary_uuid' => $salescall->itinerary->local_uuid,
                    'itinerary_server_id' => $salescall->itinerary->server_id,
                    'customer_id' => $salescall->customer_id,
                    'salescall_type_id' => $salescall->salescall_type_id,
                    'route_start_at' => $salescall->route_start_at?->toDateTimeString(),
                    'latitude' => $salescall->latitude,
                    'longitude' => $salescall->longitude,
                    'latitude_actual_in' => $salescall->latitude_actual_in,
                    'longitude_actual_in' => $salescall->longitude_actual_in,
                    'latitude_actual_out' => $salescall->latitude_actual_out,
                    'longitude_actual_out' => $salescall->longitude_actual_out,
                    'actual_in' => $salescall->actual_in?->toDateTimeString(),
                    'actual_out' => $salescall->actual_out?->toDateTimeString(),
                    'salescall_status_id' => $salescall->salescall_status_id,
                    'outcome_reason' => $salescall->outcome_reason,

                    'material_group_id' => $salescall->material_group_id,
                    'brand_id' => $salescall->brand_id,
                    'brand_other' => $salescall->brand_other,

                    'category_id' => $salescall->category_id,
                    'sub_category_id' => $salescall->sub_category_id,
                    'sub_sub_category_id' => $salescall->sub_sub_category_id,

                    'collection_amount' => $salescall->collection_amount,
                    'remarks' => $salescall->remarks,
                    'concerns' => $salescall->concerns,
                ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $salescall->update(['sync_status' => 'synced', 'server_id' => $response->json('server_id'), 'sync_error' => null]);
                    $pushed++;
                } else {
                    $this->markFailed($salescall, $response->status().': '.$response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($salescall, $e->getMessage());
                $failed++;
            }
        }

        $pendingBrandSalescallIds = SalescallBrand::where('sync_status', 'pending')
            ->orWhere(fn ($q) => $q->where('sync_status', 'failed')->where('sync_attempts', '<', 3))
            ->distinct()
            ->pluck('salescall_id');

        foreach ($pendingBrandSalescallIds as $salescallId) {
            $salescall = Salescall::find($salescallId);

            if (! $salescall?->server_id) {
                continue; // wait for salescall to sync first
            }

            $rows = SalescallBrand::where('salescall_id', $salescallId)->get();

            try {
                $response = $client->post("{$this->serverUrl}/api/sync/push/salescall-brands", [
                    'salescall_server_id' => $salescall->server_id,
                    'brands' => $rows->map(fn ($r) => [
                        'material_group_id' => $r->material_group_id,
                        'brand_id' => $r->brand_id,
                        'quantity' => $r->quantity,
                        'brand_other' => $r->brand_other,
                    ])->values()->all(),
                ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    SalescallBrand::where('salescall_id', $salescallId)->update(['sync_status' => 'synced', 'sync_error' => null]);
                    $pushed++;
                } else {
                    SalescallBrand::where('salescall_id', $salescallId)->update([
                        'sync_status' => 'failed',
                        'sync_attempts' => DB::raw('sync_attempts + 1'),
                        'sync_error' => $response->status().': '.$response->body(),
                    ]);
                    $failed++;
                }
            } catch (\Exception $e) {
                SalescallBrand::where('salescall_id', $salescallId)->update([
                    'sync_status' => 'failed',
                    'sync_attempts' => DB::raw('sync_attempts + 1'),
                    'sync_error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $pendingCategories = SalescallCategory::where('sync_status', 'pending')
            ->orWhere(fn ($q) => $q->where('sync_status', 'failed')->where('sync_attempts', '<', 3))
            ->get();

        foreach ($pendingCategories as $categoryRecord) {
            $salescall = Salescall::find($categoryRecord->salescall_id);

            if (! $salescall?->server_id) {
                continue; // wait for salescall to sync first
            }

            try {
                $response = $client->post("{$this->serverUrl}/api/sync/push/salescall-category", [
                    'salescall_server_id' => $salescall->server_id,
                    'category_id' => $categoryRecord->category_id,
                    'sub_category_id' => $categoryRecord->sub_category_id,
                ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $categoryRecord->update(['sync_status' => 'synced', 'sync_error' => null]);
                    $pushed++;
                } else {
                    $this->markFailed($categoryRecord, $response->status().': '.$response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($categoryRecord, $e->getMessage());
                $failed++;
            }
        }

        $pendingImages = SalescallImage::with('salescall')
            ->where(function ($q) {
                $q->where('sync_status', 'pending')
                    ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
            })
            ->get();

        foreach ($pendingImages as $image) {
            if (! $image->salescall?->server_id) {
                continue; // wait for salescall to sync first
            }

            if (! file_exists($image->local_path)) {
                $this->markFailed($image, 'Local file not found: '.$image->local_path);
                $failed++;

                continue;
            }

            try {
                $response = $client
                    ->attach('image', fopen($image->local_path, 'r'), basename($image->local_path))
                    ->post("{$this->serverUrl}/api/sync/push/salescall-image", [
                        'local_uuid' => $image->local_uuid,
                        'salescall_server_id' => $image->salescall->server_id,
                        'salescall_image_type_id' => $image->salescall_image_type_id,
                        'notes' => $image->notes,
                        'latitude' => $image->latitude,
                        'longitude' => $image->longitude,
                    ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $image->update([
                        'sync_status' => 'synced',
                        'server_id' => $response->json('server_id'),
                        'sync_error' => null,
                    ]);
                    $pushed++;
                } else {
                    $this->markFailed($image, $response->status().': '.$response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($image, $e->getMessage());
                $failed++;
            }
        }

        $pendingProfiles = CustomerProfile::with('salescall')
            ->where(function ($q) {
                $q->where('sync_status', 'pending')
                    ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
            })
            ->get();

        foreach ($pendingProfiles as $profile) {
            if (! $profile->salescall?->server_id) {
                continue; // salescall must sync first
            }

            try {
                $signature = null;
                if ($profile->signature_path && file_exists($profile->signature_path)) {
                    $signature = 'data:image/png;base64,'.base64_encode(file_get_contents($profile->signature_path));
                }

                $response = $client->post("{$this->serverUrl}/api/sync/push/customer-profile", [
                    'local_uuid' => $profile->local_uuid,
                    'salescall_server_id' => $profile->salescall->server_id,
                    'sub_category_id' => $profile->sub_category_id,
                    'registered_name' => $profile->registered_name,
                    'owner_name' => $profile->owner_name,
                    'address' => $profile->address,
                    'tin' => $profile->tin,
                    'landline' => $profile->landline,
                    'mobile' => $profile->mobile,
                    'classification' => $profile->classification,
                    'incentive_type' => $profile->incentive_type,
                    'birthday' => $profile->birthday?->format('Y-m-d'),
                    'gender' => $profile->gender,
                    'marital_status' => $profile->marital_status,
                    'brand_products' => $profile->brand_products,
                    'signature' => $signature,
                ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $profile->update([
                        'sync_status' => 'synced',
                        'server_id' => $response->json('server_id'),
                        'sync_error' => null,
                    ]);
                    $pushed++;
                } else {
                    $this->markFailed($profile, $response->status().': '.$response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($profile, $e->getMessage());
                $failed++;
            }
        }

        $pendingNotes = CustomerNote::where(function ($q) {
            $q->where('sync_status', 'pending')
                ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
        })->get();

        foreach ($pendingNotes as $note) {
            try {
                $response = $client->post("{$this->serverUrl}/api/sync/push/customer-note", [
                    'local_uuid' => $note->local_uuid,
                    'customer_id' => $note->customer_id,
                    'title' => $note->title,
                    'body' => $note->body,
                ]);

                if ($response->status() === 401) {
                    return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
                }

                if ($response->successful()) {
                    $note->update([
                        'sync_status' => 'synced',
                        'server_id' => $response->json('server_id'),
                        'sync_error' => null,
                        'synced_at' => now(),
                    ]);
                    $pushed++;
                } else {
                    $this->markFailed($note, $response->status().': '.$response->body());
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->markFailed($note, $e->getMessage());
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

    /**
     * Best-effort immediate push of a Quick Note deletion — fired synchronously
     * when the user deletes a note (not queued through the regular pending-sync
     * loop, since there's no local row left afterward to track sync_status on).
     * Silently no-ops if offline; the note is already gone locally either way.
     */
    public function pushCustomerNoteDelete(string $localUuid): void
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return;
        }

        try {
            $this->client($user->api_token)
                ->post("{$this->serverUrl}/api/sync/push/customer-note/delete", ['local_uuid' => $localUuid]);
        } catch (\Exception) {
            // best-effort — nothing local left to mark as failed
        }
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout($this->timeout);
    }

    private function markFailed(Model $model, string $error): void
    {
        $model->update([
            'sync_status' => 'failed',
            'sync_attempts' => ($model->sync_attempts ?? 0) + 1,
            'sync_error' => $error,
        ]);
    }
}
