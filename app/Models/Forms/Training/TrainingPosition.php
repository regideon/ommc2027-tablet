<?php

namespace App\Models\Forms\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPosition extends Model
{
    protected $guarded = ['id'];

    public function participants(): HasMany
    {
        return $this->hasMany(TrainingParticipant::class, 'position_id');
    }
}
