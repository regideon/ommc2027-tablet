<?php

namespace App\Contracts;

interface ProvidesFormRules
{
    /**
     * Validation rules for a form's local create/update payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array;
}
