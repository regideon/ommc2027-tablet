<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Support\Forms\Concerns\ValidatesFormPayload;

class CrrRules implements ProvidesFormRules
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
            'crr_program_id' => ['required', 'integer', 'exists:crr_programs,id'],
            'date_implemented' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'details' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'crr_program_id' => 'CRR program',
            'date_implemented' => 'date implemented',
            'amount' => 'amount',
            'details' => 'details',
            'remarks' => 'remarks',
            'salescall_uuid' => 'sales call',
        ];
    }
}
