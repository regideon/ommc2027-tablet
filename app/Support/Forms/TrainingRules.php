<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;

class TrainingRules implements ProvidesFormRules
{
    /**
     * Validation rules for a local Training create/update payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'server_id' => ['nullable', 'integer'],
            'salescall_uuid' => ['nullable', 'string', 'max:36'],
            'topic_id' => ['nullable', 'integer', 'exists:training_topics,id'],
            'other_topic' => ['nullable', 'string', 'max:255'],
            'preferred_at' => ['required', 'date'],
            'omcc_volume' => ['nullable', 'integer'],
            'competitor_volume' => ['nullable', 'integer'],
            'participants' => ['nullable', 'array'],
            'participants.*.name' => ['required', 'string', 'max:255'],
            'participants.*.position_id' => ['required', 'integer', 'exists:training_positions,id'],
        ];
    }
}
