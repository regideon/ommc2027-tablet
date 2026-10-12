<?php

namespace App\Enums;

use App\Enums\Traits\BaseEnum;

enum SPSStatus: int
{
    use BaseEnum;

    case PENDING = 1;
    case RETURNED = 2;
    case ENDORSED = 3;
    case COMPLETED = 4;

    public static function metaProperties(): array
    {
        return [
            self::PENDING->value => [
                'title' => 'Pending',
                'color' => 'warning',
            ],
            self::RETURNED->value => [
                'title' => 'Returned',
                'color' => 'danger',
            ],
            self::ENDORSED->value => [
                'title' => 'Endorsed',
                'color' => 'success',
            ],
            self::COMPLETED->value => [
                'title' => 'Completed',
                'color' => 'info',
            ],
        ];
    }
}
