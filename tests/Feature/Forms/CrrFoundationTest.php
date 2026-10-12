<?php

use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrAttachment;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\User;
use App\Support\Forms\CrrRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the crr tables and key columns exist', function () {
    expect(Schema::hasColumns('crr_programs', ['id', 'server_id', 'name']))->toBeTrue()
        ->and(Schema::hasColumns('crrs', [
            'id', 'local_uuid', 'server_id', 'submitted_by', 'salescall_id',
            'crr_program_id', 'date_implemented', 'amount', 'details', 'remarks',
            'sap_doc_number', 'rsm_status', 'sps_status', 'spm_status',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('crr_attachments', [
            'id', 'local_uuid', 'server_id', 'crr_id', 'local_path',
            'original_name', 'mime_type', 'extension', 'byte_size',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue();
});

test('crr models expose enum casts and relationships', function () {
    $user = User::factory()->create();

    $program = CrrProgram::create([
        'server_id' => 9001,
        'name' => 'Trade Program',
    ]);

    $crr = Crr::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'crr_program_id' => $program->id,
        'date_implemented' => '2026-10-11',
        'amount' => 1234.56,
        'details' => 'Details',
        'remarks' => 'Remarks',
    ]);

    $attachment = CrrAttachment::create([
        'local_uuid' => (string) Str::uuid(),
        'crr_id' => $crr->id,
        'local_path' => 'crr_attachments/example.png',
        'original_name' => 'example.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 1024,
    ]);

    $fresh = $crr->fresh();

    expect($fresh->rsm_status)->toBe(RSMStatus::PENDING)
        ->and($fresh->sps_status)->toBe(SPSStatus::PENDING)
        ->and($fresh->spm_status)->toBe(SPMStatus::PENDING)
        ->and($fresh->amount)->toBe('1234.56')
        ->and($fresh->program->is($program))->toBeTrue()
        ->and($fresh->submittedBy->is($user))->toBeTrue()
        ->and($program->crrs()->count())->toBe(1)
        ->and($fresh->attachments()->count())->toBe(1)
        ->and($attachment->crr->is($crr))->toBeTrue();
});

test('crr rules accept a valid payload and reject an invalid one', function () {
    $program = CrrProgram::create(['name' => 'Rules Program']);

    $valid = [
        'server_id' => null,
        'salescall_uuid' => null,
        'crr_program_id' => $program->id,
        'date_implemented' => '2026-10-11',
        'amount' => 500.25,
        'details' => 'Details',
        'remarks' => 'Remarks',
    ];

    expect(Validator::make($valid, CrrRules::rules())->passes())->toBeTrue();

    $invalid = array_merge($valid, [
        'crr_program_id' => 999999,
        'amount' => 'not-a-number',
    ]);

    expect(Validator::make($invalid, CrrRules::rules())->passes())->toBeFalse();
});
