<?php

namespace App\Models\Forms\TradeCheck;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeCheck extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function group(): HasMany
    {
        return $this->hasMany(TradeCheckGroup::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(TradeCheckAnswer::class);
    }

    /**
     * A submission's metadata (submitter, sales call, statuses) lives on its
     * answer rows; they are shared across the submission.
     */
    public function firstAnswer(): HasOne
    {
        return $this->hasOne(TradeCheckAnswer::class)->oldestOfMany();
    }
}
