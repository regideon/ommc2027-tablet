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
        'actual_in' => 'datetime',
        'actual_out' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
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
                ?? $this->attributes['route_start_at']
                ?? $this->created_at
        );
    }

    public function getStatusAttribute(): string
    {
        if (is_null($this->actual_in)) {
            return 'scheduled';
        }
        if (is_null($this->actual_out)) {
            return 'in_progress';
        }

        return match ($this->salescallStatus?->name) {
            SalescallStatus::PARTIALLY_COMPLETED => 'partially_completed',
            SalescallStatus::CANCELLED => 'cancelled',
            default => 'completed',
        };
    }

    public function salescallStatus()
    {
        return $this->belongsTo(SalescallStatus::class);
    }

    public function images()
    {
        return $this->hasMany(SalescallImage::class);
    }

    public function salescallBrands()
    {
        return $this->hasMany(SalescallBrand::class);
    }

    public function salescallCategory()
    {
        return $this->hasOne(SalescallCategory::class);
    }
}
