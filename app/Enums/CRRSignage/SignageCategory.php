<?php

namespace App\Enums\CRRSignage;

use App\Enums\Traits\BaseEnum;

enum SignageCategory: int
{
    use BaseEnum;

    case NEW = 1;
    case REPLACEMENT = 2;

    public static function metaProperties(): array
    {
        return [
            self::NEW->value => [
                'title' => 'New',
            ],
            self::REPLACEMENT->value => [
                'title' => 'Replacement',
            ],
        ];
    }
}
