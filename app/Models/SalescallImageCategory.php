<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalescallImageCategory extends Model
{
    protected $guarded = ['id'];

    public function types()
    {
        return $this->hasMany(SalescallImageType::class)->orderBy('sort');
    }
}
