<?php

namespace App\Models\Forms\Repair;

use App\Enums\Repair\RepairAttachmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairAttachment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'type' => RepairAttachmentType::class,
        'byte_size' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }
}
