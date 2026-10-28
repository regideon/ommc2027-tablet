<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use App\Models\CustomerProfileAttachment;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrAttachment;
use App\Models\Forms\CRRSignage\Signage;
use App\Models\Forms\CRRSignage\SignageAttachment;
use App\Models\Forms\Giveaway\Giveaway;
use App\Models\Forms\Giveaway\GiveawaySignature;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Repair\Repair;
use App\Models\Forms\Repair\RepairAttachment;
use App\Models\Forms\TradeCheck\TradeCheck;
use App\Models\Forms\TradeCheck\TradeCheckAttachment;
use App\Models\Forms\TradeRequest\TradeRequest;
use App\Models\Forms\TradeRequest\TradeRequestAttachment;
use App\Models\Forms\Training\Training;
use App\Models\Forms\Training\TrainingAttachment;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\SalescallBrand;
use App\Models\SalescallCategory;
use App\Models\SalescallImage;
use App\Models\User;
use App\Services\Sync\Push\CustomerPushService;
use App\Services\Sync\SyncFailureRecorder;
use App\Support\ExpensePaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

class SyncService
{
    private string $serverUrl;

    private int $timeout;

    private int $pullTimeout;

    /** Local customer edits in these states must not be overwritten by a pull. */
    private const PROTECTED_CUSTOMER_STATUSES = ['pending', 'failed', 'conflict'];

    private const CUSTOMER_PULL_PAGE_SIZE = 100;

    private const CUSTOMER_PULL_IDS_PER_REQUEST = 200;

    /** Per-user sync_states key prefixes; a shared tablet keeps one run and watermark per rep. */
    private const CUSTOMER_PULL_RUN = 'customers.pull_run.';

    private const CUSTOMER_PULL_WATERMARK = 'customers.pulled_through.';

    /** Per-user watermark key for the notification pull. */
    private const NOTIFICATIONS_PULL_WATERMARK = 'notifications.pulled_through.';

    /** Hard ceiling on notification pull pages per run. */
    private const NOTIFICATIONS_PULL_PAGE_LIMIT = 100;

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

        $pendingOrFailed = fn ($query) => $query->whereIn('sync_status', ['pending', 'failed']);

        return $pendingOrRetryable(Itinerary::query())->exists()
            || $pendingOrFailed(Salescall::query())->exists()
            || $pendingOrRetryable(SalescallBrand::query())->exists()
            || $pendingOrRetryable(SalescallCategory::query())->exists()
            || $pendingOrFailed(SalescallImage::query())->exists()
            || $pendingOrFailed(CustomerProfile::query())->exists()
            || $pendingOrFailed(CustomerProfileAttachment::query())->exists()
            || $pendingOrRetryable(Expense::query())->exists()
            || $pendingOrRetryable(ExpenseAttachment::query())->exists()
            || $pendingOrRetryable(Crr::query())->exists()
            || $pendingOrRetryable(CrrAttachment::query())->exists()
            || $pendingOrRetryable(Marketing::query())->exists()
            || $pendingOrRetryable(TradeCheck::query())->exists()
            || $pendingOrRetryable(TradeCheckAttachment::query())->exists()
            || $pendingOrRetryable(Giveaway::query())->exists()
            || $pendingOrRetryable(GiveawaySignature::query())->exists()
            || $pendingOrRetryable(Repair::query())->exists()
            || $pendingOrRetryable(RepairAttachment::query())->exists()
            || $pendingOrRetryable(Signage::query())->exists()
            || $pendingOrRetryable(SignageAttachment::query())->exists()
            || $pendingOrRetryable(TradeRequest::query())->exists()
            || $pendingOrRetryable(TradeRequestAttachment::query())->exists()
            || $pendingOrRetryable(Training::query())->exists()
            || $pendingOrRetryable(TrainingAttachment::query())->exists()
            || $pendingOrFailed(CustomerNote::query())->exists();
    }

    public function retryFailedExpenseAttachment(int $attachmentId): SyncResult
    {
        $attachment = ExpenseAttachment::with('expense')->find($attachmentId);

        if (! $attachment || $attachment->sync_status !== 'failed') {
            return SyncResult::fail('This Expense attachment is no longer available for retry.', 'not_retryable');
        }

        if (! $attachment->expense?->server_id) {
            return SyncResult::fail('Sync the Expense before retrying its attachment.', 'expense_not_synced');
        }

        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $result = $this->pushExpenseAttachmentItem($this->client($user->api_token), $attachment);

        if ($result instanceof SyncResult) {
            return $result;
        }

        if ($result['busy'] ?? false) {
            return SyncResult::fail('This Expense attachment is already being retried.', 'retry_in_progress');
        }

        if (! $result['success']) {
            $message = ($result['reason'] ?? null) === 'local_file_missing'
                ? 'The local Expense attachment file is missing. Restore the file before retrying.'
                : 'Expense attachment retry failed. The failure details are saved and the attachment can be retried.';

            return SyncResult::fail($message, $result['reason'] ?? 'push_failed', 0, 1, 1, [$result['reason'] ?? 'push_failed']);
        }

        return SyncResult::ok('Expense attachment synchronized.', 1);
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
                ->post("{$this->serverUrl}/api/speedtest");

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
        } catch (Throwable) {
            return false;
        }
    }

    public function isPortalResponsive(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            // Any HTTP response proves that Portal was reached, regardless of status.
            Http::timeout(3)->get("{$this->serverUrl}/api/ping");

            return true;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function isConfigured(): bool
    {
        return filled($this->serverUrl);
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
                "{$this->serverUrl}/api/customers/reserve-code",
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
        } catch (ConnectionException) {
            return SyncResult::fail('Could not reach Portal.', 'connection_error');
        } catch (Throwable) {
            return SyncResult::fail('Could not complete Portal authentication.', 'request_error');
        }

        if ($response->status() === 401) {
            return SyncResult::fail('Invalid email or password.', 'invalid_credentials', httpStatus: 401);
        }

        if ($response->failed()) {
            return SyncResult::fail("Portal authentication failed ({$response->status()}).", 'server_error', httpStatus: $response->status());
        }

        $data = $response->json();

        if (! is_array($data)
            || ! is_string($data['email'] ?? null)
            || ! is_string($data['name'] ?? null)
            || ! is_string($data['password'] ?? null)
            || ! is_string($data['api_token'] ?? null)
            || ! is_array($data['roles'] ?? null)) {
            return SyncResult::fail('Portal returned an incomplete authentication response.', 'invalid_response');
        }

        try {
            DB::transaction(function () use ($data): void {
                $attributes = [
                    'name' => $data['name'],
                    'password' => $data['password'],
                    'api_token' => $data['api_token'],
                    'server_id' => $data['id'] ?? null,
                    'rsm_id' => $data['rsm_id'] ?? null,
                    'region_type_id' => $data['region_type_id'] ?? null,
                    'base_start_latitude' => $data['base_start_latitude'] ?? null,
                    'base_start_longitude' => $data['base_start_longitude'] ?? null,
                    'base_end_latitude' => $data['base_end_latitude'] ?? null,
                    'base_end_longitude' => $data['base_end_longitude'] ?? null,
                ];
                if (Schema::hasColumn('users', 'last_name') && array_key_exists('last_name', $data)) {
                    $attributes['last_name'] = $data['last_name'];
                }
                $user = User::updateOrCreate(['email' => $data['email']], $attributes);

                if (! empty($data['roles'])) {
                    foreach ($data['roles'] as $roleName) {
                        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
                    }
                    $user->syncRoles($data['roles']);
                }
            });

            return SyncResult::ok('Token refreshed.');
        } catch (Throwable) {
            return SyncResult::fail('Could not persist the Portal identity locally.', 'persistence_error');
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

        $locationSnapshot = $this->syncState('location_reference_snapshot_complete');
        if (($locationSnapshot['reference_contract_version'] ?? 0) < 3) {
            $locations = $this->pullLocations();
            if (! $locations->success) {
                return $locations;
            }
        }

        $data = $this->fetchPullPayload($user, '/api/schedule/pull');

        if ($data instanceof SyncResult) {
            return $data;
        }

        try {
            DB::transaction(fn () => $this->applyPullPayload($data, $user));
        } catch (\Exception $e) {
            return SyncResult::fail('Pull error: '.$e->getMessage(), 'exception');
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

        $data = $this->fetchPullPayload($user, '/api/locations/pull');

        if ($data instanceof SyncResult) {
            return $data;
        }

        try {
            $this->validateLocationSnapshot($data);
            DB::transaction(function () use ($data, $user): void {
                $this->applyPullPayload($data, $user);

                $this->putSyncState('location_reference_snapshot_complete', [
                    'completed_at' => now()->toISOString(),
                    'reference_contract_version' => (int) $data['reference_contract_version'],
                ]);
                if (($data['reference_contract_version'] ?? 0) >= 3) {
                    $this->putSyncState('landmass_options', $data['landmass_options']);
                }
            });
        } catch (\Exception $e) {
            return SyncResult::fail('Pull error: '.$e->getMessage(), 'exception');
        }

        return SyncResult::ok('Pulled location references.');
    }

    /**
     * Notification pull: the logged-in user's in-app notifications, stored
     * locally with device-local read state.
     */
    public function pullNotifications(): SyncResult
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $watermarkKey = self::NOTIFICATIONS_PULL_WATERMARK.($user->server_id ?? $user->id);
        $state = $this->syncState($watermarkKey);
        $since = is_array($state) ? ($state['last_created_at'] ?? null) : null;

        $rows = [];
        $page = 1;

        do {
            $query = ['page' => $page];

            if (is_string($since) && $since !== '') {
                $query['since'] = $since;
            }

            $data = $this->fetchPullPayload($user, '/api/notifications/pull', $query);

            if ($data instanceof SyncResult) {
                return $data;
            }

            foreach ($data['notifications'] ?? [] as $row) {
                if (is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '') {
                    $rows[$row['id']] = $row;
                }
            }

            $page++;
        } while (($data['has_more'] ?? false) === true && $page <= self::NOTIFICATIONS_PULL_PAGE_LIMIT);

        $pulledAt = now();

        if ($rows !== []) {
            $insertRows = array_map(function (array $row) use ($user, $pulledAt): array {
                $createdAt = is_string($row['created_at'] ?? null)
                    ? Carbon::parse($row['created_at'])->setTimezone(config('app.timezone'))->toDateTimeString()
                    : $pulledAt->toDateTimeString();

                return [
                    'id' => $row['id'],
                    'type' => 'templated-notification',
                    'notifiable_type' => User::class,
                    'notifiable_id' => $user->id,
                    'data' => json_encode([
                        'title' => $row['title'] ?? null,
                        'body' => $row['body'] ?? null,
                        'tag' => $row['module'] ?? null,
                    ], JSON_THROW_ON_ERROR),
                    'read_at' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }, array_values($rows));

            foreach (array_chunk($insertRows, 100) as $chunk) {
                DB::table('notifications')->insertOrIgnore($chunk);
            }
        }

        $createdAts = array_filter(
            array_map(fn (array $row): mixed => $row['created_at'] ?? null, array_values($rows)),
            'is_string',
        );

        $this->putSyncState($watermarkKey, [
            'last_created_at' => $createdAts === [] ? $since : max($createdAts),
            'pulled_at' => $pulledAt->toISOString(),
        ]);

        return SyncResult::ok('Pulled '.count($rows).' notifications.', count($rows));
    }

    private function validateLocationSnapshot(array $data): void
    {
        $sections = ['regions', 'region_specifics', 'area_clusters', 'provinces', 'municipalities', 'barangays'];
        $parentKeys = [
            'region_specifics' => ['region_id' => 'regions'],
            'area_clusters' => ['region_specific_id' => 'region_specifics'],
            'provinces' => ['region_id' => 'regions'],
            'municipalities' => ['region_id' => 'regions', 'province_id' => 'provinces'],
            'barangays' => ['municipality_id' => 'municipalities'],
        ];
        $requiredParents = [
            'region_specifics' => ['region_id'],
            'area_clusters' => ['region_specific_id'],
            'barangays' => ['municipality_id'],
        ];
        $requiredStrings = [
            'regions' => ['code'],
            'area_clusters' => ['code'],
            'barangays' => ['code'],
        ];
        $nullableParentKeys = [
            'provinces' => ['region_id'],
            'municipalities' => ['region_id', 'province_id'],
        ];

        if (! in_array(($data['reference_contract_version'] ?? null), [2, 3], true) || ! is_array($data['reference_counts'] ?? null)) {
            throw new \RuntimeException('Portal location response is missing supported snapshot metadata.');
        }
        if (($data['reference_contract_version'] ?? 0) >= 3 && ! is_array($data['landmass_options'] ?? null)) {
            throw new \RuntimeException('Portal location response is missing Landmass options.');
        }
        foreach ($data['landmass_options'] ?? [] as $code => $label) {
            if (! is_string($code) || trim($code) === '' || ! is_string($label) || trim($label) === '') {
                throw new \RuntimeException('Portal location response contains an invalid Landmass option.');
            }
        }

        if (! array_key_exists('customer_access_mappings', $data)) {
            throw new \RuntimeException('Portal location response is missing creator-group and Customer Access references.');
        }

        $ids = [];
        foreach ($sections as $section) {
            $rows = $data[$section] ?? null;
            $expected = $data['reference_counts'][$section] ?? null;

            if (! is_array($rows) || ! array_is_list($rows) || ! is_int($expected) || $expected < 0 || count($rows) !== $expected) {
                throw new \RuntimeException("Portal location response section {$section} is incomplete or malformed.");
            }

            $ids[$section] = [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1 || ! is_string($row['name'] ?? null) || trim($row['name']) === '') {
                    throw new \RuntimeException("Portal location response contains an invalid {$section} row.");
                }
                foreach ($requiredParents[$section] ?? [] as $parentKey) {
                    if (! is_int($row[$parentKey] ?? null) || $row[$parentKey] < 1) {
                        throw new \RuntimeException("Portal location response contains an invalid {$section}.{$parentKey} value.");
                    }
                }
                foreach ($requiredStrings[$section] ?? [] as $stringKey) {
                    if (! is_string($row[$stringKey] ?? null) || trim($row[$stringKey]) === '') {
                        throw new \RuntimeException("Portal location response contains an invalid {$section}.{$stringKey} value.");
                    }
                }
                foreach ($nullableParentKeys[$section] ?? [] as $parentKey) {
                    if (($row[$parentKey] ?? null) !== null && (! is_int($row[$parentKey]) || $row[$parentKey] < 1)) {
                        throw new \RuntimeException("Portal location response contains an invalid {$section}.{$parentKey} value.");
                    }
                }
                if (in_array($section, ['area_clusters', 'provinces', 'barangays'], true)
                    && ! (is_bool($row['enabled'] ?? null) || in_array($row['enabled'] ?? null, [0, 1, '0', '1'], true))) {
                    throw new \RuntimeException("Portal location response contains an invalid {$section}.enabled value.");
                }
                if (in_array($row['id'], $ids[$section], true)) {
                    throw new \RuntimeException("Portal location response contains duplicate {$section} IDs.");
                }
                $ids[$section][] = $row['id'];
            }
        }

        if (array_diff(array_keys($data['reference_counts']), $sections) !== [] || count($data['reference_counts']) !== count($sections)) {
            throw new \RuntimeException('Portal location response count metadata does not match its required sections.');
        }

        if (array_key_exists('customer_access_mappings', $data)) {
            $this->validateCustomerAccessMappings($data['customer_access_mappings'], $ids['region_specifics']);
        }
        if (array_key_exists('location_area_cluster_mappings', $data)) {
            $this->validateLocationAreaClusterMappings($data['location_area_cluster_mappings'], $data);
        }

        $idSets = array_map(fn (array $sectionIds): array => array_fill_keys($sectionIds, true), $ids);
        foreach ($sections as $section) {
            foreach ($data[$section] as $row) {
                foreach ($parentKeys[$section] ?? [] as $parentKey => $parentSection) {
                    $parentId = $row[$parentKey] ?? null;
                    if ($parentId !== null && (! is_int($parentId) || ! isset($idSets[$parentSection][$parentId]))) {
                        throw new \RuntimeException("Portal location response contains an invalid {$section}.{$parentKey} parent.");
                    }
                }
            }
        }

        $provinceRows = collect($data['provinces'])->keyBy('id');
        foreach ($data['municipalities'] as $municipality) {
            $province = isset($municipality['province_id']) ? $provinceRows->get($municipality['province_id']) : null;
            if ($province && $municipality['region_id'] !== null && $province['region_id'] !== null
                && (int) $municipality['region_id'] !== (int) $province['region_id']) {
                throw new \RuntimeException('Portal location response contains a Municipality outside its physical Province Region.');
            }
        }

    }

    /**
     * @param  list<int>  $regionSpecificIds
     */
    private function validateCustomerAccessMappings(mixed $rows, array $regionSpecificIds): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new \RuntimeException('Portal Customer Access mapping references are incomplete or malformed.');
        }

        $mappingKeys = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_bool($row['is_complete'] ?? null) || ! is_array($row['users'] ?? null) || ! array_is_list($row['users'])) {
                throw new \RuntimeException('Portal returned an invalid Customer Access mapping row.');
            }

            if (($row['scope'] ?? null) === 'region_specific') {
                $mappingKey = $row['region_specific_id'] ?? null;
                if (! is_int($mappingKey) || $mappingKey < 1 || ! in_array($mappingKey, $regionSpecificIds, true)
                    || ! is_string($row['group_code'] ?? null) || trim($row['group_code']) === '') {
                    throw new \RuntimeException('Portal returned an invalid Specific Region Access mapping reference.');
                }
                $mappingKey = (string) $mappingKey;
            } elseif (($row['scope'] ?? null) === 'group') {
                $mappingKey = $row['group_code'] ?? null;
                if (! is_string($mappingKey) || trim($mappingKey) === '') {
                    throw new \RuntimeException('Portal returned an invalid Access group reference.');
                }
            } elseif (($row['scope'] ?? null) === 'company') {
                $mappingKey = $row['company_code'] ?? null;
                if (! is_string($mappingKey) || ! in_array($mappingKey, ['OE', 'IB'], true)) {
                    throw new \RuntimeException('Portal returned an invalid Company Access mapping reference.');
                }
            } else {
                throw new \RuntimeException('Portal returned an unsupported Customer Access mapping scope.');
            }

            $scope = $row['scope'];
            if (isset($mappingKeys[$scope][$mappingKey])) {
                throw new \RuntimeException('Portal returned duplicate Customer Access mapping keys.');
            }
            $mappingKeys[$scope][$mappingKey] = true;

            $emails = [];
            foreach ($row['users'] as $reference) {
                if (! is_array($reference) || ! is_string($reference['email'] ?? null)
                    || filter_var($reference['email'], FILTER_VALIDATE_EMAIL) === false
                    || ! is_string($reference['name'] ?? null) || trim($reference['name']) === ''
                    || (array_key_exists('last_name', $reference)
                        && $reference['last_name'] !== null
                        && ! is_string($reference['last_name']))) {
                    throw new \RuntimeException('Portal returned an invalid mapped User identity.');
                }
                if (in_array($reference['email'], $emails, true)) {
                    throw new \RuntimeException('Portal returned a duplicate mapped User email.');
                }
                $emails[] = $reference['email'];
            }

            if ($row['is_complete'] && $emails === []) {
                throw new \RuntimeException('Portal marked an empty Customer Access mapping complete.');
            }
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function validateLocationAreaClusterMappings(mixed $rows, array $snapshot): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new \RuntimeException('Portal location mapping references are incomplete or malformed.');
        }

        $provinces = collect($snapshot['provinces'])->keyBy('id');
        $municipalities = collect($snapshot['municipalities'])->keyBy('id');
        $barangays = collect($snapshot['barangays'])->keyBy('id');
        $regionSpecifics = collect($snapshot['region_specifics'])->keyBy('id');
        $areaClusters = collect($snapshot['area_clusters'])->keyBy('id');
        $keys = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1
                || ! in_array($row['location_level'] ?? null, ['province', 'municipality', 'barangay'], true)
                || ! is_int($row['location_id'] ?? null) || $row['location_id'] < 1
                || ! in_array($row['decision'] ?? null, ['mapped', 'unresolved', 'requires_finer_location'], true)) {
                throw new \RuntimeException('Portal returned an invalid location mapping row.');
            }

            $level = $row['location_level'];
            $locationId = $row['location_id'];
            $location = match ($level) {
                'province' => $provinces->get($locationId),
                'municipality' => $municipalities->get($locationId),
                'barangay' => $barangays->get($locationId),
            };
            $key = $level.':'.$locationId;
            if (! $location || isset($keys[$key]) || isset($keys['id:'.$row['id']])) {
                throw new \RuntimeException('Portal returned a missing or duplicate location mapping key.');
            }
            $keys[$key] = true;
            $keys['id:'.$row['id']] = true;

            if ($row['decision'] !== 'mapped') {
                if (($row['area_cluster_id'] ?? null) !== null) {
                    throw new \RuntimeException('A blocked location mapping cannot include an Area Cluster.');
                }

                continue;
            }

            $clusterId = $row['area_cluster_id'] ?? null;
            $cluster = is_int($clusterId) ? $areaClusters->get($clusterId) : null;
            $specificRegion = $cluster ? $regionSpecifics->get($cluster['region_specific_id']) : null;
            $physicalRegionId = match ($level) {
                'province' => $location['region_id'] ?? null,
                'municipality' => $location['region_id'] ?? null,
                'barangay' => $municipalities->get($location['municipality_id'])['region_id'] ?? null,
            };

            $mappingMunicipalityId = $level === 'municipality' ? $locationId : (int) ($location['municipality_id'] ?? 0);
            $expectedNcrCodes = (array) config('metro_manila_area_cluster_mapping.municipality_area_codes.'.$mappingMunicipalityId, []);
            $ncrAllowed = $cluster
                && (int) ($cluster['region_specific_id'] ?? 0) === (int) config('metro_manila_area_cluster_mapping.operational_region_specific_id')
                && (int) ($specificRegion['id'] ?? 0) === (int) config('metro_manila_area_cluster_mapping.operational_region_specific_id')
                && (int) ($specificRegion['region_id'] ?? 0) === (int) config('metro_manila_area_cluster_mapping.operational_parent_region_id')
                && (int) $physicalRegionId === (int) config('metro_manila_area_cluster_mapping.physical_region_id')
                && in_array($cluster['code'] ?? null, $expectedNcrCodes, true);

            if (! $cluster || ! $cluster['enabled'] || ! $specificRegion || ! is_int($physicalRegionId)
                || (! $ncrAllowed && (int) $specificRegion['region_id'] !== $physicalRegionId)) {
                throw new \RuntimeException('Portal returned a location mapping with an invalid Area Cluster or physical Region.');
            }
        }
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
     * Every run refreshes location references before customer pages; the
     * customer watermark still limits later runs to changed customers. The
     * last page reconciles scope: customers that left it are
     * deactivated locally and customers that came back into it are re-fetched.
     * Progress is checkpointed after every step, so an interrupted run resumes.
     *
     * @return array{success: bool, done: bool, customer_pull_succeeded: bool, location_pull_succeeded: ?bool, location_refresh_failed: bool, full_sync_succeeded: bool, pulled: int, total: ?int, message: string}
     */
    public function pullCustomersStep(): array
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return $this->customerPullProgress([], false, false, 'No API token found. Please log in first.');
        }

        $runKey = self::CUSTOMER_PULL_RUN.$user->id;
        $run = $this->syncState($runKey) ?? $this->startCustomerPullRun($user);
        $run['counts'] ??= ['returned' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0];
        if (($run['phase'] ?? null) !== 'locations' && ! array_key_exists('location_refresh_attempted', $run)) {
            // Older interrupted runs entered `pages` only after a successful
            // location Pull, before these stage results were added to progress.
            $run['location_refresh_attempted'] = true;
            $run['location_refresh_failed'] = false;
            $run['location_failure_message'] = null;
        }

        // A failed step checkpoints the run as it was before the step, so the
        // retry repeats the whole step rather than resuming half-applied state.
        $runBeforeStep = $run;
        $currentPageReturned = 0;
        $pageTransactionCommitted = false;
        $failureStage = 'customer_page_request';

        try {
            if ($run['phase'] === 'locations') {
                $result = $this->pullLocations();

                $run['location_refresh_attempted'] = true;
                $run['location_refresh_failed'] = ! $result->success;
                $run['location_failure_message'] = $result->success ? null : $result->message;
                if (! $result->success) {
                    Log::warning('sync:pull:locations:failed', [
                        'error_code' => $result->errorCode,
                        'message' => $result->message,
                    ]);
                }
                $run['phase'] = 'pages';
                $this->putSyncState($runKey, $run);

                if (! $result->success) {
                    return $this->customerPullProgress(
                        $run,
                        true,
                        false,
                        'Location references could not be refreshed; existing local reference data was preserved. Continuing Customer Pull.'
                    );
                }

                return $this->customerPullProgress($run, true, false, 'Location references updated.');
            }

            $query = $run['phase'] === 'missing'
                ? ['ids' => implode(',', array_slice($run['missing'], 0, self::CUSTOMER_PULL_IDS_PER_REQUEST))]
                : array_filter([
                    'after_id' => $run['after_id'],
                    'limit' => self::CUSTOMER_PULL_PAGE_SIZE,
                    'updated_since' => $run['since'],
                ], fn ($value) => $value !== null);

            $data = $this->fetchPullPayload($user, '/api/customers/pull', $query);

            if ($data instanceof SyncResult) {
                $this->putSyncState($runKey, $run);
                $reference = (string) Str::uuid();
                Log::error('sync:pull:customers:failed', [
                    'reference' => $reference,
                    'stage' => $failureStage,
                    'phase' => $run['phase'] ?? null,
                    'after_id' => $run['after_id'] ?? null,
                    'error_code' => $data->errorCode,
                    'http_status' => $data->httpStatus,
                    'exception_class' => $data->exceptionClass,
                    'duration_ms' => $data->durationMs,
                    'response_bytes' => $data->responseBytes,
                    'page_transaction_committed' => false,
                ]);
                $message = $this->customerPullFailureMessage($data, $reference);
                if ($run['location_refresh_failed'] ?? false) {
                    $message .= ' Location refresh also failed; existing local reference data was preserved.';
                }

                return $this->failedCustomerPullProgress($run, $failureStage, $reference, $message);
            }

            $currentPageReturned = is_array($data['customers'] ?? null) ? count($data['customers']) : 0;
            $failureStage = 'customer_page_validation';
            $this->validateCustomerPullRequiredReferences($data);
            $failureStage = 'customer_page_transaction';
            $pageResult = DB::transaction(function () use ($data, $user, $runKey, &$run): array {
                $customerStateBefore = $this->customerPullState($data);
                $this->applyPullPayload($data, $user);
                $currentPageCounts = $this->classifyCustomerPullChanges($data, $customerStateBefore);

                $run['counts']['returned'] += is_array($data['customers'] ?? null) ? count($data['customers']) : 0;
                foreach ($currentPageCounts as $count => $value) {
                    $run['counts'][$count] += $value;
                }
                $run['pulled'] += is_array($data['customers'] ?? null) ? count($data['customers']) : 0;

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

                $done = $run['phase'] === 'missing' && $run['missing'] === [];
                if ($done) {
                    $this->putSyncState(self::CUSTOMER_PULL_WATERMARK.$user->id, $run['server_time']);
                    $this->forgetSyncState($runKey);
                } else {
                    $this->putSyncState($runKey, $run);
                }

                return ['done' => $done];
            });
            $pageTransactionCommitted = true;

            if ($pageResult['done']) {
                $counts = $run['counts'];
                $message = "Customer Pull succeeded: {$counts['returned']} returned; {$counts['inserted']} inserted, {$counts['updated']} changed, {$counts['unchanged']} unchanged, {$counts['rejected']} rejected.";
                if ($run['location_refresh_failed'] ?? false) {
                    $message .= ' Customer records are current. Location references could not be refreshed; existing local reference data was preserved. Full synchronization is incomplete. Contact support to resolve the Portal reference-data issue.';
                }

                return $this->customerPullProgress($run, true, true, $message);
            }

            return $this->customerPullProgress($run, true, false, "Pulled {$run['pulled']} customers…");
        } catch (Throwable $e) {
            $this->putSyncState($runKey, $runBeforeStep);
            $reference = (string) Str::uuid();
            Log::error('sync:pull:customers:failed', [
                'reference' => $reference,
                'stage' => $failureStage,
                'phase' => $runBeforeStep['phase'] ?? null,
                'after_id' => $runBeforeStep['after_id'] ?? null,
                'returned_customers' => $currentPageReturned,
                'customer_server_ids' => isset($data) && is_array($data['customers'] ?? null)
                    ? array_values(array_filter(array_map(
                        static fn (mixed $id): ?int => (is_int($id) || (is_string($id) && ctype_digit($id))) ? (int) $id : null,
                        array_column($data['customers'], 'id'),
                    ), static fn (?int $id): bool => $id !== null))
                    : [],
                'exception_class' => $e::class,
                'exception' => $e,
                'exception_code' => is_numeric($e->getCode()) ? (int) $e->getCode() : null,
                'page_transaction_committed' => $pageTransactionCommitted,
            ]);
            $message = $this->customerPullExceptionMessage($e, $failureStage, $reference);
            $failedRun = $runBeforeStep;
            if ($currentPageReturned > 0) {
                $failedRun['counts']['returned'] += $currentPageReturned;
                $failedRun['counts']['rejected'] += $currentPageReturned;
            }
            if ($runBeforeStep['location_refresh_failed'] ?? false) {
                $message .= ' Location refresh also failed; existing local reference data was preserved.';
            }

            return $this->failedCustomerPullProgress($failedRun, $failureStage, $reference, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function failedCustomerPullProgress(array $run, string $stage, string $reference, string $message): array
    {
        return [
            ...$this->customerPullProgress($run, false, false, $message),
            'failure_stage' => $stage,
            'diagnostic_reference' => $reference,
        ];
    }

    private function customerPullFailureMessage(SyncResult $result, string $reference): string
    {
        $reason = match ($result->errorCode) {
            'token_expired' => 'The Portal session has expired. Log in again before retrying.',
            'server_error' => $result->httpStatus === null
                ? 'The Portal did not accept the Customer page request.'
                : "The Portal returned HTTP {$result->httpStatus} for the Customer page request.",
            'exception', 'connection_error' => 'The Portal request or response could not be completed.',
            default => 'The Customer page request could not be completed.',
        };

        return "Customer Pull failed while requesting a page. {$reason} Reference: {$reference}.";
    }

    private function customerPullExceptionMessage(Throwable $exception, string $stage, string $reference): string
    {
        $rawMessage = $exception->getMessage();

        $reason = match ($rawMessage) {
            'A Portal Customer has no valid required Company ID.' => 'A Portal Customer has no valid required Company ID.',
            default => $stage === 'customer_page_transaction'
                ? 'The returned Customer page could not be safely applied; its local transaction was rolled back.'
                : 'The Customer page could not be validated against local reference data.',
        };

        $message = "Customer Pull failed during {$stage}. {$reason}";
        // Controlled Portal-side reasons (identity conflicts, duplicate or
        // invalid reference data) and database errors are surfaced so a failed
        // page is diagnosable from the device screen without the log.
        $detail = null;
        if (str_starts_with($rawMessage, 'Portal ')) {
            $detail = $rawMessage;
        } elseif ($exception instanceof QueryException) {
            $detail = (string) strtok($rawMessage, "\n");
        }

        if ($detail !== null && ! str_contains($reason, $detail)) {
            $message .= ' ('.substr($detail, 0, 300).')';
        }

        return $message." Reference: {$reference}.";
    }

    /**
     * @return array<string, mixed>
     */
    private function startCustomerPullRun(User $user): array
    {
        $since = $this->syncState(self::CUSTOMER_PULL_WATERMARK.$user->id);

        return [
            'phase' => 'locations',
            'location_refresh_attempted' => false,
            'location_refresh_failed' => false,
            'location_failure_message' => null,
            'since' => $since,
            'after_id' => 0,
            'server_time' => null,
            'total' => null,
            'pulled' => 0,
            'counts' => ['returned' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0],
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
     * @return array{success: bool, done: bool, customer_pull_succeeded: bool, location_pull_succeeded: ?bool, location_refresh_failed: bool, full_sync_succeeded: bool, pulled: int, returned: int, inserted: int, updated: int, unchanged: int, rejected: int, total: ?int, message: string}
     */
    private function customerPullProgress(array $run, bool $success, bool $done, string $message): array
    {
        $locationRefreshAttempted = (bool) ($run['location_refresh_attempted'] ?? false);
        $locationRefreshFailed = (bool) ($run['location_refresh_failed'] ?? false);
        $customerPullSucceeded = $success && $done;
        $locationPullSucceeded = $locationRefreshAttempted && ! $locationRefreshFailed;

        return [
            'success' => $success,
            'done' => $done,
            'customer_pull_succeeded' => $customerPullSucceeded,
            'location_pull_succeeded' => $locationRefreshAttempted ? $locationPullSucceeded : null,
            'location_refresh_failed' => $locationRefreshFailed,
            'full_sync_succeeded' => $customerPullSucceeded && $locationPullSucceeded,
            'pulled' => (int) ($run['pulled'] ?? 0),
            'returned' => (int) ($run['counts']['returned'] ?? $run['pulled'] ?? 0),
            'inserted' => (int) ($run['counts']['inserted'] ?? 0),
            'updated' => (int) ($run['counts']['updated'] ?? 0),
            'unchanged' => (int) ($run['counts']['unchanged'] ?? 0),
            'rejected' => (int) ($run['counts']['rejected'] ?? 0),
            'total' => isset($run['total']) ? (int) $run['total'] : null,
            'message' => $message,
        ];
    }

    /**
     * Capture a PII-free comparison of each Customer and its Tablet-owned
     * profile, category history, and Access relationships before a Pull page.
     * Volatile synchronization timestamps are intentionally excluded.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{local_id: int|null, fingerprint: string|null}>
     */
    private function customerPullState(array $data): array
    {
        $states = [];

        foreach ($data['customers'] ?? [] as $customer) {
            $serverId = (int) $customer['id'];
            $local = DB::table('customers')->where('server_id', $serverId)->first();

            if (! $local && filled($customer['local_uuid'] ?? null)) {
                $local = DB::table('customers')->where('local_uuid', $customer['local_uuid'])->first();
            }

            if (! $local) {
                $candidate = DB::table('customers')->where('id', $serverId)->first();
                if ($candidate && ! in_array($candidate->sync_status, self::PROTECTED_CUSTOMER_STATUSES, true)) {
                    $local = $candidate;
                }
            }

            $states[$serverId] = [
                'local_id' => $local ? (int) $local->id : null,
                'fingerprint' => $local ? $this->customerPullFingerprint((int) $local->id) : null,
            ];
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{local_id: int|null, fingerprint: string|null}>  $before
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    private function classifyCustomerPullChanges(array $data, array $before): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($data['customers'] ?? [] as $customer) {
            $serverId = (int) $customer['id'];
            $previous = $before[$serverId] ?? ['local_id' => null, 'fingerprint' => null];
            $local = DB::table('customers')->where('server_id', $serverId)->first();

            if (! $local && filled($customer['local_uuid'] ?? null)) {
                $local = DB::table('customers')->where('local_uuid', $customer['local_uuid'])->first();
            }

            if (! $local) {
                $counts['inserted']++;

                continue;
            }

            if ($previous['local_id'] === null) {
                $counts['inserted']++;

                continue;
            }

            $fingerprint = $this->customerPullFingerprint((int) $local->id);
            if ($previous['fingerprint'] !== $fingerprint) {
                $counts['updated']++;
            } else {
                $counts['unchanged']++;
            }
        }

        return $counts;
    }

    private function customerPullFingerprint(int $localCustomerId): string
    {
        $customer = DB::table('customers')->where('id', $localCustomerId)->first();
        $customerValues = (array) $customer;
        foreach (['created_at', 'updated_at', 'synced_at', 'server_updated_at', 'sync_error', 'sync_status'] as $volatile) {
            unset($customerValues[$volatile]);
        }

        $profile = DB::table('customer_trade_profiles')->where('customer_id', $localCustomerId)->first();
        $history = DB::table('customer_category_histories')->where('customer_id', $localCustomerId)
            ->orderBy('category_year')->get()->map(fn ($row): array => collect((array) $row)->except(['id', 'created_at', 'updated_at'])->all())->all();
        $access = DB::table('customer_user')->where('customer_id', $localCustomerId)
            ->orderBy('user_id')->pluck('user_id')->all();
        $profileValues = $profile ? collect((array) $profile)->except(['id', 'created_at', 'updated_at'])->all() : null;

        return hash('sha256', json_encode([$customerValues, $profileValues, $history, $access], JSON_THROW_ON_ERROR));
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
        $startedAt = hrtime(true);
        $httpStatus = null;
        $responseBytes = null;

        try {
            $response = $this->client($user->api_token)
                ->timeout($this->pullTimeout)
                ->sink($sinkPath)
                ->get("{$this->serverUrl}{$path}", $query);
            $httpStatus = $response->status();
            $responseBytes = is_file($sinkPath) ? (int) filesize($sinkPath) : null;
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            if ($httpStatus === 401) {
                return SyncResult::fail(
                    'Session expired. Please log out and log back in.',
                    'token_expired',
                    httpStatus: $httpStatus,
                    durationMs: $durationMs,
                    responseBytes: $responseBytes,
                );
            }

            if ($response->failed()) {
                return SyncResult::fail(
                    "Pull failed ({$httpStatus}).",
                    'server_error',
                    httpStatus: $httpStatus,
                    durationMs: $durationMs,
                    responseBytes: $responseBytes,
                );
            }

            return $this->decodePullResponse($sinkPath);
        } catch (\Exception $e) {
            return SyncResult::fail(
                'Pull request or response decoding failed.',
                'exception',
                httpStatus: $httpStatus,
                exceptionClass: $e::class,
                durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
                responseBytes: $responseBytes,
            );
        } finally {
            @unlink($sinkPath);
        }
    }

    /**
     * A non-null Company ID must be well-formed, but it is retained as supplied
     * even when its local reference row is unavailable — the same policy as the
     * location fields — so an unresolvable Company never fails a Customer page.
     * A missing Company ID field is treated as null: the Customer is pulled as
     * Company-less.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateCustomerPullRequiredReferences(array $data): void
    {
        foreach ($data['customers'] ?? [] as $customer) {
            $companyId = $customer['company_id'] ?? null;
            if ($companyId === null) {
                continue;
            }
            if ((! is_int($companyId) && (! is_string($companyId) || ! ctype_digit($companyId))) || (int) $companyId < 1) {
                throw new \RuntimeException('A Portal Customer has no valid required Company ID.');
            }
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
        $localUserIdsByEmail = [];

        foreach ($data['customer_users'] ?? [] as $customerUser) {
            $email = $customerUser['email'] ?? null;

            if (! is_string($email) || $email === '') {
                throw new \RuntimeException('Portal returned a Customer User reference without an email.');
            }

            $matches = User::query()->get(['id', 'email'])->filter(fn (User $candidate): bool => $candidate->email === $email)->values();

            if ($matches->count() > 1) {
                throw new \RuntimeException('Portal returned a Customer User email that matches multiple local Users.');
            }

            $local = $matches->first();

            if (! $local) {
                $attributes = [
                    'name' => $customerUser['name'],
                    'email' => $email,
                    'password' => Str::random(64),
                ];
                if (Schema::hasColumn('users', 'last_name') && array_key_exists('last_name', $customerUser)) {
                    $attributes['last_name'] = $customerUser['last_name'];
                }
                $local = User::create($attributes);
            } elseif ($local->name !== $customerUser['name']
                || (Schema::hasColumn('users', 'last_name') && array_key_exists('last_name', $customerUser) && $local->last_name !== $customerUser['last_name'])) {
                $attributes = ['name' => $customerUser['name']];
                if (Schema::hasColumn('users', 'last_name') && array_key_exists('last_name', $customerUser)) {
                    $attributes['last_name'] = $customerUser['last_name'];
                }
                $local->update($attributes);
            }

            $localUserIdsByEmail[$email] = (int) $local->id;
        }

        if (array_key_exists('customer_access_mappings', $data)) {
            $mappings = [];
            foreach ($data['customer_access_mappings'] as $mapping) {
                $scope = $mapping['scope'];
                $mappingKey = match ($scope) {
                    'region_specific' => (string) $mapping['region_specific_id'],
                    'group' => (string) $mapping['group_code'],
                    default => $mapping['company_code'],
                };

                foreach ($mapping['users'] as $reference) {
                    $email = $reference['email'];
                    if (! array_key_exists($email, $localUserIdsByEmail)) {
                        $matches = User::query()->get(['id', 'email'])
                            ->filter(fn (User $candidate): bool => $candidate->email === $email)
                            ->values();

                        if ($matches->count() > 1 && $scope !== 'group') {
                            throw new \RuntimeException('Portal returned a mapped User email that matches multiple local Users.');
                        }

                        if ($matches->count() > 1) {
                            continue;
                        }

                        $local = $matches->first();
                        if (! $local) {
                            if ($scope === 'group') {
                                continue;
                            }

                            $attributes = [
                                'name' => $reference['name'],
                                'email' => $email,
                                'password' => Str::random(64),
                            ];
                            if (Schema::hasColumn('users', 'last_name') && array_key_exists('last_name', $reference)) {
                                $attributes['last_name'] = $reference['last_name'];
                            }
                            $local = User::create($attributes);
                        } elseif ($local->name !== $reference['name']
                            || (Schema::hasColumn('users', 'last_name') && array_key_exists('last_name', $reference) && $local->last_name !== $reference['last_name'])) {
                            $attributes = ['name' => $reference['name']];
                            if (Schema::hasColumn('users', 'last_name') && array_key_exists('last_name', $reference)) {
                                $attributes['last_name'] = $reference['last_name'];
                            }
                            $local->update($attributes);
                        }

                        $localUserIdsByEmail[$email] = (int) $local->id;
                    }
                }

                $mappings[] = [
                    'scope' => $scope,
                    'mapping_key' => $mappingKey,
                    'is_complete' => $mapping['is_complete'],
                    'user_references' => json_encode($mapping['users'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('customer_access_mappings')->delete();
            foreach (array_chunk($mappings, 400) as $chunk) {
                DB::table('customer_access_mappings')->insert($chunk);
            }
        }

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

        if (array_key_exists('location_area_cluster_mappings', $data)) {
            DB::table('location_area_cluster_mappings')->delete();
            foreach (array_chunk(array_map(fn (array $mapping): array => [
                'id' => $mapping['id'],
                'location_level' => $mapping['location_level'],
                'location_id' => $mapping['location_id'],
                'decision' => $mapping['decision'],
                'area_cluster_id' => $mapping['area_cluster_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $data['location_area_cluster_mappings']), 400) as $chunk) {
                DB::table('location_area_cluster_mappings')->insert($chunk);
            }
        }

        $incomingCustomers = $data['customers'] ?? [];
        $incomingCustomerIds = array_map('strval', array_column($incomingCustomers, 'id'));
        $incomingCustomerUuids = array_values(array_filter(array_column($incomingCustomers, 'local_uuid')));
        if (count($incomingCustomerIds) !== count(array_unique($incomingCustomerIds))
            || count($incomingCustomerUuids) !== count(array_unique($incomingCustomerUuids))) {
            throw new \RuntimeException('Portal Customer page contains duplicate identity records.');
        }

        $reconciler = app(CustomerAggregateReconciler::class);
        $hasPullContract = array_key_exists('customer_snapshot_contract', $data);
        $contract = $hasPullContract ? $data['customer_snapshot_contract'] : null;
        if ($hasPullContract && (! is_array($contract) || ! $reconciler->supportsCompleteSnapshot($contract))) {
            throw new \RuntimeException('Portal Customer Pull supplied an unsupported aggregate snapshot contract.');
        }

        // Resolve the whole page before mutating any Customer aggregate. This
        // keeps a duplicate or cross-identity collision atomic at the page boundary.
        $identityResolver = app(CustomerIdentityResolver::class);
        $localCustomerIdsByPortalId = [];
        foreach ($incomingCustomers as $customer) {
            $identity = $identityResolver->resolve($customer);
            if ($identity->isConflict() || $identity->localId === null) {
                throw new \RuntimeException('Portal Customer identity conflict: '.($identity->reason ?? 'unresolved').'.');
            }

            $localCustomerIdsByPortalId[(int) $customer['id']] = (int) $identity->localId;
        }

        foreach ($incomingCustomers as $customer) {
            $aggregate = [
                'customers' => [$customer],
                'customer_users' => $data['customer_users'] ?? [],
                'customer_brands' => $data['customer_brands'] ?? [],
                'customer_categories' => $data['customer_categories'] ?? [],
                'customer_trade_profiles' => $data['customer_trade_profiles'] ?? [],
                'customer_category_histories' => $data['customer_category_histories'] ?? [],
                'customer_category_events' => $data['customer_category_events'] ?? [],
            ];
            if ($hasPullContract) {
                $aggregate['customer_snapshot_contract'] = $contract;
            }

            $reconciler->reconcile($aggregate, $localUserIdsByEmail);
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
                $localCustomerId = $this->localCustomerIdForPull($sc['customer_id'], $localCustomerIdsByPortalId);

                // A salescall with unsynced local changes (e.g. a check-in or
                // finish action not yet pushed) must not be clobbered by an
                // incoming pull — the server's copy is stale until the push
                // completes. Leave it untouched; the next push will resolve it.
                $hasPendingLocalChanges = Salescall::where('local_uuid', $localUuid)
                    ->whereIn('sync_status', ['pending', 'failed'])
                    ->exists();

                if ($hasPendingLocalChanges) {
                    $localSalescall = Salescall::where('local_uuid', $localUuid)->first();

                    // Repair a reference written by an older Pull: a pending
                    // Sales Call can carry the Portal Customer ID while the
                    // Customer itself kept a negative local primary key. Do not
                    // overwrite any other pending local changes.
                    if ($localSalescall
                        && (int) $localSalescall->customer_id !== $localCustomerId
                        && ! Customer::query()->whereKey($localSalescall->customer_id)->exists()
                        && Customer::query()->whereKey($localCustomerId)->exists()) {
                        $localSalescall->update(['customer_id' => $localCustomerId]);
                    }
                } else {
                    $localSalescall = Salescall::updateOrCreate(
                        ['local_uuid' => $localUuid],
                        [
                            'server_id' => $sc['id'],
                            'ref_number' => $sc['ref_number'] ?? $sc['id'],
                            'itinerary_id' => $local->id,
                            'customer_id' => $localCustomerId,
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
                                'customer_id' => $localCustomerId,
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
                            'customer_id' => $localCustomerId,
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
                    'customer_id' => $this->localCustomerIdForPull($noteRow['customer_id'], $localCustomerIdsByPortalId),
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

    /** @param array<string, int> $localUserIdsByEmail */
    private function localUserIdForPortalEmail(string $email, array $localUserIdsByEmail): int
    {
        if (! array_key_exists($email, $localUserIdsByEmail)) {
            throw new \RuntimeException('Portal Customer User email is unresolved on this Tablet.');
        }

        return $localUserIdsByEmail[$email];
    }

    /**
     * Translate the Portal Customer ID carried by a Sales Call into the local
     * Customer primary key. A Customer created offline keeps its negative local
     * key after Push, so writing the Portal ID into salescalls.customer_id
     * breaks Customer lookups and every child row that holds the same FK.
     *
     * @param  array<int, int>  $localCustomerIdsByPortalId
     */
    private function localCustomerIdForPull(mixed $portalCustomerId, array $localCustomerIdsByPortalId): int
    {
        $portalId = filter_var($portalCustomerId, FILTER_VALIDATE_INT);

        if ($portalId === false || $portalId < 1) {
            throw new \RuntimeException('Portal pull contains an invalid Customer reference.');
        }

        if (array_key_exists($portalId, $localCustomerIdsByPortalId)) {
            return $localCustomerIdsByPortalId[$portalId];
        }

        $serverMatches = Customer::query()
            ->where('server_id', $portalId)
            ->limit(2)
            ->pluck('id');

        if ($serverMatches->count() > 1) {
            throw new \RuntimeException('Portal pull Customer reference matches multiple local Customers.');
        }

        $localIdWithSamePrimaryKey = Customer::query()->whereKey($portalId)->exists();

        if ($serverMatches->count() === 1) {
            $serverLocalId = (int) $serverMatches->first();

            if ($localIdWithSamePrimaryKey && $serverLocalId !== $portalId) {
                throw new \RuntimeException('Portal pull Customer reference matches both a local primary key and a different local Customer server ID.');
            }

            return $serverLocalId;
        }

        if ($localIdWithSamePrimaryKey) {
            return $portalId;
        }

        Log::warning('sync:salescall:customer-reference-unresolved', [
            'customer_id' => $portalId,
        ]);

        return $portalId;
    }

    /** @return array<int, array<string, mixed>> */
    public function readExpensesForSalescall(int $serverSalescallId): array
    {
        return $this->readExpenseRows("/api/expenses/salescalls/{$serverSalescallId}");
    }

    /** @return array<int, array<string, mixed>> */
    public function readExpenses(): array
    {
        return $this->readExpensesWithStatus()['expenses'];
    }

    /** @return array{expenses: array<int, array<string, mixed>>, failed: bool} */
    public function readExpensesWithStatus(): array
    {
        return $this->requestExpenseRows('/api/expenses/list');
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

    /** @return array{programs: array<int, array<string, mixed>>, failed: bool} */
    public function readCrrProgramsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/crr/programs', 'crr_programs');

        return ['programs' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readCrrPrograms(): array
    {
        return $this->readCrrProgramsWithStatus()['programs'];
    }

    /** @return array{crrs: array<int, array<string, mixed>>, failed: bool} */
    public function readCrrsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/crr/pull', 'crrs');

        return ['crrs' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readCrrs(): array
    {
        return $this->readCrrsWithStatus()['crrs'];
    }

    /** @return array{programs: array<int, array<string, mixed>>, failed: bool} */
    public function readMarketingProgramsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/marketing/programs', 'marketing_programs');

        return ['programs' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readMarketingPrograms(): array
    {
        return $this->readMarketingProgramsWithStatus()['programs'];
    }

    /** @return array{marketings: array<int, array<string, mixed>>, failed: bool} */
    public function readMarketingsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/marketing/pull', 'marketings');

        return ['marketings' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readMarketings(): array
    {
        return $this->readMarketingsWithStatus()['marketings'];
    }

    /** @return array{trade_checks: array<int, array<string, mixed>>, failed: bool} */
    public function readTradeChecksWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/trade-check/pull', 'trade_checks');

        return ['trade_checks' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readTradeChecks(): array
    {
        return $this->readTradeChecksWithStatus()['trade_checks'];
    }

    /** @return array{giveaways: array<int, array<string, mixed>>, failed: bool} */
    public function readGiveawaysWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/giveaway/pull', 'giveaways');

        return ['giveaways' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readGiveaways(): array
    {
        return $this->readGiveawaysWithStatus()['giveaways'];
    }

    /** @return array{repairs: array<int, array<string, mixed>>, failed: bool} */
    public function readRepairsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/repair/pull', 'repairs');

        return ['repairs' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readRepairs(): array
    {
        return $this->readRepairsWithStatus()['repairs'];
    }

    /** @return array{products: array<int, array<string, mixed>>, failed: bool} */
    public function readSignageProductsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/crr-signage/products', 'signage_products');

        return ['products' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array{types: array<int, array<string, mixed>>, failed: bool} */
    public function readSignageTypesWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/crr-signage/types', 'signage_types');

        return ['types' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array{signages: array<int, array<string, mixed>>, failed: bool} */
    public function readSignagesWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/crr-signage/pull', 'signages');

        return ['signages' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readSignages(): array
    {
        return $this->readSignagesWithStatus()['signages'];
    }

    /** @return array{products: array<int, array<string, mixed>>, failed: bool} */
    public function readTradeRequestProductsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/trade-request/products', 'trade_products');

        return ['products' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array{types: array<int, array<string, mixed>>, failed: bool} */
    public function readTradeRequestTypesWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/trade-request/types', 'trade_types');

        return ['types' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array{trade_requests: array<int, array<string, mixed>>, failed: bool} */
    public function readTradeRequestsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/trade-request/pull', 'trade_requests');

        return ['trade_requests' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readTradeRequests(): array
    {
        return $this->readTradeRequestsWithStatus()['trade_requests'];
    }

    /** @return array{topics: array<int, array<string, mixed>>, failed: bool} */
    public function readTrainingTopicsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/training/topics', 'training_topics');

        return ['topics' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array{positions: array<int, array<string, mixed>>, failed: bool} */
    public function readTrainingPositionsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/training/positions', 'training_positions');

        return ['positions' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array{trainings: array<int, array<string, mixed>>, failed: bool} */
    public function readTrainingsWithStatus(): array
    {
        $result = $this->requestFormRows('/api/forms/training/pull', 'trainings');

        return ['trainings' => $result['rows'], 'failed' => $result['failed']];
    }

    /** @return array<int, array<string, mixed>> */
    public function readTrainings(): array
    {
        return $this->readTrainingsWithStatus()['trainings'];
    }

    /** @return array{rows: array<int, array<string, mixed>>, failed: bool} */
    private function requestFormRows(string $path, string $key): array
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token) || blank($this->serverUrl)) {
            return ['rows' => [], 'failed' => true];
        }

        try {
            $response = $this->client($user->api_token)->get("{$this->serverUrl}{$path}");

            if (! $response->successful()) {
                Log::warning('Form read failed.', ['path' => $path, 'status' => $response->status()]);

                return ['rows' => [], 'failed' => true];
            }

            $rows = $response->json($key);

            if (! is_array($rows)) {
                return ['rows' => [], 'failed' => true];
            }

            return ['rows' => array_values(array_filter($rows, 'is_array')), 'failed' => false];
        } catch (Throwable $exception) {
            Log::warning('Form read request failed.', ['path' => $path, 'message' => $exception->getMessage()]);

            return ['rows' => [], 'failed' => true];
        }
    }

    public function push(): SyncResult
    {
        $lock = Cache::lock('sync:push', 900);

        if (! $lock->get()) {
            return SyncResult::fail('A sync is already in progress. Please wait for it to finish.', 'sync_in_progress');
        }

        try {
            return $this->performPush();
        } finally {
            $lock->release();
        }
    }

    private function performPush(): SyncResult
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
            $customerResult = $this->pushPendingCustomers();
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
                    $response = $client->post("{$this->serverUrl}/api/itineraries/push", [
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
                            'endpoint' => '/api/itineraries/push',
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
                ->whereIn('sync_status', ['pending', 'failed'])
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
                    $response = $client->post("{$this->serverUrl}/api/salescalls/push", [
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
                            'endpoint' => '/api/salescalls/push',
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
                    $response = $client->post("{$this->serverUrl}/api/expenses/push", [
                        'local_uuid' => $expense->local_uuid,
                        'salescall_server_id' => $expense->salescall->server_id,
                        'expense_type_code' => $expense->expenseType?->code,
                        // The Portal API accepts currency values with at most two decimals.
                        'amount' => number_format((float) $expense->amount, 2, '.', ''),
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
                        'attachment_count' => $expense->attachments()->count(),
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
                            'endpoint' => '/api/expenses/push',
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

                if ($result['busy'] ?? false) {
                    continue;
                }

                if ($result['success']) {
                    $pushed++;
                } else {
                    $failed++;
                    $retryable++;
                    $failureReasons[$result['reason']] = true;
                }
            }

            $pendingCrrs = Crr::with(['program', 'salescall'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingCrrs as $crr) {
                if (! $crr->program?->server_id) {
                    continue; // wait until the program is available locally
                }

                try {
                    $response = $client->post("{$this->serverUrl}/api/forms/crr/push", [
                        'server_id' => $crr->server_id,
                        'client_uuid' => $crr->local_uuid,
                        'salescall_uuid' => $crr->salescall?->local_uuid,
                        'crr_program_id' => $crr->program->server_id,
                        'date_implemented' => $crr->date_implemented,
                        'amount' => $crr->amount,
                        'details' => $crr->details,
                        'remarks' => $crr->remarks,
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($crr, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($crr, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'crr:portal',
                            'endpoint' => '/api/forms/crr/push',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($crr, $e, 'crr:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingCrrAttachments = CrrAttachment::with('crr')
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingCrrAttachments as $attachment) {
                if (! $attachment->crr?->server_id) {
                    continue; // wait for the CRR to sync first
                }

                $result = $this->pushCrrAttachmentItem($client, $attachment);

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

            $pendingMarketings = Marketing::with(['program', 'salescall', 'volumes', 'realizations', 'mechanics'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingMarketings as $marketing) {
                if (! $marketing->program?->server_id) {
                    continue; // wait until the program is available locally
                }

                try {
                    $response = $client->post("{$this->serverUrl}/api/forms/marketing/push", [
                        'server_id' => $marketing->server_id,
                        'client_uuid' => $marketing->local_uuid,
                        'salescall_uuid' => $marketing->salescall?->local_uuid,
                        'program_id' => $marketing->program->server_id,
                        'region_type_id' => $marketing->region_type_id,
                        'duration_start_at' => $marketing->duration_start_at?->toDateTimeString(),
                        'duration_end_at' => $marketing->duration_end_at?->toDateTimeString(),
                        'objective' => $marketing->objective,
                        'name' => $marketing->name,
                        'mechanics_description' => $marketing->mechanics_description,
                        'submitted_at' => $marketing->submitted_at?->toDateTimeString(),
                        'volumes' => $marketing->volumes->map(fn ($volume): array => [
                            'before_program' => $volume->before_program,
                            'during_program' => $volume->during_program,
                            'volume' => $volume->volume,
                        ])->values()->all(),
                        'realizations' => $marketing->realizations->map(fn ($realization): array => [
                            'stage' => $realization->stage->value,
                            'start_at' => $realization->start_at?->toDateTimeString(),
                            'end_at' => $realization->end_at?->toDateTimeString(),
                            'volume' => $realization->volume,
                        ])->values()->all(),
                        'mechanics' => $marketing->mechanics->map(fn ($mechanic): array => [
                            'period' => $mechanic->period?->toDateTimeString(),
                            'unit_price' => $mechanic->unit_price,
                            'sub_total' => $mechanic->sub_total,
                        ])->values()->all(),
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($marketing, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($marketing, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'marketing:portal',
                            'endpoint' => '/api/forms/marketing/push',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($marketing, $e, 'marketing:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingTradeChecks = TradeCheck::with(['group.items.answerItem', 'salescall'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingTradeChecks as $tradeCheck) {
                try {
                    $response = $client->post("{$this->serverUrl}/api/forms/trade-check/push", [
                        'server_id' => $tradeCheck->server_id,
                        'client_uuid' => $tradeCheck->local_uuid,
                        'salescall_uuid' => $tradeCheck->salescall?->local_uuid,
                        'name' => $tradeCheck->name,
                        'groups' => $tradeCheck->group
                            ->sortBy('order')
                            ->values()
                            ->map(fn ($group): array => [
                                'name' => $group->name,
                                'category' => $group->category->value,
                                'description' => $group->description,
                                'order' => $group->order,
                                'items' => $group->items
                                    ->sortBy('order')
                                    ->values()
                                    ->map(fn ($item): array => [
                                        'name' => $item->name,
                                        'description' => $item->description,
                                        'type' => $item->type->value,
                                        'is_required' => $item->is_required,
                                        'is_image_required' => $item->is_image_required,
                                        'options' => $item->options,
                                        'order' => $item->order,
                                        'answer' => $item->answerItem?->answer,
                                    ])
                                    ->all(),
                            ])
                            ->all(),
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($tradeCheck, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($tradeCheck, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'trade-check:portal',
                            'endpoint' => '/api/forms/trade-check/push',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($tradeCheck, $e, 'trade-check:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingTradeCheckAttachments = TradeCheckAttachment::with(['tradeCheck', 'item'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingTradeCheckAttachments as $attachment) {
                if (! $attachment->tradeCheck?->server_id) {
                    continue; // wait for the Trade Check to sync first
                }

                $result = $this->pushTradeCheckAttachmentItem($client, $attachment);

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

            $pendingGiveaways = Giveaway::with(['items.answerItem', 'salescall'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingGiveaways as $giveaway) {
                try {
                    $response = $client->post("{$this->serverUrl}/api/forms/giveaway/push", [
                        'server_id' => $giveaway->server_id,
                        'client_uuid' => $giveaway->local_uuid,
                        'salescall_uuid' => $giveaway->salescall?->local_uuid,
                        'name' => $giveaway->name,
                        'items' => $giveaway->items
                            ->sortBy('id')
                            ->values()
                            ->map(fn ($item): array => [
                                'category' => $item->category->value,
                                'name' => $item->name,
                                'answer' => $item->answerItem?->answer,
                            ])
                            ->all(),
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($giveaway, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($giveaway, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'giveaway:portal',
                            'endpoint' => '/api/forms/giveaway/push',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($giveaway, $e, 'giveaway:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingGiveawaySignatures = GiveawaySignature::with(['giveaway', 'answer.item'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingGiveawaySignatures as $signature) {
                if (! $signature->giveaway?->server_id) {
                    continue; // wait for the Giveaway to sync first
                }

                $result = $this->pushGiveawaySignatureItem($client, $signature);

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

            $pendingRepairs = Repair::with('salescall')
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingRepairs as $repair) {
                try {
                    $response = $client->post("{$this->serverUrl}/api/forms/repair/push", [
                        'server_id' => $repair->server_id,
                        'client_uuid' => $repair->local_uuid,
                        'salescall_uuid' => $repair->salescall?->local_uuid,
                        'plate_number' => $repair->plate_number,
                        'invoice_number' => $repair->invoice_number,
                        'details' => $repair->details,
                        'amount' => $repair->amount,
                        'remarks' => $repair->remarks,
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($repair, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($repair, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'repair:portal',
                            'endpoint' => '/api/forms/repair/push',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($repair, $e, 'repair:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingRepairAttachments = RepairAttachment::with('repair')
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingRepairAttachments as $attachment) {
                if (! $attachment->repair?->server_id) {
                    continue; // wait for the Repair to sync first
                }

                $result = $this->pushRepairAttachmentItem($client, $attachment);

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

            $pendingSignages = Signage::with(['product', 'type', 'salescall'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingSignages as $signage) {
                try {
                    $response = $client->post("{$this->serverUrl}/api/forms/crr-signage/push", [
                        'server_id' => $signage->server_id,
                        'client_uuid' => $signage->local_uuid,
                        'salescall_uuid' => $signage->salescall?->local_uuid,
                        'product_id' => $signage->product?->server_id,
                        'type_id' => $signage->type?->server_id,
                        'category' => $signage->category->value,
                        'name' => $signage->name,
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($signage, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($signage, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'signage:portal',
                            'endpoint' => '/api/forms/crr-signage/push',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($signage, $e, 'signage:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingSignageAttachments = SignageAttachment::with('signage')
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingSignageAttachments as $attachment) {
                if (! $attachment->signage?->server_id) {
                    continue; // wait for the Signage to sync first
                }

                $result = $this->pushSignageAttachmentItem($client, $attachment);

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

            $pendingTradeRequests = TradeRequest::with(['product', 'items.tradeType', 'salescall'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingTradeRequests as $tradeRequest) {
                try {
                    $response = $client->post("{$this->serverUrl}/api/forms/trade-request/push", [
                        'server_id' => $tradeRequest->server_id,
                        'client_uuid' => $tradeRequest->local_uuid,
                        'salescall_uuid' => $tradeRequest->salescall?->local_uuid,
                        'product_id' => $tradeRequest->product?->server_id,
                        'items' => $tradeRequest->items
                            ->sortBy('id')
                            ->values()
                            ->map(fn ($item): array => [
                                'trade_type_id' => $item->tradeType?->server_id,
                                'quantity' => $item->quantity,
                                'size' => $item->size?->value,
                                'description' => $item->description,
                            ])
                            ->all(),
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($tradeRequest, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($tradeRequest, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'trade-request:portal',
                            'endpoint' => '/api/forms/trade-request/push',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($tradeRequest, $e, 'trade-request:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingTradeRequestAttachments = TradeRequestAttachment::with('tradeRequest')
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingTradeRequestAttachments as $attachment) {
                if (! $attachment->tradeRequest?->server_id) {
                    continue; // wait for the Trade Request to sync first
                }

                $result = $this->pushTradeRequestAttachmentItem($client, $attachment);

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

            $pendingTrainings = Training::with(['topic', 'participants.position', 'salescall'])
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingTrainings as $training) {
                try {
                    $response = $client->post("{$this->serverUrl}/api/forms/training/push", [
                        'server_id' => $training->server_id,
                        'client_uuid' => $training->local_uuid,
                        'salescall_uuid' => $training->salescall?->local_uuid,
                        'topic_id' => $training->topic?->server_id,
                        'other_topic' => $training->other_topic,
                        'preferred_at' => $training->preferred_at?->toDateTimeString(),
                        'omcc_volume' => $training->omcc_volume,
                        'competitor_volume' => $training->competitor_volume,
                        'participants' => $training->participants
                            ->sortBy('id')
                            ->values()
                            ->map(fn ($participant): array => [
                                'name' => $participant->name,
                                'position_id' => $participant->position?->server_id,
                            ])
                            ->all(),
                    ]);

                    if ($response->status() === 401) {
                        return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired', $pushed, $failed, $retryable, array_keys($failureReasons));
                    }

                    $serverId = $response->json('server_id');
                    if ($response->successful() && $serverId !== null) {
                        $this->markSynced($training, [
                            'server_id' => $serverId,
                            'synced_at' => now(),
                        ]);
                        $pushed++;
                    } else {
                        $this->recordItemFailure($training, 'portal_rejected', $response->status().': '.$this->trimRemoteError($response->body()), [
                            'stage' => 'training:portal',
                            'endpoint' => '/api/forms/training/push',
                            'http_status' => $response->status(),
                        ]);
                        $failed++;
                        $retryable++;
                        $failureReasons['portal_rejected'] = true;
                    }
                } catch (Throwable $e) {
                    $this->recordUnexpectedItemFailure($training, $e, 'training:unexpected');
                    $failed++;
                    $retryable++;
                    $failureReasons['unexpected_sync_error'] = true;
                }
            }

            $pendingTrainingAttachments = TrainingAttachment::with('training')
                ->where(function ($q) {
                    $q->where('sync_status', 'pending')
                        ->orWhere(fn ($q2) => $q2->where('sync_status', 'failed')->where('sync_attempts', '<', 3));
                })
                ->get();

            foreach ($pendingTrainingAttachments as $attachment) {
                if (! $attachment->training?->server_id) {
                    continue; // wait for the Training to sync first
                }

                $result = $this->pushTrainingAttachmentItem($client, $attachment);

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
                    $response = $client->post("{$this->serverUrl}/api/salescalls/push-brands", [
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
                                'endpoint' => '/api/salescalls/push-brands',
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
                    $response = $client->post("{$this->serverUrl}/api/salescalls/push-category", [
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
                            'endpoint' => '/api/salescalls/push-category',
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
                ->whereIn('sync_status', ['pending', 'failed'])
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
                ->whereIn('sync_status', ['pending', 'failed'])
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
                ->whereIn('sync_status', ['pending', 'failed'])
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

            $pendingNotes = CustomerNote::whereIn('sync_status', ['pending', 'failed'])->get();

            foreach ($pendingNotes as $note) {
                try {
                    $response = $client->post("{$this->serverUrl}/api/customer-notes/push", [
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
                            'endpoint' => '/api/customer-notes/push',
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
                    $response = $client->post("{$this->serverUrl}/api/base-locations/push", [
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

        return SyncResult::aggregate($pushed, $failed, $retryable, array_keys($failureReasons));
    }

    public function pushCustomer(int $customerId): SyncResult
    {
        return app(CustomerPushService::class)->pushCustomer($customerId);
    }

    public function pushPendingCustomers(): SyncResult
    {
        return app(CustomerPushService::class)->pushPendingCustomers();
    }

    public function retryExhaustedCustomer(int $customerId): SyncResult
    {
        return app(CustomerPushService::class)->retryExhaustedCustomer($customerId);
    }

    public function customerPayloadFingerprint(Customer $customer): string
    {
        return app(CustomerPushService::class)->customerPayloadFingerprint($customer);
    }

    public function hasPendingCustomerPushWork(): bool
    {
        return app(CustomerPushService::class)->hasPendingCustomerPushWork();
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
                ->post("{$this->serverUrl}/api/customer-notes/delete", ['local_uuid' => $localUuid]);
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

    private function markSynced(Model $model, array $attributes = [], bool $clearError = true): void
    {
        $model->update([
            ...$attributes,
            'sync_status' => 'synced',
            'sync_error' => $clearError ? null : $model->sync_error,
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
                    ->post("{$this->serverUrl}/api/salescalls/push-image", [
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
                    'endpoint' => '/api/salescalls/push-image',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($image, [
                'server_id' => $response->json('server_id'),
                's3_key' => null,
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($image, $classification, $e, [
                'stage' => 'salescall-image:unexpected',
                'endpoint' => '/api/salescalls/push-image',
                'local_path' => $image->local_path,
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
                    ->post("{$this->serverUrl}/api/customer-profiles/push-attachment", [
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
                    'endpoint' => '/api/customer-profiles/push-attachment',
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
                'endpoint' => '/api/customer-profiles/push-attachment',
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
        $lock = Cache::lock('expense-attachment-sync:'.$attachment->id, 180);

        if (! $lock->get()) {
            return ['success' => false, 'reason' => 'retry_in_progress', 'busy' => true];
        }

        try {
            $attachment->refresh()->load('expense');

            if ($attachment->sync_status === 'synced') {
                return ['success' => true];
            }

            return $this->uploadExpenseAttachmentItem($client, $attachment);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function uploadExpenseAttachmentItem(PendingRequest $client, ExpenseAttachment $attachment): array|SyncResult
    {
        try {
            if (! $attachment->expense || ! $attachment->expense->server_id) {
                $this->recordItemFailure($attachment, 'expense_not_synced', 'The associated Expense is not available on Portal yet.', [
                    'stage' => 'expense-attachment:expense',
                    'endpoint' => '/api/expenses/push-attachment',
                ]);

                return ['success' => false, 'reason' => 'expense_not_synced'];
            }

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
                    ->post("{$this->serverUrl}/api/expenses/push-attachment", [
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
                    'endpoint' => '/api/expenses/push-attachment',
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
                'endpoint' => '/api/expenses/push-attachment',
                'local_path' => $attachment->local_path,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushCrrAttachmentItem(PendingRequest $client, CrrAttachment $attachment): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($attachment->local_path)) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: '.$this->displayPath($attachment->local_path), [
                    'stage' => 'crr-attachment:local-file',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $stream = fopen($attachment->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($attachment->local_path), [
                    'stage' => 'crr-attachment:local-open',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($attachment->local_path))
                    ->post("{$this->serverUrl}/api/forms/crr/attach", [
                        'server_id' => $attachment->crr->server_id,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired');
            }

            $serverId = $response->json('uploaded_file_id') ?? $response->json('server_id');
            if (! $response->successful() || $serverId === null) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($attachment, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'crr-attachment:portal',
                    'endpoint' => '/api/forms/crr/attach',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($attachment, [
                'server_id' => $serverId,
                'synced_at' => now(),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($attachment, $classification, $e, [
                'stage' => 'crr-attachment:unexpected',
                'endpoint' => '/api/forms/crr/attach',
                'local_path' => $attachment->local_path,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushTradeCheckAttachmentItem(PendingRequest $client, TradeCheckAttachment $attachment): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($attachment->local_path)) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: '.$this->displayPath($attachment->local_path), [
                    'stage' => 'trade-check-attachment:local-file',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $stream = fopen($attachment->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($attachment->local_path), [
                    'stage' => 'trade-check-attachment:local-open',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($attachment->local_path))
                    ->post("{$this->serverUrl}/api/forms/trade-check/attach", [
                        'server_id' => $attachment->tradeCheck->server_id,
                        'item_ref' => $attachment->item?->name,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired');
            }

            $serverId = $response->json('uploaded_file_id') ?? $response->json('server_id');
            if (! $response->successful() || $serverId === null) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($attachment, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'trade-check-attachment:portal',
                    'endpoint' => '/api/forms/trade-check/attach',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($attachment, [
                'server_id' => $serverId,
                'synced_at' => now(),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($attachment, $classification, $e, [
                'stage' => 'trade-check-attachment:unexpected',
                'endpoint' => '/api/forms/trade-check/attach',
                'local_path' => $attachment->local_path,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushGiveawaySignatureItem(PendingRequest $client, GiveawaySignature $signature): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($signature->local_path)) {
                $this->recordItemFailure($signature, 'local_file_missing', 'local_file_missing: '.$this->displayPath($signature->local_path), [
                    'stage' => 'giveaway-signature:local-file',
                    'local_path' => $signature->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $stream = fopen($signature->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($signature, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($signature->local_path), [
                    'stage' => 'giveaway-signature:local-open',
                    'local_path' => $signature->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($signature->local_path))
                    ->post("{$this->serverUrl}/api/forms/giveaway/attach", [
                        'server_id' => $signature->giveaway->server_id,
                        'item_ref' => $signature->answer?->item?->name,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired');
            }

            $serverId = $response->json('server_id') ?? $response->json('uploaded_file_id');
            if (! $response->successful() || $serverId === null) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($signature, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'giveaway-signature:portal',
                    'endpoint' => '/api/forms/giveaway/attach',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($signature, [
                'server_id' => $serverId,
                'synced_at' => now(),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($signature, $classification, $e, [
                'stage' => 'giveaway-signature:unexpected',
                'endpoint' => '/api/forms/giveaway/attach',
                'local_path' => $signature->local_path,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushRepairAttachmentItem(PendingRequest $client, RepairAttachment $attachment): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($attachment->local_path)) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: '.$this->displayPath($attachment->local_path), [
                    'stage' => 'repair-attachment:local-file',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $stream = fopen($attachment->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($attachment->local_path), [
                    'stage' => 'repair-attachment:local-open',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($attachment->local_path))
                    ->post("{$this->serverUrl}/api/forms/repair/attach", [
                        'server_id' => $attachment->repair->server_id,
                        'type' => $attachment->type->value,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired');
            }

            $serverId = $response->json('server_id') ?? $response->json('uploaded_file_id');
            if (! $response->successful() || $serverId === null) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($attachment, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'repair-attachment:portal',
                    'endpoint' => '/api/forms/repair/attach',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($attachment, [
                'server_id' => $serverId,
                'synced_at' => now(),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($attachment, $classification, $e, [
                'stage' => 'repair-attachment:unexpected',
                'endpoint' => '/api/forms/repair/attach',
                'local_path' => $attachment->local_path,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushSignageAttachmentItem(PendingRequest $client, SignageAttachment $attachment): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($attachment->local_path)) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: '.$this->displayPath($attachment->local_path), [
                    'stage' => 'signage-attachment:local-file',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $stream = fopen($attachment->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($attachment->local_path), [
                    'stage' => 'signage-attachment:local-open',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($attachment->local_path))
                    ->post("{$this->serverUrl}/api/forms/crr-signage/attach", [
                        'server_id' => $attachment->signage->server_id,
                        'type' => $attachment->type->value,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired');
            }

            $serverId = $response->json('server_id') ?? $response->json('uploaded_file_id');
            if (! $response->successful() || $serverId === null) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($attachment, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'signage-attachment:portal',
                    'endpoint' => '/api/forms/crr-signage/attach',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($attachment, [
                'server_id' => $serverId,
                'synced_at' => now(),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($attachment, $classification, $e, [
                'stage' => 'signage-attachment:unexpected',
                'endpoint' => '/api/forms/crr-signage/attach',
                'local_path' => $attachment->local_path,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushTradeRequestAttachmentItem(PendingRequest $client, TradeRequestAttachment $attachment): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($attachment->local_path)) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: '.$this->displayPath($attachment->local_path), [
                    'stage' => 'trade-request-attachment:local-file',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $stream = fopen($attachment->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($attachment->local_path), [
                    'stage' => 'trade-request-attachment:local-open',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($attachment->local_path))
                    ->post("{$this->serverUrl}/api/forms/trade-request/attach", [
                        'server_id' => $attachment->tradeRequest->server_id,
                        'type' => $attachment->type->value,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired');
            }

            $serverId = $response->json('server_id') ?? $response->json('uploaded_file_id');
            if (! $response->successful() || $serverId === null) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($attachment, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'trade-request-attachment:portal',
                    'endpoint' => '/api/forms/trade-request/attach',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($attachment, [
                'server_id' => $serverId,
                'synced_at' => now(),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($attachment, $classification, $e, [
                'stage' => 'trade-request-attachment:unexpected',
                'endpoint' => '/api/forms/trade-request/attach',
                'local_path' => $attachment->local_path,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
    }

    /**
     * @return array{success: bool, reason?: string}|SyncResult
     */
    private function pushTrainingAttachmentItem(PendingRequest $client, TrainingAttachment $attachment): array|SyncResult
    {
        try {
            if (! $this->isReadableLocalFile($attachment->local_path)) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: '.$this->displayPath($attachment->local_path), [
                    'stage' => 'training-attachment:local-file',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            $stream = fopen($attachment->local_path, 'r');

            if ($stream === false) {
                $this->recordItemFailure($attachment, 'local_file_missing', 'local_file_missing: unable to open '.$this->displayPath($attachment->local_path), [
                    'stage' => 'training-attachment:local-open',
                    'local_path' => $attachment->local_path,
                ]);

                return ['success' => false, 'reason' => 'local_file_missing'];
            }

            try {
                $response = $client
                    ->attach('file', $stream, basename($attachment->local_path))
                    ->post("{$this->serverUrl}/api/forms/training/attach", [
                        'server_id' => $attachment->training->server_id,
                        'type' => $attachment->type->value,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->status() === 401) {
                return SyncResult::fail('Session expired. Please log out and log in again.', 'token_expired');
            }

            $serverId = $response->json('server_id') ?? $response->json('uploaded_file_id');
            if (! $response->successful() || $serverId === null) {
                $failure = $response->failed() && $response->status() >= 500
                    ? 'portal_upload_failed'
                    : 'portal_rejected';

                $this->recordItemFailure($attachment, $failure, $response->status().': '.$this->trimRemoteError($response->body()), [
                    'stage' => 'training-attachment:portal',
                    'endpoint' => '/api/forms/training/attach',
                    'http_status' => $response->status(),
                ]);

                return ['success' => false, 'reason' => $failure];
            }

            $this->markSynced($attachment, [
                'server_id' => $serverId,
                'synced_at' => now(),
            ]);

            return ['success' => true];
        } catch (Throwable $e) {
            $classification = $this->classifyBinaryThrowable($e);
            $this->recordThrowableFailure($attachment, $classification, $e, [
                'stage' => 'training-attachment:unexpected',
                'endpoint' => '/api/forms/training/attach',
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

            $response = $client->post("{$this->serverUrl}/api/customer-profiles/push", [
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
                    'endpoint' => '/api/customer-profiles/push',
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
                'endpoint' => '/api/customer-profiles/push',
                'local_path' => $profile->signature_path,
                's3_key' => $profile->signature_s3_key,
            ]);

            return ['success' => false, 'reason' => $classification];
        }
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
        app(SyncFailureRecorder::class)->recordUnexpectedItemFailure($model, $e, $stage);
    }

    private function recordThrowableFailure(Model $model, string $classification, Throwable $e, array $context = []): void
    {
        app(SyncFailureRecorder::class)->recordThrowableFailure($model, $classification, $e, $context);
    }

    private function recordItemFailure(Model $model, string $classification, string $error, array $context = []): void
    {
        app(SyncFailureRecorder::class)->recordItemFailure($model, $classification, $error, $context);
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    private function recordCollectionFailure(Collection $models, string $classification, string $error, array $context = []): void
    {
        app(SyncFailureRecorder::class)->recordCollectionFailure($models, $classification, $error, $context);
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    private function recordCollectionUnexpectedFailure(Collection $models, Throwable $e, string $stage, array $context = []): void
    {
        app(SyncFailureRecorder::class)->recordCollectionUnexpectedFailure($models, $e, $stage, $context);
    }

    private function trimRemoteError(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return 'Empty response body';
        }

        return mb_substr($trimmed, 0, 300);
    }

    private function displayPath(?string $path): string
    {
        return is_string($path) && $path !== '' ? $path : '(missing path)';
    }
}
