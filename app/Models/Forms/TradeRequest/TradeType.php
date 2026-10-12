<?php

namespace App\Models\Forms\TradeRequest;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeType extends Model
{
    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(TradeRequestItem::class, 'trade_type_id');
    }
}
