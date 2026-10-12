<?php

namespace App\Enums\Repair;

use App\Enums\Traits\BaseEnum;

enum RepairAttachmentType: int
{
    use BaseEnum;

    case INVOICE = 1;
    case CM_PREPARATION = 2;
    case RAC_APPROVAL = 3;
    case REQUEST = 4;

    public static function metaProperties(): array
    {
        return [
            self::INVOICE->value => [
                'title' => 'Invoice',
            ],
            self::CM_PREPARATION->value => [
                'title' => 'CM Preparations',
            ],
            self::RAC_APPROVAL->value => [
                'title' => 'RAC Approval',
            ],
            self::REQUEST->value => [
                'title' => 'Request',
            ],
        ];
    }
}
