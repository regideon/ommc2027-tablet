<?php

namespace App\Models\Forms\Giveaway;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiveawayAnswerItem extends Model
{
    protected $guarded = ['id'];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(GiveawaySubmissionItem::class, 'item_id');
    }
}
