<?php

namespace App\Models\Forms\TradeRequest;

use App\Enums\RSMStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeRequest extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
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
        return $this->belongsTo(TradeProduct::class, 'product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TradeRequestItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TradeRequestAttachment::class);
    }

    /**
     * A trade request is editable until it has synced, or again after TDH
     * returns it to the DRM.
     */
    protected function isEditable(): Attribute
    {
        return Attribute::get(fn (): bool => $this->sync_status !== 'synced'
            || $this->tdh_status === TDHStatus::RETURNED);
    }
}
