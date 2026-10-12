<?php

namespace App\Models\Forms\CRRSignage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignageProduct extends Model
{
    protected $guarded = ['id'];

    public function signages(): HasMany
    {
        return $this->hasMany(Signage::class, 'product_id');
    }
}
