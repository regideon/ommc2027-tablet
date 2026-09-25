<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\ExpenseType;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocalExpenseCreationService
{
    private const SUPPORTED_TYPES = [
        'communication_expenses',
        'emergency_expenses',
        'lodging',
        'per_diem',
        'ancillary_expenses',
        'airfare',
        'representation',
        'staff_meeting',
        'repairs_and_maintenance',
        'transportation_toll',
        'transportation_gas',
        'transportation_parking',
        'transportation_commute',
    ];

    private const FORM_KEYS = [
        'communication_expenses' => [],
        'emergency_expenses' => [],
        'lodging' => ['number_of_nights', 'hotel'],
        'per_diem' => ['meal', 'number_of_days'],
        'ancillary_expenses' => ['expense_kind', 'expense_kind_other'],
        'airfare' => ['route'],
        'representation' => ['number_of_people', 'contact_person', 'names_included', 'meeting_agenda'],
        'staff_meeting' => ['number_of_people', 'contact_person', 'names_included', 'meeting_agenda'],
        'repairs_and_maintenance' => ['odometer'],
        'transportation_toll' => ['initial_odometer', 'last_odometer', 'distance_travelled'],
        'transportation_gas' => ['initial_odometer', 'last_odometer', 'distance_travelled', 'liters'],
        'transportation_parking' => ['initial_odometer', 'last_odometer', 'distance_travelled', 'number_of_days'],
        'transportation_commute' => ['initial_odometer', 'last_odometer', 'distance_travelled', 'expense_kind', 'expense_kind_other', 'route'],
    ];

    /**
     * Create one of the currently implemented local Expense forms.
     *
     * @param  array<string, mixed>  $input
     */
    public function createFromSalescall(Salescall $salescall, User $creator, array $input): Expense
    {
        $salescall->loadMissing('customer');

        if (! $salescall->customer_id || ! $salescall->customer) {
            throw ValidationException::withMessages([
                'salescall' => 'This Sales Call has no authoritative Customer and cannot own an Expense.',
            ]);
        }

        $validator = Validator::make($input, [
            'expense_type_code' => ['required', 'string', 'in:'.implode(',', self::SUPPORTED_TYPES)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date_filed' => ['required', 'date'],
            'payment_type' => ['required', 'string', 'in:Revolving Fund,Petty Cash Voucher (PCV),SBC Credit Card,Cash Advance,Fleet Card'],
            'payment_remarks' => ['required', 'string', 'max:5000'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'establishment' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:2000'],
            'purpose' => ['required', 'string', 'max:5000'],
            'tin' => ['required', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'form_data' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array', 'max:10'],
        ]);

        $validated = $validator->validate();
        $formData = $this->normalizeFormData(
            $validated['expense_type_code'],
            is_array($validated['form_data'] ?? null) ? $validated['form_data'] : [],
        );
        $attachments = $this->normalizeAttachments(
            is_array($validated['attachments'] ?? null) ? $validated['attachments'] : [],
        );
        $expenseType = ExpenseType::query()
            ->where('code', $validated['expense_type_code'])
            ->where('is_enabled', true)
            ->first();

        if (! $expenseType) {
            throw ValidationException::withMessages([
                'expense_type_code' => 'The selected Expense Type is not available locally.',
            ]);
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($salescall, $creator, $expenseType, $validated, $formData, $attachments, &$storedPaths): Expense {
                $expense = Expense::create([
                'local_uuid' => (string) \Str::uuid(),
                'server_id' => null,
                'salescall_id' => $salescall->id,
                'customer_id' => $salescall->customer_id,
                'expense_type_id' => $expenseType->id,
                'created_by' => $creator->id,
                'amount' => $validated['amount'],
                'date_filed' => $validated['date_filed'],
                'payment_type' => $validated['payment_type'],
                'payment_remarks' => $validated['payment_remarks'],
                'invoice_number' => $validated['invoice_number'],
                'with_invoice' => true,
                'establishment' => $validated['establishment'],
                'location' => $validated['location'],
                'purpose' => $validated['purpose'],
                'tin' => $validated['tin'],
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'form_data' => $formData,
                'form_schema_version' => 1,
                'approved' => null,
                'approver_remarks' => null,
                'sync_status' => 'pending',
                'sync_attempts' => 0,
                'sync_error' => null,
                'synced_at' => null,
                ]);

                foreach ($attachments as $attachment) {
                    $uuid = (string) \Str::uuid();
                    $relativePath = 'expense_attachments/'.$uuid.'.'.$attachment['extension'];
                    Storage::disk('local')->makeDirectory('expense_attachments');

                    if (! Storage::disk('local')->put($relativePath, $attachment['bytes'])) {
                        throw new \RuntimeException('The attachment file could not be stored locally.');
                    }

                    $localPath = Storage::disk('local')->path($relativePath);
                    $storedPaths[] = $localPath;

                    ExpenseAttachment::create([
                        'local_uuid' => $uuid,
                        'server_id' => null,
                        'expense_id' => $expense->id,
                        'local_path' => $localPath,
                        'storage_key' => null,
                        'original_name' => $attachment['original_name'],
                        'mime_type' => $attachment['mime_type'],
                        'extension' => $attachment['extension'],
                        'byte_size' => $attachment['byte_size'],
                        'sync_status' => 'pending',
                        'sync_attempts' => 0,
                        'sync_error' => null,
                        'synced_at' => null,
                    ]);
                }

                return $expense;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                if (is_string($storedPath) && is_file($storedPath)) {
                    @unlink($storedPath);
                }
            }

            throw $exception;
        }
    }

    /**
     * Decode, inspect, and bound temporary attachment payloads before any
     * Expense or attachment row is created.
     *
     * @param  array<int, mixed>  $attachments
     * @return array<int, array{bytes: string, original_name: string, mime_type: string, extension: string, byte_size: int}>
     */
    private function normalizeAttachments(array $attachments): array
    {
        if (count($attachments) > 10) {
            throw ValidationException::withMessages([
                'attachments' => ['An Expense may have at most 10 attachments.'],
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

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($bytes) ?: null;
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'application/pdf' => 'pdf',
                default => null,
            };

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

    /**
     * Validate and bound the type-specific portion independently from the
     * relational/common Expense columns.
     *
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    private function normalizeFormData(string $expenseTypeCode, array $formData): array
    {
        $allowedKeys = self::FORM_KEYS[$expenseTypeCode] ?? [];
        $unknownKeys = array_diff(array_keys($formData), $allowedKeys);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'form_data' => ['Unsupported fields were supplied for this Expense Type.'],
            ]);
        }

        if ($allowedKeys === []) {
            return [];
        }

        if ($expenseTypeCode === 'ancillary_expenses') {
            $this->validateFormData($formData, [
                'expense_kind' => ['nullable', 'string', 'max:255'],
                'expense_kind_other' => ['nullable', 'string', 'max:255'],
            ]);

            $expenseKind = (string) ($formData['expense_kind'] ?? '');
            $expenseKindOther = trim((string) ($formData['expense_kind_other'] ?? ''));
            $comparisonKind = strtolower(trim($expenseKind));

            if (in_array($comparisonKind, ['other', 'others'], true) && $expenseKindOther === '') {
                throw ValidationException::withMessages([
                    'form_data.expense_kind_other' => ['Please specify the Other Expense Kind.'],
                ]);
            }

            $normalized = [];
            if (trim($expenseKind) !== '') {
                // Preserve the submitted value; trim/lowercase is comparison-only.
                $normalized['expense_kind'] = $expenseKind;
            }
            if (in_array($comparisonKind, ['other', 'others'], true)) {
                $normalized['expense_kind_other'] = $expenseKindOther;
            }

            return $normalized;
        }

        if (in_array($expenseTypeCode, ['representation', 'staff_meeting'], true)) {
            $normalized = $this->validateFormData($formData, [
                'number_of_people' => ['nullable', 'integer', 'min:0'],
                'contact_person' => ['nullable', 'string', 'max:255'],
                'names_included' => ['nullable', 'array'],
                'meeting_agenda' => ['nullable', 'string', 'max:2000'],
            ]);

            if (array_key_exists('names_included', $formData) && ! is_array($formData['names_included'])) {
                throw ValidationException::withMessages([
                    'form_data.names_included' => ['Names must be supplied as an ordered array.'],
                ]);
            }

            $names = array_values(array_filter(array_map(
                static function (mixed $name): mixed {
                    return is_string($name) ? trim($name) : $name;
                },
                $formData['names_included'] ?? [],
            ), static fn (mixed $name): bool => $name !== ''));

            foreach ($names as $name) {
                if (! is_string($name) || mb_strlen($name) > 255) {
                    throw ValidationException::withMessages([
                        'form_data.names_included' => ['Each name must be a text value of 255 characters or fewer.'],
                    ]);
                }
            }

            if ($names !== []) {
                $normalized['names_included'] = $names;
            } else {
                unset($normalized['names_included']);
            }

            return array_filter($normalized, static fn (mixed $value): bool => $value !== null);
        }

        if ($expenseTypeCode === 'repairs_and_maintenance') {
            $normalizedInput = $this->normalizeScalarData($formData);
            $normalized = $this->validateFormData($normalizedInput, [
                'odometer' => ['nullable', 'numeric', 'min:0'],
            ]);

            return $this->castNumericFormData($normalized, ['odometer']);
        }

        if (in_array($expenseTypeCode, [
            'transportation_toll',
            'transportation_gas',
            'transportation_parking',
            'transportation_commute',
        ], true)) {
            $rules = [
                'initial_odometer' => ['nullable', 'numeric', 'min:0'],
                'last_odometer' => ['nullable', 'numeric', 'min:0'],
            ];

            if ($expenseTypeCode === 'transportation_gas') {
                $rules['liters'] = ['nullable', 'numeric', 'min:0'];
            }
            if ($expenseTypeCode === 'transportation_parking') {
                $rules['number_of_days'] = ['nullable', 'integer', 'min:0'];
            }
            if ($expenseTypeCode === 'transportation_commute') {
                $rules['expense_kind'] = ['nullable', 'string', 'max:255'];
                $rules['expense_kind_other'] = ['nullable', 'string', 'max:255'];
                $rules['route'] = ['nullable', 'string', 'max:1000'];
            }

            $providedDistance = $formData['distance_travelled'] ?? null;
            $validationData = $this->normalizeScalarData($formData);
            unset($validationData['distance_travelled']);
            $normalized = $this->castNumericFormData(
                $this->validateFormData($validationData, $rules),
                array_keys(array_filter([
                    'initial_odometer' => true,
                    'last_odometer' => true,
                    'liters' => $expenseTypeCode === 'transportation_gas',
                    'number_of_days' => $expenseTypeCode === 'transportation_parking',
                ])),
            );

            $initial = $normalized['initial_odometer'] ?? null;
            $last = $normalized['last_odometer'] ?? null;

            if (($initial === null) !== ($last === null)) {
                throw ValidationException::withMessages([
                    'form_data' => ['Initial and last odometer values must be supplied together.'],
                ]);
            }

            if ($initial !== null && $last !== null) {
                if ($last < $initial) {
                    throw ValidationException::withMessages([
                        'form_data.last_odometer' => ['Last odometer must not be less than initial odometer.'],
                    ]);
                }

                $normalized['distance_travelled'] = $last - $initial;
            } elseif ($providedDistance !== null && $providedDistance !== '') {
                throw ValidationException::withMessages([
                    'form_data.distance_travelled' => ['Distance travelled requires initial and last odometer values.'],
                ]);
            }

            if ($expenseTypeCode === 'transportation_commute') {
                $submittedExpenseKind = $formData['expense_kind'] ?? null;
                $expenseKind = (string) ($normalized['expense_kind'] ?? '');
                $comparisonKind = strtolower(trim($expenseKind));
                $expenseKindOther = trim((string) ($normalized['expense_kind_other'] ?? ''));

                if ($comparisonKind !== '' && ! in_array($comparisonKind, [
                    'roro fare',
                    'terminal fee',
                    'taxi fare',
                    'other',
                    'others',
                ], true)) {
                    throw ValidationException::withMessages([
                        'form_data.expense_kind' => ['Select a valid Commute Expense Kind.'],
                    ]);
                }

                if (in_array($comparisonKind, ['other', 'others'], true) && $expenseKindOther === '') {
                    throw ValidationException::withMessages([
                        'form_data.expense_kind_other' => ['Please specify the Other Expense Kind.'],
                    ]);
                }

                if (in_array($comparisonKind, ['other', 'others'], true)) {
                    $normalized['expense_kind_other'] = $expenseKindOther;
                } else {
                    unset($normalized['expense_kind_other']);
                }

                if ($submittedExpenseKind !== null) {
                    // Preserve the submitted value; normalization is comparison-only.
                    $normalized['expense_kind'] = $submittedExpenseKind;
                }
            }

            return array_filter($normalized, static fn (mixed $value): bool => $value !== null);
        }

        $rules = match ($expenseTypeCode) {
            'lodging' => [
                'number_of_nights' => ['nullable', 'integer', 'min:0'],
                'hotel' => ['nullable', 'string', 'max:255'],
            ],
            'per_diem' => [
                'meal' => ['nullable', 'string', 'max:255'],
                'number_of_days' => ['nullable', 'integer', 'min:0'],
            ],
            'airfare' => [
                'route' => ['nullable', 'string', 'max:1000'],
            ],
            default => [],
        };

        $normalizedInput = array_map(
            static function (mixed $value): mixed {
                if ($value === '' || $value === null) {
                    return null;
                }

                return is_string($value) ? trim($value) : $value;
            },
            $formData,
        );

        $normalized = $this->validateFormData($normalizedInput, $rules);

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Validate type-specific data while returning errors in the shared
     * form_data.* namespace used by the form shell.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>
     */
    private function validateFormData(array $data, array $rules): array
    {
        try {
            return Validator::make($data, $rules)->validate();
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $key => $errors) {
                $messages['form_data.'.$key] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Normalize empty text values while preserving numeric input for Laravel's
     * numeric validation and the subsequent Portal-compatible casts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeScalarData(array $data): array
    {
        return array_map(
            static function (mixed $value): mixed {
                if ($value === '' || $value === null) {
                    return null;
                }

                return is_string($value) ? trim($value) : $value;
            },
            $data,
        );
    }

    /**
     * Match the accepted Portal representation for numeric form values.
     * Odometer/liters are decimals; number_of_days is an integer.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $decimalKeys
     * @return array<string, mixed>
     */
    private function castNumericFormData(array $data, array $decimalKeys): array
    {
        foreach ($data as $key => $value) {
            if ($value === null || ! in_array($key, $decimalKeys, true)) {
                continue;
            }

            $data[$key] = $key === 'number_of_days' ? (int) $value : (float) $value;
        }

        return array_filter($data, static fn (mixed $value): bool => $value !== null);
    }
}
