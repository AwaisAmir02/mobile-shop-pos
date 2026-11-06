<?php

namespace App\Actions;

use App\Models\Grn;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a delivery. Each line creates exactly one batch through
 * CreateStockIn, so stock, FIFO/FEFO order and the vendor's balance all come
 * from the same stock_ins rows as any other stock. Unpaid at this point:
 * payments are recorded against the batches on Stock In History, as always.
 */
class ReceiveGoods
{
    /**
     * @param  array<int, array{po_line_id: int, quantity: int|float|string, cost_price: ?float, selling_price: float, expiry_date?: ?string}>  $lines
     */
    public static function handle(PurchaseOrder $order, array $lines, string $receivedOn, ?int $branchId, ?string $note, ?int $userId, bool $confirmOverReceipt = false): Grn
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Enter a quantity for at least one line.']);
        }

        return DB::transaction(function () use ($order, $lines, $receivedOn, $branchId, $note, $userId, $confirmOverReceipt) {
            // Cancelled ones too, so they get the refusal below rather than a not-found.
            $order = PurchaseOrder::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($order->id)->lockForUpdate()->firstOrFail();

            SavePurchaseOrder::assertEnabled($order->shop_id);

            if (! $order->canReceive()) {
                throw ValidationException::withMessages(['lines' => 'This order is '.strtolower($order->status->label()).', so nothing more can be received against it.']);
            }

            $poLines = $order->lines()->with('activeGrnLines')->get()->keyBy('id');
            $incoming = [];
            $over = [];

            foreach ($lines as $line) {
                /** @var PoLine|null $poLine */
                $poLine = $poLines->get($line['po_line_id']);

                if ($poLine === null) {
                    throw ValidationException::withMessages(['lines' => 'A line doesn\'t belong to this order.']);
                }

                if ($poLine->product_id === null) {
                    throw ValidationException::withMessages(['lines' => "{$poLine->product_name} has been deleted, so it can't be received."]);
                }

                $poLine->unit->assertQuantity($line['quantity'], 'lines', $poLine->product_name);
                $incoming[$poLine->id] = Quantity::add($incoming[$poLine->id] ?? 0, $line['quantity']);
            }

            foreach ($incoming as $poLineId => $quantity) {
                $poLine = $poLines->get($poLineId);
                $total = Quantity::add($poLine->receivedQuantity(), $quantity);

                if (Quantity::compare($total, $poLine->quantity) > 0) {
                    $over[] = $poLine->product_name.': '.$poLine->unit->amount($total).' against '.$poLine->unit->amount($poLine->quantity).' ordered';
                }
            }

            if ($over !== [] && ! $confirmOverReceipt) {
                throw ValidationException::withMessages(['overReceipt' => 'More than ordered: '.implode('; ', $over).'. Confirm if that\'s what arrived.']);
            }

            $grn = new Grn([
                'purchase_order_id' => $order->id,
                'user_id' => $userId,
                'received_on' => $receivedOn,
                'note' => filled($note) ? $note : null,
            ]);
            $grn->shop_id = $order->shop_id;
            $grn->branch_id = SavePurchaseOrder::branchFor($order->shop_id, $branchId ?? $order->branch_id);
            $grn->save();

            $stockLines = [];

            foreach ($lines as $line) {
                $poLine = $poLines->get($line['po_line_id']);
                $product = Product::findOrFail($poLine->product_id);

                $grnLine = $grn->lines()->create([
                    'shop_id' => $grn->shop_id,
                    'po_line_id' => $poLine->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => Quantity::normalize($line['quantity']),
                    'unit' => $product->unit,
                    'cost_price' => $line['cost_price'],
                    'selling_price' => $line['selling_price'],
                    'expiry_date' => $product->sellsByExpiry() ? ($line['expiry_date'] ?? null) : null,
                ]);

                $stockLines[] = [
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'cost_price' => $line['cost_price'],
                    'selling_price' => $line['selling_price'],
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'grn_line_id' => $grnLine->id,
                ];
            }

            CreateStockIn::handle($stockLines, $order->vendor_id, 'unpaid', null, $receivedOn, $note, $userId, $grn->branch_id);

            $order->recomputeStatus();

            return $grn->fresh('lines');
        });
    }
}
