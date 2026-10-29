<?php

namespace App\Models\Forms\TradeCheck;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeCheckSubgroup extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_image_required' => 'boolean',
    ];

    public function tradeCheck(): BelongsTo
    {
        return $this->belongsTo(TradeCheck::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TradeCheckGroup::class, 'group_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TradeCheckItem::class, 'subgroup_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TradeCheckAttachment::class, 'subgroup_id');
    }
}
