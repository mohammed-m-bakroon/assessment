<?php

namespace App\Billing;

/**
 * All money in this app is stored as integer nano-dollars (1 USD = 1,000,000,000).
 *
 * Pixelforge reports costs as exact decimal strings with 9 decimal places, so
 * nano-dollars represent every provider cost exactly, with no floats anywhere.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /** "0.046125000" -> 46125000 */
    public static function toNanos(string $usd): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', trim($usd), $m)) {
            throw new \InvalidArgumentException("Invalid USD amount [{$usd}].");
        }

        return (int) $m[1] * self::NANOS_PER_DOLLAR + (int) str_pad($m[2] ?? '', 9, '0');
    }

    /** 46125000 -> "0.046125000" (same format as Pixelforge's cost_usd) */
    public static function format(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $nanos = abs($nanos);

        return sprintf('%s%d.%09d', $sign, intdiv($nanos, self::NANOS_PER_DOLLAR), $nanos % self::NANOS_PER_DOLLAR);
    }
}
