<?php

use App\Enums\RACStatus;
use App\Enums\SPSStatus;
use App\Models\Customer;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrAttachment;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\Forms\Marketing\Marketing;
use App\Models\Forms\Marketing\MarketingProgram;
use App\Models\Itinerary;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\User;
use App\Services\Forms\CrrService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function crrWorkflowUser(): User
{
    return User::factory()->create(['api_token' => 'crr-test-token']);
}

function crrWorkflowProgram(?int $serverId = null, bool $isMarketingRequired = false): CrrProgram
{
    return CrrProgram::create([
        'name' => 'Price Support',
        'server_id' => $serverId,
        'is_marketing_required' => $isMarketingRequired,
    ]);
}

function crrPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

function crrValidInput(CrrProgram $program, array $overrides = []): array
{
    return array_merge([
        'crr_program_id' => $program->id,
        'date_implemented' => '2026-10-11',
        'amount' => '1500.50',
        'details' => 'Sample details',
        'remarks' => 'Sample remarks',
        'salescall_uuid' => null,
        'attachments' => [],
    ], $overrides);
}

test('creating a CRR validates the amount and program', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();

    expect(fn () => app(CrrService::class)->create($user, crrValidInput($program, ['amount' => '0'])))
        ->toThrow(ValidationException::class);

    expect(fn () => app(CrrService::class)->create($user, crrValidInput($program, ['crr_program_id' => 9999])))
        ->toThrow(ValidationException::class);
});

test('creating a CRR persists it locally with a pending sync status', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();

    $crr = app(CrrService::class)->create($user, crrValidInput($program));

    expect($crr->exists)->toBeTrue()
        ->and($crr->submitted_by)->toBe($user->id)
        ->and($crr->crr_program_id)->toBe($program->id)
        ->and($crr->sync_status)->toBe('pending')
        ->and($crr->local_uuid)->not->toBeNull()
        ->and($crr->server_id)->toBeNull()
        ->and((float) $crr->amount)->toBe(1500.50);
});

test('creating a CRR stores attachments on the local disk', function () {
    Storage::fake('local');
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();

    $crr = app(CrrService::class)->create($user, crrValidInput($program, [
        'attachments' => [['data' => crrPngDataUri(), 'original_name' => 'support.png']],
    ]));

    $attachment = $crr->attachments()->first();

    expect($attachment)->not->toBeNull()
        ->and($attachment->sync_status)->toBe('pending')
        ->and($attachment->extension)->toBe('png')
        ->and(is_file($attachment->local_path))->toBeTrue();
});

test('a synced CRR is read-only until it is returned to DRM', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();
    $service = app(CrrService::class);

    $crr = $service->create($user, crrValidInput($program));
    $updated = $service->update($crr, crrValidInput($program, ['amount' => '99.99']));

    expect((float) $updated->amount)->toBe(99.99)
        ->and($updated->sync_status)->toBe('pending');

    $updated->update([
        'rsm_status' => 2,
        'sync_status' => 'synced',
    ]);

    expect(fn () => $service->update($updated->refresh(), crrValidInput($program, ['amount' => '10.00'])))
        ->toThrow(ValidationException::class);
});

test('the CRR read service merges local and server rows with the server winning', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(serverId: 55);

    $local = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 900,
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '10.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'synced',
    ]);

    Http::fake([
        '*/api/forms/crr/pull' => Http::response([
            'crrs' => [
                [
                    'id' => 900,
                    'crr_program_id' => 55,
                    'amount' => '555.00',
                    'rsm_status' => 2,
                    'sps_status' => 1,
                    'spm_status' => 1,
                    'attachments' => [],
                ],
                [
                    'id' => 901,
                    'crr_program_id' => 55,
                    'amount' => '777.00',
                    'rsm_status' => 1,
                    'sps_status' => 1,
                    'spm_status' => 1,
                    'attachments' => [
                        ['id' => 12, 'original_name' => 'a.png', 'mime_type' => 'image/png', 'url' => 'https://example.test/a.png'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($user);

    $rows = app(CrrService::class)->submissions();

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->firstWhere('server_id', 900)['amount'])->toBe('555.00')
        ->and(collect($rows)->firstWhere('server_id', 901)['attachments'][0]['url'])->toBe('https://example.test/a.png');
});

test('the CRR read service keeps local rows when the server read fails', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();

    Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '10.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    Http::fake(['*/api/forms/crr/pull' => Http::response([], 500)]);

    $this->actingAs($user);
    $service = app(CrrService::class);
    $rows = $service->submissions();

    expect($rows)->toHaveCount(1)
        ->and($service->globalReadFailed())->toBeTrue();
});

test('the CRR read service upserts programs from the server', function () {
    $user = crrWorkflowUser();

    Http::fake([
        '*/api/forms/crr/programs' => Http::response([
            'crr_programs' => [
                ['id' => 10, 'name' => 'Price Support'],
                ['id' => 11, 'name' => 'Listing Fee'],
            ],
        ], 200),
    ]);

    $this->actingAs($user);
    $programs = app(CrrService::class)->programs();

    expect($programs)->toHaveCount(2)
        ->and(CrrProgram::where('server_id', 10)->value('name'))->toBe('Price Support');
});

test('pushing a CRR posts the mapped payload and stores the server id', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(serverId: 55);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '250.00',
        'date_implemented' => '2026-10-11',
        'details' => 'Details',
        'remarks' => 'Remarks',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    Http::fake([
        '*/api/forms/crr/push' => Http::response(['server_id' => 777], 200),
    ]);

    app(SyncService::class)->push();

    Http::assertSent(function ($request) use ($program) {
        return str_ends_with($request->url(), '/api/forms/crr/push')
            && $request['crr_program_id'] === $program->server_id
            && (float) $request['amount'] === 250.0
            && $request['salescall_uuid'] === null
            && ! isset($request['server_id']);
    });

    expect($crr->fresh()->sync_status)->toBe('synced')
        ->and($crr->fresh()->server_id)->toBe(777);
});

test('pushing an already-synced CRR sends its server id', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(serverId: 55);
    $salescall = crrWorkflowSalescall($user);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 321,
        'submitted_by' => $user->id,
        'salescall_id' => $salescall->id,
        'crr_program_id' => $program->id,
        'amount' => '250.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    Http::fake(fn ($request) => str_contains($request->url(), '/api/forms/crr/push')
        ? Http::response(['server_id' => 321], 200)
        : Http::response([], 200));

    app(SyncService::class)->push();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/forms/crr/push')
        && $request['server_id'] === 321
        && $request['salescall_uuid'] === $salescall->local_uuid);
});

test('CRR attachments are only pushed after the parent has a server id', function () {
    Storage::fake('local');
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(serverId: 55);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 500,
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '250.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'synced',
    ]);

    Storage::disk('local')->put('crr_attachments/a.png', 'bytes');
    $attachment = CrrAttachment::create([
        'local_uuid' => (string) Str::uuid(),
        'crr_id' => $crr->id,
        'local_path' => Storage::disk('local')->path('crr_attachments/a.png'),
        'original_name' => 'a.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 5,
        'sync_status' => 'pending',
    ]);

    Http::fake(['*/api/forms/crr/attach' => Http::response(['server_id' => 88, 'uploaded_file_id' => 88], 200)]);

    app(SyncService::class)->push();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/forms/crr/attach'));

    expect($attachment->fresh()->sync_status)->toBe('synced')
        ->and($attachment->fresh()->server_id)->toBe(88);
});

test('has pending changes includes CRR rows', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();

    Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '10.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    expect(app(SyncService::class)->hasPendingChanges())->toBeTrue();
});

test('a CRR is editable before sync and read-only after sync unless returned to DRM', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '100.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    expect($crr->is_editable)->toBeTrue();

    $crr->update(['sync_status' => 'synced']);

    expect($crr->fresh()->is_editable)->toBeFalse();

    $crr->update(['sps_status' => SPSStatus::RETURNED->value]);

    expect($crr->fresh()->is_editable)->toBeTrue();
});

test('a pulled server CRR can be adopted locally for editing', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(serverId: 55);

    $crr = app(CrrService::class)->adopt($user, [
        'server_id' => 901,
        'crr_program_id' => 55,
        'amount' => '200.00',
        'rsm_status' => 1,
        'sps_status' => SPSStatus::RETURNED->value,
        'spm_status' => 1,
    ]);

    expect($crr->server_id)->toBe(901)
        ->and($crr->crr_program_id)->toBe($program->id)
        ->and($crr->sync_status)->toBe('synced')
        ->and($crr->is_editable)->toBeTrue();
});

test('the CRR read service flags a failed program pull', function () {
    $user = crrWorkflowUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake(['*/api/forms/crr/programs' => Http::response([], 500)]);

    $service = app(CrrService::class);
    $service->programs();

    expect($service->programReadFailed())->toBeTrue();
});

test('the CRR read service clears the program failure flag on a successful pull', function () {
    $user = crrWorkflowUser();
    $this->actingAs($user);
    config(['sync.server_url' => 'http://portal.test']);
    Http::fake(['*/api/forms/crr/programs' => Http::response([
        'crr_programs' => [['id' => 1, 'name' => 'Price Support']],
    ], 200)]);

    $service = app(CrrService::class);
    $service->programs();

    expect($service->programReadFailed())->toBeFalse()
        ->and(CrrProgram::where('server_id', 1)->value('name'))->toBe('Price Support');
});

test('a CRR deleted on the server is purged locally on the next successful pull', function () {
    Storage::fake('local');
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(serverId: 55);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 900,
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '10.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'synced',
    ]);

    Storage::disk('local')->put('crr_attachments/a.png', 'bytes');

    $attachment = CrrAttachment::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 88,
        'crr_id' => $crr->id,
        'local_path' => Storage::disk('local')->path('crr_attachments/a.png'),
        'original_name' => 'a.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 5,
        'sync_status' => 'synced',
    ]);

    $previewPath = public_path('crr_attachments/'.$attachment->local_uuid.'.png');
    File::ensureDirectoryExists(dirname($previewPath));
    file_put_contents($previewPath, 'bytes');

    Http::fake(['*/api/forms/crr/pull' => Http::response(['crrs' => []], 200)]);
    $this->actingAs($user);

    $rows = app(CrrService::class)->submissions();

    expect($rows)->toHaveCount(0)
        ->and(Crr::find($crr->id))->toBeNull()
        ->and(CrrAttachment::find($attachment->id))->toBeNull()
        ->and(is_file($attachment->local_path))->toBeFalse()
        ->and(is_file($previewPath))->toBeFalse();
});

test('a local unsynced CRR survives a successful pull that does not include it', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '10.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'pending',
    ]);

    Http::fake(['*/api/forms/crr/pull' => Http::response(['crrs' => []], 200)]);
    $this->actingAs($user);

    $rows = app(CrrService::class)->submissions();

    expect($rows)->toHaveCount(1)
        ->and(Crr::find($crr->id))->not->toBeNull();
});

test('pulled CRR approval statuses are cached on synced local rows', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(serverId: 55);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 900,
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '10.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'synced',
    ]);

    Http::fake(['*/api/forms/crr/pull' => Http::response(['crrs' => [[
        'id' => 900,
        'crr_program_id' => 55,
        'amount' => '10.00',
        'rsm_status' => 1,
        'sps_status' => SPSStatus::RETURNED->value,
        'spm_status' => 1,
        'attachments' => [],
    ]]], 200)]);
    $this->actingAs($user);

    $rows = app(CrrService::class)->submissions();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['is_editable'])->toBeTrue()
        ->and($crr->fresh()->sps_status)->toBe(SPSStatus::RETURNED);
});

test('a failed pull does not purge local synced CRRs', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(serverId: 55);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'server_id' => 900,
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'amount' => '10.00',
        'rsm_status' => 1,
        'sps_status' => 1,
        'spm_status' => 1,
        'sync_status' => 'synced',
    ]);

    Http::fake(['*/api/forms/crr/pull' => Http::response([], 500)]);
    $this->actingAs($user);

    $rows = app(CrrService::class)->submissions();

    expect($rows)->toHaveCount(1)
        ->and(Crr::find($crr->id))->not->toBeNull();
});

function crrWorkflowSalescall(User $user): Salescall
{
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);
    $customer = Customer::create(['name' => 'CRR Customer', 'is_active' => true]);

    return Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);
}

function crrWorkflowMarketing(
    User $user,
    Salescall $salescall,
    int $racStatus = RACStatus::APPROVED->value,
): Marketing {
    $program = MarketingProgram::create(['name' => 'Price Support']);
    $region = RegionType::create(['name' => 'Metro Manila']);
    $role = Role::firstOrCreate(['name' => 'drm', 'guard_name' => 'web']);

    return Marketing::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_at' => now(),
        'submitted_by' => $user->id,
        'region_type_id' => $region->id,
        'role_id' => $role->id,
        'program_id' => $program->id,
        'salescall_id' => $salescall->id,
        'duration_start_at' => now(),
        'duration_end_at' => now()->addMonth(),
        'objective' => 'Grow monthly case volume',
        'name' => 'Branch Expansion Support',
        'mechanics_description' => 'Volume rebate per incremental case.',
        'sps_status' => SPSStatus::ENDORSED->value,
        'rac_status' => $racStatus,
        'rac_return_count' => 0,
        'sync_status' => 'pending',
    ]);
}

test('creating a CRR linked to a sales call does not require Marketing for a non-requiring program', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram();
    $salescall = crrWorkflowSalescall($user);

    $crr = app(CrrService::class)->create($user, crrValidInput($program, [
        'salescall_uuid' => $salescall->local_uuid,
    ]));

    expect($crr->salescall_id)->toBe($salescall->id);
});

test('creating a CRR linked to a sales call requires approved Marketing for a requiring program', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(isMarketingRequired: true);
    $salescall = crrWorkflowSalescall($user);

    expect(fn () => app(CrrService::class)->create($user, crrValidInput($program, [
        'salescall_uuid' => $salescall->local_uuid,
    ])))->toThrow(ValidationException::class);

    expect(Crr::query()->count())->toBe(0);
});

test('creating a CRR linked to a sales call rejects a pending Marketing for a requiring program', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(isMarketingRequired: true);
    $salescall = crrWorkflowSalescall($user);

    crrWorkflowMarketing($user, $salescall, RACStatus::PENDING->value);

    expect(fn () => app(CrrService::class)->create($user, crrValidInput($program, [
        'salescall_uuid' => $salescall->local_uuid,
    ])))->toThrow(ValidationException::class);

    expect(Crr::query()->count())->toBe(0);
});

test('creating a CRR linked to a sales call with an approved Marketing recommendation succeeds', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(isMarketingRequired: true);
    $salescall = crrWorkflowSalescall($user);

    crrWorkflowMarketing($user, $salescall);

    $crr = app(CrrService::class)->create($user, crrValidInput($program, [
        'salescall_uuid' => $salescall->local_uuid,
    ]));

    expect($crr->salescall_id)->toBe($salescall->id);
});

test('a Marketing for another sales call or another rep does not satisfy a requiring program', function () {
    $user = crrWorkflowUser();
    $otherUser = crrWorkflowUser();
    $program = crrWorkflowProgram(isMarketingRequired: true);
    $salescall = crrWorkflowSalescall($user);
    $otherSalescall = crrWorkflowSalescall($user);

    crrWorkflowMarketing($otherUser, $salescall);
    crrWorkflowMarketing($user, $otherSalescall);

    expect(fn () => app(CrrService::class)->create($user, crrValidInput($program, [
        'salescall_uuid' => $salescall->local_uuid,
    ])))->toThrow(ValidationException::class);

    expect(Crr::query()->count())->toBe(0);
});

test('a standalone CRR with a requiring program does not require Marketing', function () {
    $user = crrWorkflowUser();
    $program = crrWorkflowProgram(isMarketingRequired: true);

    $crr = app(CrrService::class)->create($user, crrValidInput($program));

    expect($crr->salescall_id)->toBeNull();
});

test('editing a linked CRR into a requiring program requires approved Marketing', function () {
    $user = crrWorkflowUser();
    $exemptProgram = crrWorkflowProgram();
    $requiringProgram = crrWorkflowProgram(isMarketingRequired: true);
    $salescall = crrWorkflowSalescall($user);
    $service = app(CrrService::class);

    $crr = $service->create($user, crrValidInput($exemptProgram, [
        'salescall_uuid' => $salescall->local_uuid,
    ]));

    expect(fn () => $service->update($crr, crrValidInput($requiringProgram, [
        'salescall_uuid' => $salescall->local_uuid,
    ])))->toThrow(ValidationException::class);

    expect($crr->fresh()->crr_program_id)->toBe($exemptProgram->id);

    crrWorkflowMarketing($user, $salescall);

    $updated = $service->update($crr->fresh(), crrValidInput($requiringProgram, [
        'salescall_uuid' => $salescall->local_uuid,
    ]));

    expect($updated->crr_program_id)->toBe($requiringProgram->id);
});

test('the CRR program sync persists the marketing requirement flag', function () {
    config(['sync.server_url' => 'http://portal.test']);
    $this->actingAs(crrWorkflowUser());

    Http::fake([
        '*/api/forms/crr/programs' => Http::response([
            'crr_programs' => [
                ['id' => 501, 'name' => 'Price Support', 'is_marketing_required' => true],
                ['id' => 502, 'name' => 'Battery Swap', 'is_marketing_required' => false],
                ['id' => 503, 'name' => 'Legacy Program'],
            ],
        ], 200),
    ]);

    app(CrrService::class)->programs();

    expect(CrrProgram::query()->where('name', 'Price Support')->first()?->is_marketing_required)->toBeTrue()
        ->and(CrrProgram::query()->where('name', 'Battery Swap')->first()?->is_marketing_required)->toBeFalse()
        ->and(CrrProgram::query()->where('name', 'Legacy Program')->first()?->is_marketing_required)->toBeTrue();
});
