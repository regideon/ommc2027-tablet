<?php

use App\Enums\CRRSignage\SignageAttachmentType;
use App\Enums\CRRSignage\SignageCategory;
use App\Enums\FormKey;
use App\Enums\Giveaway\GiveawayCategory;
use App\Enums\Marketing\RealizationStage;
use App\Enums\RACStatus;
use App\Enums\Repair\RepairAttachmentType;
use App\Enums\RSMStatus;
use App\Enums\SPMStatus;
use App\Enums\SPSStatus;
use App\Enums\TDHStatus;
use App\Enums\TDSStatus;
use App\Enums\TradeCheck\FieldType;
use App\Enums\TradeCheck\GroupCategory;
use App\Enums\TradeRequest\Size;
use App\Enums\TradeRequest\TradeRequestAttachmentType;
use App\Enums\Training\TrainingAttachmentType;

test('global status enums match the portal values and metadata', function () {
    expect(RSMStatus::PENDING->value)->toBe(1)
        ->and(RSMStatus::APPROVED->value)->toBe(2)
        ->and(RSMStatus::DISAPPROVED->value)->toBe(3)
        ->and(RSMStatus::PENDING->meta('title'))->toBe('Pending')
        ->and(RSMStatus::DISAPPROVED->meta('color'))->toBe('danger');

    expect(SPSStatus::PENDING->value)->toBe(1)
        ->and(SPSStatus::RETURNED->value)->toBe(2)
        ->and(SPSStatus::ENDORSED->value)->toBe(3)
        ->and(SPSStatus::COMPLETED->value)->toBe(4);

    expect(SPMStatus::PENDING->value)->toBe(1)
        ->and(SPMStatus::ENDORSED->value)->toBe(2)
        ->and(SPMStatus::RETURNED->value)->toBe(3)
        ->and(SPMStatus::APPROVED->value)->toBe(4)
        ->and(SPMStatus::DISAPPROVED->value)->toBe(5);

    expect(RACStatus::RETURNED_TO_DRM->value)->toBe(2)
        ->and(RACStatus::RETURNED_TO_SPS->value)->toBe(3)
        ->and(RACStatus::APPROVED->value)->toBe(4)
        ->and(RACStatus::DISAPPROVED->value)->toBe(5)
        ->and(RACStatus::PENDING->meta('color'))->toBe('gray');

    expect(TDHStatus::APPROVED->value)->toBe(2)
        ->and(TDHStatus::RETURNED->value)->toBe(3);

    expect(TDSStatus::COMPLETED->value)->toBe(2)
        ->and(TDSStatus::PENDING->meta('title'))->toBe('Pending');
});

test('per-form enums match the portal values and labels', function () {
    expect(RealizationStage::FIRST_STAGE->value)->toBe(1)
        ->and(RealizationStage::FIRST_STAGE->meta('title'))->toBe('1st Stage')
        ->and(RealizationStage::SECOND_STAGE->value)->toBe(2);

    expect(GiveawayCategory::GC->meta('title'))->toBe('Gift Check')
        ->and(GiveawayCategory::CALENDAR->value)->toBe(2)
        ->and(GiveawayCategory::SHIRT->value)->toBe(3);

    expect(SignageCategory::NEW->value)->toBe(1)
        ->and(SignageCategory::REPLACEMENT->value)->toBe(2);

    expect(SignageAttachmentType::REQUEST->value)->toBe(1)
        ->and(SignageAttachmentType::COMPLETED->value)->toBe(2);

    expect(GroupCategory::EXTERNAL->value)->toBe(1)
        ->and(GroupCategory::SOCIAL_MEDIA->value)->toBe(6)
        ->and(GroupCategory::SOCIAL_MEDIA->meta('title'))->toBe('Social Media');

    expect(FieldType::BOOLEAN->value)->toBe(1)
        ->and(FieldType::SELECT->value)->toBe(4);

    expect(RepairAttachmentType::INVOICE->value)->toBe(1)
        ->and(RepairAttachmentType::CM_PREPARATION->meta('title'))->toBe('CM Preparations')
        ->and(RepairAttachmentType::REQUEST->value)->toBe(4);

    expect(Size::SMALL->value)->toBe(1)
        ->and(Size::EXTRA_LARGE->meta('title'))->toBe('Extra Large')
        ->and(Size::XXXL->value)->toBe(6);

    expect(TradeRequestAttachmentType::COMPLETED->value)->toBe(2);

    expect(TrainingAttachmentType::REQUEST->value)->toBe(1)
        ->and(TrainingAttachmentType::COMPLETED->value)->toBe(2);
});

test('base enum helpers expose values selects and pills', function () {
    expect(RSMStatus::values())->toBe([1, 2, 3])
        ->and(RSMStatus::PENDING())->toBe(1)
        ->and(RSMStatus::PENDING->pill())->toBe([
            'id' => 1,
            'text' => 'Pending',
            'color' => 'warning',
        ])
        ->and(collect(RSMStatus::renderSelect())->pluck('label')->all())
        ->toBe(['Approved', 'Disapproved', 'Pending']);
});

test('form key exposes the eight form slugs and availability', function () {
    expect(FormKey::values())->toBe([
        'crr',
        'marketing',
        'trade-check',
        'giveaway',
        'repair',
        'crr-signage',
        'trade-request',
        'training',
    ]);

    expect(FormKey::CRR->label())->toBe('CRR')
        ->and(FormKey::CRR->available())->toBeTrue()
        ->and(FormKey::MARKETING->available())->toBeTrue()
        ->and(FormKey::TRADE_CHECK->available())->toBeTrue()
        ->and(FormKey::GIVEAWAY->available())->toBeTrue();

    foreach (FormKey::cases() as $form) {
        expect($form->label())->not->toBe('')
            ->and($form->icon())->toStartWith('heroicon');
    }
});
