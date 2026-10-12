<?php

namespace App\Models\Forms\TradeRequest;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeProduct extends Model
{
    protected $guarded = ['id'];

    public function requests(): HasMany
    {
        return $this->hasMany(TradeRequest::class, 'product_id');
    }
}
