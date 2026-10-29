<?php

namespace App\Models\Forms\Giveaway;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GiveawaySubmissionItem extends Model
{
    protected $guarded = ['id'];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(GiveawayItem::class, 'giveaway_item_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GiveawayAnswer::class, 'item_id');
    }

    public function answerItem(): HasOne
    {
        return $this->hasOne(GiveawayAnswerItem::class, 'item_id');
    }
}
