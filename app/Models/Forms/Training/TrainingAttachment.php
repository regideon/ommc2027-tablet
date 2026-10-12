<?php

namespace App\Models\Forms\Training;

use App\Enums\Training\TrainingAttachmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingAttachment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'type' => TrainingAttachmentType::class,
        'byte_size' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'training_id');
    }
}
