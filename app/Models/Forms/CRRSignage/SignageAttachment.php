<?php

namespace App\Models\Forms\CRRSignage;

use App\Enums\CRRSignage\SignageAttachmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignageAttachment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'type' => SignageAttachmentType::class,
        'byte_size' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function signage(): BelongsTo
    {
        return $this->belongsTo(Signage::class);
    }
}
