<?php

namespace App\Enums\TradeRequest;

use App\Enums\Traits\BaseEnum;

enum TradeRequestAttachmentType: int
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
