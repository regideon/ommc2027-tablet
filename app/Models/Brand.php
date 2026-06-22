<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Brand extends Model
{
    protected $fillable = ['material_group_id', 'name', 'enabled'];

    public function materialGroup(): BelongsTo
    {
        return $this->belongsTo(MaterialGroup::class);
    }
}
