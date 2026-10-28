<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Support\Forms\Concerns\ValidatesFormPayload;

class TradeCheckRules implements ProvidesFormRules
{
    use ValidatesFormPayload;

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

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => 'name',
            'salescall_uuid' => 'sales call',
            'groups' => 'groups',
            'groups.*.name' => 'group name',
            'groups.*.category' => 'group category',
            'groups.*.description' => 'group description',
            'groups.*.order' => 'group order',
            'groups.*.items' => 'group items',
            'groups.*.items.*.name' => 'item name',
            'groups.*.items.*.description' => 'item description',
            'groups.*.items.*.type' => 'item type',
            'groups.*.items.*.is_required' => 'requirement',
            'groups.*.items.*.is_image_required' => 'image requirement',
            'groups.*.items.*.options' => 'item options',
            'groups.*.items.*.order' => 'item order',
            'groups.*.items.*.answer' => 'answer',
        ];
    }
}
