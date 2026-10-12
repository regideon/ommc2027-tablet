<?php

namespace App\Services\Forms;

use App\Models\Salescall;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Shared plumbing for the per-form offline services: sales-call resolution,
 * local attachment staging, idempotent child snapshots, and upsert-by-server-id.
 */
abstract class AbstractFormService
{
    private const MAX_ATTACHMENTS = 10;

    /** @var array<string, string> */
    private const SUPPORTED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function resolveSalescall(?string $localUuid): ?int
    {
        if ($localUuid === null || trim($localUuid) === '') {
            return null;
        }

        return Salescall::query()->where('local_uuid', $localUuid)->value('id');
    }

    /**
     * Decode and bound temporary attachment payloads before any row is stored.
     *
     * @param  array<int, mixed>  $attachments
     * @return array<int, array{bytes: string, original_name: string, mime_type: string, extension: string, byte_size: int}>
     */
    public function normalizeAttachments(array $attachments): array
    {
        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'attachments' => ['A form may have at most '.self::MAX_ATTACHMENTS.' attachments.'],
            ]);
        }

        $normalized = [];

        foreach ($attachments as $index => $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['data'] ?? null)) {
                throw ValidationException::withMessages([
                    'attachments.'.$index => ['The attachment payload is invalid.'],
                ]);
            }

            $raw = preg_replace('#^data:[^;]+;base64,#i', '', $attachment['data']);
            $bytes = is_string($raw) ? base64_decode($raw, true) : false;

            if ($bytes === false || $bytes === '') {
                throw ValidationException::withMessages([
                    'attachments.'.$index => ['The attachment data could not be decoded.'],
                ]);
            }

            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: null;
            $extension = self::SUPPORTED_MIME_EXTENSIONS[$mimeType] ?? null;

            if ($mimeType === null || $extension === null) {
                throw ValidationException::withMessages([
                    'attachments.'.$index => ['Only JPEG, PNG, and PDF attachments are supported.'],
                ]);
            }

            $originalName = basename((string) ($attachment['original_name'] ?? 'attachment.'.$extension));
            $originalName = mb_substr($originalName !== '' ? $originalName : 'attachment.'.$extension, 0, 255);

            $normalized[] = [
                'bytes' => $bytes,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'byte_size' => strlen($bytes),
            ];
        }

        return $normalized;
    }

    public function storeLocalAttachment(string $directory, string $localUuid, string $extension, string $bytes): string
    {
        $path = $directory.'/'.$localUuid.'.'.$extension;

        Storage::disk('local')->makeDirectory($directory);

        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('The attachment file could not be stored locally.');
        }

        return $path;
    }

    /**
     * Replace a child snapshot wholesale so re-applying the same submission does
     * not duplicate rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function replaceChildren(Model $parent, string $relation, array $rows, Closure $create): void
    {
        $parent->{$relation}()->delete();

        foreach ($rows as $row) {
            $create($parent, $row);
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
    public function upsertByServerId(string $modelClass, array $data, array $attributes): Model
    {
        $serverId = $data['server_id'] ?? null;

        if (filled($serverId)) {
            $model = $modelClass::query()->where('server_id', $serverId)->firstOrFail();
            $model->update($attributes);

            return $model;
        }

        return $modelClass::create($attributes);
    }
}
