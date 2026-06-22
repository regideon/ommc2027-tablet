<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialGroup extends Model
{
    protected $fillable = ['name'];

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }
}
