<?php

namespace App\Support;

class Money
{
    /**
     * Convert a pounds amount typed by a person, like "12.5", into pence.
     */
    public static function toPence(string|int|float $pounds): int
    {
        return (int) round((float) $pounds * 100);
    }

    /**
     * Format pence for display, like "£1,234.50".
     */
    public static function format(int $pence, string $currency = 'GBP'): string
    {
        $symbol = $currency === 'GBP' ? '£' : $currency.' ';

        return ($pence < 0 ? '-' : '').$symbol.number_format(abs($pence) / 100, 2);
    }
}
