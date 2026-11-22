<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\SaleRevisionAction;
use App\Enums\SaleStatus;
use App\Models\Buyback;
use App\Models\Sale;
use App\Models\SaleRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelSale
{
    /**
     * @return array{restocked: array<int, string>, skipped: array<int, string>}
     */
    /**
     * @param  bool  $fromExchange  only when the exchange's own return is being cancelled, which undoes both
     */
    public static function handle(Sale $sale, ?int $userId, ?string $reason = null, bool $fromExchange = false): array
    {
        return DB::transaction(function () use ($sale, $userId, $reason, $fromExchange) {
            $sale = Sale::withoutGlobalScopes()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw new \RuntimeException('This sale has already been cancelled.');
            }

            $sale->loadMissing(['items', 'payments']);

            SaleReturns::assertNoReturns($sale, 'cancelReason', 'cancelled');

            // Paid for by an exchange: undone with that return, never on its own.
            if (! $fromExchange && ($exchange = SaleReturns::exchangeFor($sale)) !== null) {
                throw ValidationException::withMessages(['cancelReason' => 'This sale was paid for by exchange '.$exchange->number().' on '.$exchange->sale?->invoiceNumber().'. Cancel that return to undo the whole exchange.']);
            }

            // Store credit it was paid with goes back to the customer.
            StoreCredit::cancelSpendsFor($sale, $userId);

            $snapshot = [
                'subtotal' => (float) $sale->subtotal,
                'discount_amount' => (float) $sale->discount_amount,
                'total' => (float) $sale->total,
                'customer_id' => $sale->customer_id,
                'items' => $sale->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'stock_in_id' => $item->stock_in_id,
                    'product_name' => $item->product_name,
                    'product_type' => $item->product_type,
                    'unit_price' => (float) $item->unit_price,
                    'list_price' => $item->list_price !== null ? (float) $item->list_price : null,
                    'cost_price' => $item->cost_price !== null ? (float) $item->cost_price : null,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit->value,
                    'discount_amount' => (float) $item->discount_amount,
                    'line_total' => (float) $item->line_total,
                ])->all(),
            ];

            // A traded-in phone goes back to the customer with the sale. Once it has been
            // sold on it can't, so the sale stays: nothing is cancelled half-way.
            foreach (Buyback::withoutGlobalScopes()->where('sale_id', $sale->id)->where('status', RecordStatus::Active->value)->get() as $tradeIn) {
                try {
                    Buybacks::cancel($tradeIn, $userId, 'Sale '.$sale->invoiceNumber().' cancelled: phone handed back', fromSale: true);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['cancelReason' => 'The phone traded in on this sale ('.$tradeIn->phoneName().') has already been sold on, so this sale can\'t be cancelled. Cancel that later sale first, or leave this one as it is.']);
                }
            }

            $skipped = RestoreStockFifo::handle($sale->items);
            $skippedNames = array_unique($skipped);

            $restocked = $sale->items
                ->filter(fn ($item) => $item->product_id !== null && ! in_array($item->product_name, $skippedNames, true))
                ->pluck('product_name')
                ->unique()
                ->values()
                ->all();

            $sale->update([
                'status' => SaleStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            SaleRevision::create([
                'sale_id' => $sale->id,
                'action' => SaleRevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return ['restocked' => $restocked, 'skipped' => $skippedNames];
        });
    }
}
