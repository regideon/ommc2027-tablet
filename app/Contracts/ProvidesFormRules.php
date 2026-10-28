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

    /**
     * Custom validation messages for the form.
     *
     * @return array<string, string>
     */
    public static function messages(): array;

    /**
     * Friendly field names used in validation messages.
     *
     * @return array<string, string>
     */
    public static function attributes(): array;
}
