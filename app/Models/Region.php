<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $guarded = ['id'];

    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class);
    }

    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class);
    }
}
