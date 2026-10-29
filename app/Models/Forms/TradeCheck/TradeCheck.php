<?php

namespace App\Models\Forms\TradeCheck;

use App\Enums\TDHStatus;
use App\Models\Salescall;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeCheck extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function group(): HasMany
    {
        return $this->hasMany(TradeCheckGroup::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(TradeCheckAnswer::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TradeCheckAttachment::class);
    }

    public function salescall(): BelongsTo
    {
        return $this->belongsTo(Salescall::class);
    }

    /**
     * A submission's metadata (submitter, sales call, statuses) lives on its
     * answer rows; they are shared across the submission.
     */
    public function firstAnswer(): HasOne
    {
        return $this->hasOne(TradeCheckAnswer::class)->oldestOfMany();
    }

    /**
     * Editable until synced, or again after TDH returns the user's answers to
     * the DRM.
     */
    protected function isEditable(): Attribute
    {
        return Attribute::get(fn (): bool => $this->isEditableBy((int) auth()->id()));
    }

    public function isEditableBy(int $userId): bool
    {
        $answers = $this->answers->where('submitted_by', $userId);

        if ($answers->isEmpty()) {
            return false;
        }

        if ($this->sync_status !== 'synced') {
            return true;
        }

        return $answers->every(
            fn (TradeCheckAnswer $answer): bool => $answer->tdh_status === TDHStatus::RETURNED
        );
    }
}
