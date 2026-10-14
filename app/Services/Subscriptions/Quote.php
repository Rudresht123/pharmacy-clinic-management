<?php

namespace App\Services\Subscriptions;

/**
 * What something costs, worked out by the server. Amounts are paise.
 *
 * Immutable: an invoice copies a quote's lines and totals as they are, so the
 * money on the invoice is exactly the money that was quoted.
 */
final class Quote
{
    /**
     * @param  list<array{type: string, ref: string, description: string, quantity: int, unit_price: int, amount: int}>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $currency,
        public readonly int $subtotal,
        public readonly string $taxMode,
        public readonly int $taxRateBp,
        public readonly string $taxLabel,
        public readonly int $tax,
        public readonly int $total,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'lines' => $this->lines,
            'subtotal' => $this->subtotal,
            'tax_mode' => $this->taxMode,
            'tax_rate_bp' => $this->taxRateBp,
            'tax_label' => $this->taxLabel,
            'tax' => $this->tax,
            'total' => $this->total,
        ];
    }
}
