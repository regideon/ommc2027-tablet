<?php

use App\Filament\Pages\CustomerCreatePage;
use App\Filament\Pages\CustomerPage;
use App\Models\User;
use App\Services\CustomerAccessMappingResolver;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);

    $this->user = User::factory()->create(['api_token' => 'test-token']);
    DB::table('companies')->insert(['id' => 1, 'name' => 'OMMC', 'code' => 'OMMC', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->user);

    $this->requests = [];
    $this->makeLocationPayload = function (array $sections = []): array {
        $sections = array_replace(array_fill_keys(['regions', 'region_specifics', 'area_clusters', 'provinces', 'municipalities', 'barangays'], []), $sections);

        return [
            'reference_contract_version' => 2,
            ...$sections,
            'customer_access_mappings' => [],
            'reference_counts' => array_map('count', $sections),
        ];
    };

    $this->customer = fn (int $id, ?string $name = null): array => [
        'id' => $id,
        'company_id' => 1,
        'name' => $name ?? "Customer {$id}",
        'is_active' => true,
        'updated_at' => '2026-10-05T00:00:00.000000Z',
    ];

    /**
     * Fakes the portal pull endpoints. $customerPages maps an after_id (or
     * "ids") to the customers response for that request.
     */
    $this->fakePortal = function (array $customerPages): void {
        $this->customerPages = $customerPages;

        Http::fake(function (Request $request) {
            $customerPages = $this->customerPages;
            $url = parse_url($request->url());
            parse_str($url['query'] ?? '', $query);
            $this->requests[] = ['path' => $url['path'], 'query' => $query];

            return match ($url['path']) {
                '/api/sync/pull/locations' => Http::response($this->locationPayload ?? ($this->makeLocationPayload)(['municipalities' => [['id' => 1, 'region_id' => null, 'province_id' => null, 'name' => 'M1', 'enabled' => true]]])),
                '/api/sync/pull/customers' => $customerPages[isset($query['ids']) ? 'ids' : (int) ($query['after_id'] ?? 0)],
                default => Http::response([], 404),
            };
        });
    };

    $this->runToCompletion = function (): array {
        $steps = [];

        do {
            $step = app(SyncService::class)->pullCustomersStep();
            $steps[] = $step;
        } while ($step['success'] && ! $step['done'] && count($steps) < 20);

        return $steps;
    };
});

test('a first customer pull fetches locations, pages through customers and records the user\'s scope', function () {
    // A synced customer outside this user's scope (another rep's on a shared
    // tablet), and one with unsynced local edits that the pull must not touch.
    DB::table('customers')->insert([
        ['id' => 50, 'server_id' => 50, 'name' => 'Left scope', 'is_active' => true, 'sync_status' => 'synced'],
        ['id' => 60, 'server_id' => 60, 'name' => 'Local edit', 'is_active' => true, 'sync_status' => 'pending'],
    ]);

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [($this->customer)(1), ($this->customer)(2)],
            'customer_category_histories' => [['customer_id' => 2, 'category_year' => 2026, 'category' => 'AB Loyal']],
            'next_after_id' => 2,
            'total' => 3,
            'server_time' => '2026-10-05T01:00:00.000000Z',
            'scope_ids' => null,
        ]),
        2 => Http::response([
            'customers' => [($this->customer)(3)],
            'next_after_id' => null,
            'total' => null,
            'server_time' => '2026-10-05T01:00:05.000000Z',
            'scope_ids' => [1, 2, 3, 60],
        ]),
    ]);

    expect(app(SyncService::class)->customerPullPending())->toBeTrue();

    $steps = ($this->runToCompletion)();

    expect(collect($steps)->pluck('success')->all())->toBe([true, true, true])
        ->and(end($steps))->toMatchArray(['done' => true, 'pulled' => 3, 'returned' => 3, 'total' => 3])
        ->and(end($steps)['message'])->toContain('3 returned; 3 inserted, 0 changed, 0 unchanged, 0 rejected')
        ->and(collect($this->requests)->pluck('path')->all())->toBe(['/api/sync/pull/locations', '/api/sync/pull/customers', '/api/sync/pull/customers'])
        ->and($this->requests[1]['query'])->toBe(['after_id' => '0', 'limit' => '100'])
        ->and(DB::table('municipalities')->count())->toBe(1)
        ->and(DB::table('customers')->whereIn('server_id', [1, 2, 3])->where('is_active', true)->count())->toBe(3)
        ->and(DB::table('customer_category_histories')->where('customer_id', 2)->value('category'))->toBe('AB Loyal')
        // Out-of-scope customers are left alone: they may belong to another rep.
        ->and(DB::table('customers')->where('id', 50)->value('is_active'))->toBeTruthy()
        ->and((array) DB::table('customers')->where('id', 60)->first())->toMatchArray(['name' => 'Local edit', 'sync_status' => 'pending'])
        ->and(DB::table('customer_scopes')->where('user_id', $this->user->id)->orderBy('customer_id')->pluck('customer_id')->all())
        ->toBe(DB::table('customers')->whereIn('server_id', [1, 2, 3, 60])->orderBy('id')->pluck('id')->all())
        ->and(app(SyncService::class)->customerPullPending())->toBeFalse();
});

test('Customer Pull inserts new Portal Customers and applies changed rows across keyset pages', function () {
    DB::table('customers')->insert([
        ['id' => -91002, 'server_id' => 91002, 'company_id' => 1, 'name' => 'Before update', 'is_active' => true, 'sync_status' => 'synced'],
        ['id' => -91003, 'server_id' => 91003, 'company_id' => 1, 'name' => 'Unchanged customer', 'is_active' => true, 'sync_status' => 'synced'],
    ]);
    DB::table('sync_states')->insert([
        'key' => 'customers.pulled_through.'.$this->user->id,
        'value' => json_encode('2026-10-09T00:00:00.000000Z'),
    ]);

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [($this->customer)(91001, 'New Portal Customer')],
            'next_after_id' => 91001,
            'total' => 3,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => null,
        ]),
        91001 => Http::response([
            'customers' => [
                ($this->customer)(91002, 'Updated from Portal'),
                ($this->customer)(91003, 'Unchanged customer'),
            ],
            'next_after_id' => null,
            'total' => null,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [91001, 91002, 91003],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $final = end($steps);

    expect($final)->toMatchArray([
        'success' => true,
        'done' => true,
        'returned' => 3,
        'inserted' => 1,
        'updated' => 1,
        'unchanged' => 1,
        'rejected' => 0,
    ])->and($final['message'])->toContain('1 inserted, 1 changed, 1 unchanged, 0 rejected')
        ->and($this->requests[1]['query'])->toMatchArray(['after_id' => '0', 'updated_since' => '2026-10-09T00:00:00.000000Z'])
        ->and($this->requests[2]['query'])->toMatchArray(['after_id' => '91001'])
        ->and(DB::table('customers')->where('server_id', 91001)->count())->toBe(1)
        ->and(DB::table('customers')->where('server_id', 91001)->value('name'))->toBe('New Portal Customer')
        ->and(DB::table('customers')->where('server_id', 91002)->value('name'))->toBe('Updated from Portal')
        ->and(DB::table('customers')->where('server_id', 91003)->value('name'))->toBe('Unchanged customer');
});

test('versioned Customer Pull applies Portal values to the stable local identity idempotently', function () {
    $localId = -99101;
    $portalId = 99101;
    $uuid = (string) Str::uuid();
    DB::table('customers')->insert([
        'id' => $localId,
        'server_id' => $portalId,
        'local_uuid' => $uuid,
        'company_id' => 1,
        'name' => 'Before Portal refresh',
        'unique_id' => 'LOCAL-CODE',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = [
        'customers' => [[
            'id' => $portalId,
            'local_uuid' => $uuid,
            'company_id' => 1,
            'name' => 'Portal-authoritative name',
            'unique_id' => 'PORTAL-CODE',
            'is_active' => true,
            'person_in_charge_email' => null,
            'access_user_emails' => [],
            'updated_at' => '2026-10-10T00:00:00.000000Z',
        ]],
        'customer_users' => [],
        'customer_brands' => [],
        'customer_categories' => [],
        'customer_trade_profiles' => [],
        'customer_category_histories' => [],
        'customer_category_events' => [],
        'customer_snapshot_contract' => [
            'contract_version' => 1,
            'capabilities' => ['customer.aggregate.snapshot.v1'],
            'complete_collections' => [
                'customers', 'customer_users', 'customer_brands', 'customer_categories',
                'customer_trade_profiles', 'customer_category_histories', 'customer_category_events',
            ],
        ],
        'next_after_id' => null,
        'total' => 1,
        'server_time' => '2026-10-10T00:00:00.000000Z',
        'scope_ids' => [$portalId],
    ];
    ($this->fakePortal)([0 => Http::response($page)]);
    $firstRun = ($this->runToCompletion)();
    $first = DB::table('customers')->where('id', $localId)->first();

    ($this->fakePortal)([0 => Http::response($page)]);
    $secondRun = ($this->runToCompletion)();
    $second = DB::table('customers')->where('id', $localId)->first();

    expect(end($firstRun)['success'])->toBeTrue()
        ->and(end($secondRun)['success'])->toBeTrue()
        ->and(DB::table('customers')->where('server_id', $portalId)->count())->toBe(1)
        ->and($second->id)->toBe($localId)
        ->and($second->local_uuid)->toBe($uuid)
        ->and($second->name)->toBe('Portal-authoritative name')
        ->and($second->unique_id)->toBe('PORTAL-CODE')
        ->and(DB::table('customer_scopes')->where('user_id', $this->user->id)->where('customer_id', $localId)->exists())->toBeTrue();
});

test('a successful empty Customer Pull reports success with zero diagnostics counts', function () {
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [],
            'next_after_id' => null,
            'total' => 0,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $final = end($steps);

    expect($final)->toMatchArray([
        'success' => true,
        'done' => true,
        'customer_pull_succeeded' => true,
        'returned' => 0,
        'inserted' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'rejected' => 0,
    ]);
});

test('zero changed Customer count means returned Portal data matched local values', function () {
    $portalCustomer = ($this->customer)(91004, 'Already current');
    $portalCustomer['local_uuid'] = (string) Str::uuid();

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [91004],
        ]),
    ]);

    ($this->runToCompletion)();
    $this->requests = [];
    DB::table('sync_states')->where('key', 'customers.pulled_through.'.$this->user->id)->update([
        'value' => json_encode('2026-10-10T01:00:00.000000Z'),
    ]);
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-10T02:00:00.000000Z',
            'scope_ids' => [91004],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $final = end($steps);

    expect($final)->toMatchArray([
        'success' => true,
        'done' => true,
        'returned' => 1,
        'inserted' => 0,
        'updated' => 0,
        'unchanged' => 1,
        'rejected' => 0,
    ])->and($final['message'])->toContain('1 returned; 0 inserted, 0 changed, 1 unchanged, 0 rejected')
        ->and(DB::table('customers')->where('server_id', 91004)->count())->toBe(1);
});

test('a later customer pull asks only for changes and fetches customers that re-entered scope', function () {
    DB::table('sync_states')->insert(['key' => 'customers.pulled_through.'.$this->user->id, 'value' => json_encode('2026-10-04T00:00:00.000000Z')]);
    DB::table('municipalities')->insert(['id' => 1, 'name' => 'M1']);
    $firstLocalUuid = (string) Str::uuid();
    $secondLocalUuid = (string) Str::uuid();
    DB::table('customers')->insert([
        ['id' => 1, 'server_id' => 1, 'local_uuid' => $firstLocalUuid, 'name' => 'Customer 1', 'is_active' => true, 'sync_status' => 'synced'],
        ['id' => 2, 'server_id' => 2, 'local_uuid' => $secondLocalUuid, 'name' => 'Customer 2', 'is_active' => false, 'sync_status' => 'synced'],
    ]);
    $firstPortalCustomer = ($this->customer)(1, 'Renamed');
    $firstPortalCustomer['local_uuid'] = $firstLocalUuid;
    $reenteredPortalCustomer = ($this->customer)(2);
    $reenteredPortalCustomer['local_uuid'] = $secondLocalUuid;

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$firstPortalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-05T01:00:00.000000Z',
            'scope_ids' => [1, 2],
        ]),
        'ids' => Http::response(['customers' => [$reenteredPortalCustomer]]),
    ]);

    $steps = ($this->runToCompletion)();

    expect(end($steps))->toMatchArray(['done' => true, 'pulled' => 2, 'returned' => 2, 'updated' => 2])
        ->and(end($steps)['message'])->toContain('2 changed')
        ->and(collect($this->requests)->pluck('path')->all())->toBe(['/api/sync/pull/locations', '/api/sync/pull/customers', '/api/sync/pull/customers'])
        ->and($this->requests[1]['query'])->toMatchArray(['updated_since' => '2026-10-04T00:00:00.000000Z'])
        ->and($this->requests[2]['query'])->toBe(['ids' => '2'])
        ->and(DB::table('customers')->where('id', 1)->value('name'))->toBe('Renamed')
        ->and(DB::table('customers')->where('id', 2)->value('is_active'))->toBeTruthy()
        ->and(json_decode(DB::table('sync_states')->where('key', 'customers.pulled_through.'.$this->user->id)->value('value')))->toBe('2026-10-05T01:00:00.000000Z');
});

test('a later customer pull refreshes location references before changed customers', function () {
    DB::table('sync_states')->insert(['key' => 'customers.pulled_through.'.$this->user->id, 'value' => json_encode('2026-10-04T00:00:00.000000Z')]);
    DB::table('municipalities')->insert(['id' => 1, 'name' => 'M1']);
    $this->locationPayload = [
        'regions' => [['id' => 4, 'code' => 'R4', 'name' => 'Region 4']],
        'region_specifics' => [['id' => 14, 'region_id' => 4, 'name' => 'Specific 14']],
        'area_clusters' => [['id' => 24, 'region_specific_id' => 14, 'code' => 'AC24', 'name' => 'Cluster 24', 'enabled' => true]],
        'provinces' => [['id' => 34, 'region_id' => 4, 'region_specific_id' => 14, 'name' => 'Province 34', 'enabled' => true]],
        'municipalities' => [['id' => 44, 'region_id' => 4, 'province_id' => 34, 'name' => 'Municipality 44', 'enabled' => true]],
        'barangays' => [['id' => 54, 'municipality_id' => 44, 'code' => 'B54', 'name' => 'Barangay 54', 'enabled' => true]],
    ];
    $this->locationPayload = ($this->makeLocationPayload)($this->locationPayload);
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [],
            'next_after_id' => null,
            'total' => 0,
            'server_time' => '2026-10-05T01:00:00.000000Z',
            'scope_ids' => [],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    expect(end($steps)['done'])->toBeTrue()
        ->and(collect($this->requests)->pluck('path')->first())->toBe('/api/sync/pull/locations')
        ->and(DB::table('region_specifics')->where('id', 14)->value('name'))->toBe('Specific 14')
        ->and(DB::table('area_clusters')->where('id', 24)->value('region_specific_id'))->toBe(14)
        ->and(DB::table('provinces')->where('id', 34)->value('region_id'))->toBe(4)
        ->and(DB::table('barangays')->where('id', 54)->value('municipality_id'))->toBe(44);
});

test('an interrupted customer pull resumes from the page that failed', function () {
    DB::table('municipalities')->insert(['id' => 1, 'name' => 'M1']);
    DB::table('sync_states')->insert(['key' => 'customers.pulled_through.'.$this->user->id, 'value' => json_encode('2026-10-04T00:00:00.000000Z')]);

    $page = [
        0 => Http::response([
            'customers' => [($this->customer)(1)],
            'next_after_id' => 1,
            'total' => 2,
            'server_time' => '2026-10-05T01:00:00.000000Z',
        ]),
        1 => Http::response([], 500),
    ];
    ($this->fakePortal)($page);

    $locations = app(SyncService::class)->pullCustomersStep();
    $first = app(SyncService::class)->pullCustomersStep();
    Log::spy();
    $failed = app(SyncService::class)->pullCustomersStep();

    expect($locations)->toMatchArray(['success' => true, 'done' => false, 'pulled' => 0])
        ->and($first)->toMatchArray(['success' => true, 'done' => false, 'pulled' => 1])
        ->and($failed)->toMatchArray([
            'success' => false,
            'done' => false,
            'pulled' => 1,
            'failure_stage' => 'customer_page_request',
        ])
        ->and($failed['message'])->toContain('HTTP 500', 'Reference: '.$failed['diagnostic_reference'])
        ->and($failed['message'])->not->toContain('Pull failed (500)')
        ->and(Str::isUuid($failed['diagnostic_reference']))->toBeTrue()
        ->and(app(SyncService::class)->customerPullPending())->toBeTrue();

    Log::shouldHaveReceived('error')->once()->with('sync:pull:customers:failed', Mockery::on(
        fn (array $context): bool => $context['reference'] === $failed['diagnostic_reference']
            && $context['stage'] === 'customer_page_request'
            && $context['phase'] === 'pages'
            && $context['after_id'] === 1
            && $context['http_status'] === 500
            && $context['page_transaction_committed'] === false
    ));

    $this->requests = [];
    $this->customerPages[1] = Http::response([
        'customers' => [($this->customer)(2)],
        'next_after_id' => null,
        'scope_ids' => [1, 2],
    ]);

    $resumed = app(SyncService::class)->pullCustomersStep();

    expect($resumed)->toMatchArray(['success' => true, 'done' => true, 'pulled' => 2])
        ->and($this->requests[0]['query'])->toMatchArray(['after_id' => '1'])
        // The watermark is the first page's server time, not the resumed page's.
        ->and(json_decode(DB::table('sync_states')->where('key', 'customers.pulled_through.'.$this->user->id)->value('value')))->toBe('2026-10-05T01:00:00.000000Z');
});

test('the salescall pull uses the schedule endpoint and fetches locations only when missing', function () {
    $locationPayload = ($this->makeLocationPayload)(['municipalities' => [['id' => 1, 'region_id' => null, 'province_id' => null, 'name' => 'M1', 'enabled' => true]]]);
    $locationPayload['reference_contract_version'] = 3;
    $locationPayload['landmass_options'] = [];

    Http::fake(function (Request $request) use ($locationPayload) {
        if (str_ends_with($request->url(), '/api/sync/pull/locations')) {
            return Http::response($locationPayload);
        }

        return Http::response(['itineraries' => [], 'customers' => []]);
    });

    expect(app(SyncService::class)->pull()->success)->toBeTrue();
    expect(app(SyncService::class)->pull()->success)->toBeTrue();

    Http::assertSentCount(3);
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/sync/pull/locations'))->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), '/api/sync/pull/schedule'))->count())->toBe(2)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), '/api/sync/pull/notifications'))->count())->toBe(0);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/api/sync/pull'));
});

test('salescall pull refreshes a legacy v1 reference snapshot before resolving creator groups', function (): void {
    $this->user->forceFill(['name' => 'Jordan', 'last_name' => null])->save();
    DB::table('sync_states')->insert([
        'key' => 'location_reference_snapshot_complete',
        'value' => json_encode(['reference_contract_version' => 1]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payload = ($this->makeLocationPayload)();
    $payload['customer_access_mappings'] = [[
        'scope' => 'group',
        'group_code' => 'CREATOR-REFRESH',
        'is_complete' => true,
        'users' => [['email' => $this->user->email, 'name' => $this->user->name, 'last_name' => 'Rivera']],
    ]];
    Http::fake([
        'portal.test/api/sync/pull/locations' => Http::response($payload),
        'portal.test/api/sync/pull/schedule' => Http::response(['itineraries' => [], 'customers' => []]),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message)
        ->and(json_decode(DB::table('sync_states')->where('key', 'location_reference_snapshot_complete')->value('value'), true)['reference_contract_version'])->toBe(2)
        ->and(DB::table('customer_access_mappings')->where('scope', 'group')->value('mapping_key'))->toBe('CREATOR-REFRESH')
        ->and(app(CustomerAccessMappingResolver::class)->forCreator($this->user->id)['user_ids'])->toBe([$this->user->id]);

    $reloadedUser = User::query()->findOrFail($this->user->id);
    $this->actingAs($reloadedUser);
    expect($reloadedUser->last_name)->toBe('Rivera')
        ->and($reloadedUser->fullName())->toBe('Jordan Rivera');

    Livewire::test(CustomerCreatePage::class)
        ->assertSet('person_in_charge_id', $reloadedUser->id)
        ->assertSet('access_user_ids', [$reloadedUser->id])
        ->assertSee('Jordan Rivera');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/sync/pull/locations'));
});

test('the customers page offers a customer pull and starts it when none has completed', function () {
    Livewire::test(CustomerPage::class)
        ->assertSee('Pull Customers')
        ->assertSee('Customers are up to date.')
        ->assertSee('Customer Pull completed successfully.')
        ->assertSee('Customer Pull failed. Please try again.')
        ->assertDontSee('Location references could not be refreshed; existing local reference data was preserved.')
        ->assertSeeHtml('$nextTick(() => run())');

    DB::table('sync_states')->insert(['key' => 'customers.pulled_through.'.$this->user->id, 'value' => json_encode('2026-10-05T00:00:00.000000Z')]);

    Livewire::test(CustomerPage::class)
        ->assertSee('Pull Customers')
        ->assertDontSeeHtml('$nextTick(() => run())');
});

test('reps sharing a tablet each get a full pull and their own customer list', function () {
    DB::table('sync_states')->insert(['key' => 'customers.pulled_through.'.$this->user->id, 'value' => json_encode('2026-10-04T00:00:00.000000Z')]);
    DB::table('municipalities')->insert(['id' => 1, 'name' => 'M1']);
    DB::table('customers')->insert(['id' => 1, 'server_id' => 1, 'name' => 'First rep customer', 'is_active' => true, 'sync_status' => 'synced']);
    DB::table('customer_scopes')->insert(['user_id' => $this->user->id, 'customer_id' => 1]);

    $otherRep = User::factory()->create(['api_token' => 'other-token']);
    $this->actingAs($otherRep);

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [($this->customer)(2, 'Second rep customer')],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-05T01:00:00.000000Z',
            'scope_ids' => [2],
        ]),
    ]);

    expect(app(SyncService::class)->customerPullPending())->toBeTrue();

    $steps = ($this->runToCompletion)();

    expect(end($steps))->toMatchArray(['done' => true, 'returned' => 1, 'inserted' => 1])
        ->and($this->requests[1]['query'])->not->toHaveKey('updated_since')
        ->and(DB::table('customers')->where('id', 1)->value('is_active'))->toBeTruthy()
        ->and(DB::table('customer_scopes')->where('user_id', $this->user->id)->pluck('customer_id')->all())->toBe([1])
        ->and(DB::table('customer_scopes')->where('user_id', $otherRep->id)->pluck('customer_id')->all())
        ->toBe([DB::table('customers')->where('server_id', 2)->value('id')]);
});

test('a drm sees the customers from their own customer pull', function () {
    Role::create(['name' => 'drm']);
    $this->user->assignRole('drm');

    DB::table('customers')->insert([
        ['id' => 1, 'server_id' => 1, 'name' => 'Pulled For Me', 'is_active' => true, 'sync_status' => 'synced'],
        ['id' => 2, 'server_id' => 2, 'name' => 'Pulled For Someone Else', 'is_active' => true, 'sync_status' => 'synced'],
    ]);
    DB::table('customer_scopes')->insert(['user_id' => $this->user->id, 'customer_id' => 1]);

    Livewire::test(CustomerPage::class)
        ->assertSee('Pulled For Me')
        ->assertDontSee('Pulled For Someone Else');
});

test('a step that fails after reading its page is retried from the same page', function () {
    DB::table('municipalities')->insert(['id' => 1, 'name' => 'M1']);
    DB::table('sync_states')->insert(['key' => 'customers.pulled_through.'.$this->user->id, 'value' => json_encode('2026-10-04T00:00:00.000000Z')]);

    // A malformed scope list makes recording the scope throw after the page
    // has already been read and applied.
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [($this->customer)(1)],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-05T01:00:00.000000Z',
            'scope_ids' => ['not-an-id'],
        ]),
    ]);

    $locations = app(SyncService::class)->pullCustomersStep();
    Log::spy();
    $failed = app(SyncService::class)->pullCustomersStep();

    expect($locations)->toMatchArray(['success' => true, 'done' => false, 'pulled' => 0]);

    expect($failed['success'])->toBeFalse()
        ->and($failed['failure_stage'])->toBe('customer_page_transaction')
        ->and($failed['message'])->toContain('local transaction was rolled back', 'Reference: '.$failed['diagnostic_reference'])
        ->and($failed['message'])->not->toContain('not-an-id')
        ->and(json_decode(DB::table('sync_states')->where('key', 'customers.pull_run.'.$this->user->id)->value('value'), true))
        ->toMatchArray(['phase' => 'pages', 'after_id' => 0, 'pulled' => 0]);

    Log::shouldHaveReceived('error')->once()->with('sync:pull:customers:failed', Mockery::on(
        fn (array $context): bool => $context['stage'] === 'customer_page_transaction'
            && $context['returned_customers'] === 1
            && $context['customer_server_ids'] === [1]
            && $context['page_transaction_committed'] === false
    ));

    $this->customerPages[0] = Http::response([
        'customers' => [($this->customer)(1)],
        'next_after_id' => null,
        'total' => 1,
        'server_time' => '2026-10-05T01:00:00.000000Z',
        'scope_ids' => [1],
    ]);

    expect(app(SyncService::class)->pullCustomersStep())->toMatchArray(['success' => true, 'done' => true, 'pulled' => 1]);
});

test('an escaped Customer Pull exception returns a safe diagnostic reference', function () {
    $syncService = Mockery::mock(SyncService::class);
    $syncService->shouldReceive('pullCustomersStep')->once()->andThrow(new RuntimeException('secret SQL or token detail'));
    $syncService->shouldReceive('hasPendingCustomerPushWork')->twice()->andReturn(false);
    $syncService->shouldReceive('customerPullPending')->zeroOrMoreTimes()->andReturn(false);
    $this->app->instance(SyncService::class, $syncService);
    Log::spy();

    Livewire::test(CustomerPage::class)
        ->call('pullCustomersStep')
        ->assertReturned(fn (array $result): bool => $result['success'] === false
            && $result['failure_stage'] === 'livewire_action'
            && Str::isUuid($result['diagnostic_reference'])
            && str_contains($result['message'], $result['diagnostic_reference'])
            && ! str_contains($result['message'], 'secret SQL or token detail'));

    Log::shouldHaveReceived('error')->once()->with('sync:pull:customers:failed', Mockery::on(
        fn (array $context): bool => $context['stage'] === 'livewire_action'
            && $context['exception_class'] === RuntimeException::class
            && isset($context['reference'])
    ));
});

test('pull refreshes a synced Customer by server ID without replacing its negative local key', function () {
    $localId = -7_166_839_558_920_329_640;
    $serverId = 98001;
    $localUuid = (string) Str::uuid();
    DB::table('material_groups')->insert(['id' => 1, 'name' => 'Group', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('brands')->insert(['id' => 1, 'material_group_id' => 1, 'name' => 'Brand', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('categories')->insert(['id' => 1, 'name' => 'Category', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sub_categories')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Subcategory', 'with_form' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customers')->insert([
        'id' => $localId,
        'server_id' => $serverId,
        'local_uuid' => $localUuid,
        'name' => 'Before pull',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('salescalls')->insert([
        'itinerary_id' => 1,
        'customer_id' => $localId,
        'visit_date' => now(),
        'created_by' => $this->user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $portalCustomer = ($this->customer)($serverId, 'After pull');
    $portalCustomer['local_uuid'] = $localUuid;

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'customer_brands' => [['customer_id' => $serverId, 'material_group_id' => 1, 'brand_id' => 1, 'quantity' => 4]],
            'customer_categories' => [['customer_id' => $serverId, 'category_id' => 1, 'sub_category_id' => 1]],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-09T01:00:00.000000Z',
            'scope_ids' => [$serverId],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $saved = DB::table('customers')->where('server_id', $serverId)->get();

    expect(end($steps)['success'])->toBeTrue()
        ->and($saved)->toHaveCount(1)
        ->and($saved->first()->id)->toBe($localId)
        ->and($saved->first()->name)->toBe('After pull')
        ->and(DB::table('customer_brands')->where('customer_id', $localId)->value('quantity'))->toBe(4)
        ->and(DB::table('customer_categories')->where('customer_id', $localId)->value('category_id'))->toBe(1)
        ->and(DB::table('customer_brands')->where('customer_id', $serverId)->exists())->toBeFalse()
        ->and(DB::table('customer_categories')->where('customer_id', $serverId)->exists())->toBeFalse()
        ->and(DB::table('salescalls')->where('customer_id', $localId)->exists())->toBeTrue();
});

test('pull reconciles a Portal-committed Customer by local UUID after a lost push acknowledgment', function () {
    $localId = -2_291_945_407_554_291_093;
    $serverId = 98002;
    $localUuid = (string) Str::uuid();
    DB::table('customers')->insert([
        'id' => $localId,
        'local_uuid' => $localUuid,
        'name' => 'Tablet value retained',
        'unique_id' => 'FLEET-RESERVED-IDENTITY',
        'customer_code_reservation_token' => (string) Str::uuid(),
        'is_active' => true,
        'sync_status' => 'failed',
        'sync_attempts' => 1,
        'sync_error' => 'Push acknowledgment was not received.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $portalCustomer = ($this->customer)($serverId, 'Portal committed value');
    $portalCustomer['local_uuid'] = $localUuid;

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-09T01:00:00.000000Z',
            'scope_ids' => [$serverId],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $saved = DB::table('customers')->where('local_uuid', $localUuid)->get();
    $customer = $saved->first();

    expect(end($steps)['success'])->toBeTrue()
        ->and($saved)->toHaveCount(1)
        ->and($customer->id)->toBe($localId)
        ->and($customer->server_id)->toBe($serverId)
        ->and($customer->name)->toBe('Tablet value retained')
        ->and($customer->unique_id)->toBe('FLEET-RESERVED-IDENTITY')
        ->and($customer->customer_code_reservation_token)->not->toBeNull()
        ->and($customer->sync_status)->toBe('conflict')
        ->and($customer->sync_error)->toContain('pending local edits')
        ->and(DB::table('customers')->where('server_id', $serverId)->count())->toBe(1);
});

test('pull fails safely when a local UUID is already bound to another Portal ID', function () {
    $localId = -2_291_945_407_554_291_093;
    $localUuid = (string) Str::uuid();
    DB::table('customers')->insert([
        'id' => $localId,
        'server_id' => 98004,
        'local_uuid' => $localUuid,
        'name' => 'Identity Conflict Local Value',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $portalCustomer = ($this->customer)(98005, 'Conflicting Portal Value');
    $portalCustomer['local_uuid'] = $localUuid;

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-09T01:00:00.000000Z',
            'scope_ids' => [98005],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $saved = DB::table('customers')->where('local_uuid', $localUuid)->get();

    expect(end($steps)['success'])->toBeFalse()
        ->and($saved)->toHaveCount(1)
        ->and($saved->first()->id)->toBe($localId)
        ->and($saved->first()->server_id)->toBe(98004)
        ->and($saved->first()->name)->toBe('Identity Conflict Local Value');
});

test('pull fails safely when the incoming UUID and Portal ID resolve to different local Customers', function () {
    $localUuid = (string) Str::uuid();
    $serverId = 98006;
    $pendingLocalId = -3_100_000_000_000_000_006;
    $alreadyMappedLocalId = -3_100_000_000_000_000_007;
    DB::table('customers')->insert([
        [
            'id' => $pendingLocalId,
            'server_id' => null,
            'local_uuid' => $localUuid,
            'name' => 'Pending UUID match',
            'is_active' => true,
            'sync_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $alreadyMappedLocalId,
            'server_id' => $serverId,
            'local_uuid' => (string) Str::uuid(),
            'name' => 'Existing Portal ID match',
            'is_active' => true,
            'sync_status' => 'synced',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $portalCustomer = ($this->customer)($serverId, 'Portal value');
    $portalCustomer['local_uuid'] = $localUuid;

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-09T01:00:00.000000Z',
            'scope_ids' => [$serverId],
        ]),
    ]);

    $steps = ($this->runToCompletion)();

    expect(end($steps)['success'])->toBeFalse()
        ->and(DB::table('customers')->where('server_id', $serverId)->count())->toBe(1)
        ->and(DB::table('customers')->where('id', $pendingLocalId)->value('server_id'))->toBeNull()
        ->and(DB::table('customers')->where('id', $pendingLocalId)->value('name'))->toBe('Pending UUID match')
        ->and(DB::table('customers')->where('id', $alreadyMappedLocalId)->value('name'))->toBe('Existing Portal ID match');
});

test('repeating a Portal Customer pull updates the same Tablet row without a duplicate', function () {
    $serverId = 98007;
    $localUuid = (string) Str::uuid();
    $portalCustomer = ($this->customer)($serverId, 'Pulled Customer');
    $portalCustomer['local_uuid'] = $localUuid;
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-09T01:00:00.000000Z',
            'scope_ids' => [$serverId],
        ]),
    ]);
    $firstRun = ($this->runToCompletion)();
    $firstLocalId = DB::table('customers')->where('server_id', $serverId)->value('id');

    DB::table('sync_states')->whereIn('key', [
        'customer.pull.watermark.'.$this->user->id,
        'customer.pull.run.'.$this->user->id,
    ])->delete();
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-09T01:01:00.000000Z',
            'scope_ids' => [$serverId],
        ]),
    ]);
    $secondRun = ($this->runToCompletion)();

    expect(end($firstRun)['success'])->toBeTrue()
        ->and(end($secondRun)['success'])->toBeTrue()
        ->and(DB::table('customers')->where('server_id', $serverId)->count())->toBe(1)
        ->and(DB::table('customers')->where('server_id', $serverId)->value('id'))->toBe($firstLocalId)
        ->and(DB::table('customers')->where('local_uuid', $localUuid)->count())->toBe(1);
});

test('pull fails safely when a Portal ID is already duplicated across Tablet rows', function () {
    $serverId = 98008;
    DB::table('customers')->insert([
        ['id' => -3_200_000_000_000_000_001, 'server_id' => $serverId, 'local_uuid' => (string) Str::uuid(), 'name' => 'Local duplicate A', 'is_active' => true, 'sync_status' => 'synced'],
        ['id' => -3_200_000_000_000_000_002, 'server_id' => $serverId, 'local_uuid' => (string) Str::uuid(), 'name' => 'Local duplicate B', 'is_active' => true, 'sync_status' => 'synced'],
    ]);
    $portalCustomer = ($this->customer)($serverId, 'Portal customer');
    $portalCustomer['local_uuid'] = (string) Str::uuid();
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-09T01:00:00.000000Z',
            'scope_ids' => [$serverId],
        ]),
    ]);

    $steps = ($this->runToCompletion)();

    expect(end($steps)['success'])->toBeFalse()
        ->and(DB::table('customers')->where('server_id', $serverId)->count())->toBe(2)
        ->and(DB::table('customers')->where('server_id', $serverId)->orderBy('id')->pluck('name')->all())
        ->toBe(['Local duplicate B', 'Local duplicate A']);
});

test('pull does not bind a Portal Customer to a different Customer occupying the same numeric local ID', function () {
    $portalId = 98009;
    DB::table('customers')->insert([
        'id' => $portalId,
        'local_uuid' => (string) Str::uuid(),
        'unique_id' => 'UNRELATED-98009',
        'company_id' => 1,
        'name' => 'Unrelated numeric collision',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('customer_trade_profiles')->insert([
        'customer_id' => $portalId,
        'profile_type' => 'ib',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $beforeCustomer = (array) DB::table('customers')->where('id', $portalId)->first();
    $beforeProfile = (array) DB::table('customer_trade_profiles')->where('customer_id', $portalId)->first();
    $portalCustomer = ($this->customer)($portalId, 'Different Portal Customer');
    $portalCustomer['local_uuid'] = (string) Str::uuid();
    $portalCustomer['unique_id'] = 'PORTAL-98009';

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-13T01:00:00.000000Z',
            'scope_ids' => [$portalId],
        ]),
    ]);

    $steps = ($this->runToCompletion)();

    expect(end($steps)['success'])->toBeFalse()
        ->and((array) DB::table('customers')->where('id', $portalId)->first())->toBe($beforeCustomer)
        ->and((array) DB::table('customer_trade_profiles')->where('customer_id', $portalId)->first())->toBe($beforeProfile)
        ->and(DB::table('customers')->where('server_id', $portalId)->exists())->toBeFalse();
});

test('clearing only an incorrect server ID preserves the local Customer and lets repeated Pull resolve the canonical row', function () {
    $serverId = 23591;
    $canonicalId = -687143025929916172;
    $canonicalUuid = '25a4b477-d5f8-48e4-aca7-3b61346f1f06';
    $otherUuid = 'ed72ddcd-d2e0-48fc-ba08-a6369eaeff9d';
    $accessUsers = collect([$this->user]);
    for ($index = 1; $index < 8; $index++) {
        $accessUsers->push(User::factory()->create([
            'email' => "collision-access-{$index}@example.test",
        ]));
    }
    DB::table('companies')->insert(['id' => 3, 'name' => 'CAR CLUBS', 'code' => 'CAR_CLUBS', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customers')->insert([
        [
            'id' => $canonicalId,
            'server_id' => $serverId,
            'local_uuid' => $canonicalUuid,
            'unique_id' => 'CARCLUBS00013',
            'company_id' => 3,
            'name' => 'Canonical fixture',
            'is_active' => true,
            'sync_status' => 'synced',
            'created_at' => '2026-10-13 04:49:21',
            'updated_at' => '2026-10-13 04:49:21',
        ],
        [
            'id' => $serverId,
            'server_id' => $serverId,
            'local_uuid' => $otherUuid,
            'unique_id' => 'IB53385',
            'company_id' => 6,
            'name' => 'Separate local fixture',
            'is_active' => true,
            'sync_status' => 'synced',
            'created_at' => null,
            'updated_at' => '2026-10-09 16:28:51',
        ],
        [
            'id' => -77800,
            'server_id' => 77800,
            'local_uuid' => 'f94eb23d-84eb-4527-8495-ccfd306312b8',
            'unique_id' => 'UNRELATED00001',
            'company_id' => 1,
            'name' => 'Unrelated fixture',
            'is_active' => true,
            'sync_status' => 'synced',
            'created_at' => '2026-10-01 00:00:00',
            'updated_at' => '2026-10-01 00:00:00',
        ],
    ]);
    DB::table('customer_trade_profiles')->insert([
        ['customer_id' => $canonicalId, 'profile_type' => 'outlet', 'entry_detail' => 'AB', 'classifications' => json_encode(['Battery Specialist', 'Hardware', 'General Merchandise']), 'created_at' => now(), 'updated_at' => now()],
        ['customer_id' => $serverId, 'profile_type' => 'ib', 'entry_detail' => null, 'classifications' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    foreach ($accessUsers as $accessUser) {
        DB::table('customer_user')->insert(['customer_id' => $canonicalId, 'user_id' => $accessUser->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('customer_user')->insert(['customer_id' => $serverId, 'user_id' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customer_trade_profiles')->insert(['customer_id' => -77800, 'profile_type' => 'ib', 'entry_detail' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customer_user')->insert(['customer_id' => -77800, 'user_id' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('customer_scopes')->insert(['customer_id' => -77800, 'user_id' => $this->user->id]);
    foreach (range(2018, 2026) as $year) {
        DB::table('customer_category_histories')->insert([
            'customer_id' => $canonicalId,
            'category_year' => $year,
            'profile_type' => 'outlet',
            'stream' => 'ab',
            'category' => $year < 2023 || $year === 2026 ? 'AB Company Owned' : 'AB JV Equity',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $affected = DB::table('customers')->where('id', $serverId)
        ->where('server_id', $serverId)
        ->where('local_uuid', $otherUuid)
        ->update(['server_id' => null]);
    $otherCustomerAfterPreparedRepair = (array) DB::table('customers')->where('id', $serverId)->first();
    $otherProfileAfterPreparedRepair = (array) DB::table('customer_trade_profiles')->where('customer_id', $serverId)->first();
    $otherAccessAfterPreparedRepair = DB::table('customer_user')->where('customer_id', $serverId)->orderBy('user_id')->pluck('user_id')->all();
    $unrelatedCustomerBeforePull = (array) DB::table('customers')->where('id', -77800)->first();
    $unrelatedProfileBeforePull = (array) DB::table('customer_trade_profiles')->where('customer_id', -77800)->first();
    $unrelatedAccessBeforePull = DB::table('customer_user')->where('customer_id', -77800)->orderBy('user_id')->pluck('user_id')->all();

    $portalCustomer = ($this->customer)($serverId, 'Canonical fixture');
    $portalCustomer['local_uuid'] = $canonicalUuid;
    $portalCustomer['unique_id'] = 'CARCLUBS00013';
    $portalCustomer['company_id'] = 3;
    $portalCustomer['customer_code_reservation_token'] = null;
    $portalCustomer['person_in_charge_id'] = null;
    $portalCustomer['person_in_charge_email'] = $this->user->email;
    $portalCustomer['access_user_emails'] = $accessUsers->pluck('email')->all();
    $history = collect(range(2018, 2026))->map(fn (int $year): array => [
        'customer_id' => $serverId,
        'category_year' => $year,
        'profile_type' => 'outlet',
        'stream' => 'ab',
        'category' => $year < 2023 || $year === 2026 ? 'AB Company Owned' : 'AB JV Equity',
    ])->all();
    $payload = [
        'customers' => [$portalCustomer],
        'customer_users' => $accessUsers->map(fn (User $user): array => [
            'name' => $user->name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ])->all(),
        'customer_trade_profiles' => [[
            'customer_id' => $serverId,
            'profile_type' => 'outlet',
            'entry_detail' => 'AB',
            'classifications' => ['Battery Specialist', 'Hardware', 'General Merchandise'],
        ]],
        'customer_category_histories' => $history,
        'next_after_id' => null,
        'total' => 1,
        'server_time' => '2026-10-13T05:00:00.000000Z',
        'scope_ids' => [$serverId, 77800],
    ];
    ($this->fakePortal)([0 => Http::response($payload)]);
    $firstRun = ($this->runToCompletion)();

    DB::table('sync_states')->whereIn('key', [
        'customers.pull_run.'.$this->user->id,
        'customers.pulled_through.'.$this->user->id,
    ])->delete();
    ($this->fakePortal)([0 => Http::response($payload)]);
    $secondRun = ($this->runToCompletion)();

    $canonical = DB::table('customers')->where('id', $canonicalId)->first();
    $other = (array) DB::table('customers')->where('id', $serverId)->first();

    expect($affected)->toBe(1)
        ->and(end($firstRun)['success'])->toBeTrue()
        ->and(end($secondRun)['success'])->toBeTrue()
        ->and(end($secondRun)['unchanged'])->toBe(1)
        ->and(end($secondRun)['updated'])->toBe(0)
        ->and(DB::table('customers')->count())->toBe(3)
        ->and(DB::table('customers')->where('server_id', $serverId)->count())->toBe(1)
        ->and(DB::table('customers')->where('local_uuid', $canonicalUuid)->count())->toBe(1)
        ->and(DB::table('customers')->where('local_uuid', $canonicalUuid)->value('id'))->toBe($canonicalId)
        ->and($canonical->server_id)->toBe($serverId)
        ->and($canonical->local_uuid)->toBe($canonicalUuid)
        ->and($canonical->unique_id)->toBe('CARCLUBS00013')
        ->and($other)->toBe($otherCustomerAfterPreparedRepair)
        ->and((array) DB::table('customer_trade_profiles')->where('customer_id', $serverId)->first())->toBe($otherProfileAfterPreparedRepair)
        ->and(DB::table('customer_user')->where('customer_id', $serverId)->orderBy('user_id')->pluck('user_id')->all())->toBe($otherAccessAfterPreparedRepair)
        ->and(DB::table('customer_category_histories')->where('customer_id', $canonicalId)->count())->toBe(9)
        ->and(DB::table('customer_category_histories')->where('customer_id', $canonicalId)->orderBy('category_year')->pluck('category_year')->all())->toBe(range(2018, 2026))
        ->and(DB::table('customer_category_histories')->where('customer_id', $canonicalId)->orderBy('category_year')->pluck('category')->all())->toBe(array_merge(array_fill(0, 5, 'AB Company Owned'), array_fill(0, 3, 'AB JV Equity'), ['AB Company Owned']))
        ->and(DB::table('customer_category_histories')->where('customer_id', $serverId)->count())->toBe(0)
        ->and(DB::table('customer_user')->where('customer_id', $canonicalId)->count())->toBe(8)
        ->and(DB::table('customer_user')->where('customer_id', $canonicalId)->orderBy('user_id')->pluck('user_id')->all())->toBe($accessUsers->pluck('id')->sort()->values()->all())
        ->and(DB::table('customers')->where('id', $canonicalId)->value('person_in_charge_id'))->toBe($this->user->id)
        ->and(DB::table('customer_trade_profiles')->where('customer_id', $canonicalId)->value('classifications'))->toBe(json_encode(['Battery Specialist', 'Hardware', 'General Merchandise']))
        ->and(DB::table('customer_trade_profiles')->where('customer_id', $canonicalId)->value('entry_detail'))->toBe('AB')
        ->and((array) DB::table('customers')->where('id', -77800)->first())->toBe($unrelatedCustomerBeforePull)
        ->and((array) DB::table('customer_trade_profiles')->where('customer_id', -77800)->first())->toBe($unrelatedProfileBeforePull)
        ->and(DB::table('customer_user')->where('customer_id', -77800)->orderBy('user_id')->pluck('user_id')->all())->toBe($unrelatedAccessBeforePull)
        ->and(app(SyncService::class)->hasPendingCustomerPushWork())->toBeFalse();
});

test('an invalid location snapshot is rejected atomically while the independent Customer Pull completes with canonical location IDs intact', function () {
    Log::spy();
    DB::table('regions')->insert(['id' => 1, 'code' => 'OLD', 'name' => 'Existing local Region', 'created_at' => now(), 'updated_at' => now()]);
    $this->locationPayload = ($this->makeLocationPayload)([
        'regions' => [['id' => 2, 'code' => 'NEW', 'name' => 'Snapshot Region']],
        'area_clusters' => [['id' => 24, 'region_specific_id' => 53, 'code' => 'AC24', 'name' => 'Cluster 24', 'enabled' => true]],
    ]);
    $portalCustomer = ($this->customer)(90001, 'Customer with canonical unavailable location');
    $portalCustomer['local_uuid'] = (string) Str::uuid();
    $portalCustomer['region_specific_id'] = 53;
    $portalCustomer['area_cluster_id'] = 24;
    $portalCustomer['province_id'] = 77;
    $portalCustomer['municipality_id'] = 88;
    $portalCustomer['barangay_id'] = 99;

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [90001],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $locationStep = $steps[0];
    $customerStep = end($steps);
    $saved = DB::table('customers')->where('server_id', 90001)->first();

    expect($locationStep)->toMatchArray([
        'success' => true,
        'done' => false,
        'customer_pull_succeeded' => false,
        'location_pull_succeeded' => false,
        'location_refresh_failed' => true,
        'full_sync_succeeded' => false,
    ])->and($locationStep['message'])->toContain('Continuing Customer Pull')
        ->and($customerStep)->toMatchArray([
            'success' => true,
            'done' => true,
            'customer_pull_succeeded' => true,
            'location_pull_succeeded' => false,
            'location_refresh_failed' => true,
            'full_sync_succeeded' => false,
        ])->and($customerStep['message'])->toContain('Customer Pull succeeded', 'Location references could not be refreshed', 'Full synchronization is incomplete')
        ->and(collect($this->requests)->pluck('path')->all())->toBe(['/api/sync/pull/locations', '/api/sync/pull/customers'])
        ->and(DB::table('regions')->where('id', 1)->value('name'))->toBe('Existing local Region')
        ->and(DB::table('regions')->where('id', 2)->exists())->toBeFalse()
        ->and(DB::table('area_clusters')->where('id', 24)->exists())->toBeFalse()
        ->and($saved)->not->toBeNull()
        ->and($saved->server_id)->toBe(90001)
        ->and($saved->region_specific_id)->toBe(53)
        ->and($saved->area_cluster_id)->toBe(24)
        ->and($saved->province_id)->toBe(77)
        ->and($saved->municipality_id)->toBe(88)
        ->and($saved->barangay_id)->toBe(99)
        ->and(DB::table('customers')->where('server_id', 90001)->count())->toBe(1);

    Log::shouldHaveReceived('warning')->once()->with('sync:pull:locations:failed', Mockery::on(
        fn (array $context): bool => $context['error_code'] === 'exception'
            && str_contains($context['message'], 'area_clusters.region_specific_id parent')
    ));
});

test('Customer Pull accepts null Company IDs across mixed pages without changing Customer identities or Access', function () {
    $now = now();
    $existingLocalUuid = (string) Str::uuid();
    DB::table('customers')->insert([
        'id' => -90012,
        'server_id' => 90012,
        'local_uuid' => $existingLocalUuid,
        'company_id' => 1,
        'name' => 'Existing Company-less Customer',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('customer_user')->insert([
        'customer_id' => -90012,
        'user_id' => $this->user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $itineraryId = DB::table('itineraries')->insertGetId([
        'created_by' => $this->user->id,
        'sync_status' => 'synced',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('salescalls')->insert([
        'itinerary_id' => $itineraryId,
        'customer_id' => -90012,
        'created_by' => $this->user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->locationPayload = ($this->makeLocationPayload)();
    $this->locationPayload['customer_access_mappings'] = [[
        'scope' => 'group',
        'group_code' => 'NULL-COMPANY-CREATOR',
        'is_complete' => true,
        'users' => [[
            'email' => $this->user->email,
            'name' => $this->user->name,
            'last_name' => null,
        ]],
    ]];

    $companyLessCustomer = function (int $id, ?string $name = null) use ($existingLocalUuid): array {
        $customer = ($this->customer)($id, $name);
        $customer['company_id'] = null;
        if ($id === 90012) {
            $customer['local_uuid'] = $existingLocalUuid;
        }

        return $customer;
    };
    $firstCustomer = ($this->customer)(90010, 'Valid Company Customer');
    $mixedPage = [($this->customer)(90011), $companyLessCustomer(90012), $companyLessCustomer(90013), $companyLessCustomer(90014), $companyLessCustomer(90015)];
    $lastCustomer = ($this->customer)(90016, 'Customer after Company-less records');

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$firstCustomer],
            'next_after_id' => 90010,
            'total' => 7,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => null,
        ]),
        90010 => Http::response([
            'customers' => $mixedPage,
            'next_after_id' => 90015,
            'total' => null,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => null,
        ]),
        90015 => Http::response([
            'customers' => [$lastCustomer],
            'next_after_id' => null,
            'total' => null,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [90010, 90011, 90012, 90013, 90014, 90015, 90016],
        ]),
    ]);

    $steps = ($this->runToCompletion)();

    expect(collect($steps)->pluck('success')->every(fn (bool $success): bool => $success))->toBeTrue()
        ->and(end($steps)['done'])->toBeTrue()
        ->and(DB::table('customers')->whereIn('server_id', [90010, 90011, 90012, 90013, 90014, 90015, 90016])->count())->toBe(7)
        ->and(DB::table('customers')->whereIn('server_id', [90012, 90013, 90014, 90015])->whereNull('company_id')->count())->toBe(4)
        ->and(DB::table('customers')->whereIn('server_id', [90010, 90011, 90016])->where('company_id', 1)->count())->toBe(3)
        ->and(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('customers')->where('id', -90012)->value('server_id'))->toBe(90012)
        ->and(DB::table('customer_user')->where('customer_id', -90012)->pluck('user_id')->all())->toBe([$this->user->id])
        ->and(DB::table('salescalls')->where('customer_id', -90012)->count())->toBe(1)
        ->and(DB::table('customer_access_mappings')->where('scope', 'group')->where('mapping_key', 'NULL-COMPANY-CREATOR')->exists())->toBeTrue()
        ->and(app(CustomerAccessMappingResolver::class)->forCreator($this->user->id)['user_ids'])->toBe([$this->user->id])
        ->and(array_column($this->requests, 'path'))->toBe([
            '/api/sync/pull/locations',
            '/api/sync/pull/customers',
            '/api/sync/pull/customers',
            '/api/sync/pull/customers',
        ]);
});

test('Customer Pull treats a missing Company ID field as null and pulls the Customer as Company-less', function () {
    $portalCustomer = ($this->customer)(90001, 'Customer missing Company field');
    unset($portalCustomer['company_id']);
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [90001],
        ]),
    ]);

    $steps = ($this->runToCompletion)();

    expect(collect($steps)->pluck('success')->every(fn (bool $success): bool => $success))->toBeTrue()
        ->and(end($steps)['done'])->toBeTrue()
        ->and(DB::table('customers')->where('server_id', 90001)->whereNull('company_id')->count())->toBe(1)
        ->and(DB::table('customers')->where('server_id', 90001)->value('name'))->toBe('Customer missing Company field');
});

test('Customer Pull rejects malformed non-null Company IDs before persisting the Customer', function (mixed $companyId) {
    $portalCustomer = ($this->customer)(90003, 'Customer with malformed Company ID');
    $portalCustomer['company_id'] = $companyId;
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [90003],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $failure = end($steps);

    expect($failure['success'])->toBeFalse()
        ->and($failure['message'])->toContain('no valid required Company ID')
        ->and(DB::table('customers')->where('server_id', 90003)->exists())->toBeFalse();
})->with([
    'negative integer' => [-1],
    'nonnumeric string' => ['not-a-company-id'],
]);

test('Customer Pull rolls back a page with an invalid non-null Company without changing cached Customer or Access', function () {
    $now = now();
    DB::table('customers')->insert([
        'id' => -90020,
        'server_id' => 90020,
        'company_id' => 1,
        'name' => 'Cached Customer',
        'is_active' => true,
        'sync_status' => 'synced',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('customer_user')->insert([
        'customer_id' => -90020,
        'user_id' => $this->user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $portalCustomer = ($this->customer)(90020, 'Rejected Changed Name');
    $portalCustomer['company_id'] = 999;
    $portalCustomer['access_user_emails'] = [];
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [90020],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $failure = end($steps);

    expect($failure['success'])->toBeFalse()
        ->and($failure['message'])->toContain('required Company is unavailable on this device')
        ->and(DB::table('customers')->where('id', -90020)->first())->toMatchArray([
            'server_id' => 90020,
            'company_id' => 1,
            'name' => 'Cached Customer',
            'sync_status' => 'synced',
        ])
        ->and(DB::table('customer_user')->where('customer_id', -90020)->pluck('user_id')->all())->toBe([$this->user->id]);
});

test('Customer Pull rejects a Customer whose non-null Company reference is unresolved locally', function () {
    $portalCustomer = ($this->customer)(90002, 'Customer with unresolved Company');
    $portalCustomer['company_id'] = 999;
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [$portalCustomer],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [90002],
        ]),
    ]);

    $steps = ($this->runToCompletion)();
    $customerFailure = end($steps);

    expect($customerFailure)->toMatchArray([
        'success' => false,
        'done' => false,
        'customer_pull_succeeded' => false,
        'location_pull_succeeded' => true,
        'location_refresh_failed' => false,
        'full_sync_succeeded' => false,
    ])->and($customerFailure['message'])->toContain('required Company is unavailable on this device')
        ->and($customerFailure)->toMatchArray(['returned' => 1, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 1])
        ->and(DB::table('customers')->where('server_id', 90002)->exists())->toBeFalse();
});

test('Customer Pull and location refresh failures are independently reported', function () {
    $this->locationPayload = ($this->makeLocationPayload)([
        'area_clusters' => [['id' => 24, 'region_specific_id' => 53, 'code' => 'AC24', 'name' => 'Cluster 24', 'enabled' => true]],
    ]);
    ($this->fakePortal)([
        0 => Http::response(['message' => 'Portal Customer endpoint unavailable.'], 500),
    ]);

    $steps = ($this->runToCompletion)();
    $customerFailure = end($steps);

    expect($steps[0]['location_refresh_failed'])->toBeTrue()
        ->and($customerFailure)->toMatchArray([
            'success' => false,
            'done' => false,
            'customer_pull_succeeded' => false,
            'location_pull_succeeded' => false,
            'location_refresh_failed' => true,
            'full_sync_succeeded' => false,
        ])->and($customerFailure['message'])->toContain('Customer Pull failed', 'Location refresh also failed')
        ->and(DB::table('customers')->count())->toBe(0);
});

test('a pre-existing interrupted pages-phase run is treated as having completed its location refresh', function () {
    DB::table('sync_states')->insert([
        'key' => 'customers.pull_run.'.$this->user->id,
        'value' => json_encode([
            'phase' => 'pages',
            'since' => null,
            'after_id' => 0,
            'server_time' => null,
            'total' => null,
            'pulled' => 0,
            'missing' => [],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [($this->customer)(90003)],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-10T01:00:00.000000Z',
            'scope_ids' => [90003],
        ]),
    ]);

    $result = app(SyncService::class)->pullCustomersStep();

    expect($result)->toMatchArray([
        'success' => true,
        'done' => true,
        'customer_pull_succeeded' => true,
        'location_pull_succeeded' => true,
        'location_refresh_failed' => false,
        'full_sync_succeeded' => true,
    ]);
});
