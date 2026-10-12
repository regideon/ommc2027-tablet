<?php

namespace App\Models\Forms\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingMechanic extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period' => 'datetime',
            'unit_price' => 'decimal:2',
            'sub_total' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */
    public function marketing(): BelongsTo
    {
        return $this->belongsTo(Marketing::class);
    }
}
