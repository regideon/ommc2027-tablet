<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;

class TradeCheckRules implements ProvidesFormRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'name' => ['required', 'string', 'max:255'],
            'groups' => ['nullable', 'array'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.category' => ['required', 'integer'],
            'groups.*.description' => ['nullable', 'string'],
            'groups.*.order' => ['nullable', 'integer'],
            'groups.*.items' => ['nullable', 'array'],
            'groups.*.items.*.name' => ['required', 'string', 'max:255'],
            'groups.*.items.*.description' => ['nullable', 'string'],
            'groups.*.items.*.type' => ['nullable', 'integer'],
            'groups.*.items.*.is_required' => ['nullable', 'boolean'],
            'groups.*.items.*.is_image_required' => ['nullable', 'boolean'],
            'groups.*.items.*.options' => ['nullable', 'array'],
            'groups.*.items.*.order' => ['nullable', 'integer'],
            'groups.*.items.*.answer' => ['nullable', 'string'],
        ];
    }
}
