<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\StockInPaymentSource;
use App\Models\Product;
use App\Models\StockIn;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one way stock arrives: each line becomes one batch (a stock_ins row) and
 * adds to its product's stock. What's paid at the time is shared across the
 * lines by their cost. These rows are the single source of FIFO/FEFO order,
 * batch cost and what's owed to the vendor.
 */
class CreateStockIn
{
    /**
     * @param  array<int, array{product_id: int, quantity: int|float|string, cost_price: ?float, selling_price: float, expiry_date?: ?string, grn_line_id?: int}>  $lines
     * @return Collection<int, StockIn> one per line, in order
     */
    public static function handle(array $lines, ?int $vendorId, string $paymentStatus, ?float $amountPaid, string $stockDate, ?string $note, ?int $userId, ?int $branchId = null, ?string $paymentMethod = null, ?int $shopAccountId = null): Collection
    {
        $lines = array_values($lines);
        $count = count($lines);

        $grandTotal = self::grandTotalCost($lines);

        $headerAmountPaid = match ($paymentStatus) {
            'paid' => $grandTotal,
            'partial' => $amountPaid ?? 0.0,
            default => 0.0,
        };

        return DB::transaction(function () use ($lines, $count, $grandTotal, $headerAmountPaid, $vendorId, $stockDate, $note, $userId, $branchId, $paymentMethod, $shopAccountId) {
            $amountAllocated = 0.0;
            $entries = collect();

            foreach ($lines as $index => $line) {
                $product = Product::whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
                // A screen's cart is client-held state, so the product's own unit decides here.
                $product->unit->assertQuantity($line['quantity'], 'quantity', $product->name);
                $product->increment('stock_quantity', Quantity::normalize($line['quantity']));

                $lineTotalCost = self::lineTotalCost($line);

                $isLast = $index === $count - 1;
                $lineAmountPaid = $grandTotal > 0
                    ? ($isLast ? round($headerAmountPaid - $amountAllocated, 2) : round($headerAmountPaid * ($lineTotalCost / $grandTotal), 2))
                    : 0.0;
                $amountAllocated += $lineAmountPaid;

                $linePaymentStatus = match (true) {
                    $lineTotalCost > 0 && $lineAmountPaid >= $lineTotalCost => PaymentStatus::Paid,
                    $lineAmountPaid > 0 => PaymentStatus::Partial,
                    default => PaymentStatus::Unpaid,
                };

                $entry = StockIn::create([
                    'product_id' => $product->id,
                    'vendor_id' => $vendorId,
                    'product_name' => $product->name,
                    'user_id' => $userId,
                    'quantity' => Quantity::normalize($line['quantity']),
                    'unit' => $product->unit,
                    'cost_price' => $line['cost_price'],
                    'selling_price' => $line['selling_price'],
                    'quantity_remaining' => Quantity::normalize($line['quantity']),
                    'total_cost' => $line['cost_price'] !== null ? $lineTotalCost : null,
                    'payment_status' => $linePaymentStatus,
                    'amount_paid' => $lineAmountPaid,
                    'stock_date' => $stockDate,
                    'expiry_date' => $product->sellsByExpiry() ? ($line['expiry_date'] ?? null) : null,
                    'note' => $note !== null && $note !== '' ? $note : null,
                    'grn_line_id' => $line['grn_line_id'] ?? null,
                    // Otherwise the branch the person is working in, as always.
                    ...($branchId !== null ? ['branch_id' => $branchId] : []),
                ]);

                // Dated history of this line's share of the purchase-time payment.
                if (round($lineAmountPaid, 2) != 0.0) {
                    $entry->payments()->create([
                        'shop_id' => $entry->shop_id,
                        'user_id' => $userId,
                        'amount' => $lineAmountPaid,
                        'payment_date' => $stockDate,
                        'source' => StockInPaymentSource::Initial,
                        'payment_method' => $paymentMethod,
                        'shop_account_id' => $shopAccountId,
                    ]);
                }

                $entries->push($entry);
            }

            return $entries;
        });
    }

    /** @param array{quantity: mixed, cost_price: ?float} $line */
    public static function lineTotalCost(array $line): float
    {
        return $line['cost_price'] !== null ? Quantity::money($line['cost_price'], $line['quantity']) : 0.0;
    }

    public static function grandTotalCost(array $lines): float
    {
        return round(array_sum(array_map(fn (array $line) => self::lineTotalCost($line), $lines)), 2);
    }
}
