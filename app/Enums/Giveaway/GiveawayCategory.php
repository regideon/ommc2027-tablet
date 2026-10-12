<?php

namespace App\Enums\Giveaway;

use App\Enums\Traits\BaseEnum;

enum GiveawayCategory: int
{
    use BaseEnum;

    case GC = 1;
    case CALENDAR = 2;
    case SHIRT = 3;

    public static function metaProperties(): array
    {
        return [
            self::GC->value => [
                'title' => 'Gift Check',
            ],
            self::CALENDAR->value => [
                'title' => 'Calendar',
            ],
            self::SHIRT->value => [
                'title' => 'Shirt',
            ],
        ];
    }
}
