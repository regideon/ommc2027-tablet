<?php

use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function stagedAttachmentUser(): User
{
    return User::factory()->create();
}

function stagedPngDataUri(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );

    return 'data:image/png;base64,'.base64_encode($png);
}

test('staging a data URI stores the file and a staging row', function () {
    Storage::fake('local');
    $user = stagedAttachmentUser();

    $staged = app(StagedAttachmentService::class)->stageFromDataUri($user, stagedPngDataUri(), 'photo.png');

    expect($staged->user_id)->toBe($user->id)
        ->and($staged->mime_type)->toBe('image/png')
        ->and($staged->extension)->toBe('png')
        ->and($staged->original_name)->toBe('photo.png')
        ->and(is_file($staged->local_path))->toBeTrue();
});

test('staging rejects unsupported mime types', function () {
    Storage::fake('local');
    $user = stagedAttachmentUser();

    expect(fn () => app(StagedAttachmentService::class)->stageFromDataUri($user, 'data:text/plain;base64,'.base64_encode('hello'), 'x.txt'))
        ->toThrow(ValidationException::class);
});

test('consuming a staged attachment moves the file into the target directory and clears staging', function () {
    Storage::fake('local');
    $user = stagedAttachmentUser();
    $service = app(StagedAttachmentService::class);

    $staged = $service->stageFromDataUri($user, stagedPngDataUri(), 'photo.png');
    $sourcePath = $staged->local_path;

    $metadata = $service->consume($user, $staged, 'trade_check_attachments');

    expect(StagedAttachment::find($staged->id))->toBeNull()
        ->and(is_file($sourcePath))->toBeFalse()
        ->and(is_file($metadata['local_path']))->toBeTrue()
        ->and($metadata['original_name'])->toBe('photo.png')
        ->and($metadata['extension'])->toBe('png');
});

test('consuming another users staged attachment is rejected', function () {
    Storage::fake('local');
    $owner = stagedAttachmentUser();
    $other = stagedAttachmentUser();
    $service = app(StagedAttachmentService::class);

    $staged = $service->stageFromDataUri($owner, stagedPngDataUri(), 'photo.png');

    expect(fn () => $service->consume($other, $staged, 'trade_check_attachments'))
        ->toThrow(ValidationException::class);
});

test('discarding a staged attachment deletes the file and row', function () {
    Storage::fake('local');
    $user = stagedAttachmentUser();
    $service = app(StagedAttachmentService::class);

    $staged = $service->stageFromDataUri($user, stagedPngDataUri(), 'photo.png');
    $path = $staged->local_path;

    $service->discard($staged);

    expect(StagedAttachment::find($staged->id))->toBeNull()
        ->and(is_file($path))->toBeFalse();
});

test('pruning removes staged attachments older than the window', function () {
    Storage::fake('local');
    $user = stagedAttachmentUser();
    $service = app(StagedAttachmentService::class);

    $old = $service->stageFromDataUri($user, stagedPngDataUri(), 'old.png');
    $fresh = $service->stageFromDataUri($user, stagedPngDataUri(), 'fresh.png');

    $old->forceFill(['created_at' => now()->subDays(2)])->save();

    $removed = $service->prune(24);

    expect($removed)->toBe(1)
        ->and(StagedAttachment::find($old->id))->toBeNull()
        ->and(StagedAttachment::find($fresh->id))->not->toBeNull();
});
