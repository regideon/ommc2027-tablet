<?php

use App\Filament\Pages\CustomerPage;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['sync.server_url' => 'http://portal.test']);

    $this->user = User::factory()->create(['api_token' => 'test-token']);
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
        ->and(end($steps))->toMatchArray(['done' => true, 'pulled' => 3, 'total' => 3, 'message' => 'Pulled 3 customers.'])
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

    expect(end($steps))->toMatchArray(['done' => true, 'pulled' => 2, 'message' => 'Customers up to date (2 updated).'])
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
        ->and($failed)->toMatchArray(['success' => false, 'done' => false, 'pulled' => 1, 'message' => 'Pull failed (500).'])
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

    expect(end($steps))->toMatchArray(['done' => true, 'message' => 'Pulled 1 customers.'])
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
