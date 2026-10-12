<?php

namespace App\Models\Forms\Giveaway;

use App\Enums\Giveaway\GiveawayCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GiveawayItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'category' => GiveawayCategory::class,
    ];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
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
