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
            'groups.*.subgroups' => ['nullable', 'array'],
            'groups.*.subgroups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.subgroups.*.description' => ['nullable', 'string'],
            'groups.*.subgroups.*.is_image_required' => ['nullable', 'boolean'],
            'groups.*.subgroups.*.order' => ['nullable', 'integer'],
            'groups.*.subgroups.*.items' => ['nullable', 'array'],
            'groups.*.subgroups.*.items.*.name' => ['required', 'string', 'max:255'],
            'groups.*.subgroups.*.items.*.description' => ['nullable', 'string'],
            'groups.*.subgroups.*.items.*.type' => ['nullable', 'integer'],
            'groups.*.subgroups.*.items.*.is_required' => ['nullable', 'boolean'],
            'groups.*.subgroups.*.items.*.options' => ['nullable', 'array'],
            'groups.*.subgroups.*.items.*.order' => ['nullable', 'integer'],
            'groups.*.subgroups.*.items.*.answer' => ['nullable', 'string'],
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
            'groups.*.subgroups' => 'sub-groups',
            'groups.*.subgroups.*.name' => 'sub-group name',
            'groups.*.subgroups.*.description' => 'sub-group description',
            'groups.*.subgroups.*.is_image_required' => 'sub-group image requirement',
            'groups.*.subgroups.*.order' => 'sub-group order',
            'groups.*.subgroups.*.items' => 'sub-group items',
            'groups.*.subgroups.*.items.*.name' => 'item name',
            'groups.*.subgroups.*.items.*.description' => 'item description',
            'groups.*.subgroups.*.items.*.type' => 'item type',
            'groups.*.subgroups.*.items.*.is_required' => 'requirement',
            'groups.*.subgroups.*.items.*.options' => 'item options',
            'groups.*.subgroups.*.items.*.order' => 'item order',
            'groups.*.subgroups.*.items.*.answer' => 'answer',
        ];
    }
}
