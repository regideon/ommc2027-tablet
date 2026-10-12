<?php

namespace App\Enums\Training;

use App\Enums\Traits\BaseEnum;

enum TrainingAttachmentType: int
{
    use BaseEnum;

    case REQUEST = 1;
    case COMPLETED = 2;

    public static function metaProperties(): array
    {
        return [
            self::REQUEST->value => [
                'title' => 'Request',
            ],
            self::COMPLETED->value => [
                'title' => 'Completed',
            ],
        ];
    }
}
