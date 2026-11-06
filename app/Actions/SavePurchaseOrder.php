<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shop;
use App\Models\Vendor;
use App\Support\BranchContext;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavePurchaseOrder
{
    /**
     * @param  array{vendor_id: int, branch_id: ?int, order_date: string, expected_date: ?string, note: ?string}  $fields
     * @param  array<int, array{product_id: int, quantity: int|float|string, expected_cost: ?float}>  $lines
     */
    public static function handle(?PurchaseOrder $order, array $fields, array $lines, ?int $userId): PurchaseOrder
    {
        self::assertEnabled(Auth::user()?->shop_id ?? $order?->shop_id);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one product.']);
        }

        if (count(array_unique(array_column($lines, 'product_id'))) !== count($lines)) {
            throw ValidationException::withMessages(['lines' => 'Each product can only be on one line of an order.']);
        }

        return DB::transaction(function () use ($order, $fields, $lines, $userId) {
            $vendor = Vendor::findOrFail($fields['vendor_id']);

            $snapshot = null;

            if ($order !== null) {
                $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if (! $order->isEditable()) {
                    throw ValidationException::withMessages(['lines' => 'This order can no longer be edited, because goods have been received or it has been closed.']);
                }

                $snapshot = [
                    'vendor_id' => $order->vendor_id,
                    'branch_id' => $order->branch_id,
                    'order_date' => $order->order_date->toDateString(),
                    'expected_date' => $order->expected_date?->toDateString(),
                    'note' => $order->note,
                    'lines' => $order->lines->map(fn ($line) => $line->only(['product_id', 'product_name', 'expected_cost']) + ['quantity' => $line->quantity, 'unit' => $line->unit->value])->all(),
                ];
            }

            $order ??= new PurchaseOrder(['user_id' => $userId]);
            $order->fill([
                'vendor_id' => $vendor->id,
                'order_date' => $fields['order_date'],
                'expected_date' => $fields['expected_date'] ?: null,
                'note' => filled($fields['note'] ?? null) ? $fields['note'] : null,
            ]);
            $order->shop_id = $vendor->shop_id;
            $order->branch_id = self::branchFor($vendor->shop_id, $fields['branch_id'] ?? null);
            $order->save();

            $order->lines()->delete();

            foreach ($lines as $line) {
                $product = Product::findOrFail($line['product_id']);
                $product->unit->assertQuantity($line['quantity'], 'lines', $product->name);

                $order->lines()->create([
                    'shop_id' => $order->shop_id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => Quantity::normalize($line['quantity']),
                    'unit' => $product->unit,
                    'expected_cost' => $line['expected_cost'] ?? null,
                ]);
            }

            if ($snapshot !== null) {
                ModuleRevision::create([
                    'shop_id' => $order->shop_id,
                    'revisionable_type' => PurchaseOrder::class,
                    'revisionable_id' => $order->id,
                    'action' => RevisionAction::Edited,
                    'user_id' => $userId,
                    'reason' => null,
                    'snapshot' => $snapshot,
                    'created_at' => now(),
                ]);
            }

            return $order->fresh('lines');
        });
    }

    /** A shop whose plan dropped the feature keeps its history, read-only. */
    public static function assertEnabled(?int $shopId): void
    {
        if (! Shop::whereKey($shopId)->value('purchase_orders_enabled')) {
            throw ValidationException::withMessages(['lines' => 'Purchase orders aren\'t on your plan right now, so this order is read-only. Use Stock In to receive goods.']);
        }
    }

    /** Branch-restricted staff can only work for their own branch. */
    public static function branchFor(int $shopId, ?int $requested): int
    {
        $context = BranchContext::current();

        if ($restricted = $context->restrictedBranchId()) {
            return $restricted;
        }

        if ($requested !== null && $context->branchesFor($shopId)->contains('id', $requested)) {
            return $requested;
        }

        return $context->recordingBranchIdFor($shopId);
    }
}
