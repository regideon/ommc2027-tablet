<?php

namespace App\Support;

final class ExpensePaymentType
{
    public const PETTY_CASH = 'Petty Cash';

    public const PETTY_CASH_VOUCHER = 'Petty Cash Voucher (PCV)';

    /** @return list<string> */
    public static function newEntryValues(): array
    {
        return [
            'Cash',
            'SBC Credit Card',
            'Other Payment Type',
            'Fleet Card',
            self::PETTY_CASH_VOUCHER,
            'Revolving Fund',
            'Cash Advance',
        ];
    }

    public static function forDisplay(?string $value): ?string
    {
        return self::normalizeLegacyValue($value);
    }

    public static function forSync(?string $value): ?string
    {
        return self::normalizeLegacyValue($value);
    }

    private static function normalizeLegacyValue(?string $value): ?string
    {
        return $value === self::PETTY_CASH ? self::PETTY_CASH_VOUCHER : $value;
    }
}
