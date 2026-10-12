<?php

namespace App\Enums;

use App\Enums\Traits\BaseEnum;

enum TDSStatus: int
{
    use BaseEnum;

    case PENDING = 1;
    case COMPLETED = 2;

    public static function metaProperties(): array
    {
        return [
            self::PENDING->value => [
                'title' => 'Pending',
                'color' => 'warning',
            ],
            self::COMPLETED->value => [
                'title' => 'Completed',
                'color' => 'success',
            ],
        ];
    }
}
