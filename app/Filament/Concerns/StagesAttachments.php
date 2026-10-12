<?php

namespace App\Filament\Concerns;

use App\Models\StagedAttachment;
use App\Models\User;
use App\Services\StagedAttachmentService;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Livewire-side helper for the staged-attachment flow. Forms call these to
 * stage one file at a time (from a native capture path or a web data URI) and
 * keep only the returned staging id in their state.
 */
trait StagesAttachments
{
    protected function stageAttachmentFromPath(string $path, ?string $originalName = null): ?array
    {
        return $this->stageAttachment(
            fn (StagedAttachmentService $service, User $user): StagedAttachment => $service->stageFromPath($user, $path, $originalName),
        );
    }

    protected function stageAttachmentFromDataUri(string $data, ?string $originalName = null): ?array
    {
        return $this->stageAttachment(
            fn (StagedAttachmentService $service, User $user): StagedAttachment => $service->stageFromDataUri($user, $data, $originalName),
        );
    }

    protected function discardStagedAttachment(int $id): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $staged = app(StagedAttachmentService::class)->findForUser($user, $id);

        if ($staged) {
            app(StagedAttachmentService::class)->discard($staged);
        }
    }

    /**
     * @return array{id: int, original_name: ?string, mime_type: ?string, url: ?string}
     */
    protected function stagedAttachmentPayload(StagedAttachment $staged): array
    {
        return [
            'id' => $staged->id,
            'original_name' => $staged->original_name,
            'mime_type' => $staged->mime_type,
            'url' => app(StagedAttachmentService::class)->previewUrl($staged),
        ];
    }

    /**
     * @param  Closure(StagedAttachmentService, User): StagedAttachment  $stage
     * @return array{id: int, original_name: ?string, mime_type: ?string, url: ?string}|null
     */
    private function stageAttachment(Closure $stage): ?array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        try {
            return $this->stagedAttachmentPayload($stage(app(StagedAttachmentService::class), $user));
        } catch (ValidationException $exception) {
            Notification::make()
                ->title((string) (collect($exception->errors())->flatten()->first() ?? 'The attachment could not be staged.'))
                ->danger()
                ->send();

            return null;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('The attachment could not be staged.')->danger()->send();

            return null;
        }
    }
}
