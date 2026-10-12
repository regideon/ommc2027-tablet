<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;

class CrrRules implements ProvidesFormRules
{
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
            'amount' => ['required', 'numeric'],
            'details' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
