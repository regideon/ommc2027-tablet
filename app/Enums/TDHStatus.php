<?php

namespace App\Enums;

use App\Enums\Traits\BaseEnum;

enum TDHStatus: int
{
    use BaseEnum;

    case PENDING = 1;
    case APPROVED = 2;
    case RETURNED = 3;

    public static function metaProperties(): array
    {
        return [
            self::PENDING->value => [
                'title' => 'Pending',
                'color' => 'warning',
            ],
            self::APPROVED->value => [
                'title' => 'Approved',
                'color' => 'success',
            ],
            self::RETURNED->value => [
                'title' => 'Returned',
                'color' => 'danger',
            ],
        ];
    }
}
