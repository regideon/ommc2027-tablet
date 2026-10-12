<?php

namespace App\Models\Forms\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingTopic extends Model
{
    protected $guarded = ['id'];

    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class, 'topic_id');
    }
}
