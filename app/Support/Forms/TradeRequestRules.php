<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;

class TradeRequestRules implements ProvidesFormRules
{
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
            'items.*.trade_type_id' => ['required', 'integer', 'exists:trade_types,id'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'items.*.size' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string'],
        ];
    }
}
