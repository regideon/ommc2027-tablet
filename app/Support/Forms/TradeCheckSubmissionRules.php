<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Support\Forms\Concerns\ValidatesFormPayload;
use Illuminate\Support\Facades\Validator;

class TradeCheckSubmissionRules implements ProvidesFormRules
{
    use ValidatesFormPayload;

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $includeTemplate = true): array
    {
        $rules = [
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'answers' => ['nullable', 'array'],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
        ];

        if ($includeTemplate) {
            $rules['trade_check_id'] = ['required', 'integer', 'exists:trade_checks,id'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'trade_check_id' => 'Trade Check template',
            'salescall_uuid' => 'sales call',
            'answers' => 'answer',
            'remove_attachments' => 'attachment',
            'remove_attachments.*' => 'attachment',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function validate(array $input, bool $includeTemplate = true): array
    {
        return Validator::make(
            $input,
            static::rules($includeTemplate),
            static::messages(),
            static::attributes(),
        )->validate();
    }
}
