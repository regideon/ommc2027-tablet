<?php

namespace App\Models\Forms\CRR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrrProgram extends Model
{
    protected $guarded = ['id'];

    public function crrs(): HasMany
    {
        return $this->hasMany(Crr::class, 'crr_program_id');
    }
}
