<?php

namespace App\Support\Forms\Concerns;

use Illuminate\Support\Facades\Validator;

trait ValidatesFormPayload
{
    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function validate(array $input): array
    {
        return Validator::make($input, static::rules(), static::messages(), static::attributes())->validate();
    }
}
