<?php

namespace App\Models\Forms\TradeCheck;

use App\Enums\TradeCheck\GroupCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeCheckGroup extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'category' => GroupCategory::class,
    ];

    public function tradeCheck(): BelongsTo
    {
        return $this->belongsTo(TradeCheck::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TradeCheckItem::class, 'group_id');
    }
}
