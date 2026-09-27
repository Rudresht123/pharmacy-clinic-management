<?php

namespace App\Services\Billing;

use App\Models\Tenant\BillingSetting;

/**
 * The arithmetic behind an invoice line and its totals.
 *
 * Kept apart from BillingService so the same numbers a saved invoice reads
 * are what a preview (draw-but-don't-save, for a UI showing the running
 * total) reads: one function, called by both.
 *
 * Percentages compose. A line with a 10% discount priced tax-exclusive at
 * ₹100 × 2 comes to 200 → −20 → 180 taxable → +18% tax → 212.40. The same
 * line priced tax-inclusive treats 100 as the after-tax figure and works
 * back — because "the display price is ₹100 and 18% is inside it" is what a
 * shop advertising an all-in price actually means.
 */
class InvoicePricing
{
    /**
     * Compute one line's discount, tax and total.
     *
     * The result is what an invoice_item row writes into its money columns —
     * `line_total` is what the invoice adds up.
     *
     * @param  array{quantity: float|int, unit_price: float|int, discount_percent?: float, discount_amount?: float, tax_percent?: float}  $input
     * @return array{
     *     quantity: float,
     *     unit_price: float,
     *     discount_percent: float,
     *     discount_amount: float,
     *     tax_percent: float,
     *     tax_amount: float,
     *     line_total: float,
     * }
     */
    public function line(array $input, BillingSetting $settings): array
    {
        $quantity = round((float) ($input['quantity'] ?? 1), 2);
        $unitPrice = round((float) ($input['unit_price'] ?? 0), 2);
        $discountPercent = round((float) ($input['discount_percent'] ?? 0), 2);
        $discountAmount = round((float) ($input['discount_amount'] ?? 0), 2);
        $taxPercent = round((float) ($input['tax_percent'] ?? 0), 2);

        $gross = round($quantity * $unitPrice, 2);

        // Percentage discount wins if both are given, so a UI passing one from
        // a slider does not have to zero the other out.
        if ($discountPercent > 0) {
            $discountAmount = round($gross * $discountPercent / 100, 2);
        }

        $afterDiscount = round(max(0.0, $gross - $discountAmount), 2);

        if ($settings->prices_include_tax) {
            /*
             * The unit price already carries the tax. Back it out so the row
             * still records what the tax component was.
             */
            $taxable = round($afterDiscount / (1 + ($taxPercent / 100)), 2);
            $tax = round($afterDiscount - $taxable, 2);
            $lineTotal = $afterDiscount;
        } else {
            $tax = round($afterDiscount * $taxPercent / 100, 2);
            $lineTotal = round($afterDiscount + $tax, 2);
        }

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $tax,
            'line_total' => $lineTotal,
        ];
    }

    /**
     * Roll up a set of computed lines into the invoice's own money columns.
     *
     * @param  list<array<string, mixed>>  $lines  each one already run through line()
     * @return array{subtotal: float, discount_amount: float, tax_amount: float, total_amount: float}
     */
    public function totals(array $lines, BillingSetting $settings): array
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $total = 0.0;

        foreach ($lines as $line) {
            $gross = round((float) $line['quantity'] * (float) $line['unit_price'], 2);

            $subtotal += $gross;
            $discount += (float) $line['discount_amount'];
            $tax += (float) $line['tax_amount'];
            $total += (float) $line['line_total'];
        }

        /*
         * Tax-inclusive: the subtotal on the printed invoice reads pre-tax, so
         * the tax stays reported as a separate figure even though the line
         * totals already contain it. Subtracting it from the subtotal is what
         * gives a bill that adds up.
         */
        if ($settings->prices_include_tax) {
            $subtotal = round($subtotal - $tax, 2);
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'tax_amount' => round($tax, 2),
            'total_amount' => round($total, 2),
        ];
    }
}
