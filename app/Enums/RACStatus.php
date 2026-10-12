<?php

namespace App\Enums;

use App\Enums\Traits\BaseEnum;

enum RACStatus: int
{
    use BaseEnum;

    case PENDING = 1;
    case RETURNED_TO_DRM = 2;
    case RETURNED_TO_SPS = 3;
    case APPROVED = 4;
    case DISAPPROVED = 5;

    public static function metaProperties(): array
    {
        return [
            self::PENDING->value => [
                'title' => 'Pending',
                'color' => 'gray',
            ],
            self::RETURNED_TO_DRM->value => [
                'title' => 'Returned to DRM',
                'color' => 'warning',
            ],
            self::RETURNED_TO_SPS->value => [
                'title' => 'Returned to SPS',
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
