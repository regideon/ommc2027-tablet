<?php

namespace App\Enums\TradeRequest;

use App\Enums\Traits\BaseEnum;

enum Size: int
{
    use BaseEnum;

    case SMALL = 1;
    case MEDIUM = 2;
    case LARGE = 3;
    case EXTRA_LARGE = 4;
    case XXL = 5;
    case XXXL = 6;

    public static function metaProperties(): array
    {
        return [
            self::SMALL->value => [
                'title' => 'Small',
            ],
            self::MEDIUM->value => [
                'title' => 'Medium',
            ],
            self::LARGE->value => [
                'title' => 'Large',
            ],
            self::EXTRA_LARGE->value => [
                'title' => 'Extra Large',
            ],
            self::XXL->value => [
                'title' => 'XXL',
            ],
            self::XXXL->value => [
                'title' => 'XXXL',
            ],
        ];
    }
}
