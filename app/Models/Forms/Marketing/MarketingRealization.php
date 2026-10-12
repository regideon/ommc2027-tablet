<?php

namespace App\Models\Forms\Marketing;

use App\Enums\Marketing\RealizationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingRealization extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage' => RealizationStage::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
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
