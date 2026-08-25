<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfileAttachment extends Model
{
    protected $guarded = ['id'];

    public function salescall()
    {
        return $this->belongsTo(Salescall::class);
    }
}
