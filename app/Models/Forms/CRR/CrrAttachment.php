<?php

namespace App\Models\Forms\CRR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrrAttachment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'byte_size' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function crr(): BelongsTo
    {
        return $this->belongsTo(Crr::class);
    }
}
