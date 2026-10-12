<?php

namespace App\Enums\CRRSignage;

use App\Enums\Traits\BaseEnum;

enum SignageAttachmentType: int
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
