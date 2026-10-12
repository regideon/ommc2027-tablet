<?php

namespace App\Models\Forms\TradeCheck;

use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeCheckAnswer extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'rsm_status' => RSMStatus::class,
        'tdh_status' => TDHStatus::class,
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

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function salescall(): BelongsTo
    {
        return $this->belongsTo(Salescall::class);
    }
}
