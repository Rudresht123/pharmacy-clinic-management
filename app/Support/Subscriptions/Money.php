<?php

namespace App\Support\Subscriptions;

/**
 * Integer money for the platform's billing.
 *
 * Amounts are paise; per-unit usage rates are ten-thousandths of a rupee
 * (`e4`). No float is ever used to compute an amount — only, at the very end,
 * to format one for a person to read.
 */
final class Money
{
    /** ₹1,49,900.00 style — Indian digit grouping for INR. */
    public static function format(int $paise, string $currency = 'INR'): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);

        $rupees = intdiv($paise, 100);
        $fraction = str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);

        $grouped = $currency === 'INR' ? self::indianGrouping((string) $rupees) : number_format($rupees);
        $symbol = $currency === 'INR' ? '₹' : $currency.' ';

        return ($negative ? '-' : '').$symbol.$grouped.'.'.$fraction;
    }

    /** A rupee amount typed on a screen (e.g. "1499.50") to paise, exactly. */
    public static function toPaise(string|int|float $rupees): int
    {
        $value = trim((string) $rupees);

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException("Not an amount: {$value}");
        }

        [$whole, $fraction] = array_pad(explode('.', $value), 2, '0');
        $fraction = str_pad($fraction, 2, '0');
        $sign = str_starts_with($whole, '-') ? -1 : 1;

        return $sign * (abs((int) $whole) * 100 + (int) $fraction);
    }

    /** e4 units × quantity → paise, rounded half-up once. */
    public static function e4ToPaise(int $e4): int
    {
        // 1 paisa = 100 e4 units.
        return intdiv($e4 + 50, 100);
    }

    /** `amount × bp / 10000`, rounded half-up. */
    public static function percentOf(int $amount, int $basisPoints): int
    {
        return intdiv($amount * $basisPoints + 5000, 10000);
    }

    /**
     * Tax on a subtotal under the given mode.
     *
     * exclusive  tax is added on top:          total = subtotal + tax
     * inclusive  tax is inside the subtotal:   total = subtotal
     * none       no tax line at all
     *
     * @return array{tax: int, total: int, net: int}
     */
    public static function tax(int $subtotal, string $mode, int $rateBp): array
    {
        if ($mode === 'none' || $rateBp <= 0) {
            return ['tax' => 0, 'total' => $subtotal, 'net' => $subtotal];
        }

        if ($mode === 'inclusive') {
            // net = total × 10000 / (10000 + rate), rounded half-up.
            $net = intdiv($subtotal * 10000 * 2 + (10000 + $rateBp), (10000 + $rateBp) * 2);

            return ['tax' => $subtotal - $net, 'total' => $subtotal, 'net' => $net];
        }

        $tax = self::percentOf($subtotal, $rateBp);

        return ['tax' => $tax, 'total' => $subtotal + $tax, 'net' => $subtotal];
    }

    private static function indianGrouping(string $digits): string
    {
        if (strlen($digits) <= 3) {
            return $digits;
        }

        $last3 = substr($digits, -3);
        $rest = substr($digits, 0, -3);

        return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3;
    }
}
