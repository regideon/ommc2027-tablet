<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Support\Forms\Concerns\ValidatesFormPayload;

class GiveawayRules implements ProvidesFormRules
{
    use ValidatesFormPayload;

    /**
     * Validation rules for a local Giveaway create/update payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'name' => ['required', 'string', 'max:255'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.category' => ['required', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.answer' => ['nullable', 'string'],
            'items.*.signature_id' => ['nullable', 'integer'],
            'items.*.staged_signature_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => 'name',
            'salescall_uuid' => 'sales call',
            'items' => 'items',
            'items.*.id' => 'item',
            'items.*.category' => 'category',
            'items.*.name' => 'item name',
            'items.*.answer' => 'answer',
            'items.*.signature_id' => 'signature',
            'items.*.staged_signature_id' => 'signature',
        ];
    }
}
