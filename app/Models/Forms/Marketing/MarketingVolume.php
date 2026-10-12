<?php

namespace App\Models\Forms\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingVolume extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before_program' => 'decimal:2',
            'during_program' => 'decimal:2',
            'volume' => 'decimal:2',
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
