<?php

use App\Filament\Pages\CustomerPage;
use App\Models\User;
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
            'reference_contract_version' => 1,
            ...$sections,
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
        ->and($this->requests[1]['query'])->toBe(['after_id' => '0', 'limit' => '500'])
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
    DB::table('customers')->insert([
        ['id' => 1, 'server_id' => 1, 'name' => 'Customer 1', 'is_active' => true, 'sync_status' => 'synced'],
        ['id' => 2, 'server_id' => 2, 'name' => 'Customer 2', 'is_active' => false, 'sync_status' => 'synced'],
    ]);

    ($this->fakePortal)([
        0 => Http::response([
            'customers' => [($this->customer)(1, 'Renamed')],
            'next_after_id' => null,
            'total' => 1,
            'server_time' => '2026-10-05T01:00:00.000000Z',
            'scope_ids' => [1, 2],
        ]),
        'ids' => Http::response(['customers' => [($this->customer)(2)]]),
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
    $failed = app(SyncService::class)->pullCustomersStep();

    expect($locations)->toMatchArray(['success' => true, 'done' => false, 'pulled' => 0])
        ->and($first)->toMatchArray(['success' => true, 'done' => false, 'pulled' => 1])
        ->and($failed)->toMatchArray(['success' => false, 'done' => false, 'pulled' => 1, 'message' => 'Customer Pull failed: Pull failed (500).'])
        ->and(app(SyncService::class)->customerPullPending())->toBeTrue();

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
    Http::fake([
        'portal.test/api/sync/pull/schedule' => Http::response(['itineraries' => [], 'customers' => []]),
        'portal.test/api/sync/pull/locations' => Http::response(($this->makeLocationPayload)(['municipalities' => [['id' => 1, 'region_id' => null, 'province_id' => null, 'name' => 'M1', 'enabled' => true]]])),
    ]);

    expect(app(SyncService::class)->pull()->success)->toBeTrue();
    expect(app(SyncService::class)->pull()->success)->toBeTrue();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/sync/pull/locations'));
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/api/sync/pull'));
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
    $failed = app(SyncService::class)->pullCustomersStep();

    expect($locations)->toMatchArray(['success' => true, 'done' => false, 'pulled' => 0]);

    expect($failed['success'])->toBeFalse()
        ->and(json_decode(DB::table('sync_states')->where('key', 'customers.pull_run.'.$this->user->id)->value('value'), true))
        ->toMatchArray(['phase' => 'pages', 'after_id' => 0, 'pulled' => 0]);

    $this->customerPages[0] = Http::response([
        'customers' => [($this->customer)(1)],
        'next_after_id' => null,
        'total' => 1,
        'server_time' => '2026-10-05T01:00:00.000000Z',
        'scope_ids' => [1],
    ]);

    expect(app(SyncService::class)->pullCustomersStep())->toMatchArray(['success' => true, 'done' => true, 'pulled' => 1]);
});

test('pull refreshes a synced Customer by server ID without replacing its negative local key', function () {
    $localId = -7_166_839_558_920_329_640;
    $serverId = 98001;
    $localUuid = (string) Str::uuid();
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
        ->and($customer->sync_status)->toBe('failed')
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

test('Customer Pull rejects a Customer whose required Company reference is unresolved locally', function () {
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
