<?php

namespace App\Models\Forms\TradeCheck;

use App\Enums\TradeCheck\FieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeCheckItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
        'type' => FieldType::class,
        'is_required' => 'boolean',
        'is_image_required' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(TradeCheckGroup::class, 'group_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(TradeCheckAnswer::class, 'item_id');
    }

    public function answerItem(): HasOne
    {
        return $this->hasOne(TradeCheckAnswerItems::class, 'item_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TradeCheckAttachment::class, 'item_id');
    }
}
