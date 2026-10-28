<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Enums\Marketing\RealizationStage;
use App\Support\Forms\Concerns\ValidatesFormPayload;

class MarketingRules implements ProvidesFormRules
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
            'program_id' => ['required', 'integer', 'exists:marketing_programs,id'],
            'region_type_id' => ['required', 'integer', 'exists:region_types,id'],
            'duration_start_at' => ['required', 'date'],
            'duration_end_at' => ['required', 'date', 'after_or_equal:duration_start_at'],
            'objective' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'mechanics_description' => ['required', 'string'],
            'submitted_at' => ['nullable', 'date'],
            'volumes' => ['nullable', 'array'],
            'volumes.*.before_program' => ['required_with:volumes', 'numeric'],
            'volumes.*.during_program' => ['required_with:volumes', 'numeric'],
            'volumes.*.volume' => ['nullable', 'numeric'],
            'realizations' => ['nullable', 'array'],
            'realizations.*.stage' => ['required_with:realizations', 'integer', 'in:'.implode(',', RealizationStage::values())],
            'realizations.*.start_at' => ['required_with:realizations', 'date'],
            'realizations.*.end_at' => ['required_with:realizations', 'date', 'after_or_equal:realizations.*.start_at'],
            'realizations.*.volume' => ['required_with:realizations', 'numeric'],
            'mechanics' => ['nullable', 'array'],
            'mechanics.*.period' => ['required_with:mechanics', 'date'],
            'mechanics.*.unit_price' => ['required_with:mechanics', 'numeric'],
            'mechanics.*.sub_total' => ['required_with:mechanics', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'duration_end_at.after_or_equal' => 'The duration end must be on or after the duration start.',
            'realizations.*.end_at.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'program_id' => 'program',
            'region_type_id' => 'region type',
            'duration_start_at' => 'duration start',
            'duration_end_at' => 'duration end',
            'objective' => 'objective',
            'name' => 'name',
            'mechanics_description' => 'mechanics description',
            'submitted_at' => 'submission date',
            'salescall_uuid' => 'sales call',
            'volumes' => 'volumes',
            'volumes.*.before_program' => 'before program',
            'volumes.*.during_program' => 'during program',
            'volumes.*.volume' => 'volume',
            'realizations' => 'realizations',
            'realizations.*.stage' => 'stage',
            'realizations.*.start_at' => 'start date',
            'realizations.*.end_at' => 'end date',
            'realizations.*.volume' => 'volume',
            'mechanics' => 'mechanics',
            'mechanics.*.period' => 'period',
            'mechanics.*.unit_price' => 'unit price',
            'mechanics.*.sub_total' => 'sub total',
        ];
    }
}
