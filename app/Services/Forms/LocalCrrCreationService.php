<?php

namespace App\Services\Forms;

use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Models\Forms\CRR\Crr;
use App\Models\Forms\CRR\CrrProgram;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates and edits CRR responses locally, offline-first. Validation applies to
 * user-entered input only; pulled server data is never validated here.
 */
class LocalCrrCreationService extends AbstractFormService
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Crr
    {
        $validated = Validator::make($input, $this->rules())->validate();
        $attachments = $this->normalizeAttachments($this->attachmentPayload($input));
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($user, $validated, $attachments, &$storedPaths): Crr {
                $crr = Crr::create([
                    'local_uuid' => (string) Str::uuid(),
                    'submitted_by' => $user->id,
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'crr_program_id' => $validated['crr_program_id'],
                    'date_implemented' => $validated['date_implemented'] ?? null,
                    'amount' => $validated['amount'],
                    'details' => $validated['details'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'rsm_status' => RSMStatus::PENDING->value,
                    'sps_status' => SPSStatus::PENDING->value,
                    'spm_status' => SPMStatus::PENDING->value,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->persistAttachments($crr, $attachments, $storedPaths);

                return $crr->load('attachments');
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Crr $crr, array $input): Crr
    {
        if (! $crr->isEditable()) {
            throw ValidationException::withMessages([
                'crr' => ['Only CRRs that are still pending can be edited.'],
            ]);
        }

        $validated = Validator::make($input, $this->rules())->validate();
        $attachments = $this->normalizeAttachments($this->attachmentPayload($input));
        $storedPaths = [];

        try {
            DB::transaction(function () use ($crr, $validated, $attachments, &$storedPaths): void {
                $crr->update([
                    'salescall_id' => $this->resolveSalescall($validated['salescall_uuid'] ?? null),
                    'crr_program_id' => $validated['crr_program_id'],
                    'date_implemented' => $validated['date_implemented'] ?? null,
                    'amount' => $validated['amount'],
                    'details' => $validated['details'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                    'sync_error' => null,
                ]);

                $this->persistAttachments($crr, $attachments, $storedPaths);
            });
        } catch (Throwable $exception) {
            $this->cleanupStoredPaths($storedPaths);

            throw $exception;
        }

        return $crr->fresh('attachments');
    }

    /**
     * Materialize a pulled server CRR locally so it can be edited and pushed
     * back with its existing server id.
     *
     * @param  array<string, mixed>  $row
     */
    public function adopt(User $user, array $row): Crr
    {
        $program = CrrProgram::query()
            ->where('server_id', $row['crr_program_id'] ?? null)
            ->first();

        if (! $program) {
            throw ValidationException::withMessages([
                'crr' => ['The CRR program is not available locally yet. Refresh and try again.'],
            ]);
        }

        return Crr::create([
            'local_uuid' => (string) Str::uuid(),
            'server_id' => $row['server_id'] ?? null,
            'submitted_by' => $user->id,
            'salescall_id' => null,
            'crr_program_id' => $program->id,
            'date_implemented' => $row['date_implemented'] ?? null,
            'amount' => $row['amount'] ?? 0,
            'details' => $row['details'] ?? null,
            'remarks' => $row['remarks'] ?? null,
            'rsm_status' => $row['rsm_status'] ?? RSMStatus::PENDING->value,
            'sps_status' => $row['sps_status'] ?? SPSStatus::PENDING->value,
            'spm_status' => $row['spm_status'] ?? SPMStatus::PENDING->value,
            'sync_status' => 'synced',
            'sync_attempts' => 0,
            'sync_error' => null,
            'synced_at' => now(),
        ]);
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
            'crr_program_id' => ['required', 'integer', 'exists:crr_programs,id'],
            'date_implemented' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'details' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, mixed>
     */
    private function attachmentPayload(array $input): array
    {
        return is_array($input['attachments'] ?? null) ? $input['attachments'] : [];
    }

    /**
     * @param  array<int, array{bytes: string, original_name: string, mime_type: string, extension: string, byte_size: int}>  $attachments
     * @param  list<string>  $storedPaths
     */
    private function persistAttachments(Crr $crr, array $attachments, array &$storedPaths): void
    {
        foreach ($attachments as $attachment) {
            $uuid = (string) Str::uuid();
            $relativePath = $this->storeLocalAttachment(
                'crr_attachments',
                $uuid,
                $attachment['extension'],
                $attachment['bytes'],
            );
            $storedPaths[] = Storage::disk('local')->path($relativePath);

            $crr->attachments()->create([
                'local_uuid' => $uuid,
                'local_path' => Storage::disk('local')->path($relativePath),
                'original_name' => $attachment['original_name'],
                'mime_type' => $attachment['mime_type'],
                'extension' => $attachment['extension'],
                'byte_size' => $attachment['byte_size'],
                'sync_status' => 'pending',
                'sync_attempts' => 0,
                'sync_error' => null,
            ]);
        }
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
}
