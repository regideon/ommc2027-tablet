<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Enums\Repair\RepairAttachmentType;
use App\Support\Forms\Concerns\ValidatesFormPayload;

class RepairRules implements ProvidesFormRules
{
    use ValidatesFormPayload;

    /**
     * Validation rules for a local Repair create/update payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'plate_number' => ['required', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'details' => ['required', 'string'],
            'amount' => ['required', 'numeric'],
            'remarks' => ['nullable', 'string'],
            'staged_attachments' => ['nullable', 'array'],
            'staged_attachments.*.type' => ['required_with:staged_attachments', 'integer', 'in:'.implode(',', RepairAttachmentType::values())],
            'staged_attachments.*.staged_id' => ['required_with:staged_attachments', 'integer'],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'salescall_uuid' => 'sales call',
            'plate_number' => 'plate number',
            'invoice_number' => 'invoice number',
            'details' => 'details',
            'amount' => 'amount',
            'remarks' => 'remarks',
            'staged_attachments' => 'attachment',
            'staged_attachments.*.type' => 'attachment type',
            'staged_attachments.*.staged_id' => 'attachment',
            'remove_attachments' => 'attachment',
            'remove_attachments.*' => 'attachment',
        ];
    }
}
