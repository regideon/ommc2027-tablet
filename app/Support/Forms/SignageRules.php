<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Enums\CRRSignage\SignageAttachmentType;
use App\Enums\CRRSignage\SignageCategory;
use App\Support\Forms\Concerns\ValidatesFormPayload;

class SignageRules implements ProvidesFormRules
{
    use ValidatesFormPayload;

    /**
     * Validation rules for a local CRR Signage create/update payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'product_id' => ['required', 'integer', 'exists:signage_products,id'],
            'type_id' => ['required', 'integer', 'exists:signage_types,id'],
            'category' => ['required', 'integer', 'in:'.implode(',', SignageCategory::values())],
            'name' => ['required', 'string', 'max:255'],
            'staged_attachments' => ['nullable', 'array'],
            'staged_attachments.*.type' => ['required_with:staged_attachments', 'integer', 'in:'.implode(',', SignageAttachmentType::values())],
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
            'product_id' => 'product',
            'type_id' => 'type',
            'category' => 'category',
            'name' => 'name',
            'staged_attachments' => 'attachment',
            'staged_attachments.*.type' => 'attachment type',
            'staged_attachments.*.staged_id' => 'attachment',
            'remove_attachments' => 'attachment',
            'remove_attachments.*' => 'attachment',
        ];
    }
}
