<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalescallStatus extends Model
{
    protected $guarded = ['id'];

    public const PENDING = 'Pending';

    public const IN_PROGRESS = 'In Progress';

    public const SCHEDULED = 'Scheduled';

    public const COMPLETED = 'Completed';

    public const PARTIALLY_COMPLETED = 'Partially Completed';

    public const CANCELLED = 'Cancelled';

    public const APPROVED = 'Approved';

    public const REVISED = 'Revised';

    public const REJECTED = 'Rejected';

    public static function idFor(string $name): ?int
    {
        return static::query()->where('name', $name)->value('id');
    }
}
