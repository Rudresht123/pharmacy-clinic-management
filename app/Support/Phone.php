<?php

namespace App\Support;

/**
 * A mobile number, reduced to the part a person actually owns.
 *
 * Numbers arrive as "+91 98765 43210", "098765-43210" or "9876543210" and must
 * all mean the same patient. The country code and a trunk zero are dropped and
 * only the ten digits are kept — which is also what Customer::scopeWithPhone
 * compares on, so the two cannot disagree about whether numbers match.
 */
final class Phone
{
    /** An Indian mobile number: ten digits, starting 6–9. */
    public const PATTERN = '/^[6-9]\d{9}$/';

    public static function digits(?string $raw): string
    {
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    /** "98XXX XX210" — enough for someone to recognise their own number. */
    public static function masked(string $digits): string
    {
        return strlen($digits) === 10
            ? substr($digits, 0, 2).'XXX XX'.substr($digits, 7)
            : $digits;
    }
}
