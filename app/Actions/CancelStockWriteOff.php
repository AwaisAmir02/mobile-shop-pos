<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockWriteOff;
use Illuminate\Support\Facades\DB;

class CancelStockWriteOff
{
    /**
     * Puts each quantity back in the exact batch it came from, like cancelling a sale.
     *
     * @return array<int, string> product names that could not be restocked because the product no longer exists
     */
    public static function handle(StockWriteOff $writeOff, ?int $userId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($writeOff, $userId, $reason) {
            $writeOff = StockWriteOff::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($writeOff->id)->lockForUpdate()->firstOrFail();

            if ($writeOff->isCancelled()) {
                throw new \RuntimeException('This write-off has already been cancelled.');
            }

            $writeOff->load('items');

            $snapshot = [
                'product_id' => $writeOff->product_id,
                'product_name' => $writeOff->product_name,
                'quantity' => $writeOff->quantity,
                'unit' => $writeOff->unit->value,
                'reason' => $writeOff->reason->value,
                'note' => $writeOff->note,
                'written_off_on' => $writeOff->written_off_on->toDateString(),
                'total_cost' => $writeOff->total_cost !== null ? (float) $writeOff->total_cost : null,
                'items' => $writeOff->items->map(fn ($item) => [
                    'stock_in_id' => $item->stock_in_id,
                    'quantity' => $item->quantity,
                    'cost_price' => $item->cost_price !== null ? (float) $item->cost_price : null,
                ])->all(),
            ];

            $skipped = RestoreStockFifo::handle($writeOff->items);

            $writeOff->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => filled($reason) ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $writeOff->shop_id,
                'revisionable_type' => StockWriteOff::class,
                'revisionable_id' => $writeOff->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return array_values(array_unique($skipped));
        });
    }
}
