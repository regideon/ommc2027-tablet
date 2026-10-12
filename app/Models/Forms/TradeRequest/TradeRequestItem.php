<?php

namespace App\Models\Forms\TradeRequest;

use App\Enums\TradeRequest\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeRequestItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'size' => Size::class,
    ];

    public function tradeRequest(): BelongsTo
    {
        return $this->belongsTo(TradeRequest::class, 'trade_request_id');
    }

    public function tradeType(): BelongsTo
    {
        return $this->belongsTo(TradeType::class, 'trade_type_id');
    }
}
