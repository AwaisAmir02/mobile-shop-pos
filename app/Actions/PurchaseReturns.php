<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\StockIn;
use App\Models\VendorCredit;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods sent back to the vendor they came from (W10): expired, damaged,
 * wrong or excess stock.
 *
 * Each line comes out of the exact batch it was bought in, at that batch's
 * cost, the way a write-off does. Unlike a write-off, the vendor then owes
 * the shop that value: a debit note, issued as a vendor credit that is taken
 * off the same batches first and then the vendor's oldest unpaid stock
 * (VendorCredits). Anything beyond what is owed stays as credit with the
 * vendor, to take off later stock or to be refunded. The stock movement and
 * the money are one document, so the same units are never reversed twice.
 */
class PurchaseReturns
{
    /** @param  array<int, mixed>  $lines  stock_in_id => quantity */
    public static function create(int $vendorId, array $lines, string $reason, ?string $note, string $returnDate, ?int $userId): PurchaseReturn
    {
        $lines = array_filter($lines, fn ($quantity) => is_numeric($quantity) && (float) $quantity > 0);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Enter how many of at least one batch are going back.']);
        }

        if (! array_key_exists($reason, PurchaseReturn::REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose why the goods are going back.']);
        }

        if ($reason === 'other' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say why, when the reason is Other.']);
        }

        return DB::transaction(function () use ($vendorId, $lines, $reason, $note, $returnDate, $userId) {
            $items = [];
            $total = 0.0;

            foreach ($lines as $stockInId => $quantity) {
                $batch = StockIn::query()->whereKey((int) $stockInId)->where('vendor_id', $vendorId)->first()
                    ?? throw ValidationException::withMessages(["lines.{$stockInId}" => 'That batch wasn\'t bought from this vendor.']);

                if ($batch->cost_price === null) {
                    throw ValidationException::withMessages(["lines.{$stockInId}" => "The {$batch->product_name} batch has no cost, so its value can't be claimed back. Add its cost on Stock In first."]);
                }

                $product = Product::whereKey($batch->product_id)->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(["lines.{$stockInId}" => 'That product no longer exists.']);

                $product->unit->assertQuantity($quantity, "lines.{$stockInId}", $product->name);
                $quantity = Quantity::normalize($quantity);

                try {
                    ConsumeStockFifo::handleFromBatch($product, $batch, $quantity);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(["lines.{$stockInId}" => $e->errors()['quantity'] ?? ['Not enough left in that batch.']]);
                }

                $product->decrement('stock_quantity', $quantity);
                $value = Quantity::money($batch->cost_price, $quantity);
                $total += $value;

                $items[] = [
                    'stock_in_id' => $batch->id,
                    'product_id' => $product->id,
                    'product_name' => $batch->product_name,
                    'quantity' => $quantity,
                    'cost_price' => $batch->cost_price,
                    'line_value' => $value,
                ];
            }

            $return = PurchaseReturn::create([
                'vendor_id' => $vendorId,
                'return_date' => $returnDate,
                'reason' => $reason,
                'note' => filled($note) ? $note : null,
                'total_value' => round($total, 2),
                'status' => 'active',
                'user_id' => $userId,
            ]);

            foreach ($items as $item) {
                $return->items()->create($item + ['shop_id' => $return->shop_id]);
            }

            VendorCredits::issue($vendorId, round($total, 2), VendorCredit::DEBIT_NOTE, $return->id, $userId, $return->number().': '.$return->reasonLabel(), true, array_column($items, 'stock_in_id'));

            return $return;
        });
    }

    /** Takes a return back: the goods go back into their batches and the credit is undone. */
    public static function cancel(PurchaseReturn $return, ?int $userId): void
    {
        DB::transaction(function () use ($return, $userId) {
            $return = PurchaseReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($return->isCancelled()) {
                return;
            }

            if ($credit = $return->credit()) {
                VendorCredits::cancel($credit, $userId);
            }

            foreach ($return->items as $item) {
                $batch = StockIn::withoutGlobalScopes()->whereKey($item->stock_in_id)->lockForUpdate()->first();
                $product = Product::withoutGlobalScopes()->whereKey($item->product_id)->lockForUpdate()->first();

                if ($batch !== null) {
                    $batch->increment('quantity_remaining', Quantity::normalize($item->quantity));
                }

                $product?->increment('stock_quantity', Quantity::normalize($item->quantity));
            }

            $return->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId]);
        });
    }
}
