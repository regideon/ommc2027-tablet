<?php

use App\Services\CustomerIdentityResolution;
use App\Services\CustomerIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function insertIdentityResolverCustomer(array $attributes = []): int
{
    return (int) DB::table('customers')->insertGetId([
        ...array_key_exists('id', $attributes) ? ['id' => $attributes['id']] : [],
        'name' => $attributes['name'] ?? 'Identity Fixture Customer',
        'local_uuid' => $attributes['local_uuid'] ?? (string) Str::uuid(),
        'server_id' => $attributes['server_id'] ?? null,
        'unique_id' => $attributes['unique_id'] ?? 'IDENTITY-001',
        'company_id' => $attributes['company_id'] ?? null,
        'is_active' => true,
        'sync_status' => $attributes['sync_status'] ?? 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('offline Customer identity and Portal acknowledgment resolve repeatedly by local UUID', function () {
    $localUuid = (string) Str::uuid();
    $localId = insertIdentityResolverCustomer(['local_uuid' => $localUuid, 'company_id' => 7]);
    $portalIdentity = ['id' => 72001, 'local_uuid' => $localUuid, 'unique_id' => 'IDENTITY-001', 'company_id' => 7];
    $resolver = app(CustomerIdentityResolver::class);

    $first = $resolver->resolve($portalIdentity, $localId);
    DB::table('customers')->where('id', $localId)->update(['server_id' => 72001]);
    $second = $resolver->resolve($portalIdentity, $localId);

    expect($first->status)->toBe(CustomerIdentityResolution::MATCHED)
        ->and($first->matchedBy)->toBe('local_uuid')
        ->and($first->localId)->toBe($localId)
        ->and($second->status)->toBe(CustomerIdentityResolution::MATCHED)
        ->and($second->localId)->toBe($localId)
        ->and(DB::table('customers')->where('id', $localId)->value('server_id'))->toBe(72001);
});

test('different Customers sharing a Portal server ID return an explicit conflict', function () {
    insertIdentityResolverCustomer(['server_id' => 72002, 'local_uuid' => (string) Str::uuid()]);
    insertIdentityResolverCustomer(['server_id' => 72002, 'local_uuid' => (string) Str::uuid()]);

    $result = app(CustomerIdentityResolver::class)->resolve([
        'id' => 72002,
        'local_uuid' => (string) Str::uuid(),
        'unique_id' => 'IDENTITY-001',
        'company_id' => 7,
    ]);

    expect($result->status)->toBe(CustomerIdentityResolution::CONFLICT)
        ->and($result->reason)->toBe('duplicate_server_id')
        ->and(DB::table('customers')->where('server_id', 72002)->count())->toBe(2);
});

test('numeric ID collision is not used as identity evidence', function () {
    $portalId = 72003;
    $unrelatedId = insertIdentityResolverCustomer([
        'id' => $portalId,
        'name' => 'Unrelated same numeric key',
        'local_uuid' => (string) Str::uuid(),
        'unique_id' => 'UNRELATED-003',
    ]);

    $result = app(CustomerIdentityResolver::class)->resolve([
        'id' => $portalId,
        'local_uuid' => (string) Str::uuid(),
        'unique_id' => 'PORTAL-003',
        'company_id' => 8,
    ]);

    expect($result->status)->toBe(CustomerIdentityResolution::CONFLICT)
        ->and($result->reason)->toBe('numeric_id_occupied_without_identity_match')
        ->and(DB::table('customers')->where('id', $unrelatedId)->value('unique_id'))->toBe('UNRELATED-003');
});

test('Customer 23591 UUID selects the canonical negative local row and leaves the separate numeric row intact', function () {
    DB::table('companies')->insert([
        ['id' => 3, 'name' => 'CAR CLUBS', 'code' => 'CAR_CLUBS', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 6, 'name' => 'IB', 'code' => 'IB', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $canonicalId = -687143025929916172;
    $canonicalUuid = '25a4b477-d5f8-48e4-aca7-3b61346f1f06';
    $otherUuid = 'ed72ddcd-d2e0-48fc-ba08-a6369eaeff9d';
    DB::table('customers')->insert([
        ['id' => $canonicalId, 'server_id' => 23591, 'local_uuid' => $canonicalUuid, 'unique_id' => 'CARCLUBS00013', 'company_id' => 3, 'name' => 'Canonical', 'is_active' => true, 'sync_status' => 'synced'],
        ['id' => 23591, 'server_id' => null, 'local_uuid' => $otherUuid, 'unique_id' => 'IB53385', 'company_id' => 6, 'name' => 'Separate Customer', 'is_active' => true, 'sync_status' => 'synced'],
    ]);
    $before = DB::table('customers')->where('id', 23591)->first();

    $result = app(CustomerIdentityResolver::class)->resolve([
        'id' => 23591,
        'local_uuid' => $canonicalUuid,
        'unique_id' => 'CARCLUBS00013',
        'company_id' => 3,
    ]);

    expect($result->status)->toBe(CustomerIdentityResolution::MATCHED)
        ->and($result->localId)->toBe($canonicalId)
        ->and(DB::table('customers')->where('id', 23591)->first())->toEqual($before)
        ->and(DB::table('customers')->where('id', 23591)->value('local_uuid'))->toBe($otherUuid);
});

test('legacy server ID matching requires corroborating Company and Customer Code when UUID is absent', function () {
    DB::table('companies')->insert(['id' => 9, 'name' => 'Legacy Company', 'code' => 'LEGACY', 'created_at' => now(), 'updated_at' => now()]);
    $localId = insertIdentityResolverCustomer([
        'server_id' => 72004,
        'local_uuid' => null,
        'unique_id' => 'LEGACY-004',
        'company_id' => 9,
    ]);
    $resolver = app(CustomerIdentityResolver::class);

    $match = $resolver->resolve(['id' => 72004, 'local_uuid' => null, 'unique_id' => 'LEGACY-004', 'company_id' => 9]);
    $mismatch = $resolver->resolve(['id' => 72004, 'local_uuid' => null, 'unique_id' => 'OTHER-004', 'company_id' => 9]);
    $uuidConflict = $resolver->resolve(['id' => 72004, 'local_uuid' => (string) Str::uuid(), 'unique_id' => 'LEGACY-004', 'company_id' => 9]);

    expect($match->status)->toBe(CustomerIdentityResolution::MATCHED)
        ->and($match->matchedBy)->toBe('server_id_legacy')
        ->and($match->localId)->toBe($localId)
        ->and($mismatch->status)->toBe(CustomerIdentityResolution::CONFLICT)
        ->and($mismatch->reason)->toBe('legacy_server_id_identity_not_corroborated')
        ->and($uuidConflict->status)->toBe(CustomerIdentityResolution::CONFLICT)
        ->and($uuidConflict->reason)->toBe('server_id_has_different_local_uuid');
});

test('missing UUID cannot resolve a Push acknowledgment by numeric ID alone', function () {
    $localId = insertIdentityResolverCustomer(['company_id' => 10]);

    $result = app(CustomerIdentityResolver::class)->resolve([
        'id' => 72005,
        'local_uuid' => null,
        'unique_id' => 'IDENTITY-001',
        'company_id' => 10,
    ], $localId);

    expect($result->status)->toBe(CustomerIdentityResolution::CONFLICT)
        ->and($result->reason)->toBe('acknowledgment_has_no_matching_local_uuid');
});

test('resolveMany resolves a page with the same outcomes as per-customer resolve', function () {
    DB::table('companies')->insert(['id' => 7, 'name' => 'Batch Company', 'code' => 'BATCH', 'created_at' => now(), 'updated_at' => now()]);

    $uuid = (string) Str::uuid();
    $uuidLocal = insertIdentityResolverCustomer(['local_uuid' => $uuid, 'company_id' => 7, 'unique_id' => 'BATCH-U']);
    $legacyLocal = insertIdentityResolverCustomer(['server_id' => 73001, 'company_id' => 7, 'unique_id' => 'BATCH-L']);

    $page = [
        ['id' => 73000, 'local_uuid' => $uuid, 'unique_id' => 'BATCH-U', 'company_id' => 7],
        ['id' => 73001, 'local_uuid' => null, 'unique_id' => 'BATCH-L', 'company_id' => 7],
        ['id' => 73002, 'local_uuid' => (string) Str::uuid(), 'unique_id' => 'BATCH-N', 'company_id' => 7],
    ];

    $many = app(CustomerIdentityResolver::class)->resolveMany($page);

    expect($many)->toHaveCount(3)
        ->and($many[0]->status)->toBe(CustomerIdentityResolution::MATCHED)
        ->and($many[0]->matchedBy)->toBe('local_uuid')
        ->and($many[0]->localId)->toBe($uuidLocal)
        ->and($many[1]->status)->toBe(CustomerIdentityResolution::MATCHED)
        ->and($many[1]->matchedBy)->toBe('server_id_legacy')
        ->and($many[1]->localId)->toBe($legacyLocal)
        ->and($many[2]->status)->toBe(CustomerIdentityResolution::NEW)
        ->and($many[2]->localId)->toBe(73002);
});

test('resolveMany reports duplicate server ids as conflicts', function () {
    insertIdentityResolverCustomer(['server_id' => 73010]);
    insertIdentityResolverCustomer(['server_id' => 73010]);

    $many = app(CustomerIdentityResolver::class)->resolveMany([
        ['id' => 73010, 'local_uuid' => (string) Str::uuid(), 'unique_id' => 'X', 'company_id' => null],
    ]);

    expect($many[0]->status)->toBe(CustomerIdentityResolution::CONFLICT)
        ->and($many[0]->reason)->toBe('duplicate_server_id');
});
