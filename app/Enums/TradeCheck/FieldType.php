<?php

namespace App\Enums\TradeCheck;

use App\Enums\Traits\BaseEnum;

enum FieldType: int
{
    use BaseEnum;

    case BOOLEAN = 1;
    case TEXT = 2;
    case NUMBER = 3;
    case SELECT = 4;

    public static function metaProperties(): array
    {
        return [
            self::BOOLEAN->value => [
                'title' => 'Boolean',
            ],
            self::TEXT->value => [
                'title' => 'Text',
            ],
            self::NUMBER->value => [
                'title' => 'Number',
            ],
            self::SELECT->value => [
                'title' => 'Select',
            ],
        ];
    }
}
