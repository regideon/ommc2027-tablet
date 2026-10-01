<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AreaCluster extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function regionSpecific(): BelongsTo
    {
        return $this->belongsTo(RegionSpecific::class);
    }
}
