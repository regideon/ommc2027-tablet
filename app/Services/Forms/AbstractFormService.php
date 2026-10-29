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
     * Reconcile a cached lookup table with the Portal's current rows.
     *
     * Rows are matched by server id first, then by name, so a Portal reseed
     * that changes ids keeps local ids and submission references intact.
     * Same-name duplicates are merged into the matched row, with child
     * references reassigned before the duplicate is removed. Unreferenced
     * stale rows are removed; referenced stale rows are kept for history.
     * An empty or invalid response leaves local data untouched.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Closure(array<string, mixed>): array<string, mixed>  $values
     * @param  string  $referenceRelation  Relation used to decide whether a stale row is still in use.
     * @return array<int, int>
     */
    protected function reconcileLookup(string $modelClass, array $rows, Closure $values, string $referenceRelation): array
    {
        $currentIds = [];
        $validRows = 0;
        $remoteIds = collect($rows)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->values()
            ->all();

        foreach ($rows as $row) {
            if (! isset($row['id'], $row['name'])) {
                continue;
            }

            $validRows++;

            $model = $modelClass::query()->where('server_id', $row['id'])->first()
                ?? $modelClass::query()->where('name', $row['name'])->first()
                ?? new $modelClass;

            $model->fill($values($row))->save();
            $currentIds[] = $model->id;

            $modelClass::query()
                ->where('name', $row['name'])
                ->whereKeyNot($model->getKey())
                ->where(function ($query) use ($remoteIds): void {
                    $query->whereNull('server_id')
                        ->orWhereNotIn('server_id', $remoteIds);
                })
                ->get()
                ->each(function (Model $duplicate) use ($model, $referenceRelation): void {
                    $relation = $duplicate->{$referenceRelation}();
                    $relation->update([$relation->getForeignKeyName() => $model->getKey()]);
                    $duplicate->delete();
                });
        }

        if ($validRows === 0) {
            return [];
        }

        $modelClass::query()
            ->whereNotIn('id', $currentIds)
            ->withExists($referenceRelation)
            ->get()
            ->each(function (Model $model) use ($referenceRelation): void {
                if (! (bool) ($model->{$referenceRelation.'_exists'} ?? false)) {
                    $model->delete();
                }
            });

        return $currentIds;
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
