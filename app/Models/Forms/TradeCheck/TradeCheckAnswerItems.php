<?php

namespace App\Models\Forms\TradeCheck;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeCheckAnswerItems extends Model
{
    protected $table = 'trade_check_answer_items';

    protected $guarded = ['id'];

    public function tradeCheck(): BelongsTo
    {
        return $this->belongsTo(TradeCheck::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TradeCheckItem::class, 'item_id');
    }
}
