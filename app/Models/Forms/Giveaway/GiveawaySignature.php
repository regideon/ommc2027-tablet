<?php

namespace App\Models\Forms\Giveaway;

use App\Models\Salescall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiveawaySignature extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'byte_size' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(GiveawayAnswer::class, 'answer_id');
    }

    public function salescall(): BelongsTo
    {
        return $this->belongsTo(Salescall::class);
    }
}
