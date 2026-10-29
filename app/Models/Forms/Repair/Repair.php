<?php

namespace App\Models\Forms\Repair;

use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repair extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'rsm_status' => RSMStatus::class,
        'sps_status' => SPSStatus::class,
        'spm_status' => SPMStatus::class,
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

    public function attachments(): HasMany
    {
        return $this->hasMany(RepairAttachment::class);
    }

    /**
     * A repair is editable until it has synced, or again after SPS returns it
     * to the DRM. A return to SPS is not a DRM edit.
     */
    protected function isEditable(): Attribute
    {
        return Attribute::get(fn (): bool => $this->sync_status !== 'synced'
            || $this->sps_status === SPSStatus::RETURNED);
    }
}
