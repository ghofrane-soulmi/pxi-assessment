<?php

namespace App\Support;

final class Money
{
    private const NANOS_PER_DOLLAR = 1_000_000_000;

    /**
     * Exact dollar string for display: 211_350_000 → "$0.21135", 0 → "$0.00".
     * No rounding, so what users see matches what we recorded (and Pixelforge's
     * 9-decimal numbers) digit for digit. Trailing zeros are trimmed to 2 decimals.
     */
    public static function format(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);

        $dollars = intdiv($abs, self::NANOS_PER_DOLLAR);
        $fraction = rtrim(str_pad((string) ($abs % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT), '0');

        return $sign.'$'.$dollars.'.'.str_pad($fraction, 2, '0');
    }
}
