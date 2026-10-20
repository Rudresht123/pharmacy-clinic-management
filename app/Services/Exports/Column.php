<?php

namespace App\Services\Exports;

/**
 * One column of an export — what it is called, and what kind of value it
 * holds, which decides how each file writes it: money is a number in Excel,
 * "1,250.00" in a PDF and "1250.00" in a CSV; text is text everywhere.
 *
 * `width` is the column's share of a PDF row, nothing else. The shares of an
 * export need not add up to anything — PdfTable divides the page by them.
 */
final class Column
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const DATE = 'date';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TEXT,
        public readonly float $width = 8,
    ) {}

    public function isNumeric(): bool
    {
        return $this->type === self::NUMBER || $this->type === self::MONEY;
    }

    /** @return array{key: string, label: string, type: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type];
    }
}
