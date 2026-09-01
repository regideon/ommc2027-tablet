<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function generalCategory(): BelongsTo
    {
        return $this->belongsTo(GeneralCategory::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function tradeProfile(): HasOne
    {
        return $this->hasOne(CustomerTradeProfile::class);
    }

    public function categoryHistories(): HasMany
    {
        return $this->hasMany(CustomerCategoryHistory::class)->orderBy('category_year');
    }

    public function salescalls()
    {
        return $this->hasMany(Salescall::class);
    }
}
