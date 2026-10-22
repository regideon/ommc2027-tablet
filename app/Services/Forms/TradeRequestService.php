<?php

namespace App\Services\Forms;

use App\Contracts\FormSubmissionService;
use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Enums\TradeRequest\Size;
use App\Enums\TradeRequest\TradeRequestAttachmentType;
use App\Models\Forms\TradeRequest\TradeProduct;
use App\Models\Forms\TradeRequest\TradeRequest;
use App\Models\Forms\TradeRequest\TradeRequestAttachment;
use App\Models\Forms\TradeRequest\TradeRequestItem;
use App\Models\Forms\TradeRequest\TradeType;
use App\Models\Salescall;
use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use App\Services\SyncService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The Trade Request form's single service: reads the current user's
 * submissions (local rows cached from a live, scoped Portal pull) and
 * creates/edits them offline-first. Products and types are pull-only masters,
 * items are a wholesale snapshot, and attachments are typed (request,
 * completed) with at least one request required.
 */
class TradeRequestService extends AbstractFormService implements FormSubmissionService
{
    private bool $globalReadFailed = false;

    private bool $programReadFailed = false;

    /** @var array{trade_requests: array<int, array<string, mixed>>, failed: bool}|null */
    private ?array $pullCache = null;

    public function __construct(private readonly SyncService $syncService) {}

    /*
    |--------------------------------------------------------------------------
    | READS
    |--------------------------------------------------------------------------
    */

    /**
     * Refresh both master picklists (products and types) from the Portal; the
     * contract returns the product cache, and the form page reads both.
     *
     * @return Collection<int, TradeProduct>
     */
    public function programs(): Collection
    {
        $products = $this->syncService->readTradeRequestProductsWithStatus();
        $this->programReadFailed = $products['failed'];

        if (! $products['failed']) {
            foreach ($products['products'] as $product) {
                if (! isset($product['id'], $product['name'])) {
                    continue;
                }

                TradeProduct::updateOrCreate(
                    ['server_id' => $product['id']],
                    ['name' => $product['name']],
                );
            }
        }

        $types = $this->syncService->readTradeRequestTypesWithStatus();
        $this->programReadFailed = $this->programReadFailed || $types['failed'];

        if (! $types['failed']) {
            foreach ($types['types'] as $type) {
                if (! isset($type['id'], $type['name'])) {
                    continue;
                }

                TradeType::updateOrCreate(
                    ['server_id' => $type['id']],
                    ['name' => $type['name']],
                );
            }
        }

        return TradeProduct::query()->orderBy('name')->get();
    }

    public function programReadFailed(): bool
    {
        return $this->programReadFailed;
    }

    /**
     * The current user's Trade Request submissions, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function submissions(): Collection
    {
        $viewer = auth()->user();

        abort_unless($viewer instanceof User, 401);

        $result = $this->pull();
        $this->globalReadFailed = $result['failed'];

        if (! $result['failed']) {
            $this->cacheRows($result['trade_requests']);
            $this->reconcileDeleted($result['trade_requests']);
        }

        $local = TradeRequest::query()
            ->with(['items.tradeType', 'attachments', 'product', 'salescall.customer'])
            ->where('submitted_by', $viewer->id)
            ->get();

        return $local
            ->map(fn (TradeRequest $tradeRequest): array => $this->mapLocal($tradeRequest))
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
            ])
            ->values();
    }

    public function globalReadFailed(): bool
    {
        return $this->globalReadFailed;
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE / EDIT
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): TradeRequest
    {
        $validated = Validator::make($input, $this->rules())->validate();

        $items = $this->normalizeItems($validated['items'] ?? []);
        $staged = $this->normalizeStagedAttachments($validated['staged_attachments'] ?? []);

        $this->assertItems($items);
        $this->assertRequestAttachmentForCreate($staged);

        $tradeRequest = null;
        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($user, $validated, $items, $staged, &$tradeRequest, &$storedPaths): void {
                $tradeRequest = TradeRequest::create([
                    'local_uuid' => (string) Str::uuid(),
                    'submitted_by' => $user->id,
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'product_id' => $validated['product_id'],
                    'rsm_status' => RSMStatus::PENDING->value,
                    'tdh_status' => TDHStatus::PENDING->value,
                    'tds_status' => TDSStatus::PENDING->value,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->replaceItems($tradeRequest, $items);
                $this->persistStagedAttachments($tradeRequest, $user, $staged, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $tradeRequest->fresh(['items.tradeType', 'attachments']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Model $submission, array $input): TradeRequest
    {
        if (! $submission instanceof TradeRequest) {
            throw new InvalidArgumentException('Expected a CRR - LSM submission.');
        }

        $tradeRequest = $submission;

        if (! $tradeRequest->isEditable()) {
            throw ValidationException::withMessages([
                'trade_request' => ['Only CRR - LSM that are still pending can be edited.'],
            ]);
        }

        $validated = Validator::make($input, $this->rules())->validate();

        $items = $this->normalizeItems($validated['items'] ?? []);
        $staged = $this->normalizeStagedAttachments($validated['staged_attachments'] ?? []);
        $removed = $this->normalizeIds($validated['remove_attachments'] ?? []);

        $this->assertItems($items);
        $this->assertRequestAttachmentForUpdate($tradeRequest, $staged, $removed);

        $storedPaths = [];

        try {
            $this->attemptTransaction(function () use ($tradeRequest, $validated, $items, $staged, $removed, &$storedPaths): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $tradeRequest->update([
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'product_id' => $validated['product_id'],
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->replaceItems($tradeRequest, $items);
                $this->removeAttachments($tradeRequest, $removed);
                $this->persistStagedAttachments($tradeRequest, $user, $staged, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $tradeRequest->fresh(['items.tradeType', 'attachments']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): TradeRequest
    {
        $tradeRequest = isset($row['server_id'])
            ? TradeRequest::query()->where('server_id', $row['server_id'])->first()
            : null;

        if ($tradeRequest) {
            return $tradeRequest;
        }

        throw ValidationException::withMessages([
            'trade_request' => ['This CRR - LSM is not available locally yet. Refresh the form list and try again.'],
        ]);
    }

    public function attachmentUrl(TradeRequestAttachment $attachment): ?string
    {
        if (! $attachment->local_path || ! is_file($attachment->local_path)) {
            return null;
        }

        $extension = strtolower(pathinfo($attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $relativePath = "trade_request_attachments/{$identity}.{$extension}";
        $previewPath = public_path($relativePath);

        try {
            File::ensureDirectoryExists(dirname($previewPath));
        } catch (Throwable) {
            return null;
        }

        if (! is_file($previewPath) && @copy($attachment->local_path, $previewPath) === false) {
            return null;
        }

        return '/_assets/'.$relativePath;
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNALS
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{trade_requests: array<int, array<string, mixed>>, failed: bool}
     */
    private function pull(): array
    {
        return $this->pullCache ??= $this->syncService->readTradeRequestsWithStatus();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cacheRows(array $rows): void
    {
        $viewer = auth()->user();

        if (! $viewer instanceof User) {
            return;
        }

        $rows = array_values(array_filter(
            $rows,
            fn (mixed $row): bool => is_array($row) && isset($row['id']),
        ));

        if ($rows === []) {
            return;
        }

        $itemRowsByTradeRequestId = [];
        $productServerIds = collect($rows)
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $typeServerIds = collect($rows)
            ->flatMap(fn (array $row): array => collect($row['items'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->pluck('trade_type_id')
                ->all())
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $salescallServerIds = collect($rows)
            ->pluck('salescall_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $rowServerIds = collect($rows)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // One lookup per master + one fetch of existing rows for the whole pull.
        $productsByServerId = TradeProduct::query()->whereIn('server_id', $productServerIds)->pluck('id', 'server_id');
        $typesByServerId = TradeType::query()->whereIn('server_id', $typeServerIds)->pluck('id', 'server_id');
        $salescallsByServerId = Salescall::query()->whereIn('server_id', $salescallServerIds)->pluck('id', 'server_id');
        $existingByServerId = TradeRequest::query()->whereIn('server_id', $rowServerIds)->get()->keyBy('server_id');

        foreach ($rows as $row) {
            $productId = $productsByServerId->get((int) ($row['product_id'] ?? 0));

            // Wait for the master picklists to be cached before mirroring rows.
            if (! $productId) {
                continue;
            }

            $itemRows = [];
            $typesResolved = true;

            foreach ($row['items'] ?? [] as $itemRow) {
                if (! is_array($itemRow)) {
                    continue;
                }

                $typeId = $typesByServerId->get((int) ($itemRow['trade_type_id'] ?? 0));

                if (! $typeId) {
                    $typesResolved = false;

                    break;
                }

                $size = $itemRow['size'] ?? null;

                $itemRows[] = [
                    'trade_type_id' => $typeId,
                    'quantity' => (int) ($itemRow['quantity'] ?? 0),
                    'size' => $size === null || $size === '' ? null : (int) $size,
                    'description' => $itemRow['description'] ?? null,
                ];
            }

            if (! $typesResolved) {
                continue;
            }

            $tradeRequest = $existingByServerId->get((int) $row['id'])
                ?? new TradeRequest(['server_id' => $row['id']]);

            // Never overwrite local work that has not been pushed yet.
            if ($tradeRequest->exists && $tradeRequest->sync_status !== 'synced') {
                continue;
            }

            if (! $tradeRequest->exists) {
                $tradeRequest->local_uuid = (string) Str::uuid();
                $tradeRequest->submitted_by = $viewer->id;
                $tradeRequest->sync_status = 'synced';
                $tradeRequest->sync_attempts = 0;
            }

            $salescallId = isset($row['salescall_id']) && $row['salescall_id'] !== null
                ? $salescallsByServerId->get((int) $row['salescall_id'])
                : null;

            $tradeRequest->fill([
                'salescall_id' => $salescallId,
                'product_id' => $productId,
                'rsm_status' => (int) ($row['rsm_status'] ?? RSMStatus::PENDING->value),
                'tdh_status' => (int) ($row['tdh_status'] ?? TDHStatus::PENDING->value),
                'tds_status' => (int) ($row['tds_status'] ?? TDSStatus::PENDING->value),
                'synced_at' => now(),
            ]);

            $tradeRequest->save();

            $existingByServerId->put((int) $row['id'], $tradeRequest);

            // The Portal rebuilds items on every push, so items are a snapshot.
            $itemRowsByTradeRequestId[$tradeRequest->id] = $itemRows;
        }

        $this->replaceItemsSnapshot($itemRowsByTradeRequestId);
    }

    /**
     * Replace the item snapshots for many trade requests with one delete and
     * one insert so a pull never issues a write per row.
     *
     * @param  array<int, array<int, array{trade_type_id: int, quantity: int, size: ?int, description: ?string}>>  $itemRowsByTradeRequestId
     */
    private function replaceItemsSnapshot(array $itemRowsByTradeRequestId): void
    {
        if ($itemRowsByTradeRequestId === []) {
            return;
        }

        TradeRequestItem::query()
            ->whereIn('trade_request_id', array_keys($itemRowsByTradeRequestId))
            ->delete();

        $now = now();
        $inserts = [];

        foreach ($itemRowsByTradeRequestId as $tradeRequestId => $rows) {
            foreach ($rows as $row) {
                $inserts[] = [
                    'trade_request_id' => $tradeRequestId,
                    'trade_type_id' => $row['trade_type_id'],
                    'quantity' => $row['quantity'],
                    'size' => $row['size'],
                    'description' => $row['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($inserts !== []) {
            TradeRequestItem::query()->insert($inserts);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function reconcileDeleted(array $rows): void
    {
        $serverIds = collect($rows)
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        TradeRequest::query()
            ->with('attachments')
            ->whereNotNull('server_id')
            ->whereNotIn('server_id', $serverIds)
            ->get()
            ->each(function (TradeRequest $tradeRequest): void {
                foreach ($tradeRequest->attachments ?? collect() as $attachment) {
                    $this->deleteAttachmentFile($attachment);
                }

                $tradeRequest->delete();
            });
    }

    /**
     * @param  array<int, array{trade_type_id: int, quantity: int, size: ?int, description: ?string}>  $items
     */
    private function replaceItems(TradeRequest $tradeRequest, array $items): void
    {
        $this->replaceChildren($tradeRequest, 'items', $items, function (TradeRequest $parent, array $row): void {
            TradeRequestItem::create([
                'trade_request_id' => $parent->id,
                'trade_type_id' => $row['trade_type_id'],
                'quantity' => $row['quantity'],
                'size' => $row['size'],
                'description' => $row['description'],
            ]);
        });
    }

    /**
     * @param  array<int, array{trade_type_id: int, quantity: int, size: ?int, description: ?string}>  $items
     */
    private function assertItems(array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one item.'],
            ]);
        }
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     */
    private function assertRequestAttachmentForCreate(array $staged): void
    {
        if (! $this->hasStagedType($staged, TradeRequestAttachmentType::REQUEST)) {
            throw $this->missingRequestAttachment();
        }
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     * @param  array<int, int>  $removed
     */
    private function assertRequestAttachmentForUpdate(TradeRequest $tradeRequest, array $staged, array $removed): void
    {
        $remaining = TradeRequestAttachment::query()
            ->where('trade_request_id', $tradeRequest->id)
            ->where('type', TradeRequestAttachmentType::REQUEST->value)
            ->whereNotIn('id', $removed)
            ->count();

        if ($remaining + ($this->hasStagedType($staged, TradeRequestAttachmentType::REQUEST) ? 1 : 0) === 0) {
            throw $this->missingRequestAttachment();
        }
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     */
    private function hasStagedType(array $staged, TradeRequestAttachmentType $type): bool
    {
        return collect($staged)->contains(
            fn (array $row): bool => $row['type'] === $type->value
        );
    }

    private function missingRequestAttachment(): ValidationException
    {
        return ValidationException::withMessages([
            'attachments' => ['At least one Request attachment is required.'],
        ]);
    }

    /**
     * @param  array<int, array{type: int, staged_id: int}>  $staged
     * @param  list<string>  $storedPaths
     */
    private function persistStagedAttachments(TradeRequest $tradeRequest, User $user, array $staged, array &$storedPaths): void
    {
        foreach ($staged as $index => $row) {
            $stagedService = app(StagedAttachmentService::class);
            $stagedModel = $stagedService->findForUser($user, $row['staged_id']);

            if (! $stagedModel instanceof StagedAttachment) {
                throw ValidationException::withMessages([
                    "staged_attachments.$index.staged_id" => ['A staged attachment is no longer available. Re-add the file and try again.'],
                ]);
            }

            $metadata = $stagedService->consume($user, $stagedModel, 'trade_request_attachments');
            $storedPaths[] = $metadata['local_path'];

            TradeRequestAttachment::create([
                'local_uuid' => (string) Str::uuid(),
                'trade_request_id' => $tradeRequest->id,
                'type' => $row['type'],
                'local_path' => $metadata['local_path'],
                'original_name' => $metadata['original_name'],
                'mime_type' => $metadata['mime_type'],
                'extension' => $metadata['extension'],
                'byte_size' => $metadata['byte_size'],
                'sync_status' => 'pending',
                'sync_attempts' => 0,
                'sync_error' => null,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function removeAttachments(TradeRequest $tradeRequest, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        TradeRequestAttachment::query()
            ->where('trade_request_id', $tradeRequest->id)
            ->whereIn('id', $ids)
            ->get()
            ->each(function (TradeRequestAttachment $attachment): void {
                $this->deleteAttachmentFile($attachment);
                $attachment->delete();
            });
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return array<int, array{trade_type_id: int, quantity: int, size: ?int, description: ?string}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach (array_values($items) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $quantity = $row['quantity'] ?? 0;
            $size = $row['size'] ?? null;
            $description = $row['description'] ?? null;

            $normalized[] = [
                'trade_type_id' => (int) ($row['trade_type_id'] ?? 0),
                'quantity' => is_numeric($quantity) ? (int) $quantity : 0,
                'size' => $size === null || $size === '' ? null : (int) $size,
                'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $staged
     * @return array<int, array{type: int, staged_id: int}>
     */
    private function normalizeStagedAttachments(array $staged): array
    {
        $normalized = [];

        foreach (array_values($staged) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = isset($row['type']) ? (int) $row['type'] : 0;
            $stagedId = isset($row['staged_id']) ? (int) $row['staged_id'] : 0;

            if ($stagedId <= 0 || ! in_array($type, TradeRequestAttachmentType::values(), true)) {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'staged_id' => $stagedId,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param  list<string>  $storedPaths
     */
    private function cleanupStoredPaths(array $storedPaths): void
    {
        foreach ($storedPaths as $storedPath) {
            if (is_string($storedPath) && is_file($storedPath)) {
                @unlink($storedPath);
            }
        }
    }

    /**
     * Retry a local write on transient SQLite open/lock errors (CANTOPEN, busy,
     * disk I/O) before surfacing the failure.
     */
    private function attemptTransaction(Closure $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! $this->isTransientDatabaseError($exception)) {
                    throw $exception;
                }

                usleep(150_000 * $attempt);
            }
        }
    }

    private function isTransientDatabaseError(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'unable to open database file')
            || str_contains($message, 'database is locked')
            || str_contains($message, 'disk I/O error');
    }

    private function deleteAttachmentFile(TradeRequestAttachment $attachment): void
    {
        if ($attachment->local_path && is_file($attachment->local_path)) {
            @unlink($attachment->local_path);
        }

        $extension = strtolower(pathinfo((string) $attachment->local_path, PATHINFO_EXTENSION)) ?: 'bin';
        $identity = $attachment->local_uuid ?: (string) $attachment->id;
        $previewPath = public_path("trade_request_attachments/{$identity}.{$extension}");

        if (is_file($previewPath)) {
            @unlink($previewPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocal(TradeRequest $tradeRequest): array
    {
        return [
            'id' => $tradeRequest->id,
            'server_id' => $tradeRequest->server_id,
            'local_uuid' => $tradeRequest->local_uuid,
            'product' => $tradeRequest->product?->name ?? '—',
            'sales_call' => $this->salesCallLabel($tradeRequest->salescall),
            'salescall_uuid' => $tradeRequest->salescall?->local_uuid,
            'rsm_status' => $tradeRequest->rsm_status->value,
            'tdh_status' => $tradeRequest->tdh_status->value,
            'tds_status' => $tradeRequest->tds_status->value,
            'is_editable' => $tradeRequest->isEditable(),
            'sync_status' => $tradeRequest->sync_status,
            'items' => $tradeRequest->items
                ->sortBy('id')
                ->values()
                ->map(fn (TradeRequestItem $item): array => [
                    'id' => $item->id,
                    'trade_type' => $item->tradeType?->name ?? '—',
                    'quantity' => $item->quantity,
                    'size' => $item->size?->value,
                    'size_label' => $item->size?->meta('title') ?? '—',
                    'description' => $item->description,
                ])
                ->all(),
            'attachments' => $tradeRequest->attachments
                ->sortBy('id')
                ->values()
                ->map(fn (TradeRequestAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'type' => $attachment->type->value,
                    'type_label' => $attachment->type->meta('title'),
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'url' => $this->attachmentUrl($attachment),
                ])
                ->all(),
            'created_at' => $tradeRequest->created_at?->toISOString(),
        ];
    }

    private function salesCallLabel(?Salescall $salescall): string
    {
        if (! $salescall) {
            return '—';
        }

        return $salescall->customer?->name ?? ('Salescall #'.$salescall->id);
    }

    /**
     * Local user-input rules. The Portal push request remains the final
     * server-side validator.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'product_id' => ['required', 'integer', 'exists:trade_products,id'],
            'items' => ['nullable', 'array'],
            'items.*.trade_type_id' => ['required_with:items', 'integer', 'exists:trade_types,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:0'],
            'items.*.size' => ['nullable', 'integer', 'in:'.implode(',', Size::values())],
            'items.*.description' => ['nullable', 'string'],
            'staged_attachments' => ['nullable', 'array'],
            'staged_attachments.*.type' => ['required_with:staged_attachments', 'integer', 'in:'.implode(',', TradeRequestAttachmentType::values())],
            'staged_attachments.*.staged_id' => ['required_with:staged_attachments', 'integer'],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
        ];
    }
}
