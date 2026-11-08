<?php

declare(strict_types=1);

namespace Demo\Camt;

/** Amounts are whole cents (integers): money is never added up as a float. */
final class Money
{
    /** @param string $amount a decimal such as "12.30" (the schema allows exactly two decimals) */
    public static function cents(string $amount): int
    {
        if (1 !== preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $amount, $match)) {
            throw new \InvalidArgumentException('Not an amount: ' . $amount);
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
    }

    public static function format(int $cents): string
    {
        return \sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
