<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalescallImageType extends Model
{
    protected $guarded = ['id'];

    public function category()
    {
        return $this->belongsTo(SalescallImageCategory::class, 'salescall_image_category_id');
    }
}
