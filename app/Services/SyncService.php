<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerBrand;
use App\Models\CustomerCategory;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use App\Models\CustomerProfileAttachment;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallCategory;
use App\Models\SalescallImage;
use App\Models\User;
use App\Support\ExpensePaymentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Throwable;

class SyncService
{
    private string $serverUrl;

    private int $timeout;

    private int $pullTimeout;

    /** Local customer edits in these states must not be overwritten by a pull. */
    private const PROTECTED_CUSTOMER_STATUSES = ['pending', 'failed', 'conflict'];

    private const CUSTOMER_PULL_PAGE_SIZE = 500;

    private const CUSTOMER_PULL_IDS_PER_REQUEST = 200;

    /** Per-user sync_states key prefixes; a shared tablet keeps one run and watermark per rep. */
    private const CUSTOMER_PULL_RUN = 'customers.pull_run.';

    private const CUSTOMER_PULL_WATERMARK = 'customers.pulled_through.';

    public function __construct(
        private readonly TabletS3UploadService $tabletS3UploadService,
    ) {
        $this->serverUrl = rtrim(config('sync.server_url', ''), '/');
        $this->timeout = (int) config('sync.timeout', 15);
        $this->pullTimeout = (int) config('sync.pull_timeout', 60);
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
            || $pendingOrRetryable(CustomerProfileAttachment::query())->exists()
            || $pendingOrRetryable(Expense::query())->exists()
            || $pendingOrRetryable(ExpenseAttachment::query())->exists()
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

    /** @return array{token: string, code: string}|null */
    public function reserveCustomerCode(int $companyId): ?array
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token) || blank($this->serverUrl)) {
            return null;
        }

        try {
            $response = $this->client($user->api_token)->post(
                "{$this->serverUrl}/api/sync/reserve-customer-code",
                ['company_id' => $companyId],
            );

            if (! $response->successful()) {
                return null;
            }

            $token = $response->json('token');
            $code = $response->json('code');

            return is_string($token) && $token !== '' && is_string($code) && $code !== ''
                ? ['token' => $token, 'code' => $code]
                : null;
        } catch (Throwable) {
            return null;
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
                    'region_type_id' => $data['region_type_id'] ?? null,
                    'base_start_latitude' => $data['base_start_latitude'] ?? null,
                    'base_start_longitude' => $data['base_start_longitude'] ?? null,
                    'base_end_latitude' => $data['base_end_latitude'] ?? null,
                    'base_end_longitude' => $data['base_end_longitude'] ?? null,
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

    /**
     * Salescall pull: the approved schedule, the customers on it and the small
     * lookup tables. Location references are fetched once when the tablet has
     * none; the full customer list comes from pullCustomersStep().
     */
    public function pull(): SyncResult
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $data = $this->fetchPullPayload($user, '/api/sync/pull/schedule');

        if ($data instanceof SyncResult) {
            return $data;
        }

        try {
            DB::transaction(fn () => $this->applyPullPayload($data, $user));
        } catch (\Exception $e) {
            return SyncResult::fail('Pull error: '.$e->getMessage(), 'exception');
        }

        if (DB::table('municipalities')->doesntExist()) {
            $locations = $this->pullLocations();

            if (! $locations->success) {
                return $locations;
            }
        }

        $itineraryCount = count($data['itineraries'] ?? []);
        $salescallCount = array_sum(
            array_map(fn ($i) => count($i['salescalls'] ?? []), $data['itineraries'] ?? [])
        );

        return SyncResult::ok("Pulled {$itineraryCount} itineraries, {$salescallCount} salescalls.");
    }

    /**
     * Location reference tables (regions down to barangays).
     */
    public function pullLocations(): SyncResult
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $data = $this->fetchPullPayload($user, '/api/sync/pull/locations');

        if ($data instanceof SyncResult) {
            return $data;
        }

        try {
            DB::transaction(fn () => $this->applyPullPayload($data, $user));
        } catch (\Exception $e) {
            return SyncResult::fail('Pull error: '.$e->getMessage(), 'exception');
        }

        return SyncResult::ok('Pulled location references.');
    }

    /**
     * Whether the Customers list still needs a pull: none has ever completed,
     * or one was interrupted and can resume.
     */
    public function customerPullPending(): bool
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user) {
            return false;
        }

        return $this->syncState(self::CUSTOMER_PULL_WATERMARK.$user->id) === null
            || $this->syncState(self::CUSTOMER_PULL_RUN.$user->id) !== null;
    }

    /**
     * Runs one step of the customer pull and returns its progress. The caller
     * repeats until `done` (or a failure), so each request does at most one
     * portal round trip and stays inside the device's execution time limit.
     *
     * A run with no watermark pulls every customer in scope (plus location
     * references); later runs ask only for customers changed since the last
     * completed run. The last page reconciles scope: customers that left it are
     * deactivated locally and customers that came back into it are re-fetched.
     * Progress is checkpointed after every step, so an interrupted run resumes.
     *
     * @return array{success: bool, done: bool, pulled: int, total: ?int, message: string}
     */
    public function pullCustomersStep(): array
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return $this->customerPullProgress([], false, false, 'No API token found. Please log in first.');
        }

        $runKey = self::CUSTOMER_PULL_RUN.$user->id;
        $run = $this->syncState($runKey) ?? $this->startCustomerPullRun($user);

        // A failed step checkpoints the run as it was before the step, so the
        // retry repeats the whole step rather than resuming half-applied state.
        $runBeforeStep = $run;

        try {
            if ($run['phase'] === 'locations') {
                $result = $this->pullLocations();

                if (! $result->success) {
                    $this->putSyncState($runKey, $run);

                    return $this->customerPullProgress($run, false, false, $result->message);
                }

                $run['phase'] = 'pages';
                $this->putSyncState($runKey, $run);

                return $this->customerPullProgress($run, true, false, 'Location references updated.');
            }

            $query = $run['phase'] === 'missing'
                ? ['ids' => implode(',', array_slice($run['missing'], 0, self::CUSTOMER_PULL_IDS_PER_REQUEST))]
                : array_filter([
                    'after_id' => $run['after_id'],
                    'limit' => self::CUSTOMER_PULL_PAGE_SIZE,
                    'updated_since' => $run['since'],
                ], fn ($value) => $value !== null);

            $data = $this->fetchPullPayload($user, '/api/sync/pull/customers', $query);

            if ($data instanceof SyncResult) {
                $this->putSyncState($runKey, $run);

                return $this->customerPullProgress($run, false, false, $data->message);
            }

            DB::transaction(fn () => $this->applyPullPayload($data, $user));

            $run['pulled'] += count($data['customers'] ?? []);

            if ($run['phase'] === 'missing') {
                $this->addToCustomerScope($user, array_column($data['customers'] ?? [], 'id'));
                $run['missing'] = array_values(array_slice($run['missing'], self::CUSTOMER_PULL_IDS_PER_REQUEST));
            } else {
                if ($run['after_id'] === 0) {
                    $run['total'] = $data['total'] ?? null;
                    $run['server_time'] = $data['server_time'] ?? null;
                }

                $run['after_id'] = $data['next_after_id'] ?? null;

                if ($run['after_id'] === null) {
                    $run['missing'] = $this->recordCustomerScope($user, $data['scope_ids'] ?? null);
                    $run['phase'] = 'missing';
                }
            }

            if ($run['phase'] === 'missing' && $run['missing'] === []) {
                $this->putSyncState(self::CUSTOMER_PULL_WATERMARK.$user->id, $run['server_time']);
                $this->forgetSyncState($runKey);

                $message = $run['since'] === null
                    ? "Pulled {$run['pulled']} customers."
                    : "Customers up to date ({$run['pulled']} updated).";

                return $this->customerPullProgress($run, true, true, $message);
            }

            $this->putSyncState($runKey, $run);

            return $this->customerPullProgress($run, true, false, "Pulled {$run['pulled']} customers…");
        } catch (Throwable $e) {
            report($e);
            $this->putSyncState($runKey, $runBeforeStep);

            return $this->customerPullProgress($run, false, false, 'Customer pull error: '.$e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function startCustomerPullRun(User $user): array
    {
        $since = $this->syncState(self::CUSTOMER_PULL_WATERMARK.$user->id);

        return [
            'phase' => $since === null || DB::table('municipalities')->doesntExist() ? 'locations' : 'pages',
            'since' => $since,
            'after_id' => 0,
            'server_time' => null,
            'total' => null,
            'pulled' => 0,
            'missing' => [],
        ];
    }

    /**
     * Replaces the user's customer_scopes rows with the portal's full list of
     * customer ids this user may see, and returns the in-scope ids the tablet
     * lacks (no local row, or one marked inactive) so they can be fetched by
     * id. Nothing is deactivated or deleted: other reps sharing the tablet may
     * still need those customers, and expenses cascade from customers.
     *
     * @param  list<int>|null  $scopeIds
     * @return list<int>
     */
    private function recordCustomerScope(User $user, ?array $scopeIds): array
    {
        if ($scopeIds === null) {
            return [];
        }

        $localIds = [];
        $present = [];

        foreach (array_chunk($scopeIds, 500) as $chunk) {
            $rows = DB::table('customers')->whereIn('server_id', $chunk)->get(['id', 'server_id', 'is_active', 'sync_status']);

            foreach ($rows as $row) {
                $localIds[$row->server_id] = $row->id;

                if ($row->is_active || in_array($row->sync_status, self::PROTECTED_CUSTOMER_STATUSES, true)) {
                    $present[$row->server_id] = true;
                }
            }
        }

        DB::transaction(function () use ($user, $localIds): void {
            DB::table('customer_scopes')->where('user_id', $user->id)->delete();

            foreach (array_chunk(array_values(array_unique($localIds)), 400) as $chunk) {
                DB::table('customer_scopes')->insert(array_map(
                    fn (int $customerId): array => ['user_id' => $user->id, 'customer_id' => $customerId],
                    $chunk,
                ));
            }
        });

        return array_values(array_filter($scopeIds, fn (int $id): bool => ! isset($present[$id])));
    }

    /**
     * Adds customers fetched by id after the scope was recorded.
     *
     * @param  list<int>  $serverIds
     */
    private function addToCustomerScope(User $user, array $serverIds): void
    {
        if ($serverIds === []) {
            return;
        }

        $rows = DB::table('customers')->whereIn('server_id', $serverIds)->pluck('id')
            ->map(fn (int $customerId): array => ['user_id' => $user->id, 'customer_id' => $customerId])
            ->all();

        DB::table('customer_scopes')->insertOrIgnore($rows);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array{success: bool, done: bool, pulled: int, total: ?int, message: string}
     */
    private function customerPullProgress(array $run, bool $success, bool $done, string $message): array
    {
        return [
            'success' => $success,
            'done' => $done,
            'pulled' => (int) ($run['pulled'] ?? 0),
            'total' => isset($run['total']) ? (int) $run['total'] : null,
            'message' => $message,
        ];
    }

    private function syncState(string $key): mixed
    {
        $value = DB::table('sync_states')->where('key', $key)->value('value');

        return $value === null ? null : json_decode($value, true);
    }

    private function putSyncState(string $key, mixed $value): void
    {
        DB::table('sync_states')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function forgetSyncState(string $key): void
    {
        DB::table('sync_states')->where('key', $key)->delete();
    }

    /**
     * GETs a pull endpoint, streaming the body to a file, and decodes it.
     *
     * The response is streamed to a file instead of letting Guzzle buffer it in
     * php://temp: the embedded runtime has no usable PHP temporary directory,
     * and a payload past php://temp's 2 MB memory threshold spills to disk and
     * fails with "Unable to create temporary file".
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|SyncResult
     */
    private function fetchPullPayload(User $user, string $path, array $query = []): array|SyncResult
    {
        $sinkPath = $this->pullSinkPath();

        try {
            $response = $this->client($user->api_token)
                ->timeout($this->pullTimeout)
                ->sink($sinkPath)
                ->get("{$this->serverUrl}{$path}", $query);

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
            }

            if ($response->failed()) {
                return SyncResult::fail("Pull failed ({$response->status()}).", 'server_error');
            }

            return $this->decodePullResponse($sinkPath);
        } catch (\Exception $e) {
            return SyncResult::fail('Pull error: '.$e->getMessage(), 'exception');
        } finally {
            @unlink($sinkPath);
        }
    }

    /**
     * Writes whichever pull sections the payload carries. Reference/lookup
     * tables are written before anything that holds a foreign key into them.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyPullPayload(array $data, User $user): void
    {
        // Refresh the logged-in rep's itinerary base location from the pulled
        // users list. Portal user ids differ from tablet ids, so match on the
        // unique email instead of the numeric id.
        $portalUser = collect($data['users'] ?? [])->firstWhere('email', $user->email);

        // A base location the rep edited on the tablet and has not pushed yet
        // wins over the portal value; overwriting it here would discard the
        // local edit before the push loop could deliver it.
        if ($portalUser && ! $user->base_location_pending) {
            $user->update([
                'base_start_latitude' => $portalUser['base_start_latitude'] ?? null,
                'base_start_longitude' => $portalUser['base_start_longitude'] ?? null,
                'base_end_latitude' => $portalUser['base_end_latitude'] ?? null,
                'base_end_longitude' => $portalUser['base_end_longitude'] ?? null,
            ]);
        }

        // The region type is portal-authoritative and drives the photo
        // requirement; it is not locally editable, so apply it even when the
        // rep has an unsynced base-location edit.
        if ($portalUser && array_key_exists('region_type_id', $portalUser)) {
            $user->update(['region_type_id' => $portalUser['region_type_id']]);
        }

        // Reference/lookup tables must be populated before anything below that
        // holds a foreign key into them (salescall_brands -> material_groups/brands,
        // salescall_categories/customer_categories -> categories/sub_categories,
        // salescall_brands/salescall_categories -> customers). On a fresh install
        // with these tables still empty, a first pull whose itineraries already
        // carry salescall_brands/salescall_categories data (e.g. an RSM-added call)
        // would otherwise throw a foreign key integrity violation and abort the
        // entire pull before customers/brands/categories ever get written.
        $this->upsertRows('general_categories', array_map(fn (array $category): array => [
            'id' => $category['id'],
            'name' => $category['name'],
            'priority_visit' => $category['priority_visit'] ?? null,
            'duration_per_visit' => $category['duration_per_visit'] ?? null,
            'sort' => $category['sort'] ?? 0,
        ], $data['general_categories'] ?? []));

        $this->upsertRows('companies', array_map(fn (array $company): array => [
            'id' => $company['id'],
            'name' => $company['name'],
            'code' => $company['code'] ?? null,
        ], $data['companies'] ?? []));

        $this->upsertRows('regions', array_map(fn (array $region): array => [
            'id' => $region['id'],
            'code' => $region['code'],
            'psgc_code' => $region['psgc_code'] ?? null,
            'name' => $region['name'],
        ], $data['regions'] ?? []));

        $this->upsertRows('region_specifics', array_map(fn (array $regionSpecific): array => [
            'id' => $regionSpecific['id'],
            'region_id' => $regionSpecific['region_id'],
            'name' => $regionSpecific['name'],
            'sort' => $regionSpecific['sort'] ?? 0,
        ], $data['region_specifics'] ?? []));

        $this->upsertRows('region_types', array_map(fn (array $regionType): array => [
            'id' => $regionType['id'],
            'name' => $regionType['name'],
            'sort' => $regionType['sort'] ?? 0,
            'is_image_required' => $regionType['is_image_required'] ?? false,
        ], $data['region_types'] ?? []));

        $this->upsertRows('provinces', array_map(fn (array $province): array => [
            'id' => $province['id'],
            'region_id' => $province['region_id'] ?? null,
            'psgc_code' => $province['psgc_code'] ?? null,
            'region_specific_id' => $province['region_specific_id'] ?? null,
            'name' => $province['name'],
            'enabled' => $province['enabled'] ?? true,
        ], $data['provinces'] ?? []));

        $this->upsertRows('municipalities', $data['municipalities'] ?? [], fn (array $municipality): array => [
            'id' => $municipality['id'],
            'psgc_code' => $municipality['psgc_code'] ?? null,
            'region_id' => $municipality['region_id'] ?? null,
            'province_id' => $municipality['province_id'] ?? null,
            'locality_type' => $municipality['locality_type'] ?? null,
            'name' => $municipality['name'],
            'sort' => $municipality['sort'] ?? 0,
            'enabled' => $municipality['enabled'] ?? true,
        ]);

        $this->upsertRows('barangays', $data['barangays'] ?? [], fn (array $barangay): array => [
            'id' => $barangay['id'],
            'municipality_id' => $barangay['municipality_id'],
            'psgc_code' => $barangay['psgc_code'] ?? null,
            'code' => $barangay['code'] ?? null,
            'name' => $barangay['name'],
            'enabled' => $barangay['enabled'] ?? true,
        ]);

        $this->upsertRows('area_clusters', array_map(fn (array $areaCluster): array => [
            'id' => $areaCluster['id'],
            'region_specific_id' => $areaCluster['region_specific_id'],
            'code' => $areaCluster['code'] ?? null,
            'name' => $areaCluster['name'],
            'enabled' => $areaCluster['enabled'] ?? true,
        ], $data['area_clusters'] ?? []));

        $protectedStatuses = self::PROTECTED_CUSTOMER_STATUSES;
        $serverToLocalCustomer = [];
        $protectedCustomerIds = DB::table('customers')->whereIn('sync_status', $protectedStatuses)->pluck('id', 'server_id')->filter()->all();

        // Resolve every incoming customer's local row with two queries per
        // chunk rather than one SELECT per customer: match on server_id first,
        // then a same-id local row that is not holding unsynced edits.
        $incomingCustomerIds = array_column($data['customers'] ?? [], 'id');
        $localIdByServerId = [];
        $localIdBySameId = [];

        foreach (array_chunk($incomingCustomerIds, 500) as $chunk) {
            $localIdByServerId += DB::table('customers')->whereIn('server_id', $chunk)->orderBy('id')->pluck('id', 'server_id')->all();
            $localIdBySameId += DB::table('customers')->whereIn('id', $chunk)->whereNotIn('sync_status', $protectedStatuses)->pluck('id', 'id')->all();
        }

        foreach ($data['customers'] ?? [] as $customer) {
            $localId = $localIdByServerId[$customer['id']] ?? $localIdBySameId[$customer['id']] ?? $customer['id'];
            $serverToLocalCustomer[$customer['id']] = $localId;
            if (isset($protectedCustomerIds[$customer['id']])) {
                continue;
            }

            DB::table('customers')->updateOrInsert(
                ['id' => $localId],
                [
                    'server_id' => $customer['id'],
                    'company_id' => $customer['company_id'] ?? null,
                    'general_category_id' => $customer['general_category_id'] ?? null,
                    'region_specific_id' => $customer['region_specific_id'] ?? null,
                    'municipality_id' => $customer['municipality_id'] ?? null,
                    'province_id' => $customer['province_id'] ?? null,
                    'barangay_id' => $customer['barangay_id'] ?? null,
                    'area_cluster_id' => $customer['area_cluster_id'] ?? null,
                    'name' => $customer['name'],
                    'unique_id' => $customer['unique_id'] ?? null,
                    'contact_person' => $customer['contact_person'] ?? null,
                    'contact_number' => $customer['contact_number'] ?? null,
                    'business_landline_number' => $customer['business_landline_number'] ?? null,
                    'business_mobile_number' => $customer['business_mobile_number'] ?? null,
                    'date_established' => $customer['date_established'] ?? null,
                    'person_in_charge_id' => $customer['person_in_charge_id'] ?? null,
                    'address' => $customer['address'] ?? null,
                    'latitude' => $customer['latitude'] ?? null,
                    'longitude' => $customer['longitude'] ?? null,
                    'is_active' => $customer['is_active'] ?? true,
                    'competitor_volume' => $customer['competitor_volume'] ?? null,
                    'sync_status' => 'synced',
                    'sync_error' => null,
                    'synced_at' => now(),
                    'server_updated_at' => $customer['updated_at'] ?? null,
                    'updated_at' => now(),
                ]
            );
        }

        foreach ($data['customer_trade_profiles'] ?? [] as $profile) {
            $localCustomerId = $serverToLocalCustomer[$profile['customer_id']] ?? $profile['customer_id'];
            if (isset($protectedCustomerIds[$profile['customer_id']])) {
                continue;
            }
            DB::table('customer_trade_profiles')->updateOrInsert(
                ['customer_id' => $localCustomerId],
                [
                    'profile_type' => $profile['profile_type'] ?? null,
                    'profile_data' => isset($profile['profile_data']) ? json_encode($profile['profile_data']) : null,
                    'house_number' => $profile['house_number'] ?? null,
                    'entry_detail' => $profile['entry_detail'] ?? null,
                    'classifications' => isset($profile['classifications']) ? json_encode($profile['classifications']) : null,
                    'ommc_brands' => isset($profile['ommc_brands']) ? json_encode($profile['ommc_brands']) : null,
                    'ommc_mcb_brands' => isset($profile['ommc_mcb_brands']) ? json_encode($profile['ommc_mcb_brands']) : null,
                    'tpl_pollux' => isset($profile['tpl_pollux']) ? json_encode($profile['tpl_pollux']) : null,
                    'other_competitor_brands' => isset($profile['other_competitor_brands']) ? json_encode($profile['other_competitor_brands']) : null,
                    'mcb_competitors' => isset($profile['mcb_competitors']) ? json_encode($profile['mcb_competitors']) : null,
                    'other_competitors_note' => $profile['other_competitors_note'] ?? null,
                    'working_days' => isset($profile['working_days']) ? json_encode($profile['working_days']) : null,
                    'operating_hours' => isset($profile['operating_hours']) ? json_encode($profile['operating_hours']) : null,
                    'motiv_user' => $profile['motiv_user'] ?? null,
                    'delivery_method' => $profile['delivery_method'] ?? null,
                    'ulab' => $profile['ulab'] ?? null,
                    'updated_at' => now(),
                ]
            );
        }

        foreach ($data['customer_category_histories'] ?? [] as $history) {
            $localCustomerId = $serverToLocalCustomer[$history['customer_id']] ?? $history['customer_id'];
            if (isset($protectedCustomerIds[$history['customer_id']])) {
                continue;
            }
            DB::table('customer_category_histories')->updateOrInsert(
                ['customer_id' => $localCustomerId, 'profile_type' => $history['profile_type'] ?? null, 'stream' => $history['stream'] ?? null, 'category_year' => $history['category_year']],
                ['category' => $history['category'], 'updated_at' => now()]
            );
        }

        foreach ($data['customer_category_events'] ?? [] as $event) {
            $localCustomerId = $serverToLocalCustomer[$event['customer_id']] ?? $event['customer_id'];
            if (isset($protectedCustomerIds[$event['customer_id']])) {
                continue;
            }
            $supersedesId = filled($event['supersedes_event_key'] ?? null)
                ? DB::table('customer_category_events')->where('event_key', $event['supersedes_event_key'])->value('id')
                : null;
            DB::table('customer_category_events')->updateOrInsert(
                ['event_key' => $event['event_key']],
                [
                    'customer_id' => $localCustomerId,
                    'profile_type' => $event['profile_type'],
                    'stream' => $event['stream'],
                    'category' => $event['category'],
                    'effective_at' => $event['effective_at'],
                    'source' => $event['source'],
                    'supersedes_event_id' => $supersedesId,
                    'supersedes_event_key' => $event['supersedes_event_key'] ?? null,
                    'updated_at' => now(),
                ]
            );
        }

        $this->upsertRows('salescall_statuses', array_map(fn (array $status): array => [
            'id' => $status['id'],
            'name' => $status['name'],
        ], $data['salescall_statuses'] ?? []));

        $this->upsertRows('salescall_types', array_map(fn (array $type): array => [
            'id' => $type['id'],
            'name' => $type['name'],
        ], $data['salescall_types'] ?? []));

        $this->upsertRows('material_groups', array_map(fn (array $group): array => [
            'id' => $group['id'],
            'name' => $group['name'],
        ], $data['material_groups'] ?? []));

        $this->upsertRows('brands', array_map(fn (array $brand): array => [
            'id' => $brand['id'],
            'material_group_id' => $brand['material_group_id'],
            'name' => $brand['name'],
            'enabled' => $brand['enabled'],
        ], $data['brands'] ?? []));

        $this->upsertRows('categories', array_map(fn (array $item): array => [
            'id' => $item['id'],
            'name' => $item['name'],
            'general_category_id' => $item['general_category_id'] ?? null,
        ], $data['categories'] ?? []));

        $this->upsertRows('sub_categories', array_map(fn (array $item): array => [
            'id' => $item['id'],
            'category_id' => $item['category_id'],
            'name' => $item['name'],
            'with_form' => $item['with_form'] ?? false,
            'is_active' => $item['is_active'] ?? true,
        ], $data['sub_categories'] ?? []));

        $this->upsertRows('sub_sub_categories', array_map(fn (array $item): array => [
            'id' => $item['id'],
            'sub_category_id' => $item['sub_category_id'],
            'name' => $item['name'],
        ], $data['sub_sub_categories'] ?? []));

        $this->upsertRows('salescall_image_categories', array_map(fn (array $item): array => [
            'id' => $item['id'],
            'name' => $item['name'],
            'slug' => $item['slug'],
            'sort' => $item['sort'] ?? 0,
        ], $data['salescall_image_categories'] ?? []));

        $this->upsertRows('salescall_image_types', array_map(fn (array $item): array => [
            'id' => $item['id'],
            'salescall_image_category_id' => $item['salescall_image_category_id'],
            'name' => $item['name'],
            'slug' => $item['slug'],
            'sort' => $item['sort'] ?? 0,
        ], $data['salescall_image_types'] ?? []));

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
                            'ref_number' => $sc['ref_number'] ?? $sc['id'],
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
                            'partially_completed_at' => $sc['partially_completed_at'] ?? null,
                            'partially_completed_reason' => $sc['partially_completed_reason'] ?? null,
                            'partially_completed_by' => $sc['partially_completed_by'] ?? null,
                            'resumed_at' => $sc['resumed_at'] ?? null,
                            'resumed_by' => $sc['resumed_by'] ?? null,
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
                        } catch (Throwable $e) {
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
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }
        }

        $incomingCustomerBrands = collect($data['customer_brands'] ?? [])->groupBy('customer_id');

        $customersWithPendingBrands = $incomingCustomerBrands->isEmpty()
            ? []
            : SalescallBrand::where('sync_status', 'pending')->distinct()->pluck('customer_id')->flip()->all();

        foreach ($incomingCustomerBrands as $customerId => $rows) {
            if (isset($customersWithPendingBrands[$customerId])) {
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
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $customersWithPendingCategories = empty($data['customer_categories'])
            ? []
            : SalescallCategory::where('sync_status', 'pending')->distinct()->pluck('customer_id')->flip()->all();

        foreach ($data['customer_categories'] ?? [] as $categoryRow) {
            if (isset($customersWithPendingCategories[$categoryRow['customer_id']])) {
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
            } catch (Throwable $e) {
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
    }

    /** @return array<int, array<string, mixed>> */
    public function readExpensesForSalescall(int $serverSalescallId): array
    {
        return $this->readExpenseRows("/api/sync/salescalls/{$serverSalescallId}/expenses");
    }

    /** @return array<int, array<string, mixed>> */
    public function readExpenses(): array
    {
        return $this->readExpensesWithStatus()['expenses'];
    }

    /** @return array{expenses: array<int, array<string, mixed>>, failed: bool} */
    public function readExpensesWithStatus(): array
    {
        return $this->requestExpenseRows('/api/sync/expenses');
    }

    /** @return array<int, array<string, mixed>> */
    private function readExpenseRows(string $path): array
    {
        return $this->requestExpenseRows($path)['expenses'];
    }

    /** @return array{expenses: array<int, array<string, mixed>>, failed: bool} */
    private function requestExpenseRows(string $path): array
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token) || blank($this->serverUrl)) {
            return ['expenses' => [], 'failed' => true];
        }

        try {
            $response = $this->client($user->api_token)
                ->get("{$this->serverUrl}{$path}");

            if (! $response->successful()) {
                Log::warning('Expense read failed.', ['path' => $path, 'status' => $response->status()]);

                return ['expenses' => [], 'failed' => true];
            }

            $expenses = $response->json('expenses');

            if (! is_array($expenses)) {
                return ['expenses' => [], 'failed' => true];
            }

            return ['expenses' => array_values(array_filter($expenses, 'is_array')), 'failed' => false];
        } catch (Throwable $exception) {
            Log::warning('Expense read request failed.', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return ['expenses' => [], 'failed' => true];
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
        $retryable = 0;
        $failureReasons = [];

        try {
            $pendingCustomers = $this->pendingCustomerPushQuery()->get();

            $customerResult = $this->pushCustomerRecords($client, $pendingCustomers);
            $pushed += $customerResult->syncedCount;
            $failed += $customerResult->failedCount;
            $retryable += $customerResult->retryableCount;
            $failureReasons = array_fill_keys($customerResult->failureReasons, true);

            if ($customerResult->errorCode === 'token_expired') {
                return SyncResult::fail($customerResult->message, 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
            }

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
                        return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    if ($response->successful()) {
                        $this->markSynced($itinerary, [
                            'server_id' => $response->json('server_id'),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($itinerary, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'itinerary:portal',
                            'endpoint' => '/api/sync/push/itinerary',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($itinerary, $e, 'itinerary:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
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

                $customer = Customer::find($salescall->customer_id);
                if (! $customer?->server_id) {
                    continue;
                }

                try {
                    $response = $client->post("{$this->serverUrl}/api/sync/push/salescall", [
                        'local_uuid' => $salescall->local_uuid,
                        'itinerary_uuid' => $salescall->itinerary->local_uuid,
                        'itinerary_server_id' => $salescall->itinerary->server_id,
                        'customer_id' => $customer->server_id,
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
                        'partially_completed_at' => $salescall->partially_completed_at?->toDateTimeString(),
                        'partially_completed_reason' => $salescall->partially_completed_reason,
                        'partially_completed_by' => $salescall->partially_completed_by,
                        'resumed_at' => $salescall->resumed_at?->toDateTimeString(),
                        'resumed_by' => $salescall->resumed_by,
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
                        return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    if ($response->successful()) {
                        $this->markSynced($salescall, [
                            'server_id' => $response->json('server_id'),
                            'ref_number' => $response->json('ref_number'),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($salescall, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'salescall:portal',
                            'endpoint' => '/api/sync/push/salescall',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($salescall, $e, 'salescall:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingExpenses = Expense::with(['salescall', 'expenseType'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingExpenses as $expense) {
                if (! $expense->salescall?->server_id) {
                    continue; // wait for salescall to sync first
                }

                try {
                    $response = $client->post("{$this->serverUrl}/api/sync/push/expense", [
                        'local_uuid' => $expense->local_uuid,
                        'salescall_server_id' => $expense->salescall->server_id,
                        'expense_type_code' => $expense->expenseType?->code,
                        'amount' => $expense->amount,
                        'date_filed' => $expense->date_filed?->format('Y-m-d'),
                        'payment_type' => ExpensePaymentType::forSync($expense->payment_type),
                        'payment_remarks' => $expense->payment_remarks,
                        'invoice_number' => $expense->invoice_number,
                        'with_invoice' => $expense->with_invoice,
                        'establishment' => $expense->establishment,
                        'location' => $expense->location,
                        'purpose' => $expense->purpose,
                        'tin' => $expense->tin,
                        'latitude' => $expense->latitude,
                        'longitude' => $expense->longitude,
                        'form_data' => $expense->form_data,
                        'form_schema_version' => $expense->form_schema_version,
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($expense, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($expense, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'expense:portal',
                            'endpoint' => '/api/sync/push/expense',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($expense, $e, 'expense:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingExpenseAttachments = ExpenseAttachment::with('expense')
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingExpenseAttachments as $attachment) {
                if (! $attachment->expense?->server_id) {
                    continue; // wait for expense to sync first
                }

                $result = $this->pushExpenseAttachmentItem($client, $attachment);

                if ($result instanceof SyncResult) {
                    return SyncResult::fail($result->message, $result->errorCode, $pushed + $result->syncedCount, $failed + $result->failedCount, $retryable + $result->retryableCount, array_values(array_unique([...array_keys($failureReasons), ...$result->failureReasons])));
                }

                if ($result['success']) {
                    $pushed++;
                } else {
                    $failed++;
                    $retryable++;
                    $failureReasons[$result['reason']] = true;
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
                        return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    if ($response->successful()) {
                        $rows->each(fn (SalescallBrand $row) => $this->markSynced($row));
                        $pushed++;
                    } else {
                        $this->recordCollectionFailure(
                            $rows,
                            'portal_rejected',
                            $response->status().': '.$this->trimRemoteError($response->body()),
                            [
                                'stage' => 'salescall-brands:portal',
                                'endpoint' => '/api/sync/push/salescall-brands',
                                'http_status' => $response->status(),
                                'salescall_id' => $salescallId,
                            ]
                        );
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordCollectionUnexpectedFailure($rows, $e, 'salescall-brands:unexpected', [
                        'salescall_id' => $salescallId,
                    ]);
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
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
                        return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    if ($response->successful()) {
                        $this->markSynced($categoryRecord);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($categoryRecord, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'salescall-category:portal',
                            'endpoint' => '/api/sync/push/salescall-category',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($categoryRecord, $e, 'salescall-category:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
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

                $result = $this->pushSalescallImageItem($client, $image);

                if ($result instanceof SyncResult) {
                    return SyncResult::fail($result->message, $result->errorCode, $pushed + $result->syncedCount, $failed + $result->failedCount, $retryable + $result->retryableCount, array_values(array_unique([...array_keys($failureReasons), ...$result->failureReasons])));
                }

                if ($result['success']) {
                    $pushed++;
                } else {
                    $failed++;
                    $retryable++;
                    $failureReasons[$result['reason']] = true;
                }
            }

            $pendingAttachments = CustomerProfileAttachment::with('salescall')
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingAttachments as $attachment) {
                if (! $attachment->salescall?->server_id) {
                    continue; // wait for salescall to sync first
                }

                $result = $this->pushProfileAttachmentItem($client, $attachment);

                if ($result instanceof SyncResult) {
                    return SyncResult::fail($result->message, $result->errorCode, $pushed + $result->syncedCount, $failed + $result->failedCount, $retryable + $result->retryableCount, array_values(array_unique([...array_keys($failureReasons), ...$result->failureReasons])));
                }

                if ($result['success']) {
                    $pushed++;
                } else {
                    $failed++;
                    $retryable++;
                    $failureReasons[$result['reason']] = true;
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

                $result = $this->pushCustomerProfileItem($client, $profile);

                if ($result instanceof SyncResult) {
                    return SyncResult::fail($result->message, $result->errorCode, $pushed + $result->syncedCount, $failed + $result->failedCount, $retryable + $result->retryableCount, array_values(array_unique([...array_keys($failureReasons), ...$result->failureReasons])));
                }

                if ($result['success']) {
                    $pushed++;
                } else {
                    $failed++;
                    $retryable++;
                    $failureReasons[$result['reason']] = true;
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
                        return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    if ($response->successful()) {
                        $this->markSynced($note, [
                            'server_id' => $response->json('server_id'),
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($note, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'customer-note:portal',
                            'endpoint' => '/api/sync/push/customer-note',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($note, $e, 'customer-note:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $user->refresh();

            if ($user->base_location_pending) {
                try {
                    $response = $client->post("{$this->serverUrl}/api/sync/push/base-location", [
                        'base_start_latitude' => $user->base_start_latitude,
                        'base_start_longitude' => $user->base_start_longitude,
                        'base_end_latitude' => $user->base_end_latitude,
                        'base_end_longitude' => $user->base_end_longitude,
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    if ($response->successful()) {
                        $user->update(['base_location_pending' => false]);
                        $pushed++;
                    } else {
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    Log::error('sync:push:base-location', [
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ]);
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }
        } catch (Throwable $e) {
            Log::error('sync:push:unhandled', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return SyncResult::fail(
                'Sync could not be completed. Your pending data remains saved locally and can be retried.',
                'unexpected_sync_error',
                $pushed,
                max($failed, 1),
                max($retryable, 1),
                array_values(array_unique([...array_keys($failureReasons), 'unexpected_sync_error']))
            );
        }

        return $this->buildPushResult($pushed, $failed, $retryable, array_keys($failureReasons));
    }

    public function pushCustomer(int $customerId): SyncResult
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $customer = $this->pendingCustomerPushQuery()->whereKey($customerId)->first();

        if (! $customer) {
            return SyncResult::ok('Customer has no pending changes.');
        }

        return $this->pushCustomerRecords($this->client($user->api_token), collect([$customer]));
    }

    public function pushPendingCustomers(): SyncResult
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $customers = $this->pendingCustomerPushQuery()->get();

        return $this->pushCustomerRecords($this->client($user->api_token), $customers);
    }

    public function hasPendingCustomerPushWork(): bool
    {
        return $this->pendingCustomerPushQuery()->exists();
    }

    private function pendingCustomerPushQuery(): Builder
    {
        return Customer::with(['tradeProfile', 'categoryHistories', 'categoryEvents'])
            ->where(function (Builder $query): void {
                $query->where('sync_status', 'pending')
                    ->orWhere(fn ($retry) => $retry->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
            });
    }

    /**
     * @param  Collection<int, Customer>  $customers
     */
    private function pushCustomerRecords(PendingRequest $client, Collection $customers): SyncResult
    {
        $pushed = 0;
        $failed = 0;
        $retryable = 0;
        $failureReasons = [];

        foreach ($customers as $customer) {
            $result = $this->pushCustomerRecord($client, $customer);

            if ($result->errorCode === 'token_expired') {
                return SyncResult::fail(
                    $result->message,
                    'token_expired',
                    $pushed + $result->syncedCount,
                    $failed + $result->failedCount,
                    $retryable + $result->retryableCount,
                    array_values(array_unique([...$failureReasons, ...$result->failureReasons])),
                );
            }

            $pushed += $result->syncedCount;
            $failed += $result->failedCount;
            $retryable += $result->retryableCount;
            $failureReasons = array_values(array_unique([...$failureReasons, ...$result->failureReasons]));
        }

        return $this->buildPushResult($pushed, $failed, $retryable, $failureReasons);
    }

    private function pushCustomerRecord(PendingRequest $client, Customer $customer): SyncResult
    {
        try {
            $payload = [
                'local_uuid' => $customer->local_uuid,
                'server_id' => $customer->server_id,
                'base_updated_at' => $customer->server_updated_at,
                'sync_intent' => $customer->server_id ? 'update' : 'create',
                'name' => $customer->name,
                'unique_id' => $customer->unique_id,
                'customer_code_reservation_token' => $customer->customer_code_reservation_token,
                'company_id' => $customer->company_id,
                'general_category_id' => $customer->general_category_id,
                'competitor_volume' => $customer->competitor_volume,
                'region_specific_id' => $customer->region_specific_id,
                'municipality_id' => $customer->municipality_id,
                'address' => $customer->address,
                'latitude' => $customer->latitude,
                'longitude' => $customer->longitude,
                'contact_person' => $customer->contact_person,
                'contact_number' => $customer->contact_number,
                'business_landline_number' => $customer->business_landline_number,
                'business_mobile_number' => $customer->business_mobile_number,
                'date_established' => optional($customer->date_established)->format('Y-m-d'),
                'is_active' => $customer->is_active,
                'profile_type' => $customer->tradeProfile?->profile_type,
                'person_in_charge_id' => $customer->person_in_charge_id,
                'trade_profile' => $customer->tradeProfile?->toArray() ?? [],
                'profile_data' => $customer->tradeProfile?->profile_data ?? [],
                'category_histories' => $customer->categoryHistories->map(fn ($history) => [
                    'profile_type' => $history->profile_type ?: $customer->tradeProfile?->profile_type,
                    'stream' => $history->stream ?: (($customer->tradeProfile?->profile_type === 'outlet') ? match ($customer->tradeProfile?->entry_detail) {
                        'AB' => 'ab', 'MCB' => 'mcb', default => (str_starts_with((string) $history->category, 'AB ') ? 'ab' : (str_starts_with((string) $history->category, 'MCB ') ? 'mcb' : null)),
                    } : $customer->tradeProfile?->profile_type),
                    'category_year' => $history->category_year,
                    'category' => $history->category,
                ])->values()->all(),
                'category_events' => $customer->categoryEvents->map(fn ($event) => [
                    'event_key' => $event->event_key,
                    'profile_type' => $event->profile_type,
                    'stream' => $event->stream,
                    'category' => $event->category,
                    'effective_at' => $event->effective_at?->toISOString(),
                    'source' => $event->source,
                    'supersedes_event_key' => $event->supersedes_event_key,
                ])->values()->all(),
            ];

            foreach (['province_id', 'barangay_id', 'area_cluster_id'] as $locationId) {
                if ($customer->{$locationId} !== null) {
                    $payload[$locationId] = $customer->{$locationId};
                }
            }

            $response = $client->post("{$this->serverUrl}/api/sync/push/customer", $payload);

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
            }

            if ($response->successful()) {
                $this->markSynced($customer, [
                    'server_id' => $response->json('server_id'),
                    'server_updated_at' => $response->json('updated_at'),
                    'unique_id' => $response->json('unique_id') ?: $customer->unique_id,
                    'customer_code_reservation_token' => null,
                    'synced_at' => now(),
                ]);

                return SyncResult::ok('Customer synced.', 1);
            }

            if ($response->status() === 409 && $response->json('code') === 'customer_conflict') {
                $customer->update(['sync_status' => 'conflict', 'sync_error' => $response->json('message', 'Customer changed on Portal.')]);

                return SyncResult::fail('Customer changed on the server and needs review.', 'customer_conflict', 0, 1, 0, ['customer_conflict']);
            }

            $this->recordItemFailure($customer, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                'stage' => 'customer:portal',
                'endpoint' => '/api/sync/push/customer',
                'http_status' => $response->status(),
            ]);

            return SyncResult::fail('Customer push failed and will retry later.', 'push_failed', 0, 1, 1, ['portal_rejected']);
        } catch (Throwable $exception) {
            $this->recordUnexpectedItemFailure($customer, $exception, 'customer:unexpected');

            return SyncResult::fail('Customer push failed and will retry later.', 'push_failed', 0, 1, 1, ['unexpected_sync_error']);
        }
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
        } catch (Throwable) {
            // best-effort — nothing local left to mark as failed
        }
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout($this->timeout);
    }

    /**
     * Path to a writable file the pull response can be streamed into.
     */
    private function pullSinkPath(): string
    {
        $directory = storage_path('app/tmp');

        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        return $directory.DIRECTORY_SEPARATOR.'sync-pull-'.\Str::uuid().'.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePullResponse(string $sinkPath): array
    {
        $body = is_file($sinkPath) ? file_get_contents($sinkPath) : false;

        if ($body === false) {
            throw new \RuntimeException('The pull response body was not written to the sink file.');
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Bulk insert-or-update rows keyed by their `id`.
     *
     * The reference tables are full snapshots sent on every pull — barangays
     * alone is tens of thousands of rows. A per-row updateOrInsert() is a SELECT
     * plus a write per row and times out the request, so batch them into chunks.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertRows(string $table, array $rows, ?callable $map = null): void
    {
        if ($rows === []) {
            return;
        }

        $timestamp = now();

        // Rows are mapped one chunk at a time so a large table (~42K barangays)
        // is never held in memory as a second full copy of the decoded payload.
        $prepare = function (array $row) use ($map, $timestamp): array {
            $row = $map ? $map($row) : $row;
            $row['updated_at'] = $timestamp;

            return $row;
        };

        $first = $prepare(reset($rows));
        $updateColumns = array_values(array_diff(array_keys($first), ['id', 'created_at']));

        // Keep bindings under the variable limit of older SQLite builds.
        $chunkSize = max(1, min(500, intdiv(900, count($first))));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->upsert(array_map($prepare, $chunk), ['id'], $updateColumns);
        }
    }

    private function markFailed(Model $model, string $error): void
    {
        $model->update([
            'sync_status' => 'failed',
            'sync_attempts' => ($model->sync_attempts ?? 0) + 1,
            'sync_error' => $error,
        ]);
    }

    private function markSynced(Model $model, array $attributes = []): void
    {
        $model->update([
            ...$attributes,
            'sync_status' => 'synced',
            'sync_error' => null,
        ]);
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushSalescallImageItem(PendingRequest $client, SalescallImage $image): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($image->local_path)) {
                $this->recordItemFailure($image, 'local_file_missing', 'local_file_missing: '.$this->displayPath($image->local_path), [
                    'stage' => 'salescall-image:local-file',
                    'local_path' => $image->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $s3Key = $this->ensureSalescallImageS3Key($image);

            $stream = fopen($image->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($image, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($image->local_path), [
                    'stage' => 'salescall-image:local-open',
                    'local_path' => $image->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('image', $stream, basename($image->local_path))
                    ->post("{$this->serverUrl}/api/sync/push/salescall-image", [
                        'local_uuid' => $image->local_uuid,
                        'salescall_server_id' => $image->salescall->server_id,
                        'salescall_image_type_id' => $image->salescall_image_type_id,
                        'notes' => $image->notes,
                        'latitude' => $image->latitude,
                        'longitude' => $image->longitude,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
            }

            if (! $response->successful()) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($image, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'salescall-image:portal',
                    'endpoint' => '/api/sync/push/salescall-image',
                    'http_status' => $response->status(),
                    's3_key' => $s3Key,
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($image, [
                'server_id' => $response->json('server_id'),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($image, $classification, $e, [
                'stage' => 'salescall-image:unexpected',
                'endpoint' => '/api/sync/push/salescall-image',
                'local_path' => $image->local_path,
                's3_key' => $image->s3_key,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushProfileAttachmentItem(PendingRequest $client, CustomerProfileAttachment $attachment): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($attachment->local_path)) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: '.$this->displayPath($attachment->local_path), [
                    'stage' => 'profile-attachment:local-file',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $s3Key = $this->ensureProfileAttachmentS3Key($attachment);

            $stream = fopen($attachment->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($attachment->local_path), [
                    'stage' => 'profile-attachment:local-open',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($attachment->local_path))
                    ->post("{$this->serverUrl}/api/sync/push/customer-profile-attachment", [
                        'local_uuid' => $attachment->local_uuid,
                        'salescall_server_id' => $attachment->salescall->server_id,
                        'original_name' => $attachment->original_name,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log back in.', 'token_expired');
            }

            if (! $response->successful()) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($attachment, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'profile-attachment:portal',
                    'endpoint' => '/api/sync/push/customer-profile-attachment',
                    'http_status' => $response->status(),
                    's3_key' => $s3Key,
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($attachment, [
                'server_id' => $response->json('server_id'),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($attachment, $classification, $e, [
                'stage' => 'profile-attachment:unexpected',
                'endpoint' => '/api/sync/push/customer-profile-attachment',
                'local_path' => $attachment->local_path,
                's3_key' => $attachment->s3_key,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushExpenseAttachmentItem(PendingRequest $client, ExpenseAttachment $attachment): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($attachment->local_path)) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: '.$this->displayPath($attachment->local_path), [
                    'stage' => 'expense-attachment:local-file',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $stream = fopen($attachment->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($attachment->local_path), [
                    'stage' => 'expense-attachment:local-open',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($attachment->local_path))
                    ->post("{$this->serverUrl}/api/sync/push/expense-attachment", [
                        'local_uuid' => $attachment->local_uuid,
                        'expense_server_id' => $attachment->expense->server_id,
                        'original_name' => $attachment->original_name,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired');
            }

            $serverId = $response->json('server_id');
            if (! $response->successful() || $serverId === null) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($attachment, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'expense-attachment:portal',
                    'endpoint' => '/api/sync/push/expense-attachment',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($attachment, [
                'server_id' => $serverId,
                'storage_key' => $response->json('storage_key'),
                'mime_type' => $response->json('mime_type'),
                'extension' => $response->json('extension'),
                'byte_size' => $response->json('byte_size'),
                'synced_at' => now(),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($attachment, $classification, $e, [
                'stage' => 'expense-attachment:unexpected',
                'endpoint' => '/api/sync/push/expense-attachment',
                'local_path' => $attachment->local_path,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushCustomerProfileItem(PendingRequest $client, CustomerProfile $profile): array|SyncResult
    {
        try {
            $signature = null;

            if ($profile->signature_path !== null && $profile->signature_path !== '') {
                if (! $this->isReadableLocalFile($profile->signature_path)) {
                    $this->recordItemFailure($profile, 'local_file_missing', 'local_file_missing: '.$this->displayPath($profile->signature_path), [
                        'stage' => 'customer-profile:local-file',
                        'local_path' => $profile->signature_path,
                    ]);

                    return ['success' => false, 'reason' => 'local_file_missing'];
                }

                $s3Key = $this->ensureProfileSignatureS3Key($profile);
                $signatureBytes = file_get_contents($profile->signature_path);

                if ($signatureBytes === false) {
                    $this->recordItemFailure($profile, 'local_file_missing', 'local_file_missing: unable to read '.$this->displayPath($profile->signature_path), [
                        'stage' => 'customer-profile:local-read',
                        'local_path' => $profile->signature_path,
                        's3_key' => $s3Key,
                    ]);

                    return ['success' => false, 'reason' => 'local_file_missing'];
                }

                $signature = 'data:image/png;base64,'.base64_encode($signatureBytes);
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

            if (! $response->successful()) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($profile, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'customer-profile:portal',
                    'endpoint' => '/api/sync/push/customer-profile',
                    'http_status' => $response->status(),
                    's3_key' => $profile->signature_s3_key,
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($profile, [
                'server_id' => $response->json('server_id'),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($profile, $classification, $e, [
                'stage' => 'customer-profile:unexpected',
                'endpoint' => '/api/sync/push/customer-profile',
                'local_path' => $profile->signature_path,
                's3_key' => $profile->signature_s3_key,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    private function ensureSalescallImageS3Key(SalescallImage $image): string
    {
        try {
            $s3Key = $this->tabletS3UploadService->ensureSalescallImageUploaded($image);
        } catch (Throwable $e) {
            throw $this->rethrowWithClassification($e, 's3_upload_failed');
        }

        if ($image->s3_key !== $s3Key) {
            try {
                $image->update(['s3_key' => $s3Key]);
            } catch (Throwable $e) {
                throw $this->rethrowWithClassification($e, 's3_key_persist_failed');
            }
        }

        return $s3Key;
    }

    private function ensureProfileAttachmentS3Key(CustomerProfileAttachment $attachment): string
    {
        try {
            $s3Key = $this->tabletS3UploadService->ensureProfileAttachmentUploaded($attachment);
        } catch (Throwable $e) {
            throw $this->rethrowWithClassification($e, 's3_upload_failed');
        }

        if ($attachment->s3_key !== $s3Key) {
            try {
                $attachment->update(['s3_key' => $s3Key]);
            } catch (Throwable $e) {
                throw $this->rethrowWithClassification($e, 's3_key_persist_failed');
            }
        }

        return $s3Key;
    }

    private function ensureProfileSignatureS3Key(CustomerProfile $profile): string
    {
        try {
            $s3Key = $this->tabletS3UploadService->ensureProfileSignatureUploaded($profile);
        } catch (Throwable $e) {
            throw $this->rethrowWithClassification($e, 's3_upload_failed');
        }

        if ($profile->signature_s3_key !== $s3Key) {
            try {
                $profile->update(['signature_s3_key' => $s3Key]);
            } catch (Throwable $e) {
                throw $this->rethrowWithClassification($e, 's3_key_persist_failed');
            }
        }

        return $s3Key;
    }

    private function isReadableLocalFile(?string $path): bool
    {
        return is_string($path) && $path !== '' && is_file($path) && is_readable($path);
    }

    private function classifyBinaryThrowable(Throwable $e): string
    {
        $message = $e->getMessage();

        foreach ([
            'local_file_missing',
            's3_upload_failed',
            's3_key_persist_failed',
            'portal_upload_failed',
            'portal_rejected',
            'unexpected_sync_error',
        ] as $classification) {
            if (str_starts_with($message, $classification.'|')) {
                return $classification;
            }
        }

        return 'unexpected_sync_error';
    }

    private function rethrowWithClassification(Throwable $e, string $classification): Throwable
    {
        return new \RuntimeException($classification.'|'.$e->getMessage(), 0, $e);
    }

    private function recordUnexpectedItemFailure(Model $model, Throwable $e, string $stage): void
    {
        $this->recordThrowableFailure($model, 'unexpected_sync_error', $e, [
            'stage' => $stage,
        ]);
    }

    private function recordThrowableFailure(Model $model, string $classification, Throwable $e, array $context = []): void
    {
        $message = $this->formatSyncError($classification, $e->getMessage());

        $this->recordItemFailure($model, $classification, $message, [
            ...$context,
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
        ]);
    }

    private function recordItemFailure(Model $model, string $classification, string $error, array $context = []): void
    {
        $formattedError = $this->formatSyncError($classification, $error);

        try {
            $this->markFailed($model, $formattedError);
        } catch (Throwable $markFailedError) {
            Log::error('sync:item:mark-failed-error', [
                'classification' => $classification,
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'original_error' => $formattedError,
                'mark_failed_exception_class' => $markFailedError::class,
                'mark_failed_exception_message' => $markFailedError->getMessage(),
            ]);
        }

        Log::warning('sync:item:failed', [
            'classification' => $classification,
            'model' => $model::class,
            'model_id' => $model->getKey(),
            'local_uuid' => $model->local_uuid ?? null,
            'error' => $formattedError,
            ...$context,
        ]);
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    private function recordCollectionFailure(Collection $models, string $classification, string $error, array $context = []): void
    {
        $models->each(fn (Model $model) => $this->recordItemFailure($model, $classification, $error, $context));
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    private function recordCollectionUnexpectedFailure(Collection $models, Throwable $e, string $stage, array $context = []): void
    {
        $models->each(fn (Model $model) => $this->recordThrowableFailure($model, 'unexpected_sync_error', $e, [
            'stage' => $stage,
            ...$context,
        ]));
    }

    /**
     * @param  list<string>  $failureReasons
     */
    private function buildPushResult(int $pushed, int $failed, int $retryable, array $failureReasons): SyncResult
    {
        if ($pushed === 0 && $failed === 0) {
            return SyncResult::ok('Nothing to push.', 0, 0, 0, []);
        }

        if ($failed > 0 && $pushed === 0) {
            return SyncResult::fail(
                $failed === 1
                    ? '1 item could not be uploaded and will retry later.'
                    : "{$failed} items could not be uploaded and will retry later.",
                'push_failed',
                0,
                $failed,
                $retryable,
                $failureReasons,
            );
        }

        if ($failed > 0) {
            return SyncResult::ok(
                "{$pushed} item".($pushed === 1 ? '' : 's')." synced. {$failed} item".($failed === 1 ? '' : 's').' could not be uploaded and will retry later.',
                $pushed,
                $failed,
                $retryable,
                $failureReasons,
            );
        }

        return SyncResult::ok(
            "{$pushed} item".($pushed === 1 ? '' : 's').' synced successfully.',
            $pushed,
            0,
            0,
            [],
        );
    }

    private function trimRemoteError(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return 'Empty response body';
        }

        return mb_substr($trimmed, 0, 300);
    }

    private function trimSyncError(string $error): string
    {
        return mb_substr(trim($error), 0, 300);
    }

    private function formatSyncError(string $classification, string $detail): string
    {
        if (str_starts_with($detail, $classification.'|')) {
            $detail = substr($detail, strlen($classification) + 1) ?: $detail;
        }

        return $classification.': '.$this->trimSyncError($detail);
    }

    private function displayPath(?string $path): string
    {
        return is_string($path) && $path !== '' ? $path : '(missing path)';
    }
}
