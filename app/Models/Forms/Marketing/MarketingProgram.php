<?php

namespace App\Models\Forms\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingProgram extends Model
{
    protected $table = 'marketing_programs';

    protected $guarded = ['id'];

    public function marketings(): HasMany
    {
        return $this->hasMany(Marketing::class, 'program_id');
    }
}
