<?php

namespace App\Support\Platform;

use App\Models\Platform\Organization;
use Illuminate\Support\Str;

/**
 * The code an organization's own staff type to reach it.
 *
 * Six characters, A–Z and 0–9. Short because it is typed by hand on a phone
 * keyboard, at the top of a login form, by somebody who was given it on a
 * printed sheet; every extra character is another chance to get it wrong.
 *
 * It used to be free text — `string, max:191`, nothing else — which is how one
 * organization ended up with the code "Testing Vendor", spaces and all. That is
 * fine in a database column and useless as something a person types.
 *
 * Case is not significant. It is stored upper case and matched case-insensitively,
 * so a phone keyboard that capitalises on its own cannot lock anybody out.
 */
final class OrganizationCode
{
    private function __construct() {}

    private const LETTERS = 3;

    private const DIGITS = 3;

    /**
     * Three letters, then three digits — NMG001, CLN042.
     *
     * The shape is what makes it readable. Splitting it this way means the
     * position of a character says what it is: a round mark in the first three
     * is the letter O, in the last three it is a zero, and the reader never has
     * to guess. A free mix of letters and digits has no such rule, which is why
     * codes like that are usually printed without I, O, 0 or 1 at all.
     *
     * 26^3 x 10^3 is a little over seventeen million codes.
     */
    public const PATTERN = '/^[A-Za-z]{3}[0-9]{3}$/';

    public static function normalise(string $value): string
    {
        return Str::upper(trim($value));
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, trim($value));
    }

    /**
     * A code no live organization is using.
     *
     * Random rather than sequential. A running number tells anyone holding one
     * code roughly how many organizations exist and what the neighbouring codes
     * are — and the lookup endpoint will confirm each guess.
     */
    public static function generate(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::LETTERS; $i++) {
                $code .= chr(random_int(ord('A'), ord('Z')));
            }

            for ($i = 0; $i < self::DIGITS; $i++) {
                $code .= (string) random_int(0, 9);
            }

            $taken = Organization::withTrashed()
                ->whereRaw('UPPER(organization_code) = ?', [$code])
                ->exists();
        } while ($taken);

        return $code;
    }
}
