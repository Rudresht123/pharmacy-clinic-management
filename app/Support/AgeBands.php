<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * The age brackets the application reasons in.
 *
 * One list, because the chart and the filter must agree: if the chart says
 * eleven people are 13–25 and the filter for that band returns nine, one of
 * them is lying and there is no way to tell which. Both read from here.
 *
 * Ages are completed years — AGE() returns an interval whose year part is
 * what everybody means by "how old are you".
 */
class AgeBands
{
    /** The value for people with no date of birth on file. */
    public const UNKNOWN = 'unknown';

    /** Ages are compared against this, never a re-derived expression. */
    public const AGE = 'EXTRACT(YEAR FROM AGE(date_of_birth))';

    /**
     * Bounds are inclusive on both ends; a null `upto` means open-ended.
     *
     * @return list<array{key: string, label: string, from: int, upto: int|null}>
     */
    public static function all(): array
    {
        return [
            ['key' => '0-12', 'label' => '0–12', 'from' => 0, 'upto' => 12],
            ['key' => '13-25', 'label' => '13–25', 'from' => 13, 'upto' => 25],
            ['key' => '26-40', 'label' => '26–40', 'from' => 26, 'upto' => 40],
            ['key' => '41-60', 'label' => '41–60', 'from' => 41, 'upto' => 60],
            ['key' => '61+', 'label' => '61+', 'from' => 61, 'upto' => null],
        ];
    }

    /**
     * A CASE that labels each row with its band.
     *
     * Every value interpolated is an integer from the list above — nothing
     * from a request reaches this string.
     */
    public static function caseExpression(): string
    {
        $cases = [];
        $last = null;

        foreach (self::all() as $band) {
            if ($band['upto'] === null) {
                $last = $band['key'];

                continue;
            }

            $cases[] = 'WHEN '.self::AGE." <= {$band['upto']} THEN '{$band['key']}'";
        }

        return 'CASE '.implode(' ', $cases)." ELSE '{$last}' END";
    }

    /**
     * Narrows a query to one band.
     *
     * An unrecognised key is ignored rather than returning nothing: a stale
     * bookmark should show the unfiltered list, not an empty one that reads
     * as "you have no patients".
     */
    public static function apply(Builder $query, string $band): Builder
    {
        if ($band === self::UNKNOWN) {
            return $query->whereNull('date_of_birth');
        }

        $match = collect(self::all())->firstWhere('key', $band);

        if (! $match) {
            return $query;
        }

        $query->whereNotNull('date_of_birth')
            ->whereRaw(self::AGE.' >= ?', [$match['from']]);

        if ($match['upto'] !== null) {
            $query->whereRaw(self::AGE.' <= ?', [$match['upto']]);
        }

        return $query;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return [...array_column(self::all(), 'key'), self::UNKNOWN];
    }
}
