<?php

namespace App\Enums;

use App\Enums\Traits\BaseEnum;

enum FormKey: string
{
    use BaseEnum;

    case CRR = 'crr';
    case MARKETING = 'marketing';
    case TRADE_CHECK = 'trade-check';
    case GIVEAWAY = 'giveaway';
    case REPAIR = 'repair';
    case SIGNAGE = 'crr-signage';
    case TRADE_REQUEST = 'trade-request';
    case TRAINING = 'training';

    public static function metaProperties(): array
    {
        return [
            self::CRR->value => [
                'title' => 'CRR',
                'icon' => 'heroicon-o-document-text',
                'available' => true,
            ],
            self::MARKETING->value => [
                'title' => 'Marketing',
                'icon' => 'heroicon-o-megaphone',
                'available' => true,
            ],
            self::TRADE_CHECK->value => [
                'title' => 'Trade Check',
                'icon' => 'heroicon-o-clipboard-document-check',
                'available' => false,
            ],
            self::GIVEAWAY->value => [
                'title' => 'Giveaway',
                'icon' => 'heroicon-o-gift',
                'available' => false,
            ],
            self::REPAIR->value => [
                'title' => 'Repair',
                'icon' => 'heroicon-o-wrench-screwdriver',
                'available' => false,
            ],
            self::SIGNAGE->value => [
                'title' => 'CRR Signage',
                'icon' => 'heroicon-o-photo',
                'available' => false,
            ],
            self::TRADE_REQUEST->value => [
                'title' => 'Trade Request',
                'icon' => 'heroicon-o-shopping-bag',
                'available' => false,
            ],
            self::TRAINING->value => [
                'title' => 'Training',
                'icon' => 'heroicon-o-academic-cap',
                'available' => false,
            ],
        ];
    }

    public function label(): string
    {
        return (string) $this->meta('title');
    }

    public function icon(): string
    {
        return (string) $this->meta('icon');
    }

    public function available(): bool
    {
        return (bool) $this->meta('available');
    }

    public function slug(): string
    {
        return $this->value;
    }
}
