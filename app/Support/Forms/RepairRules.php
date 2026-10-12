<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;

class RepairRules implements ProvidesFormRules
{
    /**
     * Validation rules for a local Repair create/update payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'plate_number' => ['required', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'details' => ['required', 'string'],
            'amount' => ['required', 'numeric'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
