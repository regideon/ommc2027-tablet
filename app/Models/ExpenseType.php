<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseType extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
