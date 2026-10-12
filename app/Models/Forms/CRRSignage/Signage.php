<?php

namespace App\Models\Forms\CRRSignage;

use App\Enums\CRRSignage\SignageCategory;
use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Signage extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'category' => SignageCategory::class,
        'rsm_status' => RSMStatus::class,
        'tdh_status' => TDHStatus::class,
        'tds_status' => TDSStatus::class,
        'synced_at' => 'datetime',
    ];

    public function salescall(): BelongsTo
    {
        return $this->belongsTo(Salescall::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SignageProduct::class, 'product_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(SignageType::class, 'type_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SignageAttachment::class);
    }
}
