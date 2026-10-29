<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Enums\TradeRequest\Size;
use App\Enums\TradeRequest\TradeRequestAttachmentType;
use App\Support\Forms\Concerns\ValidatesFormPayload;

class TradeRequestRules implements ProvidesFormRules
{
    use ValidatesFormPayload;

    /**
     * Validation rules for a local Trade Request create/update payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
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

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'salescall_uuid' => 'sales call',
            'product_id' => 'product',
            'items' => 'items',
            'items.*.trade_type_id' => 'trade type',
            'items.*.quantity' => 'quantity',
            'items.*.size' => 'size',
            'items.*.description' => 'description',
            'staged_attachments' => 'attachment',
            'staged_attachments.*.type' => 'attachment type',
            'staged_attachments.*.staged_id' => 'attachment',
            'remove_attachments' => 'attachment',
            'remove_attachments.*' => 'attachment',
        ];
    }
}
