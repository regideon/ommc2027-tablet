<?php

namespace App\Models\Forms\Giveaway;

use App\Enums\RSMStatus;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GiveawayAnswer extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'rsm_status' => RSMStatus::class,
    ];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(GiveawaySubmissionItem::class, 'item_id');
    }

    public function answerItem(): HasOne
    {
        return $this->hasOne(GiveawayAnswerItem::class, 'item_id');
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
