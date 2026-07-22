<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalescallType extends Model
{
    protected $guarded = ['id'];

    public const AI_GENERATED = 'AI Generated';

    public const UNPLANNED = 'Unplanned';

    public const RSM_ADDED = 'RSM Added';

    public static function idFor(string $name): ?int
    {
        return static::query()->where('name', $name)->value('id');
    }
}
