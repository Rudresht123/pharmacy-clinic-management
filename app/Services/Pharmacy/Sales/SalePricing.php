<?php

namespace App\Services\Pharmacy\Sales;

use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySetting;

/**
 * The money on a bill.
 *
 * Two rules decide everything here, and both are the organisation's setting
 * rather than this class's opinion:
 *
 *   - which price is charged: the MRP printed on the pack, or the store's
 *     own selling price for that batch;
 *   - whether that price already includes GST. In India it does — MRP is a
 *     tax-inclusive ceiling — so tax is split OUT of the price rather than
 *     added on top. A store billing tax-exclusive adds it instead.
 *
 * Every figure is rounded to the paisa as it is worked out, so the line
 * totals always add up to the bill total. Nothing here reads the database.
 */
class SalePricing
{
    /**
     * One line, priced.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function line(array $input, MedicineBatch $batch, Medicine $medicine, PharmacySetting $settings): array
    {
        $quantity = (int) $input['quantity'];

        $unitPrice = $this->unitPrice($input, $batch, $settings);

        $gross = round($unitPrice * $quantity, 2);

        $discountPercent = round((float) ($input['discount_percent'] ?? 0), 2);
        $discount = round($gross * $discountPercent / 100, 2);

        $net = round($gross - $discount, 2);

        $rate = (float) $medicine->tax_rate;

        if ($settings->prices_include_tax) {
            // ₹112 at 12% is ₹100 of goods and ₹12 of tax.
            $taxable = $rate > 0 ? round($net / (1 + $rate / 100), 2) : $net;
            $tax = round($net - $taxable, 2);
        } else {
            $taxable = $net;
            $tax = round($net * $rate / 100, 2);
        }

        return [
            'medicine_id' => $medicine->id,
            'medicine_batch_id' => $batch->id,
            'prescription_item_id' => $input['prescription_item_id'] ?? null,

            // What the bill says, kept as it was said.
            'item_name_snapshot' => mb_substr($medicine->displayName(), 0, 191),
            'hsn_code_snapshot' => $medicine->hsn_code,
            'batch_number_snapshot' => $batch->batch_number,
            'expiry_date_snapshot' => $batch->expiry_date->toDateString(),

            'quantity' => $quantity,
            'mrp' => round((float) $batch->mrp, 2),
            'unit_price' => $unitPrice,

            'discount_percent' => $discountPercent,
            'discount_amount' => $discount,

            'tax_rate' => $rate,
            'taxable_amount' => $taxable,
            'tax_amount' => $tax,

            'line_total' => round($taxable + $tax, 2),

            // What the stock cost, so profit is what it was on the day.
            'unit_cost' => round((float) $batch->purchase_price, 2),
        ];
    }

    /**
     * The bill's totals, from its priced lines.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, float>
     */
    public function totals(array $lines, PharmacySetting $settings): array
    {
        $subtotal = round(array_sum(array_column($lines, 'taxable_amount')), 2);
        $tax = round(array_sum(array_column($lines, 'tax_amount')), 2);
        $discount = round(array_sum(array_column($lines, 'discount_amount')), 2);

        $payable = round($subtotal + $tax, 2);

        // To the nearest rupee, with the difference shown on the bill.
        $rounded = $settings->round_off_enabled ? round($payable) : $payable;

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'round_off' => round($rounded - $payable, 2),
            'total_amount' => round($rounded, 2),
        ];
    }

    /**
     * What one unit is charged at.
     *
     * A cashier may charge less than the batch's price — a rounded-down
     * total, a regular customer — but never more: MRP is a ceiling in law,
     * and the store's own price is a ceiling by policy.
     *
     * @param  array<string, mixed>  $input
     */
    private function unitPrice(array $input, MedicineBatch $batch, PharmacySetting $settings): float
    {
        $basis = $settings->price_basis === PharmacySetting::PRICE_SELLING
            ? (float) $batch->selling_price
            : (float) $batch->mrp;

        $asked = isset($input['unit_price']) ? (float) $input['unit_price'] : $basis;

        return round(min($asked, $basis), 2);
    }
}
