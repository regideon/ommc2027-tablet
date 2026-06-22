<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model {
    protected $fillable = ['category_id', 'name', 'is_active'];
    public function subSubCategories() { return $this->hasMany(SubSubCategory::class); }
}