<?php

namespace App\Models\Forms\Giveaway;

use App\Enums\RSMStatus;
use App\Models\Salescall;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Giveaway extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(GiveawaySubmissionItem::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GiveawayAnswer::class);
    }

    /**
     * A submission's metadata (submitter, sales call, status) lives on its
     * answer rows; they are shared across the submission.
     */
    public function firstAnswer(): HasOne
    {
        return $this->hasOne(GiveawayAnswer::class)->oldestOfMany();
    }

    public function signature(): HasOne
    {
        return $this->hasOne(GiveawaySignature::class)->latestOfMany();
    }

    public function photo(): HasOne
    {
        return $this->hasOne(GiveawayPhoto::class)->latestOfMany();
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function salescall(): BelongsTo
    {
        return $this->belongsTo(Salescall::class);
    }

    /**
     * Editable only while every one of the user's answer rows is still Pending.
     */
    public function isEditableBy(int $userId): bool
    {
        $answers = $this->answers->where('submitted_by', $userId);

        if ($answers->isEmpty()) {
            return false;
        }

        return $answers->every(
            fn (GiveawayAnswer $answer): bool => $answer->rsm_status === RSMStatus::PENDING
        );
    }
}
