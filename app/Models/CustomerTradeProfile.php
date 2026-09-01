<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTradeProfile extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'classifications' => 'array', 'ommc_brands' => 'array', 'ommc_mcb_brands' => 'array',
        'tpl_pollux' => 'array', 'other_competitor_brands' => 'array', 'mcb_competitors' => 'array',
        'working_days' => 'array', 'operating_hours' => 'array', 'motiv_user' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
