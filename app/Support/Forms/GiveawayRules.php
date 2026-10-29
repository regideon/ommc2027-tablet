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
            'items.*.giveaway_item_id' => ['required', 'integer'],
            'items.*.answer' => ['nullable', 'string'],
            'signature_id' => ['nullable', 'integer'],
            'staged_signature_id' => ['nullable', 'integer'],
            'photo_id' => ['nullable', 'integer'],
            'staged_photo_id' => ['nullable', 'integer'],
            'remove_photo' => ['nullable', 'boolean'],
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
            'items.*.giveaway_item_id' => 'item',
            'items.*.answer' => 'quantity',
            'signature_id' => 'signature',
            'staged_signature_id' => 'signature',
            'photo_id' => 'photo',
            'staged_photo_id' => 'photo',
            'remove_photo' => 'photo',
        ];
    }
}
