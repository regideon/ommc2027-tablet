<?php

namespace App\Models\Forms\Marketing;

use App\Enums\RACStatus;
use App\Enums\SPSStatus;
use App\Models\RegionType;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role;

class Marketing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'duration_start_at' => 'datetime',
            'duration_end_at' => 'datetime',
            'synced_at' => 'datetime',
            'sps_status' => SPSStatus::class,
            'rac_status' => RACStatus::class,
            'rac_return_count' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function regionType(): BelongsTo
    {
        return $this->belongsTo(RegionType::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(MarketingProgram::class, 'program_id');
    }

    public function salescall(): BelongsTo
    {
        return $this->belongsTo(Salescall::class);
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(MarketingVolume::class);
    }

    public function realizations(): HasMany
    {
        return $this->hasMany(MarketingRealization::class);
    }

    public function mechanics(): HasMany
    {
        return $this->hasMany(MarketingMechanic::class);
    }
}
