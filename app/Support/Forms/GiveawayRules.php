<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;

class GiveawayRules implements ProvidesFormRules
{
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
            'items.*.category' => ['required', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.answer' => ['nullable', 'string'],
        ];
    }
}
