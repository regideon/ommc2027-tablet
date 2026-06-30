<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalescallImage extends Model
{
    protected $guarded = ['id'];

    public function type()
    {
        return $this->belongsTo(SalescallImageType::class, 'salescall_image_type_id');
    }
}
