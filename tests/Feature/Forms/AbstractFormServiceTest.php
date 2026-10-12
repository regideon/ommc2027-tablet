<?php

use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\SalescallImage;
use App\Models\User;
use App\Services\Forms\AbstractFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function foundationFormService(): AbstractFormService
{
    return new class extends AbstractFormService {};
}

function foundationSalescall(): Salescall
{
    $user = User::factory()->create();
    $itinerary = Itinerary::create(['created_by' => $user->id, 'itinerary_status_id' => 2]);
    $customer = Customer::create(['name' => 'Foundation Customer', 'is_active' => true]);

    return Salescall::create([
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'local_uuid' => (string) Str::uuid(),
        'sync_status' => 'pending',
        'actual_in' => now(),
    ]);
}

function foundationPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

test('resolve salescall returns the id for a known local uuid and null otherwise', function () {
    $salescall = foundationSalescall();
    $service = foundationFormService();

    expect($service->resolveSalescall($salescall->local_uuid))->toBe($salescall->id)
        ->and($service->resolveSalescall(null))->toBeNull()
        ->and($service->resolveSalescall('missing-uuid'))->toBeNull();
});

test('replace children deletes the previous snapshot and recreates rows', function () {
    $salescall = foundationSalescall();

    SalescallImage::create([
        'salescall_id' => $salescall->id,
        'salescall_image_type_id' => 1,
        'local_path' => '/tmp/old.png',
        'local_uuid' => (string) Str::uuid(),
    ]);

    $service = foundationFormService();

    $service->replaceChildren($salescall, 'images', [
        ['path' => '/tmp/a.png'],
        ['path' => '/tmp/b.png'],
    ], function (Salescall $parent, array $row): void {
        SalescallImage::create([
            'salescall_id' => $parent->id,
            'salescall_image_type_id' => 1,
            'local_path' => $row['path'],
            'local_uuid' => (string) Str::uuid(),
        ]);
    });

    expect($salescall->images()->count())->toBe(2)
        ->and($salescall->images()->where('local_path', '/tmp/old.png')->exists())->toBeFalse();
});

test('replace children is idempotent when the same snapshot is applied twice', function () {
    $salescall = foundationSalescall();
    $service = foundationFormService();

    $snapshot = [['path' => '/tmp/a.png'], ['path' => '/tmp/b.png']];
    $create = function (Salescall $parent, array $row): void {
        SalescallImage::create([
            'salescall_id' => $parent->id,
            'salescall_image_type_id' => 1,
            'local_path' => $row['path'],
            'local_uuid' => (string) Str::uuid(),
        ]);
    };

    $service->replaceChildren($salescall, 'images', $snapshot, $create);
    $service->replaceChildren($salescall, 'images', $snapshot, $create);

    expect($salescall->images()->count())->toBe(2);
});

test('normalize attachments accepts images and rejects unsupported payloads', function () {
    $service = foundationFormService();

    $normalized = $service->normalizeAttachments([
        ['data' => foundationPngDataUri(), 'original_name' => 'photo.png'],
    ]);

    expect($normalized)->toHaveCount(1)
        ->and($normalized[0]['mime_type'])->toBe('image/png')
        ->and($normalized[0]['extension'])->toBe('png')
        ->and($normalized[0]['original_name'])->toBe('photo.png')
        ->and($normalized[0]['byte_size'])->toBeGreaterThan(0);
});

test('normalize attachments rejects unsupported mime types', function () {
    $service = foundationFormService();

    expect(fn () => $service->normalizeAttachments([
        ['data' => 'data:text/plain;base64,'.base64_encode('hello'), 'original_name' => 'x.txt'],
    ]))->toThrow(ValidationException::class);
});

test('normalize attachments bounds the count to ten', function () {
    $service = foundationFormService();

    $attachments = array_fill(0, 11, [
        'data' => foundationPngDataUri(),
        'original_name' => 'photo.png',
    ]);

    expect(fn () => $service->normalizeAttachments($attachments))
        ->toThrow(ValidationException::class);
});

test('store local attachment writes the file and returns its relative path', function () {
    Storage::fake('local');
    $service = foundationFormService();
    $bytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    $path = $service->storeLocalAttachment('form_attachments', 'uuid-123', 'png', $bytes);

    expect($path)->toBe('form_attachments/uuid-123.png')
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

test('upsert by server id updates when present and creates otherwise', function () {
    $service = foundationFormService();
    $customer = Customer::create(['name' => 'Upsert Customer', 'is_active' => true]);

    $created = $service->upsertByServerId(Customer::class, [], ['server_id' => 555, 'name' => 'First']);
    expect($created)->toBeInstanceOf(Customer::class)
        ->and($created->server_id)->toBe(555);

    $updated = $service->upsertByServerId(Customer::class, ['server_id' => 555], ['name' => 'Second']);
    expect($updated->id)->toBe($created->id)
        ->and($updated->fresh()->name)->toBe('Second');

    expect($customer->id)->not->toBe($created->id);
});
