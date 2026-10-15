<?php

namespace App\Support;

final class ExpenseAmountLimit
{
    public static function exceeds(mixed $amount, string $limit): bool
    {
        [$amountWhole, $amountFraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
        [$limitWhole, $limitFraction] = array_pad(explode('.', $limit, 2), 2, '');

        $amountWhole = ltrim($amountWhole, '0') ?: '0';
        $limitWhole = ltrim($limitWhole, '0') ?: '0';

        if (strlen($amountWhole) !== strlen($limitWhole)) {
            return strlen($amountWhole) > strlen($limitWhole);
        }

        $wholeComparison = strcmp($amountWhole, $limitWhole);
        if ($wholeComparison !== 0) {
            return $wholeComparison > 0;
        }

        return strcmp(str_pad($amountFraction, 2, '0'), str_pad($limitFraction, 2, '0')) > 0;
    }
}
