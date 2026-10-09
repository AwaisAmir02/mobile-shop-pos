<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateStockIn
{
    /**
     * @param  array{quantity: int, vendor_id: ?int, stock_date: string, note: ?string, cost_price: ?float, selling_price: ?float}  $fields
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
                'vendor_id' => $entry->vendor_id,
                'cost_price' => $entry->cost_price !== null ? (float) $entry->cost_price : null,
                'selling_price' => $entry->selling_price !== null ? (float) $entry->selling_price : null,
                'total_cost' => $entry->total_cost !== null ? (float) $entry->total_cost : null,
                'stock_date' => $entry->stock_date->toDateString(),
                'note' => $entry->note,
            ];

            $delta = $fields['quantity'] - $entry->quantity;
            $newQuantityRemaining = $entry->quantity_remaining;

            if ($entry->product_id === null) {
                $newQuantityRemaining = $fields['quantity'];
            } elseif ($delta !== 0) {
                $product = Product::whereKey($entry->product_id)->lockForUpdate()->first();

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'quantity' => 'The product on this stock-in entry no longer exists, so its quantity cannot be changed.',
                    ]);
                }

                if ($delta > 0) {
                    $product->increment('stock_quantity', $delta);
                    $newQuantityRemaining += $delta;
                } else {
                    $decreaseBy = abs($delta);

                    if ($entry->quantity_remaining < $decreaseBy) {
                        $consumed = $entry->quantity - $entry->quantity_remaining;

                        throw ValidationException::withMessages([
                            'quantity' => "Cannot reduce quantity: {$consumed} units from this entry have already been sold, leaving only {$entry->quantity_remaining} available to remove. Adjust stock manually first.",
                        ]);
                    }

                    $product->decrement('stock_quantity', $decreaseBy);
                    $newQuantityRemaining -= $decreaseBy;
                }
            }

            $newTotalCost = $fields['cost_price'] !== null ? $fields['cost_price'] * $fields['quantity'] : null;
            $newTotalCostFloat = $newTotalCost ?? 0.0;

            if ($newTotalCostFloat < (float) $entry->amount_paid) {
                throw ValidationException::withMessages([
                    'quantity' => 'New total cost (Rs '.number_format($newTotalCostFloat, 2).') is less than Rs '.number_format((float) $entry->amount_paid, 2).' already paid against this stock-in entry.',
                ]);
            }

            $entry->update([
                'quantity' => $fields['quantity'],
                'quantity_remaining' => $newQuantityRemaining,
                'vendor_id' => $fields['vendor_id'],
                'cost_price' => $fields['cost_price'],
                'selling_price' => $fields['selling_price'],
                'total_cost' => $newTotalCost,
                'stock_date' => $fields['stock_date'],
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
