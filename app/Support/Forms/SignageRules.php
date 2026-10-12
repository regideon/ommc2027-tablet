<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;

class SignageRules implements ProvidesFormRules
{
    /**
     * Validation rules for a local CRR Signage create/update payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'product_id' => ['required', 'integer', 'exists:signage_products,id'],
            'type_id' => ['required', 'integer', 'exists:signage_types,id'],
            'category' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
