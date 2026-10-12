<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;

class MarketingRules implements ProvidesFormRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'program_id' => ['required', 'integer', 'exists:marketing_programs,id'],
            'region_type_id' => ['required', 'integer', 'exists:region_types,id'],
            'duration_start_at' => ['required', 'date'],
            'duration_end_at' => ['required', 'date'],
            'objective' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'mechanics_description' => ['required', 'string'],
            'submitted_at' => ['nullable', 'date'],
            'volumes' => ['nullable', 'array'],
            'volumes.*.before_program' => ['nullable', 'numeric'],
            'volumes.*.during_program' => ['nullable', 'numeric'],
            'volumes.*.volume' => ['nullable', 'numeric'],
            'realizations' => ['nullable', 'array'],
            'realizations.*.stage' => ['required_with:realizations', 'integer'],
            'realizations.*.start_at' => ['nullable', 'date'],
            'realizations.*.end_at' => ['nullable', 'date'],
            'realizations.*.volume' => ['nullable', 'numeric'],
            'mechanics' => ['nullable', 'array'],
            'mechanics.*.period' => ['nullable', 'string'],
            'mechanics.*.unit_price' => ['nullable', 'numeric'],
            'mechanics.*.sub_total' => ['nullable', 'numeric'],
        ];
    }
}
