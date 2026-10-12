<?php

namespace App\Models\Forms\Giveaway;

use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Giveaway extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(GiveawayItem::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GiveawayAnswer::class);
    }

    /**
     * A submission's metadata (submitter, sales call, status) lives on its
     * answer rows; they are shared across the submission.
     */
    public function firstAnswer(): HasOne
    {
        return $this->hasOne(GiveawayAnswer::class)->oldestOfMany();
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(GiveawaySignature::class);
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
