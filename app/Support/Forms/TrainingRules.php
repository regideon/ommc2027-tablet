<?php

namespace App\Support\Forms;

use App\Contracts\ProvidesFormRules;
use App\Enums\Training\TrainingAttachmentType;
use App\Support\Forms\Concerns\ValidatesFormPayload;

class TrainingRules implements ProvidesFormRules
{
    use ValidatesFormPayload;

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
            'participants.*.name' => ['required_with:participants', 'string', 'max:255'],
            'participants.*.position_id' => ['required_with:participants', 'integer', 'exists:training_positions,id'],
            'staged_attachments' => ['nullable', 'array'],
            'staged_attachments.*.type' => ['required_with:staged_attachments', 'integer', 'in:'.implode(',', TrainingAttachmentType::values())],
            'staged_attachments.*.staged_id' => ['required_with:staged_attachments', 'integer'],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'salescall_uuid' => 'sales call',
            'topic_id' => 'topic',
            'other_topic' => 'other topic',
            'preferred_at' => 'preferred date',
            'omcc_volume' => 'OMCC volume',
            'competitor_volume' => 'competitor volume',
            'participants' => 'participants',
            'participants.*.name' => 'name',
            'participants.*.position_id' => 'position',
            'staged_attachments' => 'attachment',
            'staged_attachments.*.type' => 'attachment type',
            'staged_attachments.*.staged_id' => 'attachment',
            'remove_attachments' => 'attachment',
            'remove_attachments.*' => 'attachment',
        ];
    }
}
