<?php

namespace App\Support;

use App\Models\VendorInvoiceLine;

/**
 * The three-way match: ordered, received, billed. The one place an invoice is
 * judged, run when it's saved and again inside the approval transaction, because
 * costs can change on Stock In History in between. Worked out in whole paise.
 */
class VendorInvoiceMatch
{
    /** Rs 1 per invoice, on the sum of each line's absolute difference. */
    public const TOLERANCE_PAISE = 100;

    /**
     * @param  iterable<VendorInvoiceLine>  $lines  with stockIn and grnLine.poLine loaded
     * @param  iterable<mixed>  $charges  each with an amount
     * @return array{
     *     lines: array<int, array{line: VendorInvoiceLine, quantity_differs: bool, base: float, received: ?float, difference: float, new_total: float, changes: bool, above_order: float}>,
     *     reasons: array<int, string>,
     *     within_tolerance: bool,
     *     passes: bool,
     *     difference_total: float,
     *     can_allocate_charges: bool,
     *     fingerprint: string,
     * }
     */
    public static function evaluate(iterable $lines, iterable $charges): array
    {
        $lines = collect($lines)->values();
        $chargePaise = collect($charges)->map(fn ($charge) => self::paise(is_array($charge) ? ($charge['amount'] ?? 0) : $charge->amount));

        $rows = [];
        $reasons = [];
        $differencePaise = 0;
        $abovePaise = 0;
        $hasQuantityDifference = false;
        $hasMissingCost = false;

        foreach ($lines as $line) {
            $row = $line->stockIn;
            $billedQuantity = Quantity::normalize($line->billed_quantity ?? 0);
            $quantityDiffers = Quantity::compare($billedQuantity, $row->quantity) !== 0;

            // Never owed for what didn't arrive: a different billed quantity only sets the price.
            $base = $quantityDiffers && (float) $billedQuantity > 0
                ? self::paise((float) $line->billed_amount * (float) $row->quantity / (float) $billedQuantity)
                : self::paise($line->billed_amount);

            $received = $line->receivedTotal();
            $difference = abs($base - self::paise($received ?? 0));
            $differencePaise += $difference;

            if ($quantityDiffers) {
                $hasQuantityDifference = true;
                $reasons[] = $row->product_name.': billed '.$row->unit->amount($billedQuantity).', received '.$row->unit->amount($row->quantity).'.';
            }

            if ($received === null) {
                $hasMissingCost = true;
                $reasons[] = $row->product_name.' was received with no cost, so this bill sets what you owe for it.';
            }

            $expected = $line->grnLine?->poLine?->expected_cost;
            $above = $expected !== null && (float) $billedQuantity > 0
                ? max(0, self::paise($line->billed_amount) - self::paise(Quantity::money($expected, $billedQuantity)))
                : 0;
            $abovePaise += $above;

            $rows[] = ['line' => $line, 'quantity_differs' => $quantityDiffers, 'base' => $base, 'received' => $received, 'difference' => $difference, 'above_order' => $above];
        }

        $differencePaise += $chargePaise->sum(fn (int $amount) => abs($amount));

        if ($differencePaise > self::TOLERANCE_PAISE) {
            $reasons[] = 'Differences add up to Rs '.number_format($differencePaise / 100, 2).', more than the Rs 1.00 allowed.';
        }

        if ($abovePaise > self::TOLERANCE_PAISE) {
            $reasons[] = 'Billed Rs '.number_format($abovePaise / 100, 2).' above the purchase order price.';
        }

        $withinTolerance = ! $hasQuantityDifference && ! $hasMissingCost && $differencePaise <= self::TOLERANCE_PAISE;
        $totals = $withinTolerance ? null : self::allocate(array_column($rows, 'base'), $chargePaise->sum());
        $canAllocate = $withinTolerance || $totals !== null;

        if (! $canAllocate) {
            $reasons[] = 'Delivery, round-off and discounts can\'t be shared across lines with no value.';
        }

        $fingerprint = [];

        foreach ($rows as $index => &$entry) {
            // Within tolerance nothing moves: what's owed stays at the received figures.
            $current = self::paise($entry['line']->stockIn->total_cost ?? 0);
            $newTotal = $totals[$index] ?? $current;
            $hadNoCost = ! $withinTolerance && $entry['line']->stockIn->total_cost === null;

            $entry['changes'] = $newTotal !== $current || $hadNoCost;
            $entry['new_total'] = $newTotal / 100;
            $entry['base'] /= 100;
            $entry['difference'] /= 100;
            $entry['above_order'] /= 100;
            $fingerprint[] = $entry['line']->stock_in_id.':'.$current;
        }
        unset($entry);

        return [
            'lines' => $rows,
            'reasons' => array_values(array_unique($reasons)),
            'within_tolerance' => $withinTolerance,
            'passes' => $reasons === [],
            'difference_total' => $differencePaise / 100,
            'can_allocate_charges' => $canAllocate,
            'fingerprint' => md5(implode('|', $fingerprint)),
        ];
    }

    public static function paise(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Shares whole-invoice charges by line value. Each line takes its share of
     * what's left, so the rounding lands on the last line and nothing is lost.
     *
     * @param  array<int, int>  $bases
     * @return array<int, int>|null null when there's nothing to share them across
     */
    protected static function allocate(array $bases, int $charges): ?array
    {
        $remainingValue = array_sum($bases);

        if ($charges === 0) {
            return $bases;
        }

        if ($remainingValue <= 0) {
            return null;
        }

        $remainingCharges = $charges;
        $totals = [];

        foreach ($bases as $index => $base) {
            $share = $remainingValue > 0 ? (int) round($remainingCharges * $base / $remainingValue) : 0;
            $totals[$index] = $base + $share;
            $remainingCharges -= $share;
            $remainingValue -= $base;
        }

        return $totals;
    }
}
