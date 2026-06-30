<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Salescall extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'visit_date' => 'datetime',
        'actual_in'  => 'datetime',
        'actual_out' => 'datetime',
    ];


    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function itinerary()
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function getVisitDateAttribute(): Carbon
    {
        return Carbon::parse(
            $this->attributes['visit_date']
                ?? $this->actual_in
                ?? $this->created_at
        );
    }

    public function getStatusAttribute(): string
    {
        if (is_null($this->actual_in))  return 'scheduled';
        if (is_null($this->actual_out)) return 'in_progress';
        return 'completed';
    }

    public function images()
    {
        return $this->hasMany(SalescallImage::class);
    }
}
