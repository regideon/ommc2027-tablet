<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Itinerary extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function salescalls()
    {
        return $this->hasMany(Salescall::class);
    }
}
