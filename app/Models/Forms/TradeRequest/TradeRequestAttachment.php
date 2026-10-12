<?php

namespace App\Models\Forms\TradeRequest;

use App\Enums\TradeRequest\TradeRequestAttachmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeRequestAttachment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'type' => TradeRequestAttachmentType::class,
        'byte_size' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function tradeRequest(): BelongsTo
    {
        return $this->belongsTo(TradeRequest::class);
    }
}
