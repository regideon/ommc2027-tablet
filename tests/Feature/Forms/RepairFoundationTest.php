<?php

use App\Enums\Repair\RepairAttachmentType;
use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\Repair\Repair;
use App\Models\Forms\Repair\RepairAttachment;
use App\Models\User;
use App\Support\Forms\RepairRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the repair tables and key columns exist', function () {
    expect(Schema::hasColumns('repairs', [
        'id', 'local_uuid', 'server_id', 'submitted_by', 'salescall_id',
        'details', 'plate_number', 'invoice_number', 'amount', 'remarks',
        'sap_doc_number', 'rsm_status', 'sps_status', 'spm_status',
        'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('repair_attachments', [
            'id', 'local_uuid', 'server_id', 'repair_id', 'type',
            'local_path', 'original_name', 'mime_type', 'extension', 'byte_size',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue();
});

test('repair models expose enum casts and relationships', function () {
    $user = User::factory()->create();

    $repair = Repair::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'details' => 'Engine repair',
        'plate_number' => 'ABC 1234',
        'invoice_number' => 'INV-1001',
        'amount' => 1234.56,
        'remarks' => 'Remarks',
    ]);

    $attachment = RepairAttachment::create([
        'local_uuid' => (string) Str::uuid(),
        'repair_id' => $repair->id,
        'type' => RepairAttachmentType::INVOICE->value,
        'local_path' => 'repair_attachments/example.png',
        'original_name' => 'example.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'byte_size' => 1024,
    ]);

    $fresh = $repair->fresh();

    expect($fresh->rsm_status)->toBe(RSMStatus::PENDING)
        ->and($fresh->sps_status)->toBe(SPSStatus::PENDING)
        ->and($fresh->spm_status)->toBe(SPMStatus::PENDING)
        ->and($fresh->amount)->toBe('1234.56')
        ->and($fresh->submittedBy->is($user))->toBeTrue()
        ->and($fresh->attachments()->count())->toBe(1)
        ->and($attachment->type)->toBe(RepairAttachmentType::INVOICE)
        ->and($attachment->repair->is($repair))->toBeTrue();
});

test('repair rules accept a valid payload and reject an invalid one', function () {
    $valid = [
        'server_id' => null,
        'salescall_uuid' => null,
        'plate_number' => 'ABC 1234',
        'invoice_number' => 'INV-1001',
        'details' => 'Engine repair',
        'amount' => 500.25,
        'remarks' => 'Remarks',
    ];

    expect(Validator::make($valid, RepairRules::rules())->passes())->toBeTrue();

    $invalid = [
        'plate_number' => '',
        'amount' => 'not-a-number',
    ];

    expect(Validator::make($invalid, RepairRules::rules())->passes())->toBeFalse();
});
