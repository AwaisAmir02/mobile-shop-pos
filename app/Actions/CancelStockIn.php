<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelStockIn
{
    /**
     * @return array{entry: StockIn, reversed: bool}
     */
    public static function handle(StockIn $entry, ?int $userId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($entry, $userId, $reason) {
            $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->isCancelled()) {
                throw new \RuntimeException('This stock-in entry has already been cancelled.');
            }

            $snapshot = [
                'product_id' => $entry->product_id,
                'product_name' => $entry->product_name,
                'vendor_id' => $entry->vendor_id,
                'quantity' => $entry->quantity,
                'quantity_remaining' => $entry->quantity_remaining,
                'unit' => $entry->unit->value,
                'cost_price' => $entry->cost_price !== null ? (float) $entry->cost_price : null,
                'selling_price' => $entry->selling_price !== null ? (float) $entry->selling_price : null,
                'total_cost' => $entry->total_cost !== null ? (float) $entry->total_cost : null,
                'stock_date' => $entry->stock_date->toDateString(),
                'expiry_date' => $entry->expiry_date?->toDateString(),
                'note' => $entry->note,
            ];

            if (Quantity::compare($entry->quantity_remaining, $entry->quantity) < 0) {
                $consumed = Quantity::plain(Quantity::subtract($entry->quantity, $entry->quantity_remaining));
                $added = $entry->unit->allowsDecimals() ? $entry->unit->amount($entry->quantity) : Quantity::plain($entry->quantity).' units';

                throw ValidationException::withMessages([
                    'quantity' => "Cannot cancel: {$consumed} of the {$added} added by this entry have already been {$entry->consumedLabel()}. Adjust stock manually first.",
                ]);
            }

            $reversed = false;

            if ($entry->product_id !== null) {
                $product = Product::whereKey($entry->product_id)->lockForUpdate()->first();

                if ($product !== null) {
                    $product->decrement('stock_quantity', $entry->quantity);
                    $reversed = true;
                }
            }

            $entry->update([
                'quantity_remaining' => 0,
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $entry->shop_id,
                'revisionable_type' => StockIn::class,
                'revisionable_id' => $entry->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return ['entry' => $entry, 'reversed' => $reversed];
        });
    }
}
