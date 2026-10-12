<?php

namespace App\Enums\Marketing;

use App\Enums\Traits\BaseEnum;

enum RealizationStage: int
{
    use BaseEnum;

    case FIRST_STAGE = 1;
    case SECOND_STAGE = 2;

    public static function metaProperties(): array
    {
        return [
            self::FIRST_STAGE->value => [
                'title' => '1st Stage',
            ],
            self::SECOND_STAGE->value => [
                'title' => '2nd Stage',
            ],
        ];
    }
}
