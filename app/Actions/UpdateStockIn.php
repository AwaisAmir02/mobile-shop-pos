<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateStockIn
{
    /**
     * @param  array{quantity: int|float|string, vendor_id: ?int, stock_date: string, note: ?string, cost_price: ?float, selling_price: ?float, expiry_date?: ?string}  $fields
     */
    public static function handle(StockIn $entry, array $fields, ?int $userId): StockIn
    {
        return DB::transaction(function () use ($entry, $fields, $userId) {
            $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->isCancelled()) {
                throw new \RuntimeException('A cancelled stock-in entry cannot be edited.');
            }

            $snapshot = [
                'quantity' => $entry->quantity,
                'quantity_remaining' => $entry->quantity_remaining,
                'unit' => $entry->unit->value,
                'vendor_id' => $entry->vendor_id,
                'cost_price' => $entry->cost_price !== null ? (float) $entry->cost_price : null,
                'selling_price' => $entry->selling_price !== null ? (float) $entry->selling_price : null,
                'total_cost' => $entry->total_cost !== null ? (float) $entry->total_cost : null,
                'stock_date' => $entry->stock_date->toDateString(),
                'expiry_date' => $entry->expiry_date?->toDateString(),
                'note' => $entry->note,
            ];

            $entry->unit->assertQuantity($fields['quantity'], 'quantity', $entry->product_name);
            $newQuantity = Quantity::normalize($fields['quantity']);

            // Whole thousandths, so "no change" really is exactly zero.
            $delta = Quantity::toThousandths($newQuantity) - Quantity::toThousandths($entry->quantity);

            // A PO's received quantity comes from its GRN, so the two can't disagree.
            if ($delta !== 0 && $entry->grn_line_id !== null) {
                throw ValidationException::withMessages(['quantity' => 'This stock came from a goods receipt, so its quantity can\'t be changed here. Cancel the receipt and record it again.']);
            }

            // Its dues stay with the vendor on its Purchase Order.
            if ($entry->grn_line_id !== null && (int) ($fields['vendor_id'] ?? 0) !== (int) $entry->vendor_id) {
                throw ValidationException::withMessages(['editVendorId' => 'This stock came from a goods receipt, so its vendor can\'t be changed here. Cancel the receipt and record it again.']);
            }

            $newQuantityRemaining = $entry->quantity_remaining;

            if ($entry->product_id === null) {
                $newQuantityRemaining = $newQuantity;
            } elseif ($delta !== 0) {
                $product = Product::whereKey($entry->product_id)->lockForUpdate()->first();

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'quantity' => 'The product on this stock-in entry no longer exists, so its quantity cannot be changed.',
                    ]);
                }

                $change = Quantity::fromThousandths(abs($delta));

                if ($delta > 0) {
                    $product->increment('stock_quantity', $change);
                    $newQuantityRemaining = Quantity::add($newQuantityRemaining, $change);
                } else {
                    if (Quantity::compare($entry->quantity_remaining, $change) < 0) {
                        $consumed = Quantity::plain(Quantity::subtract($entry->quantity, $entry->quantity_remaining));
                        $consumedLabel = $entry->unit->allowsDecimals() ? $entry->unit->amount($consumed) : "{$consumed} units";
                        $left = $entry->unit->amount($entry->quantity_remaining);

                        throw ValidationException::withMessages([
                            'quantity' => "Cannot reduce quantity: {$consumedLabel} from this entry have already been {$entry->consumedLabel()}, leaving only {$left} available to remove. Adjust stock manually first.",
                        ]);
                    }

                    $product->decrement('stock_quantity', $change);
                    $newQuantityRemaining = Quantity::subtract($newQuantityRemaining, $change);
                }
            }

            $newTotalCost = $fields['cost_price'] !== null ? Quantity::money($fields['cost_price'], $newQuantity) : null;
            $newTotalCostFloat = $newTotalCost ?? 0.0;

            if ($newTotalCostFloat < (float) $entry->amount_paid) {
                throw ValidationException::withMessages([
                    'quantity' => 'New total cost (Rs '.number_format($newTotalCostFloat, 2).') is less than Rs '.number_format((float) $entry->amount_paid, 2).' already paid against this stock-in entry.',
                ]);
            }

            $entry->update([
                'quantity' => $newQuantity,
                'quantity_remaining' => $newQuantityRemaining,
                'vendor_id' => $fields['vendor_id'],
                'cost_price' => $fields['cost_price'],
                'selling_price' => $fields['selling_price'],
                'total_cost' => $newTotalCost,
                'stock_date' => $fields['stock_date'],
                ...(array_key_exists('expiry_date', $fields) ? ['expiry_date' => $fields['expiry_date']] : []),
                'note' => $fields['note'],
            ]);

            ModuleRevision::create([
                'shop_id' => $entry->shop_id,
                'revisionable_type' => StockIn::class,
                'revisionable_id' => $entry->id,
                'action' => RevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $entry;
        });
    }
}
