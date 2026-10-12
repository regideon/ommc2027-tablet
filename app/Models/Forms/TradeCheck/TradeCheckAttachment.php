<?php

namespace App\Models\Forms\TradeCheck;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeCheckAttachment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'byte_size' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function tradeCheck(): BelongsTo
    {
        return $this->belongsTo(TradeCheck::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TradeCheckItem::class, 'item_id');
    }
}
