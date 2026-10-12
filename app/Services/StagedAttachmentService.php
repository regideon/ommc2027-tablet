<?php

namespace App\Services;

use App\Models\StagedAttachment;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Stages uploaded/captured attachments one file at a time so form saves never
 * carry image bytes (and each request stays under the PHP upload limits).
 * Forms consume a staged row into their own attachment table on save.
 */
class StagedAttachmentService
{
    public const MAX_BYTES = 6 * 1024 * 1024;

    private const DIRECTORY = 'staged_attachments';

    /** @var array<string, string> */
    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function stageFromPath(User $user, string $path, ?string $originalName = null): StagedAttachment
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'attachment' => ['The selected file could not be read.'],
            ]);
        }

        $bytes = file_get_contents($path);

        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages([
                'attachment' => ['The selected file is empty.'],
            ]);
        }

        return $this->store($user, $bytes, $originalName ?? basename($path));
    }

    public function stageFromDataUri(User $user, string $data, ?string $originalName = null): StagedAttachment
    {
        $raw = preg_replace('#^data:[^;]+;base64,#i', '', $data);
        $bytes = is_string($raw) ? base64_decode($raw, true) : false;

        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages([
                'attachment' => ['The attachment data could not be decoded.'],
            ]);
        }

        return $this->store($user, $bytes, $originalName ?? 'attachment');
    }

    public function findForUser(User $user, int $id): ?StagedAttachment
    {
        return StagedAttachment::query()
            ->where('user_id', $user->id)
            ->find($id);
    }

    /**
     * Move a staged file into a form's attachment directory and remove the
     * staging row.
     *
     * @return array{local_path: string, original_name: string, mime_type: string, extension: string, byte_size: int}
     */
    public function consume(User $user, StagedAttachment $staged, string $targetDirectory): array
    {
        if ($staged->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'attachment' => ['The staged attachment does not belong to this user.'],
            ]);
        }

        $extension = $staged->extension ?: 'bin';
        $relativeTarget = $targetDirectory.'/'.Str::uuid().'.'.$extension;
        $targetAbsolute = Storage::disk('local')->path($relativeTarget);
        $source = (string) $staged->local_path;

        if (! is_dir(dirname($targetAbsolute))) {
            mkdir(dirname($targetAbsolute), 0777, true);
        }

        $moved = $source !== '' && is_file($source) && @rename($source, $targetAbsolute);

        if (! $moved && $source !== '' && is_file($source)) {
            $moved = @copy($source, $targetAbsolute);

            if ($moved) {
                @unlink($source);
            }
        }

        if (! $moved) {
            throw ValidationException::withMessages([
                'attachment' => ['The staged attachment file is no longer available.'],
            ]);
        }

        $metadata = [
            'local_path' => $targetAbsolute,
            'original_name' => $staged->original_name ?? 'attachment.'.$extension,
            'mime_type' => $staged->mime_type ?? 'application/octet-stream',
            'extension' => $extension,
            'byte_size' => (int) $staged->byte_size,
        ];

        $this->deletePreview($staged);
        $staged->delete();

        return $metadata;
    }

    public function discard(StagedAttachment $staged): void
    {
        $this->deletePreview($staged);

        if ($staged->local_path && is_file($staged->local_path)) {
            @unlink($staged->local_path);
        }

        $staged->delete();
    }

    public function previewUrl(StagedAttachment $staged): ?string
    {
        if (! $staged->local_path || ! is_file($staged->local_path)) {
            return null;
        }

        $previewPath = $this->previewPath($staged);

        try {
            File::ensureDirectoryExists(dirname($previewPath));
        } catch (\Throwable) {
            return null;
        }

        if (! is_file($previewPath) && @copy($staged->local_path, $previewPath) === false) {
            return null;
        }

        return '/_assets/staged_attachments/'.basename($previewPath);
    }

    public function prune(int $hours = 24): int
    {
        $expired = StagedAttachment::query()
            ->where('created_at', '<', now()->subHours($hours))
            ->get();

        foreach ($expired as $staged) {
            $this->discard($staged);
        }

        return $expired->count();
    }

    private function store(User $user, string $bytes, string $originalName): StagedAttachment
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'attachment' => ['The attachment must be 6 MB or smaller.'],
            ]);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: null;
        $extension = self::ALLOWED_MIME_EXTENSIONS[$mimeType] ?? null;

        if ($mimeType === null || $extension === null) {
            throw ValidationException::withMessages([
                'attachment' => ['Only JPEG, PNG, and PDF attachments are supported.'],
            ]);
        }

        $uuid = (string) Str::uuid();
        $relativePath = self::DIRECTORY.'/'.$uuid.'.'.$extension;

        Storage::disk('local')->makeDirectory(self::DIRECTORY);

        if (! Storage::disk('local')->put($relativePath, $bytes)) {
            throw new RuntimeException('The attachment could not be stored locally.');
        }

        $name = basename($originalName !== '' ? $originalName : 'attachment.'.$extension);

        return StagedAttachment::create([
            'local_uuid' => $uuid,
            'user_id' => $user->id,
            'local_path' => Storage::disk('local')->path($relativePath),
            'original_name' => mb_substr($name, 0, 255),
            'mime_type' => $mimeType,
            'extension' => $extension,
            'byte_size' => strlen($bytes),
        ]);
    }

    private function previewPath(StagedAttachment $staged): string
    {
        $extension = $staged->extension ?: 'bin';

        return public_path('staged_attachments/'.$staged->local_uuid.'.'.$extension);
    }

    private function deletePreview(StagedAttachment $staged): void
    {
        $previewPath = $this->previewPath($staged);

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }
}
