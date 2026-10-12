<?php

use App\Enums\CRRSignage\SignageAttachmentType;
use App\Enums\CRRSignage\SignageCategory;
use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Models\Forms\CRRSignage\Signage;
use App\Models\Forms\CRRSignage\SignageAttachment;
use App\Models\Forms\CRRSignage\SignageProduct;
use App\Models\Forms\CRRSignage\SignageType;
use App\Models\User;
use App\Support\Forms\SignageRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('signage form tables and key columns exist', function () {
    expect(Schema::hasTable('signage_products'))->toBeTrue()
        ->and(Schema::hasTable('signage_types'))->toBeTrue()
        ->and(Schema::hasTable('signages'))->toBeTrue()
        ->and(Schema::hasTable('signage_attachments'))->toBeTrue();

    expect(Schema::hasColumns('signage_products', ['id', 'server_id', 'name', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('signage_types', ['id', 'server_id', 'name', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('signages', [
            'id', 'local_uuid', 'server_id', 'submitted_by', 'salescall_id',
            'product_id', 'type_id', 'category', 'name',
            'rsm_status', 'tdh_status', 'tds_status',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('signage_attachments', [
            'id', 'local_uuid', 'server_id', 'signage_id', 'type',
            'local_path', 'original_name', 'mime_type', 'extension', 'byte_size',
            'sync_status', 'sync_attempts', 'sync_error', 'synced_at',
        ]))->toBeTrue();
});

/**
 * @return array{user: User, product: SignageProduct, type: SignageType, signage: Signage}
 */
function signageFoundationRecords(): array
{
    $user = User::factory()->create();
    $product = SignageProduct::create(['name' => 'Tarpaulin']);
    $type = SignageType::create(['name' => 'Horizontal']);

    $signage = Signage::create([
        'local_uuid' => (string) Str::uuid(),
        'submitted_by' => $user->id,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
    ]);

    return ['user' => $user, 'product' => $product, 'type' => $type, 'signage' => $signage];
}

test('signage models cast enums and resolve their relationships', function () {
    ['user' => $user, 'product' => $product, 'type' => $type, 'signage' => $signage] = signageFoundationRecords();

    $signage = $signage->fresh();

    expect($signage->category)->toBe(SignageCategory::NEW)
        ->and($signage->rsm_status)->toBe(RSMStatus::PENDING)
        ->and($signage->tdh_status)->toBe(TDHStatus::PENDING)
        ->and($signage->tds_status)->toBe(TDSStatus::PENDING)
        ->and($signage->submittedBy->is($user))->toBeTrue()
        ->and($signage->product->is($product))->toBeTrue()
        ->and($signage->type->is($type))->toBeTrue()
        ->and($product->signages()->where('id', $signage->id)->exists())->toBeTrue()
        ->and($type->signages()->where('id', $signage->id)->exists())->toBeTrue();

    $attachment = SignageAttachment::create([
        'local_uuid' => (string) Str::uuid(),
        'signage_id' => $signage->id,
        'type' => SignageAttachmentType::REQUEST->value,
        'local_path' => 'signage_attachments/'.$signage->local_uuid.'.png',
    ]);

    expect($attachment->type)->toBe(SignageAttachmentType::REQUEST)
        ->and($attachment->signage->is($signage))->toBeTrue()
        ->and($signage->attachments()->where('id', $attachment->id)->exists())->toBeTrue();
});

test('signage rules accept a valid payload and reject an invalid one', function () {
    $product = SignageProduct::create(['name' => 'Tarpaulin']);
    $type = SignageType::create(['name' => 'Horizontal']);

    $valid = [
        'server_id' => null,
        'salescall_uuid' => null,
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => SignageCategory::NEW->value,
        'name' => 'Store Front Signage',
    ];

    expect(Validator::make($valid, SignageRules::rules())->passes())->toBeTrue();

    $invalid = [
        'product_id' => $product->id,
        'type_id' => $type->id,
        'category' => 'not-an-integer',
        'name' => '',
    ];

    $validator = Validator::make($invalid, SignageRules::rules());

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->has('category'))->toBeTrue()
        ->and($validator->errors()->has('name'))->toBeTrue();
});
