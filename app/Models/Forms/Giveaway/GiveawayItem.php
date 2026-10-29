<?php

namespace App\Models\Forms\Giveaway;

use App\Enums\Giveaway\GiveawayCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiveawayItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'category' => GiveawayCategory::class,
    ];

    public function submissionItems(): HasMany
    {
        return $this->hasMany(GiveawaySubmissionItem::class, 'giveaway_item_id');
    }
}
