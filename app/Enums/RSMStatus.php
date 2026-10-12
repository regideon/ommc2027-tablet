<?php

namespace App\Enums;

use App\Enums\Traits\BaseEnum;

enum RSMStatus: int
{
    use BaseEnum;

    case PENDING = 1;
    case APPROVED = 2;
    case DISAPPROVED = 3;

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
            self::DISAPPROVED->value => [
                'title' => 'Disapproved',
                'color' => 'danger',
            ],
        ];
    }
}
