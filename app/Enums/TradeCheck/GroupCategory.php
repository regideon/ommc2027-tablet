<?php

namespace App\Enums\TradeCheck;

use App\Enums\Traits\BaseEnum;

enum GroupCategory: int
{
    use BaseEnum;

    case EXTERNAL = 1;
    case STORE_FRONT = 2;
    case STORE_MERCHANDISING = 3;
    case STORE_EQUIPMENT = 4;
    case SERVICE_AND_UPKEEP = 5;
    case SOCIAL_MEDIA = 6;

    public static function metaProperties(): array
    {
        return [
            self::EXTERNAL->value => [
                'title' => 'External',
            ],
            self::STORE_FRONT->value => [
                'title' => 'Store Front',
            ],
            self::STORE_MERCHANDISING->value => [
                'title' => 'Store Merchandising',
            ],
            self::STORE_EQUIPMENT->value => [
                'title' => 'Store Equipment',
            ],
            self::SERVICE_AND_UPKEEP->value => [
                'title' => 'Service and Upkeep',
            ],
            self::SOCIAL_MEDIA->value => [
                'title' => 'Social Media',
            ],
        ];
    }
}
