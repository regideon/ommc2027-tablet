<?php

namespace App\Enums;

use App\Enums\Traits\BaseEnum;

enum SPMStatus: int
{
    use BaseEnum;

    case PENDING = 1;
    case ENDORSED = 2;
    case RETURNED = 3;
    case APPROVED = 4;
    case DISAPPROVED = 5;

    public static function metaProperties(): array
    {
        return [
            self::PENDING->value => [
                'title' => 'Pending',
                'color' => 'warning',
            ],
            self::ENDORSED->value => [
                'title' => 'Endorsed',
                'color' => 'success',
            ],
            self::RETURNED->value => [
                'title' => 'Returned',
                'color' => 'danger',
            ],
            self::APPROVED->value => [
                'title' => 'Approved',
                'color' => 'info',
            ],
            self::DISAPPROVED->value => [
                'title' => 'Disapproved',
                'color' => 'gray',
            ],
        ];
    }
}
