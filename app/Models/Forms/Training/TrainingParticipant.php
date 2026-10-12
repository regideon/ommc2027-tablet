<?php

namespace App\Models\Forms\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingParticipant extends Model
{
    protected $guarded = ['id'];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'training_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(TrainingPosition::class, 'position_id');
    }
}
